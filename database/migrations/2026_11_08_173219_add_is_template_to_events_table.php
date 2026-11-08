<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service templates are events kept out of every normal list; new services are copied from them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('is_template')->default(false)->after('description');
            $table->index(['church_id', 'is_template']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['church_id', 'is_template']);
            $table->dropColumn('is_template');
        });
    }
};
