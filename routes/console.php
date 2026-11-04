<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (config('demo.enabled')) {
    Schedule::command('demo:reset')->dailyAt(config('demo.reset_at'))->withoutOverlapping();
}

/* Church exports hold personal data: remove any that were never downloaded, and failed import uploads. */
Schedule::call(function (): void {
    $disk = Storage::disk('local');

    foreach (['church-exports', 'church-imports'] as $directory) {
        foreach ($disk->files($directory) as $file) {
            if ($disk->lastModified($file) < now()->subHours(2)->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }
})->name('remove-old-church-transfer-files')->hourly();
