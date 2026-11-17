@php
    $printableTasks = $event->tasks->reject(function ($task) {
        $names = [mb_strtolower(trim($task->displayName())), mb_strtolower(trim($task->template?->name ?? ''))];

        return count(array_intersect($names, ['frånvaro', 'franvaro', 'absence'])) > 0
            || (in_array('extra innehåll', $names, true) && blank($task->description));
    });
    $timedTasks = $printableTasks->filter(fn ($task) => ! $task->isGlobal() && (filled($task->starts_at) || filled($task->ends_at) || $task->duration > 0))->sortBy([['before_start', 'desc'], ['sort_order', 'asc'], ['id', 'asc']])->values();
    $startTime = \App\Support\DateTimeFormats::formatTime($event->starts_at->timezone(config('app.timezone')), $event->church);
    $startMarkerAt = $timedTasks->filter(fn ($task) => $task->before_start)->count();
    $responsibilities = $printableTasks->reject(fn ($task) => $timedTasks->contains('id', $task->id));
    $bulletTasks = $printableTasks->filter(fn ($task) => ($task->template?->description_as_bullets ?? false) && ($task->template?->usage('description')->isUsed() ?? true) && filled($task->description));
    $hasSidebar = $responsibilities->isNotEmpty() || $bulletTasks->isNotEmpty();
    $dense = $timedTasks->count() > 18;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $event->name }} · {{ __('Service order') }}</title>
    <style>
        @page { size: A4 landscape; margin: 8mm 9mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: {{ $dense ? '9' : '11' }}px; line-height: 1.25; color: #172b2c; }
        .header { position: relative; min-height: 26px; margin-bottom: 8px; padding: 0 105px 5px 0; border-bottom: 1px solid #52636b; }
        .church-logo { position: absolute; top: 0; right: 0; max-width: 95px; max-height: 28px; width: auto; height: auto; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        .meta { font-size: 9px; color: #52636b; }
        .wp-description-bullets { margin: 0; padding-left: 20px; list-style-type: decimal; }
        .wp-description-bullets li { margin-bottom: 4px; white-space: pre-line; }
        [dir="rtl"] .wp-description-bullets { padding-left: 0; padding-right: 20px; }
        h2 { font-size: 11px; margin: 0 0 5px; }
        .order { float: {{ app()->getLocale() === 'ar' ? 'right' : 'left' }}; width: {{ $hasSidebar ? '70.6%' : '100%' }}; }
        .people { float: {{ app()->getLocale() === 'ar' ? 'left' : 'right' }}; width: 26.4%; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: {{ app()->getLocale() === 'ar' ? 'right' : 'left' }}; color: #52636b; font-weight: normal; padding: 3px 4px; border-bottom: 1px solid #8fa0a9; }
        td { padding: {{ $dense ? '3px 4px' : '5px 4px' }}; vertical-align: top; border-bottom: 1px solid #dce3e8; overflow-wrap: break-word; }
        tr { page-break-inside: avoid; }
        .col-time { width: 35px; white-space: nowrap; font-size: 8px; }
        .col-mins { width: 25px; }
        .col-task { width: 23%; font-weight: bold; }
        .col-status { width: 56px; font-size: 8px; }
        .detail-line { margin-bottom: 1px; }
        .problem { color: #b42318; font-weight: bold; }
        .responsibility { padding: 2px 0; border-bottom: 1px solid #dce3e8; page-break-inside: avoid; }
        .responsibility strong { display: inline; }
        .responsibility .detail-line { display: inline; margin: 0; }
        .responsibility .detail-line + .detail-line:before { content: " / "; }
        .bullet-details { margin-top: 10px; }
        .bullet-detail { margin-bottom: 8px; }
        .bullet-detail h2 { margin-bottom: 3px; }
        .empty { color: #52636b; }
        .before-start td { background: #f1f4f6; font-size: {{ $dense ? '7.5' : '9' }}px; line-height: 1.1; padding: {{ $dense ? '1.6px 4px' : '2.7px 4px' }}; }
        .before-start .detail-line { margin-bottom: 0; }
        .start-marker td { padding: 0 8px; font-size: 8.2px; line-height: 1; background: #e2e7eb; color: #172b2c; text-align: center; border-top: 1px solid #c9d2d9; border-bottom: 1px solid #c9d2d9; }
        .start-marker .start-time { font-size: 8.2px; font-weight: bold; letter-spacing: 1px; }
        .start-marker .start-side { font-size: 7px; padding: 0 14px; }
    </style>
</head>
<body>
    <div class="header">
        @if (! empty($logoPath))
            <img class="church-logo" src="{{ $logoPath }}" alt="{{ $event->church?->name }}">
        @endif
        <h1>{{ $event->name }}</h1>
        <div class="meta">
            {{ $event->starts_at->timezone(config('app.timezone'))->translatedFormat(\App\Support\DateTimeFormats::dateWithWeekday()) }}
            · {{ $startTime }}
        </div>
    </div>
    <div class="order">
        @if ($timedTasks->isEmpty())
            <p class="empty">{{ __('No tasks on this service yet.') }}</p>
        @else
            <table>
                <thead><tr>
                    <th class="col-time">{{ __('Start') }}</th>
                    <th class="col-time">{{ __('End') }}</th>
                    <th class="col-mins">{{ __('Mins') }}</th>
                    <th class="col-task">{{ __('Task') }}</th>
                    <th class="col-status">{{ __('Status') }}</th>
                    <th>{{ __('Details') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($timedTasks as $index => $task)
                        @if ($index === $startMarkerAt)
                            @include('pdf.partials.start-marker')
                        @endif
                        @php
                            $state = ($states ?? [])[$task->id] ?? null;
                            $showStatus = $state && ! in_array($state, [\App\Enums\AssignmentState::Draft, \App\Enums\AssignmentState::AssumedTeam, \App\Enums\AssignmentState::Named, \App\Enums\AssignmentState::Vacant], true);
                        @endphp
                        <tr @class(['before-start' => $task->before_start])>
                            <td class="col-time">{{ filled($task->starts_at) ? \App\Support\DateTimeFormats::formatTime($task->starts_at, $event->church) : '—' }}</td>
                            <td class="col-time">{{ filled($task->ends_at) ? \App\Support\DateTimeFormats::formatTime($task->ends_at, $event->church) : '—' }}</td>
                            <td class="col-mins">{{ $task->duration ?: '—' }}</td>
                            <td class="col-task">{{ $task->displayName() }}</td>
                            <td class="col-status">{{ $showStatus ? $state->label() : '—' }}</td>
                            <td>
                                @foreach ($summary->filledBits($task, $event, forPrint: true, withDescription: ! $bulletTasks->contains('id', $task->id)) as $bit)
                                    <div class="detail-line">{{ $bit }}</div>
                                @endforeach
                                @foreach ($summary->problems($task, $event, forPrint: true) as $problem)
                                    <div class="problem">{{ $problem }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                    @if ($startMarkerAt === $timedTasks->count())
                        @include('pdf.partials.start-marker')
                    @endif
                </tbody>
            </table>
        @endif
    </div>
    @if ($hasSidebar)
        <div class="people">
            @if ($responsibilities->isNotEmpty())
                <h2>{{ __('People & teams') }}</h2>
            @endif
            @foreach ($responsibilities as $task)
                <div class="responsibility">
                    <strong>{{ $task->displayName() }}:</strong>
                    @foreach ($summary->filledBits($task, $event, forPrint: true, withDescription: ! $bulletTasks->contains('id', $task->id)) as $bit)
                        <div class="detail-line">{{ $bit }}</div>
                    @endforeach
                    @foreach ($summary->problems($task, $event, forPrint: true) as $problem)
                        <div class="problem">{{ $problem }}</div>
                    @endforeach
                </div>
            @endforeach
            @if ($bulletTasks->isNotEmpty())
                <div class="bullet-details">
                    @foreach ($bulletTasks as $task)
                        <div class="bullet-detail">
                            <h2>{{ $task->displayName() }}</h2>
                            @include('filament.events.description-bullets', ['points' => \App\Support\DescriptionBullets::fromDescription($task->description)])
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</body>
</html>
