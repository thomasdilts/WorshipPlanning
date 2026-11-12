<?php

namespace App\Services\ChurchTransfer;

use App\Core\Modules\ChurchModuleManager;
use App\Models\Church;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;
use ZipArchive;

/**
 * Loads a ChurchExporter zip as a brand-new church. It never changes or merges into an existing
 * church: every row gets a new id, and references between rows are rewritten to match.
 *
 * Rows that point at something outside the export (for example a platform admin who sent a
 * message) keep the row with the reference emptied when the column allows it, and are skipped
 * otherwise. Nobody imported becomes a platform admin.
 */
class ChurchImporter
{
    /** @var array<string, array<int, int>> table => old id => new id */
    protected array $idMap = [];

    /** @var list<string> files written so far, removed again if the import fails */
    protected array $writtenFiles = [];

    protected int $oldChurchId = 0;

    /** @var array<string, int|string> message template ids on the exported church row, by column */
    protected array $churchTemplateIds = [];

    protected int $newChurchId = 0;

    public function __construct(
        protected ChurchModuleManager $modules,
    ) {}

    /**
     * Read and check the manifest without importing anything.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $zipPath): array
    {
        $zip = $this->open($zipPath);
        $manifest = $this->manifest($zip);
        $zip->close();

        return $manifest;
    }

    /**
     * @return array{church: Church, skipped_rows: int, files: int}
     */
    public function import(string $zipPath): array
    {
        $zip = $this->open($zipPath);
        $manifest = $this->manifest($zip);

        try {
            $this->ensureEmailsAreFree($zip);
        } catch (Throwable $exception) {
            $zip->close();

            throw $exception;
        }

        $this->idMap = [
            'permissions' => $this->sharedIds('permissions', $manifest['permissions'] ?? [], fn (object $row): string => "{$row->guard_name}:{$row->name}"),
            'languages' => $this->sharedIds('languages', $manifest['languages'] ?? [], fn (object $row): string => (string) $row->iso),
        ];
        $this->writtenFiles = [];
        $this->newChurchId = 0;
        $this->oldChurchId = (int) $manifest['church_id'];
        $skipped = 0;

        try {
            DB::transaction(function () use ($zip, &$skipped): void {
                foreach (ChurchTables::TABLES as $table => $definition) {
                    $skipped += $this->importTable($zip, $table, $definition);

                    if ($table === 'churches' && $this->newChurchId === 0) {
                        throw new RuntimeException(__('This export holds no church.'));
                    }

                    if ($table === 'message_templates') {
                        $this->linkChurchTemplates();
                    }
                }
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($this->writtenFiles);

            throw $exception;
        } finally {
            $zip->close();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'church' => Church::query()->findOrFail($this->newChurchId),
            'skipped_rows' => $skipped,
            'files' => count($this->writtenFiles),
        ];
    }

    protected function open(string $zipPath): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(__('This is not a WorshipPlanning church export (the file is not a zip archive).'));
        }

        return $zip;
    }

    /**
     * @return array<string, mixed>
     */
    protected function manifest(ZipArchive $zip): array
    {
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== ChurchExporter::FORMAT || ! isset($manifest['church_id'])) {
            throw new RuntimeException(__('This is not a WorshipPlanning church export (manifest.json is missing or wrong).'));
        }

        if ((int) ($manifest['format_version'] ?? 0) > ChurchExporter::FORMAT_VERSION) {
            throw new RuntimeException(__('This export was made by a newer version of WorshipPlanning. Update this server first.'));
        }

        $migration = $manifest['latest_migration'] ?? null;

        if (is_string($migration) && ! DB::table('migrations')->where('migration', $migration)->exists()) {
            throw new RuntimeException(__('This export was made by a newer version of WorshipPlanning. Update this server first.'));
        }

        return $manifest;
    }

    /**
     * Emails are unique on the whole server, and an imported person must never share one with an
     * existing account: sign-in and password reset find people by email alone.
     */
    protected function ensureEmailsAreFree(ZipArchive $zip): void
    {
        $json = $zip->getFromName('data/users.json');
        $rows = $json === false ? [] : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $emails = collect($rows)
            ->pluck('email')
            ->filter(fn (mixed $email): bool => is_string($email) && $email !== '')
            ->map(fn (string $email): string => Str::lower($email));

        $duplicatedInFile = $emails->duplicates()->unique()->values();

        if ($duplicatedInFile->isNotEmpty()) {
            throw new RuntimeException(__('This export lists the same email for more than one person: :emails', [
                'emails' => $duplicatedInFile->implode(', '),
            ]));
        }

        $taken = $emails->isEmpty() ? collect() : DB::table('users')
            ->whereIn(DB::raw('LOWER(email)'), $emails->all())
            ->pluck('email');

        if ($taken->isNotEmpty()) {
            throw new RuntimeException(__('These people already have an account on this server, and an email can only belong to one account: :emails', [
                'emails' => $taken->implode(', '),
            ]));
        }
    }

    /**
     * Permissions and languages exist on every server; match them by name instead of id.
     *
     * @param  array<int|string, string>  $exported  old id => key
     * @param  callable(object): string  $keyFor
     * @return array<int, int>
     */
    protected function sharedIds(string $table, array $exported, callable $keyFor): array
    {
        $local = DB::table($table)->get()->mapWithKeys(fn (object $row): array => [$keyFor($row) => (int) $row->id])->all();
        $map = [];

        foreach ($exported as $oldId => $key) {
            if (isset($local[$key])) {
                $map[(int) $oldId] = $local[$key];
            }
        }

        return $map;
    }

    /**
     * @param  array{scope: string|array{0: string, 1: string}, refs?: array<string, string>, files?: list<string>}  $definition
     * @return int rows skipped
     */
    protected function importTable(ZipArchive $zip, string $table, array $definition): int
    {
        $json = $zip->getFromName("data/{$table}.json");
        $rows = $json === false ? [] : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $columns = collect(Schema::getColumns($table))->keyBy('name');
        $skipped = 0;

        foreach ($rows as $row) {
            $row = array_intersect_key($row, $columns->all());

            foreach ($definition['refs'] ?? [] as $column => $target) {
                if (! array_key_exists($column, $row) || $row[$column] === null) {
                    continue;
                }

                $newId = $this->idMap[$target][(int) $row[$column]] ?? null;

                if ($newId === null && ! $columns[$column]['nullable']) {
                    $skipped++;

                    continue 2;
                }

                $row[$column] = $newId;
            }

            /* Every church-scoped row belongs to the new church, whatever the file says; a missing
               church id would otherwise create rows (such as roles) that every church shares. */
            if ($definition['scope'] === 'church_id') {
                $row['church_id'] = $this->newChurchId;
            }

            if (in_array($table, ['model_has_roles', 'model_has_permissions'], true)
                && ($row['model_type'] ?? null) !== User::class) {
                $skipped++;

                continue;
            }

            $files = array_intersect_key($row, array_flip($definition['files'] ?? []));

            if ($table === 'churches') {
                $row = [...$row, ...array_map(fn (): null => null, $files)];
            } else {
                $row = [...$row, ...array_map(fn (mixed $path): ?string => $this->copyFile($zip, $path), $files)];
            }

            $row = $this->prepareRow($table, $row);
            $oldId = $row['id'] ?? null;
            unset($row['id']);

            if ($oldId === null) {
                DB::table($table)->insert($row);

                continue;
            }

            $newId = (int) DB::table($table)->insertGetId($row);
            $this->idMap[$table][(int) $oldId] = $newId;

            if ($table === 'churches') {
                $this->newChurchId = $newId;
            }

            if ($table === 'churches' && $files !== []) {
                /* Files live under the church's new id, which is only known now. */
                DB::table('churches')->where('id', $newId)
                    ->update(array_map(fn (mixed $path): ?string => $this->copyFile($zip, $path), $files));
            }
        }

        return $skipped;
    }

    /**
     * Fix-ups for single tables: unique values, JSON holding ids, secrets and account flags.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function prepareRow(string $table, array $row): array
    {
        return match ($table) {
            'churches' => $this->prepareChurch($row),
            'users' => [
                ...$row,
                'is_platform_admin' => false,
                'remember_token' => null,
                'spreadsheet_preferences' => $this->rekeyPreferences($row['spreadsheet_preferences'] ?? null),
            ],
            'church_modules' => [...$row, 'settings' => $this->moduleSettings((string) $row['module_key'], $row['settings'] ?? null)],
            'outbound_messages' => [...$row, 'meta' => $this->messageMeta($row['meta'] ?? null)],
            default => $row,
        };
    }

    /**
     * The church row goes in before its message templates, so template ids are set once those exist.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function prepareChurch(array $row): array
    {
        $this->churchTemplateIds = array_filter(
            array_intersect_key($row, array_flip(ChurchTables::CHURCH_TEMPLATE_COLUMNS)),
            fn (mixed $id): bool => is_numeric($id),
        );

        return [
            ...$row,
            ...array_map(fn (): null => null, array_flip(ChurchTables::CHURCH_TEMPLATE_COLUMNS)),
            'slug' => $this->uniqueSlug((string) ($row['slug'] ?? 'church')),
        ];
    }

    protected function linkChurchTemplates(): void
    {
        $ids = array_filter(array_map(
            fn (mixed $id): ?int => $this->idMap['message_templates'][(int) $id] ?? null,
            $this->churchTemplateIds,
        ));

        if ($ids !== []) {
            DB::table('churches')->where('id', $this->newChurchId)->update($ids);
        }
    }

    protected function uniqueSlug(string $slug): string
    {
        $base = $slug !== '' ? $slug : 'church';
        $candidate = $base;

        for ($n = 2; DB::table('churches')->where('slug', $candidate)->exists(); $n++) {
            $candidate = "{$base}-{$n}";
        }

        return $candidate;
    }

    /**
     * Spreadsheet preferences are stored per church id.
     */
    protected function rekeyPreferences(?string $json): ?string
    {
        $preferences = json_decode((string) $json, true);

        if (! is_array($preferences) || ! isset($preferences[(string) $this->oldChurchId])) {
            return null;
        }

        return json_encode([(string) $this->newChurchId => $preferences[(string) $this->oldChurchId]]);
    }

    /**
     * Re-encrypt secrets with this server's APP_KEY and point template settings at the new templates.
     */
    protected function moduleSettings(string $moduleKey, ?string $json): ?string
    {
        $settings = json_decode((string) $json, true);

        if (! is_array($settings)) {
            return $json;
        }

        foreach (ChurchTables::ENCRYPTED_SECRETS[$moduleKey] ?? [] as $path) {
            $value = Arr::get($settings, $path);

            if (is_string($value) && $value !== '') {
                Arr::set($settings, $path, $this->modules->encryptSecret($value));
            }
        }

        foreach ($settings as $key => $value) {
            if (str_ends_with((string) $key, '_template_id') && is_numeric($value)) {
                $settings[$key] = $this->idMap['message_templates'][(int) $value] ?? null;
            }
        }

        return json_encode($settings, JSON_UNESCAPED_UNICODE);
    }

    protected function messageMeta(?string $json): ?string
    {
        $meta = json_decode((string) $json, true);

        if (! is_array($meta)) {
            return $json;
        }

        foreach (['event_id' => 'events', 'event_message_id' => 'event_messages', 'event_task_id' => 'event_tasks'] as $key => $table) {
            if (isset($meta[$key])) {
                $meta[$key] = $this->idMap[$table][(int) $meta[$key]] ?? null;
            }
        }

        return json_encode($meta, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Copy an uploaded file out of the zip into this church's folder. Returns the new path, or null
     * when the file isn't in the export or its path isn't safe.
     */
    protected function copyFile(ZipArchive $zip, mixed $path): ?string
    {
        if (! ChurchTables::isSafeFilePath($path)) {
            return null;
        }

        $stream = $zip->getStream('files/'.$path);

        if ($stream === false) {
            return null;
        }

        $prefix = "churches/{$this->oldChurchId}/";
        $relative = str_starts_with($path, $prefix) ? Str::after($path, $prefix) : 'imported/'.$path;
        $newPath = "churches/{$this->newChurchId}/{$relative}";
        $disk = Storage::disk('public');

        if ($disk->exists($newPath)) {
            $newPath = Str::beforeLast($newPath, '/').'/'.Str::uuid()->toString().'-'.basename($newPath);
        }

        $disk->writeStream($newPath, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $this->writtenFiles[] = $newPath;

        return $newPath;
    }
}
