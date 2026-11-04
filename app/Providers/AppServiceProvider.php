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

        // The public demo must never send real email to testers, whatever MAIL_* says.
        // Mail only for the operators uses demo.operator_mailer instead.
        if (config('demo.enabled')) {
            config(['mail.default' => 'log']);
        }
    }
}
