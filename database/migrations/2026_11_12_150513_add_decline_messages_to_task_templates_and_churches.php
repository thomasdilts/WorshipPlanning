<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_templates', function (Blueprint $table) {
            $table->boolean('receives_decline_messages')->default(false)->after('show_in_spreadsheet');
        });

        Schema::table('churches', function (Blueprint $table) {
            $table->foreignId('decline_message_template_id')
                ->nullable()
                ->after('uses_24_hour_time')
                ->constrained('message_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('churches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decline_message_template_id');
        });

        Schema::table('task_templates', function (Blueprint $table) {
            $table->dropColumn('receives_decline_messages');
        });
    }
};
