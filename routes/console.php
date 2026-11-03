<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (config('demo.enabled')) {
    Schedule::command('demo:reset')->dailyAt(config('demo.reset_at'))->withoutOverlapping();
}
