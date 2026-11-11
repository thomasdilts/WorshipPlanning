<?php

namespace App\Services;

use App\Enums\SpreadsheetViewKey;
use App\Models\Church;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV staffing matrix for Tasks by event (opens in Excel/Sheets).
 * Not a song catalog export.
 */
class StaffingSpreadsheetExporter
{
    public function __construct(
        protected TaskSpreadsheetBuilder $builder,
    ) {}

    /**
     * @param  list<int>|null  $myTeamIds
     */
    public function download(
        Church $church,
        Carbon|string|null $from = null,
        Carbon|string|null $until = null,
        ?array $myTeamIds = null,
        SpreadsheetViewKey $view = SpreadsheetViewKey::AllStaffing,
        ?string $filename = null,
    ): StreamedResponse {
        $filename ??= 'staffing-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($church, $from, $until, $myTeamIds, $view): void {
            $this->write($church, $from, $until, $myTeamIds, $view);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  list<int>|null  $myTeamIds
     * @return list<list<string>>
     */
    public function rows(
        Church $church,
        Carbon|string|null $from = null,
        Carbon|string|null $until = null,
        ?array $myTeamIds = null,
        SpreadsheetViewKey $view = SpreadsheetViewKey::AllStaffing,
    ): array {
        $matrix = $this->builder->build($church, $from, $until, $myTeamIds);
        $filtered = $this->filterRows($matrix['rows'], $view);

        $headers = [
            __('Date'),
            __('Time'),
            __('Service'),
            ...collect($matrix['staffing_columns'])->pluck('name')->all(),
            ...collect($matrix['extra_columns'])->pluck('name')->all(),
            __('Unavailable'),
        ];

        $out = [array_map(fn (mixed $h): string => $this->safe((string) $h), $headers)];

        foreach ($filtered as $row) {
            $cells = [
                (string) ($row['event_date'] ?? ''),
                (string) ($row['event_time_label'] ?? ''),
                (string) ($row['event_name'] ?? ''),
            ];

            foreach ($matrix['staffing_columns'] as $column) {
                $cells[] = $this->plainLabel((string) ($row[$column['key']] ?? ''));
            }

            foreach ($matrix['extra_columns'] as $column) {
                $cells[] = $this->plainLabel((string) ($row[$column['key']] ?? ''));
            }

            $cells[] = (string) ($row['unavailable'] ?? '');

            $out[] = array_map(fn (string $cell): string => $this->safe($cell), $cells);
        }

        return $out;
    }

    /**
     * @param  list<int>|null  $myTeamIds
     */
    protected function write(
        Church $church,
        Carbon|string|null $from,
        Carbon|string|null $until,
        ?array $myTeamIds,
        SpreadsheetViewKey $view,
    ): void {
        $out = fopen('php://output', 'w');

        // UTF-8 BOM so Excel recognizes encoding.
        fwrite($out, "\xEF\xBB\xBF");

        foreach ($this->rows($church, $from, $until, $myTeamIds, $view) as $row) {
            fputcsv($out, $row, escape: '\\');
        }

        fclose($out);
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $rows
     * @return Collection<string, array<string, mixed>>
     */
    protected function filterRows(Collection $rows, SpreadsheetViewKey $view): Collection
    {
        return match ($view) {
            SpreadsheetViewKey::NeedsAttention => $rows->filter(
                fn (array $row): bool => (bool) ($row['needs_attention'] ?? false)
            )->values(),
            SpreadsheetViewKey::MyTeamNeeds => $rows->filter(
                fn (array $row): bool => (bool) ($row['my_team_needs'] ?? false)
            )->values(),
            SpreadsheetViewKey::AllStaffing => $rows->values(),
        };
    }

    /**
     * Strip HTML from builder cell labels for spreadsheet cells.
     */
    protected function plainLabel(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Neutralize formula-leading content (OUT-03).
     */
    protected function safe(string $value): string
    {
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
