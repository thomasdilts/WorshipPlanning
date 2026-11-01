<?php

namespace App\Filament\Navigation;

use App\Enums\LandingDestinationKey;
use App\Filament\Pages\MyTasks;
use App\Filament\Resources\Churches\ChurchResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\TaskSpreadsheet;
use App\Models\User;

/**
 * Every signed-in user starts on their personal schedule.
 */
class LandingDestination
{
    public function __construct(private PanelAudience $audience) {}

    public function url(): ?string
    {
        return $this->defaultUrl();
    }

    /**
     * Destinations this user may choose as their start page.
     *
     * @return array<string, string>
     */
    public function optionsFor(?User $user = null): array
    {
        $user = $user ?? $this->audience->user();

        if ($user === null) {
            return [];
        }

        $options = [];

        foreach (LandingDestinationKey::cases() as $key) {
            if ($this->urlFor($key) !== null) {
                $options[$key->value] = $key->label();
            }
        }

        return $options;
    }

    public function defaultUrl(): ?string
    {
        if (MyTasks::canAccess()) {
            return MyTasks::getUrl();
        }

        if (EventResource::canViewAny()) {
            return EventResource::getUrl('index');
        }

        if (ChurchResource::canViewAny()) {
            return ChurchResource::getUrl('index');
        }

        return null;
    }

    private function urlFor(LandingDestinationKey $key): ?string
    {
        return match ($key) {
            LandingDestinationKey::TasksByEvent => TaskSpreadsheet::canAccess()
                ? EventResource::getUrl('task-spreadsheet')
                : null,
            LandingDestinationKey::MySchedule => MyTasks::canAccess()
                ? MyTasks::getUrl()
                : null,
            LandingDestinationKey::Services => EventResource::canViewAny()
                ? EventResource::getUrl('index')
                : null,
        };
    }
}
