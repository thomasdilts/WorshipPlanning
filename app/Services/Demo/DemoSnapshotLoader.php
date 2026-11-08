<?php

namespace App\Services\Demo;

use App\Core\Permissions\PermissionRegistry;
use App\Filament\Forms\NtfyTopicField;
use App\Models\Church;
use Carbon\CarbonImmutable;
use Database\Seeders\MessageTemplatesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Loads a demo snapshot into the current database, replacing whatever the snapshot's tables hold.
 *
 * Dates move forward by whole weeks since the snapshot was taken, so the demo always has the same
 * mix of past and upcoming events and Sunday services stay on Sundays.
 */
class DemoSnapshotLoader
{
    /**
     * Date and datetime columns that move with the calendar. event_tasks.starts_at/ends_at are
     * times of day, so they are deliberately not listed.
     *
     * @var array<string, list<string>>
     */
    public const SHIFTED_COLUMNS = [
        'events' => ['starts_at', 'shared_at', 'plan_changed_at', 'created_at', 'updated_at'],
        'event_tasks' => ['accepted_verbally_at', 'created_at', 'updated_at'],
        'event_task_responses' => ['requested_at', 'occurred_at', 'created_at', 'updated_at'],
        'unavailabilities' => ['starts_on', 'ends_on', 'created_at', 'updated_at'],
    ];

    public static function shiftInDays(CarbonImmutable $anchor, CarbonImmutable $today): int
    {
        $days = $anchor->startOfDay()->diffInDays($today->startOfDay(), false);

        return (int) round($days / 7) * 7;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function load(array $snapshot, CarbonImmutable $today): void
    {
        if (($snapshot['version'] ?? null) !== DemoSnapshotBuilder::SNAPSHOT_VERSION) {
            throw new RuntimeException('Unsupported demo snapshot version.');
        }

        $days = self::shiftInDays(CarbonImmutable::parse($snapshot['anchor_date']), $today);

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($snapshot['tables'] as $table => $rows) {
                DB::table($table)->delete();

                foreach (array_chunk($this->shift($table, $rows, $days), 200) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->prepareAccounts((int) $snapshot['church_id']);
        $this->installAvatars((int) $snapshot['church_id']);

        $church = Church::query()->findOrFail($snapshot['church_id']);
        MessageTemplatesSeeder::seedForChurch($church);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /* Permissions added to the app after the snapshot was taken must still exist. */
        foreach (app(PermissionRegistry::class)->all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function shift(string $table, array $rows, int $days): array
    {
        $columns = self::SHIFTED_COLUMNS[$table] ?? [];

        if ($columns === [] || $days === 0) {
            return $rows;
        }

        return array_map(function (array $row) use ($columns, $days): array {
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;

                if (! is_string($value) || $value === '') {
                    continue;
                }

                $format = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
                $row[$column] = Carbon::parse($value)->addDays($days)->format($format);
            }

            return $row;
        }, $rows);
    }

    /**
     * Everyone gets the public demo password and a fresh random ntfy topic.
     */
    protected function prepareAccounts(int $churchId): void
    {
        $password = Hash::make((string) config('demo.password'));

        foreach (DB::table('users')->where('church_id', $churchId)->pluck('id') as $userId) {
            DB::table('users')->where('id', $userId)->update([
                'password' => $password,
                'ntfy_topic' => NtfyTopicField::generate(),
            ]);
        }
    }

    /**
     * Give each person the profile picture shipped for their user id (database/demo/avatars/{id}.jpg).
     * The pictures are AI-generated faces, so no real person's photo is part of the demo.
     */
    protected function installAvatars(int $churchId): void
    {
        $directory = (string) config('demo.avatars_path');
        $disk = Storage::disk('public');

        foreach (DB::table('users')->where('church_id', $churchId)->pluck('id') as $userId) {
            $source = "{$directory}/{$userId}.jpg";

            if (! is_file($source)) {
                continue;
            }

            $path = "churches/{$churchId}/avatars/demo-{$userId}.jpg";
            $disk->put($path, (string) file_get_contents($source));

            DB::table('users')->where('id', $userId)->update(['avatar_path' => $path]);
        }
    }
}
