<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * messaging.view now means "see every message in the church" (Message history). Everyone sees their
 * own messages under My messages without it, so it is taken off the default non-admin roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');

        $viewAllMessages = DB::table($tables['permissions'])->where('name', 'messaging.view')->value('id');

        if ($viewAllMessages !== null) {
            DB::table($tables['role_has_permissions'])
                ->where('permission_id', $viewAllMessages)
                ->whereIn('role_id', DB::table($tables['roles'])->whereIn('name', ['Member', 'Team Leader', 'Event Planner'])->select('id'))
                ->delete();
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        // Not restored automatically; grant "View all messages" to roles again if needed.
    }
};
