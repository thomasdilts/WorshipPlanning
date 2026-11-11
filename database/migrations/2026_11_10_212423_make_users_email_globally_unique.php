<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One email is one account on the whole server. Sign-in and password reset look people up by email
 * alone, so the same email in two churches let one church's admin take over the other account.
 * Stops with a list when duplicates already exist, because merging people needs a human decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('users')
            ->selectRaw('LOWER(email) as normalized_email, COUNT(*) as total')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('total', 'normalized_email');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot make user emails unique: these emails belong to more than one account. '
                .'Change or remove the extra accounts, then run the migration again: '
                .$duplicates->keys()->implode(', ')
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['email']);
        });
    }
};
