@php
    $printableTasks = $event->tasks->reject(function ($task) {
        $names = [mb_strtolower(trim($task->displayName())), mb_strtolower(trim($task->template?->name ?? ''))];

        return count(array_intersect($names, ['frånvaro', 'franvaro', 'absence'])) > 0
            || (in_array('extra innehåll', $names, true) && blank($task->description));
    });
    $timedTasks = $printableTasks->filter(fn ($task) => ! $task->isGlobal() && (filled($task->starts_at) || filled($task->ends_at) || $task->duration > 0))->sortBy('sort_order');
    $responsibilities = $printableTasks->reject(fn ($task) => $timedTasks->contains('id', $task->id));
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
        .wp-announcement-points { margin: 0; padding-left: 20px; list-style-type: decimal; }
        .wp-announcement-points li { margin-bottom: 4px; white-space: pre-line; }
        [dir="rtl"] .wp-announcement-points { padding-left: 0; padding-right: 20px; }
        h2 { font-size: 11px; margin: 0 0 5px; }
        .order { float: {{ app()->getLocale() === 'ar' ? 'right' : 'left' }}; width: {{ $responsibilities->isEmpty() ? '100%' : '75%' }}; }
        .people { float: {{ app()->getLocale() === 'ar' ? 'left' : 'right' }}; width: 22%; }
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
        .responsibility { padding: 5px 0; border-bottom: 1px solid #dce3e8; page-break-inside: avoid; }
        .responsibility strong { display: block; margin-bottom: 2px; }
        .empty { color: #52636b; }
    </style>
</head>
<body>
    <div class="header">
        @if (! empty($logoPath))
            <img class="church-logo" src="{{ $logoPath }}" alt="{{ $event->church?->name }}">
        @endif
        <h1>{{ $event->name }}</h1>
        <div class="meta">
            {{ $event->starts_at->timezone(config('app.timezone'))->translatedFormat('D j M Y') }}
            · {{ $event->starts_at->timezone(config('app.timezone'))->format('H:i') }}
        </div>
    </div>
    <div class="order">
        <h2>{{ __('Service order') }}</h2>
        @if ($timedTasks->isEmpty())
            <p class="empty">{{ __('No tasks on this event yet.') }}</p>
        @else
            <table>
                <thead><tr>
                    <th class="col-time">{{ __('Start') }}</th>
                    <th class="col-time">{{ __('End') }}</th>
                    <th class="col-mins">{{ __('Mins') }}</th>
                    <th class="col-task">{{ __('Activity') }}</th>
                    <th class="col-status">{{ __('Status') }}</th>
                    <th>{{ __('Details') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($timedTasks as $task)
                        @php
                            $state = ($states ?? [])[$task->id] ?? null;
                            $showStatus = $state && ! in_array($state, [\App\Enums\AssignmentState::Draft, \App\Enums\AssignmentState::AssumedTeam, \App\Enums\AssignmentState::Vacant], true);
                        @endphp
                        <tr>
                            <td class="col-time">{{ filled($task->starts_at) ? substr($task->starts_at, 0, 5) : '—' }}</td>
                            <td class="col-time">{{ filled($task->ends_at) ? substr($task->ends_at, 0, 5) : '—' }}</td>
                            <td class="col-mins">{{ $task->duration ?: '—' }}</td>
                            <td class="col-task">{{ $task->displayName() }}</td>
                            <td class="col-status">{{ $showStatus ? $state->label() : '—' }}</td>
                            <td>
                                @foreach ($summary->filledBits($task, $event, forPrint: true) as $bit)
                                    <div class="detail-line">{{ $bit }}</div>
                                @endforeach
                                @foreach ($summary->problems($task, $event, forPrint: true) as $problem)
                                    <div class="problem">{{ $problem }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
    @if ($responsibilities->isNotEmpty())
        <div class="people">
            <h2>{{ __('People & teams') }}</h2>
            @foreach ($responsibilities as $task)
                <div class="responsibility">
                    <strong>{{ $task->displayName() }}</strong>
                    @foreach ($summary->filledBits($task, $event, forPrint: true) as $bit)
                        <div class="detail-line">{{ $bit }}</div>
                    @endforeach
                    @foreach ($summary->problems($task, $event, forPrint: true) as $problem)
                        <div class="problem">{{ $problem }}</div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
</body>
</html>
