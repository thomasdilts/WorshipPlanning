<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Typed names are no longer a task template option: every service task can pick "Guest" as its person or team
 * and type the guest's name. Names typed in so far become guests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_tasks', function (Blueprint $table) {
            $table->boolean('guest_person')->default(false)->after('team_text');
            $table->boolean('guest_team')->default(false)->after('guest_person');
        });

        DB::table('event_tasks')->whereNotNull('person_text')->update(['guest_person' => true]);
        DB::table('event_tasks')->whereNotNull('team_text')->update(['guest_team' => true]);

        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropColumn(['allow_person_text', 'allow_team_text']);
        });
    }

    public function down(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->boolean('allow_person_text')->default(false)->after('person_usage');
            $table->boolean('allow_team_text')->default(false)->after('team_usage');
        });

        Schema::table('event_tasks', function (Blueprint $table) {
            $table->dropColumn(['guest_person', 'guest_team']);
        });
    }
};
