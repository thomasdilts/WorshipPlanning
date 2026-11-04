<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Exporting the whole church gets its own permission, given to every existing Church Admin role.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');
        $now = now();

        $export = DB::table($tables['permissions'])
            ->where(['name' => 'church.export', 'guard_name' => 'web'])
            ->value('id')
            ?? DB::table($tables['permissions'])->insertGetId([
                'name' => 'church.export',
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $roleIds = DB::table($tables['roles'])
            ->where(['name' => 'Church Admin', 'guard_name' => 'web'])
            ->whereNotIn('id', DB::table($tables['role_has_permissions'])->where('permission_id', $export)->select('role_id'))
            ->pluck('id');

        DB::table($tables['role_has_permissions'])->insert(
            $roleIds->map(fn (int $roleId): array => ['permission_id' => $export, 'role_id' => $roleId])->all(),
        );

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        DB::table(config('permission.table_names.permissions'))
            ->where('name', 'church.export')
            ->delete();
    }
};
