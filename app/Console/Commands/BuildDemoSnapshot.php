<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoSnapshotBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class BuildDemoSnapshot extends Command
{
    protected $signature = 'demo:build-snapshot
        {--church= : Church id to copy (required)}
        {--export-missing= : Write text that has no translation yet to this JSON file (keep it out of the repository: it holds the original text)}';

    protected $description = 'Build the anonymized, English demo snapshot from one church (reads only; writes database/demo/snapshot.json).';

    public function handle(DemoSnapshotBuilder $builder): int
    {
        $churchId = $this->option('church');

        if (! is_numeric($churchId)) {
            $this->components->error('Pass --church=<id>.');

            return self::INVALID;
        }

        $translations = $this->readJson(config('demo.translations_path'));
        $hymns = $this->readJson(config('demo.hymns_path'));

        $result = $builder->build((int) $churchId, CarbonImmutable::today(), $translations, $hymns);
        $missingCount = count($result['missing']) + count($result['missing_names']);

        if ($exportPath = $this->option('export-missing')) {
            file_put_contents($exportPath, json_encode(
                ['names' => $result['missing_names'], 'texts' => $result['missing']],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
            )."\n");
            $this->components->info("Wrote {$missingCount} untranslated strings to {$exportPath}.");
        }

        if ($result['missing_names'] !== []) {
            $this->components->error(count($result['missing_names']).' names have no translation, so no snapshot was written. Use --export-missing to list them.');

            return self::FAILURE;
        }

        file_put_contents(
            config('demo.snapshot_path'),
            json_encode($result['snapshot'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n",
        );

        foreach ($result['snapshot']['tables'] as $table => $rows) {
            $this->line(sprintf('  %-24s %d', $table, count($rows)));
        }

        if ($result['missing'] !== []) {
            $this->components->warn(count($result['missing']).' descriptions had no translation and were left out.');
        }

        $this->components->info('Wrote '.config('demo.snapshot_path').'. Review it before committing.');

        return self::SUCCESS;
    }

    /**
     * @return array<mixed>
     */
    protected function readJson(string $path): array
    {
        return is_file($path) ? (array) json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
    }
}
