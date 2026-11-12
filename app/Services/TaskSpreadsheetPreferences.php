<?php

namespace App\Services;

use App\Enums\SpreadsheetDensity;
use App\Enums\SpreadsheetLayout;
use App\Enums\SpreadsheetRangeKey;
use App\Enums\SpreadsheetViewKey;
use App\Models\Church;
use App\Models\User;

class TaskSpreadsheetPreferences
{
    /**
     * @return array{
     *     view: string,
     *     density: string,
     *     layout: string,
     *     range: string,
     *     from: ?string,
     *     until: ?string
     * }
     */
    public function defaults(): array
    {
        return [
            'view' => SpreadsheetViewKey::AllStaffing->value,
            'density' => SpreadsheetDensity::Comfortable->value,
            'layout' => SpreadsheetLayout::Auto->value,
            'range' => SpreadsheetRangeKey::NextSixWeeks->value,
            'from' => null,
            'until' => null,
        ];
    }

    /**
     * @return array{
     *     view: string,
     *     density: string,
     *     layout: string,
     *     range: string,
     *     from: ?string,
     *     until: ?string
     * }
     */
    public function for(User $user, Church $church): array
    {
        $stored = $user->spreadsheet_preferences ?? [];
        $churchPrefs = is_array($stored[(string) $church->id] ?? null)
            ? $stored[(string) $church->id]
            : [];

        $merged = array_merge($this->defaults(), $churchPrefs);

        return [
            'view' => SpreadsheetViewKey::tryFrom((string) $merged['view'])?->value
                ?? SpreadsheetViewKey::AllStaffing->value,
            'density' => SpreadsheetDensity::tryFrom((string) $merged['density'])?->value
                ?? SpreadsheetDensity::Comfortable->value,
            'layout' => SpreadsheetLayout::tryFrom((string) $merged['layout'])?->value
                ?? SpreadsheetLayout::Auto->value,
            'range' => SpreadsheetRangeKey::tryFrom((string) $merged['range'])?->value
                ?? SpreadsheetRangeKey::NextSixWeeks->value,
            'from' => filled($merged['from'] ?? null) ? (string) $merged['from'] : null,
            'until' => filled($merged['until'] ?? null) ? (string) $merged['until'] : null,
        ];
    }

    /**
     * @param  array{
     *     view?: string,
     *     density?: string,
     *     layout?: string,
     *     range?: string,
     *     from?: ?string,
     *     until?: ?string
     * }  $prefs
     */
    public function remember(User $user, Church $church, array $prefs): void
    {
        $current = $this->for($user, $church);
        $next = array_merge($current, $prefs);
        $all = $user->spreadsheet_preferences ?? [];
        $all[(string) $church->id] = [
            ...$this->storedFor($user, $church),
            'view' => SpreadsheetViewKey::tryFrom((string) $next['view'])?->value
                ?? SpreadsheetViewKey::AllStaffing->value,
            'density' => SpreadsheetDensity::tryFrom((string) $next['density'])?->value
                ?? SpreadsheetDensity::Comfortable->value,
            'layout' => SpreadsheetLayout::tryFrom((string) $next['layout'])?->value
                ?? SpreadsheetLayout::Auto->value,
            'range' => SpreadsheetRangeKey::tryFrom((string) $next['range'])?->value
                ?? SpreadsheetRangeKey::NextSixWeeks->value,
            'from' => filled($next['from'] ?? null) ? (string) $next['from'] : null,
            'until' => filled($next['until'] ?? null) ? (string) $next['until'] : null,
        ];

        $user->forceFill(['spreadsheet_preferences' => $all])->save();
    }

    /**
     * The person's chosen spreadsheet columns (which are shown and in what order), as Filament's column
     * manager state, or null when they have not changed the columns.
     *
     * @return list<array<string, mixed>>|null
     */
    public function columnsFor(User $user, Church $church): ?array
    {
        $columns = $this->storedFor($user, $church)['columns'] ?? null;

        return is_array($columns) && $columns !== [] ? array_values($columns) : null;
    }

    /**
     * Saves the person's column choices and order; null (or no columns) goes back to the default columns.
     *
     * @param  list<array<string, mixed>>|null  $columns
     */
    public function rememberColumns(User $user, Church $church, ?array $columns): void
    {
        $columns = filled($columns) ? array_values($columns) : null;

        if ($this->columnsFor($user, $church) === $columns) {
            return;
        }

        $all = $user->spreadsheet_preferences ?? [];
        $all[(string) $church->id] = [...$this->storedFor($user, $church), 'columns' => $columns];

        $user->forceFill(['spreadsheet_preferences' => $all])->save();
    }

    /**
     * @return array<string, mixed>
     */
    protected function storedFor(User $user, Church $church): array
    {
        $stored = $user->spreadsheet_preferences ?? [];

        return is_array($stored[(string) $church->id] ?? null) ? $stored[(string) $church->id] : [];
    }
}
