<?php

namespace App\Enums;

/**
 * Stable keys for the panel landing preference. Never store a raw URL.
 */
enum LandingDestinationKey: string
{
    case TasksByEvent = 'tasks_by_event';
    case MySchedule = 'my_schedule';
    case Services = 'services';

    public function label(): string
    {
        return match ($this) {
            self::TasksByEvent => __('Tasks by service'),
            self::MySchedule => __('My schedule'),
            self::Services => __('Services'),
        };
    }
}
