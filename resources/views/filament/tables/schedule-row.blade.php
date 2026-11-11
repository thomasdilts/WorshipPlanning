@php
    use App\Enums\AssignmentState;
    use App\Filament\Resources\Events\EventResource;
    use App\Services\AssignmentStateResolver;

    $task = $getRecord();
    $event = $task->event;
    $state = app(AssignmentStateResolver::class)->for($task);
    $showState = ! in_array($state, [AssignmentState::Draft, AssignmentState::AssumedTeam, AssignmentState::Named, AssignmentState::Vacant], true);
    $taskTime = filled($task->starts_at) && ! $task->isGlobal() ? substr((string) $task->starts_at, 0, 5) : null;
    $canOpen = $event !== null && (auth()->user()?->can('view', $event) ?? false);
    $eventUrl = $canOpen
        ? EventResource::getUrl(auth()->user()?->can('update', $event) ? 'edit' : 'view', ['record' => $event])
        : null;
@endphp

<div class="wp-schedule-row" @if ($event?->starts_at) aria-label="{{ $event->starts_at->translatedFormat(\App\Support\DateTimeFormats::longDate().' '.\App\Support\DateTimeFormats::time()).' — '.$task->displayName() }}" @endif>
    <div class="wp-schedule-when">
        <span class="wp-schedule-weekday">{{ $event?->starts_at?->translatedFormat('l') }}</span>
        <span class="wp-schedule-date">{{ $event?->starts_at?->translatedFormat('j M') }}</span>
        <span class="wp-schedule-time">{{ $event?->starts_at?->translatedFormat(\App\Support\DateTimeFormats::time()) }}</span>
    </div>

    <div class="wp-schedule-what">
        <span class="wp-schedule-role">{{ $task->displayName() }}</span>
        @if ($eventUrl)
            <a href="{{ $eventUrl }}" class="wp-schedule-event">
                {{ $event?->name }}
                @if ($task->assignedTeam)
                    · {{ $task->assignedTeam->name }}
                @endif
                @if ($task->isGlobal())
                    · {{ __('Whole service') }}
                @elseif ($taskTime)
                    · {{ __('Starts :time', ['time' => $taskTime]) }}
                @endif
            </a>
        @else
            <span class="wp-schedule-event">
                {{ $event?->name }}
            </span>
        @endif
    </div>

    @if ($showState)
        <x-assignment-status :state="$state" class="wp-schedule-status" />
    @endif
</div>
