<?php

namespace App\Providers;

use App\Http\Middleware\SetCurrentChurch;
use App\Notifications\ChurchResetPasswordNotification;
use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResetPassword::class, ChurchResetPasswordNotification::class);
    }

    public function boot(): void
    {
        // Filament create/edit submits via Livewire update routes, which skip
        // panel middleware — keep church (Spatie team) context on those requests.
        Livewire::addPersistentMiddleware([
            SetCurrentChurch::class,
        ]);

        // The public demo must never send real email to testers, whatever MAIL_* says. The real
        // mailer is kept for mail that only goes to the operators, such as church access requests.
        if (config('demo.enabled')) {
            config(['demo.operator_mailer' => config('mail.default'), 'mail.default' => 'log']);
        }
    }
}
