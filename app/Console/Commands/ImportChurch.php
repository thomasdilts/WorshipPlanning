<?php

namespace App\Console\Commands;

use App\Services\ChurchTransfer\ChurchImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportChurch extends Command
{
    protected $signature = 'church:import
        {path : The zip made by church:export or the "Export church data" page}
        {--force : Do not ask for confirmation}';

    protected $description = 'Import a church export as a new, separate church on this server. Existing churches are never changed.';

    public function handle(ChurchImporter $importer): int
    {
        if (config('demo.enabled')) {
            $this->components->error('Importing churches is turned off in the demo.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->components->error("No file at {$path}.");

            return self::FAILURE;
        }

        try {
            $manifest = $importer->inspect($path);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Church', (string) ($manifest['church_name'] ?? '?'));
        $this->components->twoColumnDetail('Exported at', (string) ($manifest['exported_at'] ?? '?'));
        $this->components->twoColumnDetail('People', (string) ($manifest['counts']['users'] ?? 0));
        $this->components->twoColumnDetail('Events', (string) ($manifest['counts']['events'] ?? 0));
        $this->components->twoColumnDetail('Files', (string) ($manifest['files'] ?? 0));
        $this->components->twoColumnDetail('Messaging passwords included', ($manifest['includes_secrets'] ?? false) ? 'yes' : 'no');

        if (! $this->option('force') && ! $this->confirm('Create this church as a new church on this server?')) {
            return self::FAILURE;
        }

        $result = $importer->import($path);
        $church = $result['church'];

        $this->components->info("Imported as church {$church->id} ({$church->slug}) with {$result['files']} files.");

        if ($result['skipped_rows'] > 0) {
            $this->components->warn("{$result['skipped_rows']} rows pointed at something outside the export and were left out.");
        }

        return self::SUCCESS;
    }
}
