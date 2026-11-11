<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task templates now have one "Default order". Global templates used a separate global order until now,
 * so it becomes their default order; the global order column is kept in step from here on.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('task_templates')
            ->where('use_globally', true)
            ->whereColumn('default_order', '!=', 'default_global_order')
            ->update(['default_order' => DB::raw('default_global_order')]);

        DB::table('task_templates')
            ->whereColumn('default_global_order', '!=', 'default_order')
            ->update(['default_global_order' => DB::raw('default_order')]);
    }

    public function down(): void
    {
        // The values are already equal; nothing to undo.
    }
};
