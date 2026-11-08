<?php

namespace Tests\Feature\Baseline;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Production runs MariaDB, which rejects index and foreign key names over 64 characters. SQLite does
 * not, so the generated names are checked here instead of failing a deploy.
 */
class IdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    private const MAX_LENGTH = 64;

    public function test_index_and_foreign_key_names_fit_mariadb(): void
    {
        $tooLong = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $names = [
                ...array_column(Schema::getIndexes($table), 'name'),
                ...array_filter(array_column(Schema::getForeignKeys($table), 'name')),
            ];

            foreach ($names as $name) {
                if (strlen($name) > self::MAX_LENGTH) {
                    $tooLong[] = $table.': '.$name;
                }
            }
        }

        $this->assertSame([], $tooLong, 'Give these indexes a shorter explicit name.');
    }
}
