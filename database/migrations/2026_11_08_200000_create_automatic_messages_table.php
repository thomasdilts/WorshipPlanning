<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages a church sends automatically to everyone on a service, a set number of hours before it starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automatic_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('message_template_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('hours_before');
            $table->text('custom_message')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['church_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automatic_messages');
    }
};
