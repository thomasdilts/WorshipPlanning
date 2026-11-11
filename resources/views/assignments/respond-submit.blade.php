@php
    $locale = str_replace('_', '-', app()->getLocale());
    $dir = in_array(app()->getLocale(), ['ar'], true) ? 'rtl' : 'ltr';
    $tz = $task->event?->church?->timezone ?: config('app.timezone');
    $isConfirm = $decision === 'confirm';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $isConfirm ? __('Confirm that you are coming') : __('Let us know you are not coming') }} — {{ config('app.name') }}</title>
    <style>
        :root {
            color-scheme: light dark;
            --wp-bg: #f6f8fa;
            --wp-surface: #fff;
            --wp-text: #172b2c;
            --wp-muted: #52636b;
            --wp-border: #dce3e8;
            --wp-primary: #0f766e;
            --wp-decline-bg: #b42318;
            --wp-focus: #0f766e;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --wp-bg: #0e1415;
                --wp-surface: #151d1e;
                --wp-text: #e6eeee;
                --wp-muted: #9fb0b3;
                --wp-border: #263234;
                --wp-focus: #5eead4;
            }
        }
        body { font-family: system-ui, sans-serif; margin: 0; background: var(--wp-bg); color: var(--wp-text); }
        .wrap { box-sizing: border-box; width: calc(100% - 2rem); max-width: 28rem; margin: 2rem auto; padding: 1.25rem; overflow-wrap: anywhere; background: var(--wp-surface); border: 1px solid var(--wp-border); border-radius: 12px; }
        h1 { font-size: 1.25rem; margin: 0 0 0.5rem; }
        .meta { color: var(--wp-muted); font-size: 0.9rem; line-height: 1.45; margin-bottom: 1rem; }
        .thanks { margin: 0; padding: 0.9rem; border-radius: 8px; background: color-mix(in srgb, var(--wp-primary) 15%, transparent); font-weight: 600; text-align: center; }
        .instruction { margin: 0; padding: 0.9rem; border: 2px dashed var(--wp-border); border-radius: 8px; text-align: center; line-height: 1.5; }
        .arrow { display: block; font-size: 1.5rem; line-height: 1; margin-top: 0.35rem; }
        form { display: flex; flex-direction: column; gap: 0.75rem; }
        button { min-height: 52px; border-radius: 8px; border: none; font-weight: 700; font-size: 1.05rem; cursor: pointer; color: #fff; }
        button:focus-visible { outline: 2px solid var(--wp-focus); outline-offset: 2px; }
        .confirm { background: var(--wp-primary); }
        .decline { background: var(--wp-decline-bg); }
    </style>
</head>
<body>
    <main class="wrap">
        <h1>{{ $task->displayName() }}</h1>
        <div class="meta">
            <div>{{ $task->event?->name }}</div>
            <div>{{ $task->event?->starts_at?->timezone($tz)->translatedFormat('l j M Y '.\App\Support\DateTimeFormats::time($task->event?->church)) }}</div>
            @if ($task->assignedTeam)
                <div>{{ __('Team: :name', ['name' => $task->assignedTeam->name]) }}</div>
            @endif
        </div>

        @if (session('status'))
            <p class="thanks" role="status">{{ session('status') }}</p>
        @else
            <form method="post" action="{{ $storeUrl }}">
                @csrf
                <input type="hidden" name="decision" value="{{ $decision }}">

                <p class="instruction">
                    <strong>{{ __('Your answer has not been saved yet.') }}</strong><br>
                    {{ __('Press the button below to send it.') }}
                    <span class="arrow" aria-hidden="true">↓</span>
                </p>

                <button type="submit" class="{{ $isConfirm ? 'confirm' : 'decline' }}">
                    {{ $isConfirm ? __('Yes, I am coming') : __('No, I am not coming') }}
                </button>
            </form>
        @endif
    </main>
</body>
</html>
