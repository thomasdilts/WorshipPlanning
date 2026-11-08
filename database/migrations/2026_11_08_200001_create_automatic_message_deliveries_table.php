<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per automatic message and service it was sent for, so each is sent only once.
 *
 * The first deploy of this migration created the table and its foreign keys, then failed on the
 * default unique index name (over MariaDB's 64 characters). On such a database the table is kept
 * and only the missing pieces are added.
 */
return new class extends Migration
{
    private const TABLE = 'automatic_message_deliveries';

    private const UNIQUE_INDEX = 'automatic_message_deliveries_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->id();
                $table->foreignId('automatic_message_id')->constrained()->cascadeOnDelete();
                $table->foreignId('event_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('message_count')->default(0);
                $table->timestamps();

                $table->unique(['automatic_message_id', 'event_id'], self::UNIQUE_INDEX);
            });

            return;
        }

        $foreignColumns = collect(Schema::getForeignKeys(self::TABLE))->flatMap(fn (array $key): array => $key['columns'])->all();

        Schema::table(self::TABLE, function (Blueprint $table) use ($foreignColumns) {
            if (! in_array('automatic_message_id', $foreignColumns, true)) {
                $table->foreign('automatic_message_id')->references('id')->on('automatic_messages')->cascadeOnDelete();
            }

            if (! in_array('event_id', $foreignColumns, true)) {
                $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            }

            if (! Schema::hasIndex(self::TABLE, self::UNIQUE_INDEX)) {
                $table->unique(['automatic_message_id', 'event_id'], self::UNIQUE_INDEX);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
