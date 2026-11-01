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
    }
}
