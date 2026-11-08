@php
    use App\Enums\EventPlanState;
    use App\Services\EventPlanStateResolver;
    use App\Services\EventStaffingSummary;

    $event = $this->getRecord();
    $summary = app(EventStaffingSummary::class)->for($event);
    $planState = app(EventPlanStateResolver::class)->for($event);
@endphp

<div class="wp-staffing-summary" role="status" aria-label="{{ __('Staffing summary') }}">
    <div class="wp-staffing-summary-stats">
        <span @class([
            'wp-staffing-pill',
            'wp-staffing-pill-await' => $planState === EventPlanState::Draft,
            'wp-staffing-pill-ok' => $planState === EventPlanState::Shared,
            'wp-staffing-pill-warn' => $planState === EventPlanState::ChangedSinceShared,
        ])>
            {{ $planState->label() }}
        </span>
        <span class="wp-staffing-pill {{ $summary['vacant'] > 0 ? 'wp-staffing-pill-warn' : '' }}">
            {{ trans_choice(':count not assigned|:count not assigned', $summary['vacant'], ['count' => $summary['vacant']]) }}
        </span>
        <span class="wp-staffing-pill {{ $summary['awaiting'] > 0 ? 'wp-staffing-pill-await' : '' }}">
            {{ trans_choice(':count waiting|:count waiting', $summary['awaiting'], ['count' => $summary['awaiting']]) }}
        </span>
        <span class="wp-staffing-pill {{ $summary['declined'] > 0 ? 'wp-staffing-pill-bad' : '' }}">
            {{ trans_choice(':count declined|:count declined', $summary['declined'], ['count' => $summary['declined']]) }}
        </span>
        <span class="wp-staffing-pill wp-staffing-pill-ok">
            {{ trans_choice(':count confirmed|:count confirmed', $summary['confirmed'], ['count' => $summary['confirmed']]) }}
        </span>
    </div>

    <div class="wp-staffing-summary-meta">
        @if ($summary['calculated_ends_at'])
            <span>
                {{ __('Program ends :time', ['time' => $summary['calculated_ends_at']->format('H:i')]) }}
            </span>
        @endif
        @if ($summary['ready'])
            <span class="wp-staffing-ready">{{ __('Staffing looks complete') }}</span>
        @endif
    </div>
</div>
