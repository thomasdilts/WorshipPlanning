<?php

namespace App\Console\Commands;

use App\Models\Church;
use App\Services\ChurchTransfer\ChurchExporter;
use Illuminate\Console\Command;

class ExportChurch extends Command
{
    protected $signature = 'church:export
        {church : Church id or slug}
        {path? : Where to write the zip (default: storage/app/private/church-exports/<slug>-<date>.zip)}
        {--with-secrets : Include messaging and Bible API passwords and tokens as plain text}';

    protected $description = 'Export one church with all its data and uploaded files to a zip that church:import can load on any WorshipPlanning server.';

    public function handle(ChurchExporter $exporter): int
    {
        if (config('demo.enabled')) {
            $this->components->error('Exporting churches is turned off in the demo.');

            return self::FAILURE;
        }

        $key = (string) $this->argument('church');
        $church = Church::query()->where(is_numeric($key) ? 'id' : 'slug', $key)->first();

        if ($church === null) {
            $this->components->error("No church with id or slug {$key}.");

            return self::FAILURE;
        }

        $path = $this->argument('path') ?? storage_path('app/private/church-exports/'.$church->slug.'-'.now()->format('Y-m-d-His').'.zip');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }

        $result = $exporter->export($church, $path, (bool) $this->option('with-secrets'));

        foreach ($result['tables'] as $table => $count) {
            $this->line(sprintf('  %-24s %d', $table, $count));
        }

        $this->line(sprintf('  %-24s %d', 'files', $result['files']));

        if ($result['missing_files'] > 0) {
            $this->components->warn("{$result['missing_files']} files are listed in the database but missing on disk, so they are not in the export.");
        }

        $this->components->info("Wrote {$path}. It holds everyone's personal data".($this->option('with-secrets') ? ' and your messaging passwords' : '').': keep it safe and delete it when you are done.');

        return self::SUCCESS;
    }
}
