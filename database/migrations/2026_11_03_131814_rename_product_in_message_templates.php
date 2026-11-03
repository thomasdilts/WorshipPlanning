<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The product is now called WorshipPlanning. Swaps the old product name in every church's message
 * templates, leaving the rest of each church's wording untouched.
 */
return new class extends Migration
{
    private const COLUMNS = ['subject', 'body', 'text_body'];

    public function up(): void
    {
        $this->replaceProductName('WorshipPlanner', 'WorshipPlanning');
    }

    public function down(): void
    {
        $this->replaceProductName('WorshipPlanning', 'WorshipPlanner');
    }

    private function replaceProductName(string $from, string $to): void
    {
        foreach (self::COLUMNS as $column) {
            DB::table('message_templates')
                ->where($column, 'like', '%'.$from.'%')
                ->update([$column => DB::raw('REPLACE('.$column.', '.DB::getPdo()->quote($from).', '.DB::getPdo()->quote($to).')')]);
        }
    }
};
