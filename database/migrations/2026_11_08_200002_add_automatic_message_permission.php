<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Automatic messages before a service are managed by Church Admins only.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const GRANTS = [
        'messaging.automatic.manage' => ['Church Admin'],
    ];

    public function up(): void
    {
        $tables = config('permission.table_names');
        $now = now();

        foreach (self::GRANTS as $name => $roles) {
            $permissionId = DB::table($tables['permissions'])
                ->where(['name' => $name, 'guard_name' => 'web'])
                ->value('id')
                ?? DB::table($tables['permissions'])->insertGetId([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            $roleIds = DB::table($tables['roles'])
                ->whereIn('name', $roles)
                ->where('guard_name', 'web')
                ->whereNotIn('id', DB::table($tables['role_has_permissions'])->where('permission_id', $permissionId)->select('role_id'))
                ->pluck('id');

            DB::table($tables['role_has_permissions'])->insert(
                $roleIds->map(fn (int $roleId): array => ['permission_id' => $permissionId, 'role_id' => $roleId])->all(),
            );
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        DB::table(config('permission.table_names.permissions'))
            ->whereIn('name', array_keys(self::GRANTS))
            ->delete();
    }
};
