<?php

namespace App\Services\ChurchTransfer;

use App\Core\Modules\ChurchModuleManager;
use App\Models\Church;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Writes one church, with all its rows and uploaded files, to a zip that ChurchImporter can load on
 * this or another WorshipPlanning server.
 *
 * Zip layout: manifest.json, data/{table}.json for every table in ChurchTables::TABLES, and
 * files/{path} for every uploaded file, at the same path it has on the public disk.
 */
class ChurchExporter
{
    public const FORMAT = 'worship-planning-church';

    public const FORMAT_VERSION = 1;

    public function __construct(
        protected ChurchModuleManager $modules,
    ) {}

    /**
     * @return array{tables: array<string, int>, files: int, missing_files: int}
     */
    public function export(Church $church, string $zipPath, bool $includeSecrets): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create {$zipPath}.");
        }

        $disk = Storage::disk('public');
        $ids = [];
        $counts = [];
        $files = 0;
        $missingFiles = 0;

        foreach (ChurchTables::TABLES as $table => $definition) {
            $rows = $this->rowsFor($church, $table, $definition['scope'], $ids);

            if ($table === 'church_modules') {
                $rows = array_map(fn (array $row): array => $this->prepareSecrets($row, $includeSecrets), $rows);
            }

            if (array_key_exists('id', $rows[0] ?? [])) {
                $ids[$table] = array_column($rows, 'id');
            }

            foreach ($definition['files'] ?? [] as $column) {
                foreach ($rows as $row) {
                    $path = $row[$column] ?? null;

                    if (blank($path)) {
                        continue;
                    }

                    if (ChurchTables::isSafeFilePath($path) && $disk->exists($path)) {
                        $zip->addFile($disk->path($path), 'files/'.$path);
                        $files++;
                    } else {
                        $missingFiles++;
                    }
                }
            }

            $zip->addFromString("data/{$table}.json", json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $counts[$table] = count($rows);
        }

        $zip->addFromString('manifest.json', json_encode([
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'latest_migration' => DB::table('migrations')->orderByDesc('id')->value('migration'),
            'church_id' => $church->id,
            'church_name' => $church->name,
            'includes_secrets' => $includeSecrets,
            'permissions' => DB::table('permissions')->get(['id', 'name', 'guard_name'])
                ->mapWithKeys(fn (object $permission): array => [$permission->id => "{$permission->guard_name}:{$permission->name}"])->all(),
            'languages' => DB::table('languages')->pluck('iso', 'id')->all(),
            'counts' => $counts,
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        if (! $zip->close()) {
            throw new RuntimeException("Could not write {$zipPath}.");
        }

        return ['tables' => $counts, 'files' => $files, 'missing_files' => $missingFiles];
    }

    /**
     * @param  string|array{0: string, 1: string}  $scope
     * @param  array<string, list<int>>  $ids
     * @return list<array<string, mixed>>
     */
    protected function rowsFor(Church $church, string $table, string|array $scope, array $ids): array
    {
        $query = DB::table($table);

        if ($scope === 'church') {
            $query->where('id', $church->id);
        } elseif ($scope === 'church_id') {
            $query->where('church_id', $church->id);
        } else {
            [$column, $parent] = $scope;
            $parentIds = $ids[$parent] ?? [];

            if ($parentIds === []) {
                return [];
            }

            $rows = [];

            foreach (array_chunk($parentIds, 500) as $chunk) {
                foreach (DB::table($table)->whereIn($column, $chunk)->get() as $row) {
                    $rows[] = (array) $row;
                }
            }

            return $rows;
        }

        if (in_array($table, ['model_has_roles', 'model_has_permissions'], true)) {
            $query->where('model_type', User::class);
        }

        return $query->get()->map(fn (object $row): array => (array) $row)->all();
    }

    /**
     * Secrets are stored encrypted with this server's APP_KEY, which another server doesn't have, so
     * they are either written out as plain text or blanked.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function prepareSecrets(array $row, bool $includeSecrets): array
    {
        $secrets = ChurchTables::SECRETS[$row['module_key']] ?? [];
        $settings = json_decode((string) ($row['settings'] ?? ''), true);

        if ($secrets === [] || ! is_array($settings)) {
            return $row;
        }

        foreach ($secrets as $path) {
            $value = Arr::get($settings, $path);

            if (! is_string($value) || $value === '') {
                continue;
            }

            $encrypted = in_array($path, ChurchTables::ENCRYPTED_SECRETS[$row['module_key']] ?? [], true);
            Arr::set($settings, $path, $includeSecrets ? ($encrypted ? $this->modules->decryptSecret($value) : $value) : '');
        }

        $row['settings'] = json_encode($settings, JSON_UNESCAPED_UNICODE);

        return $row;
    }
}
