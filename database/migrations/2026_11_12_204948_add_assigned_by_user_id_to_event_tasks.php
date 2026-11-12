<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who put the current person or team on the task. Empty for assignments made before this was recorded.
     */
    public function up(): void
    {
        Schema::table('event_tasks', function (Blueprint $table) {
            $table->foreignId('assigned_by_user_id')
                ->nullable()
                ->after('assigned_team_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_by_user_id');
        });
    }
};
