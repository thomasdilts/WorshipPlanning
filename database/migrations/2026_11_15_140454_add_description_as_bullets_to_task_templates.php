<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bullets used to switch on by template name ("Pålysningar", "Announcements"…); those templates keep them.
     */
    public function up(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->boolean('description_as_bullets')->default(false)->after('description_usage');
        });

        DB::table('task_templates')
            ->whereIn(DB::raw('LOWER(TRIM(name))'), ['pålysningar', 'palysningar', 'announcements', 'إعلانات', 'الإعلانات'])
            ->where('description_usage', '!=', 'not_used')
            ->update(['description_as_bullets' => true]);
    }

    public function down(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropColumn('description_as_bullets');
        });
    }
};
