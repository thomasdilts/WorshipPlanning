<?php

namespace App\Support;

use App\Core\Tenancy\CurrentChurch;
use App\Models\Church;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Date and time formats used across the app.
 *
 * Written dates follow the viewer's language through translatedFormat(), so "11 Nov 2026"
 * becomes "11 nov 2026" in Swedish. Edit fields use the unambiguous Y-m-d. Whether times
 * show as 14:30 or 2:30 PM is the church's choice, not the language's.
 */
class DateTimeFormats
{
    public const INPUT_DATE = 'Y-m-d';

    public static function date(): string
    {
        return 'j M Y';
    }

    public static function dateWithWeekday(): string
    {
        return 'D j M Y';
    }

    public static function longDate(): string
    {
        return 'l j F Y';
    }

    public static function time(?Church $church = null): string
    {
        return static::uses24HourTime($church) ? 'H:i' : 'g:i A';
    }

    public static function dateTime(?Church $church = null): string
    {
        return static::date().' '.static::time($church);
    }

    public static function dateWithWeekdayTime(?Church $church = null): string
    {
        return static::dateWithWeekday().' '.static::time($church);
    }

    public static function inputDateTime(?Church $church = null): string
    {
        return self::INPUT_DATE.' '.static::time($church);
    }

    /**
     * Format a time of day: a Carbon instance or a stored "HH:MM[:SS]" string.
     */
    public static function formatTime(CarbonInterface|string|null $time, ?Church $church = null): ?string
    {
        if (blank($time)) {
            return null;
        }

        $time = $time instanceof CarbonInterface ? $time : Carbon::parse((string) $time);

        return $time->translatedFormat(static::time($church));
    }

    public static function uses24HourTime(?Church $church = null): bool
    {
        $church ??= app(CurrentChurch::class)->get() ?? auth()->user()?->church;

        return (bool) ($church->uses_24_hour_time ?? true);
    }
}
