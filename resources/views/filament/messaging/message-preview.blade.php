@php
    use App\Support\HtmlMessage;

    $hasHtml = filled(HtmlMessage::toPlainText($html ?? ''));
    $hasText = filled($text ?? null);
    $emailHtml = $hasHtml ? $html : nl2br(e($text ?? ''));
@endphp

@if ($singlePreview ?? false)
    @include('filament.messaging.html-preview', [
        'subject' => $subject ?? null,
        'html' => $emailHtml,
    ])
@else
    <div class="space-y-4">
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Email') }}</div>
            @include('filament.messaging.html-preview', [
                'subject' => $subject ?? null,
                'html' => $emailHtml,
            ])
        </div>

        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Text message (SMS / WhatsApp)') }}</div>
            <div class="mt-2 whitespace-pre-wrap rounded-lg border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900">{{ $hasText ? $text : HtmlMessage::toPlainText($html ?? '') }}</div>
            @if (! $hasText && $hasHtml)
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('No text version written; this plain-text version of the HTML is sent instead.') }}</p>
            @endif
        </div>
    </div>
@endif
