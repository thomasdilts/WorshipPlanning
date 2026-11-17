<?php

namespace App\Http\Controllers;

use App\Core\Tenancy\CurrentChurch;
use App\Enums\SpreadsheetRangeKey;
use App\Enums\SpreadsheetViewKey;
use App\Models\User;
use App\Services\StaffingSpreadsheetExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffingSpreadsheetController extends Controller
{
    public function __invoke(StaffingSpreadsheetExporter $exporter): StreamedResponse
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->church_id !== null, 403);
        abort_unless($user->can('events.view'), 403);

        $church = app(CurrentChurch::class)->get() ?? $user->church;
        abort_unless($church !== null && (int) $church->id === (int) $user->church_id, 403);

        $view = SpreadsheetViewKey::tryFrom((string) request('view', SpreadsheetViewKey::AllStaffing->value))
            ?? SpreadsheetViewKey::AllStaffing;
        $range = SpreadsheetRangeKey::tryFrom((string) request('range', SpreadsheetRangeKey::NextTwelveWeeks->value))
            ?? SpreadsheetRangeKey::NextTwelveWeeks;

        [$from, $until] = $range->bounds(
            request()->string('from')->toString() ?: null,
            request()->string('until')->toString() ?: null,
        );

        $teamIds = $user->teams()->pluck('teams.id')->all();

        return $exporter->download($church, $from, $until, $teamIds, $view);
    }
}
