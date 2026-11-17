<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum SpreadsheetRangeKey: string
{
    case NextTwelveWeeks = 'next_twelve_weeks';
    case ThisMonth = 'this_month';
    case NextMonth = 'next_month';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::NextTwelveWeeks => __('Next 12 weeks'),
            self::ThisMonth => __('This month'),
            self::NextMonth => __('Next month'),
            self::Custom => __('Custom range'),
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon|null}
     */
    public function bounds(?string $from = null, ?string $until = null): array
    {
        $today = now()->startOfDay();

        return match ($this) {
            self::NextTwelveWeeks => [$today, $today->copy()->addWeeks(12)->endOfDay()],
            self::ThisMonth => [$today, $today->copy()->endOfMonth()->endOfDay()],
            self::NextMonth => [
                $today->copy()->addMonthNoOverflow()->startOfMonth(),
                $today->copy()->addMonthNoOverflow()->endOfMonth()->endOfDay(),
            ],
            self::Custom => [
                Carbon::parse($from ?? $today)->startOfDay(),
                filled($until) ? Carbon::parse($until)->endOfDay() : null,
            ],
        };
    }
}
