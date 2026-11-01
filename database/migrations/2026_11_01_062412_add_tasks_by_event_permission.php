<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Tasks by event" now has its own permission. Roles that could edit events already had the view,
 * so they get the new permission to keep it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');
        $now = now();

        $editEvents = DB::table($tables['permissions'])
            ->where(['name' => 'events.update', 'guard_name' => 'web'])
            ->value('id');

        $tasksByEvent = DB::table($tables['permissions'])
            ->where(['name' => 'events.tasks_by_event', 'guard_name' => 'web'])
            ->value('id')
            ?? DB::table($tables['permissions'])->insertGetId([
                'name' => 'events.tasks_by_event',
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        if ($editEvents !== null) {
            $roleIds = DB::table($tables['role_has_permissions'])
                ->where('permission_id', $editEvents)
                ->whereNotIn('role_id', DB::table($tables['role_has_permissions'])->where('permission_id', $tasksByEvent)->select('role_id'))
                ->pluck('role_id');

            DB::table($tables['role_has_permissions'])->insert(
                $roleIds->map(fn (int $roleId): array => ['permission_id' => $tasksByEvent, 'role_id' => $roleId])->all(),
            );
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        DB::table(config('permission.table_names.permissions'))
            ->where('name', 'events.tasks_by_event')
            ->delete();
    }
};
