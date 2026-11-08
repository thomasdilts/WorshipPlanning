<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per automatic message and service it was sent for, so each is sent only once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automatic_message_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automatic_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamps();

            $table->unique(['automatic_message_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automatic_message_deliveries');
    }
};
