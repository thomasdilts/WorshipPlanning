<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Team powers now come from being on (or leading) a team, not from roles.
 *
 * - teams.manage_members and teams.message are gone: leaders run their own teams, members message theirs.
 * - teams.view now means "see all teams", so it is taken off the default Member and Team Leader roles;
 *   their holders still see every team they belong to.
 * The Team Leader role itself is left in place for each church admin to delete or repurpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');

        DB::table($tables['permissions'])
            ->whereIn('name', ['teams.manage_members', 'teams.message'])
            ->delete();

        $viewAllTeams = DB::table($tables['permissions'])->where('name', 'teams.view')->value('id');

        if ($viewAllTeams !== null) {
            DB::table($tables['role_has_permissions'])
                ->where('permission_id', $viewAllTeams)
                ->whereIn('role_id', DB::table($tables['roles'])->whereIn('name', ['Member', 'Team Leader'])->select('id'))
                ->delete();
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        // Removed permissions are not restored; re-seed roles if needed.
    }
};
