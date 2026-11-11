@php
    use App\Enums\MessagePreference;

    $isEmail = $message->channel === MessagePreference::Email->value;
    $channel = MessagePreference::tryFrom((string) $message->channel)?->label() ?? $message->channel;
@endphp

<div class="space-y-4 text-sm">
    <div class="flex flex-wrap gap-x-6 gap-y-1 text-gray-500 dark:text-gray-400">
        <span>{{ __('Type') }}: <span class="font-medium text-gray-950 dark:text-white">{{ $channel }}</span></span>
        <span>{{ __('Sent') }}: <span class="font-medium text-gray-950 dark:text-white">{{ $message->created_at?->translatedFormat(\App\Support\DateTimeFormats::dateTime()) }}</span></span>
        @if ($message->event)
            <span>{{ __('Event') }}: <span class="font-medium text-gray-950 dark:text-white">{{ $message->event->name }}</span></span>
        @endif
    </div>

    @if ($message->status !== 'sent')
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-danger-700 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-300">
            <div class="font-medium">{{ __('Not delivered') }}</div>
            <div class="mt-1 break-words">{{ $message->error ?: __('Unknown error') }}</div>
        </div>
    @endif

    @if ($isEmail)
        @include('filament.messaging.html-preview', [
            'subject' => $message->subject,
            'html' => $message->body,
        ])
    @else
        <div class="whitespace-pre-wrap rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">{{ $message->body }}</div>
    @endif
</div>
