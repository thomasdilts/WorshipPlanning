@php
    $locale = str_replace('_', '-', app()->getLocale());
    $dir = in_array(app()->getLocale(), ['ar'], true) ? 'rtl' : 'ltr';
    $tz = $task->event?->church?->timezone ?: config('app.timezone');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Respond to invitation') }} — {{ config('app.name') }}</title>
    <style>
        :root {
            color-scheme: light dark;
            --wp-bg: #f6f8fa;
            --wp-surface: #fff;
            --wp-text: #172b2c;
            --wp-muted: #52636b;
            --wp-border: #dce3e8;
            --wp-primary: #0f766e;
            --wp-decline-bg: #fee4e2;
            --wp-decline-text: #b42318;
            --wp-ok-bg: #dcfce7;
            --wp-ok-text: #166534;
            --wp-focus: #0f766e;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --wp-bg: #0e1415;
                --wp-surface: #151d1e;
                --wp-text: #e6eeee;
                --wp-muted: #9fb0b3;
                --wp-border: #263234;
                --wp-decline-bg: #5a1410;
                --wp-decline-text: #fecaca;
                --wp-ok-bg: #14532d;
                --wp-ok-text: #bbf7d0;
                --wp-focus: #5eead4;
            }
        }
        body { font-family: system-ui, sans-serif; margin: 0; background: var(--wp-bg); color: var(--wp-text); }
        .wrap { box-sizing: border-box; width: calc(100% - 2rem); max-width: 28rem; margin: 2rem auto; padding: 1.25rem; overflow-wrap: anywhere; background: var(--wp-surface); border: 1px solid var(--wp-border); border-radius: 12px; }
        h1 { font-size: 1.25rem; margin: 0 0 0.5rem; }
        .meta { color: var(--wp-muted); font-size: 0.9rem; line-height: 1.45; margin-bottom: 1rem; }
        .status { display: inline-block; margin-bottom: 1rem; padding: 0.25rem 0.65rem; border-radius: 999px; background: color-mix(in srgb, var(--wp-muted) 18%, transparent); font-size: 0.8125rem; font-weight: 600; }
        .flash { margin-bottom: 1rem; padding: 0.75rem; border-radius: 8px; background: var(--wp-ok-bg); color: var(--wp-ok-text); }
        .actions { display: flex; flex-direction: column; gap: 0.75rem; }
        button, .btn {
            min-height: 48px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
        }
        button:focus-visible, textarea:focus-visible {
            outline: 2px solid var(--wp-focus);
            outline-offset: 2px;
        }
        .confirm { background: var(--wp-primary); color: #fff; }
        .decline { background: var(--wp-decline-bg); color: var(--wp-decline-text); }
        textarea {
            width: 100%;
            min-height: 4.5rem;
            border: 1px solid var(--wp-border);
            border-radius: 8px;
            padding: 0.65rem;
            font: inherit;
            box-sizing: border-box;
            background: var(--wp-surface);
            color: var(--wp-text);
        }
        label { display: block; font-size: 0.875rem; margin-bottom: 0.35rem; color: var(--wp-muted); }
        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; }
        }
    </style>
</head>
<body>
    <main class="wrap">
        <h1>{{ $task->displayName() }}</h1>
        <div class="meta">
            <div>{{ $task->event?->name }}</div>
            <div>
                {{ $task->event?->starts_at?->timezone($tz)->translatedFormat('l j M Y '.\App\Support\DateTimeFormats::time($task->event?->church)) }}
            </div>
            @if ($task->assignedTeam)
                <div>{{ __('Team: :name', ['name' => $task->assignedTeam->name]) }}</div>
            @endif
        </div>

        <div class="status" role="status">{{ $state->label() }}</div>

        @if (session('status'))
            <div class="flash" role="status">{{ session('status') }}</div>
        @endif

        <form method="post" action="{{ $storeUrl }}" class="actions">
            @csrf
            <button type="submit" name="decision" value="confirm" class="confirm">
                {{ __('I can help') }}
            </button>

            <div>
                <label for="note">{{ __('Optional reason') }}</label>
                <textarea id="note" name="note" maxlength="2000">{{ old('note') }}</textarea>
            </div>

            <button type="submit" name="decision" value="decline" class="decline">
                {{ __("I can't make it") }}
            </button>
        </form>
    </main>
</body>
</html>
