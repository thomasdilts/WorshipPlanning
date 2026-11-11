<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task template can allow a typed name for the person or team, for someone who is not in the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->boolean('allow_person_text')->default(false)->after('person_usage');
            $table->boolean('allow_team_text')->default(false)->after('team_usage');
        });

        Schema::table('event_tasks', function (Blueprint $table) {
            $table->string('person_text')->nullable()->after('assigned_team_id');
            $table->string('team_text')->nullable()->after('person_text');
        });
    }

    public function down(): void
    {
        Schema::table('event_tasks', function (Blueprint $table) {
            $table->dropColumn(['person_text', 'team_text']);
        });

        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropColumn(['allow_person_text', 'allow_team_text']);
        });
    }
};
