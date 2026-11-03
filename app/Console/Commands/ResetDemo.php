<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoSnapshotLoader;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ResetDemo extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Wipe the demo site and reload the demo snapshot with dates moved up to today. Only runs when DEMO_MODE=true.';

    public function handle(DemoSnapshotLoader $loader): int
    {
        if (! config('demo.enabled')) {
            $this->components->error('demo:reset wipes the whole database, so it only runs when DEMO_MODE=true.');

            return self::FAILURE;
        }

        $path = config('demo.snapshot_path');

        if (! is_file($path)) {
            $this->components->error("No demo snapshot at {$path}. Run demo:build-snapshot first.");

            return self::FAILURE;
        }

        $snapshot = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->call('migrate:fresh', ['--force' => true]);
        $loader->load($snapshot, CarbonImmutable::today());
        $this->clearUploads();

        $this->components->info('Demo reset.');

        return self::SUCCESS;
    }

    /**
     * Remove everything testers uploaded (avatars, pictures, shared files, logos).
     */
    protected function clearUploads(): void
    {
        foreach (['public', 'local'] as $disk) {
            $storage = Storage::disk($disk);

            foreach ($storage->directories() as $directory) {
                $storage->deleteDirectory($directory);
            }

            $storage->delete(array_filter($storage->files(), fn (string $file): bool => $file !== '.gitignore'));
        }
    }
}
