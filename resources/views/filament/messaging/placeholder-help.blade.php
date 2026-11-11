@php
    $open = str_repeat('{', 2);
    $close = str_repeat('}', 2);
    $token = fn (string $key): string => $open.$key.$close;
    $exampleText = __('Hi :first_name, you are scheduled for :task at :event on :date at :time.', [
        'first_name' => $token('first_name'),
        'task' => $token('task'),
        'event' => $token('event'),
        'date' => $token('date'),
        'time' => $token('time'),
    ]);
    $spacesNote = __('Spaces inside the braces are fine: :example works too.', ['example' => $open.' name '.$close]);

    $customButton = '<a href="'.$token('accept_url').'">'.__('Yes, count me in').'</a>';
    $linkDescriptions = [
        'accept_link' => __('A “Yes, I can help” button in emails; a short link in SMS / WhatsApp. One tap accepts the task.'),
        'decline_link' => __('A “No, I can’t make it” button in emails; a short link in SMS / WhatsApp. One tap declines the task.'),
        'accept_url' => __('Only the web address for accepting — for your own link or button in the HTML.'),
        'decline_url' => __('Only the web address for declining — for your own link or button in the HTML.'),
    ];

    $descriptions = [
        'name' => [__('Recipient’s name'), 'Anna Svensson'],
        'first_name' => [__('Recipient’s first name'), 'Anna'],
        'task' => [__('Their task(s) on the service'), __('Sound, Projection')],
        'event' => [__('Service name'), __('Sunday Service')],
        'date' => [__('Service date'), __('Sunday 7 September 2026')],
        'time' => [__('Service start time'), '10:00'],
        'church' => [__('Church name'), __('Your church')],
        'message_sender' => [__('Name of the person sending the message, or who changed the preferred messaging'), 'Erik Lund'],
        'team' => [__('Team name — only in messages sent to a team; elsewhere it is left as written'), __('Worship team')],
        'custom_mess' => [__('The custom message typed when sending'), __('Bring your own music stand.')],
    ];
@endphp

<div class="space-y-4 text-sm text-gray-700 dark:text-gray-300">
    <p>
        {{ __('Write a placeholder in double curly braces, for example') }}
        <code class="rounded bg-gray-100 px-1 py-0.5 font-mono text-xs dark:bg-white/10">{{ $token('first_name') }}</code>.
        {{ __('When the message is sent, each recipient gets their own value in its place. Placeholders work in the subject, the HTML and the text message.') }}
    </p>

    <div class="overflow-x-auto">
        <table class="w-full text-start">
            <thead>
                <tr class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <th class="py-1 pe-4 text-start font-medium">{{ __('Placeholder') }}</th>
                    <th class="py-1 pe-4 text-start font-medium">{{ __('Replaced with') }}</th>
                    <th class="py-1 text-start font-medium">{{ __('Example') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($placeholders as $key)
                    <tr>
                        <td class="py-1.5 pe-4"><code class="font-mono text-xs">{{ $token($key) }}</code></td>
                        <td class="py-1.5 pe-4">{{ $descriptions[$key][0] ?? '' }}</td>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400">{{ $descriptions[$key][1] ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div>
        <div class="font-medium text-gray-950 dark:text-white">{{ __('Accept and decline links') }}</div>
        <p class="mt-1">{{ __('Recipients answer straight from the message — no login needed. Someone with several tasks on the service gets one message per task, each with links for that task.') }}</p>
        <div class="mt-2 overflow-x-auto">
            <table class="w-full text-start">
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($linkPlaceholders as $key)
                        <tr>
                            <td class="py-1.5 pe-4 align-top"><code class="font-mono text-xs">{{ $token($key) }}</code></td>
                            <td class="py-1.5">{{ $linkDescriptions[$key] ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-gray-500 dark:text-gray-400">{{ __('Your own button in the HTML:') }}</p>
        <pre class="mt-1 whitespace-pre-wrap rounded-lg bg-gray-50 p-3 font-mono text-xs dark:bg-white/5">{{ $customButton }}</pre>
    </div>

    <div>
        <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Example') }}</div>
        <pre class="mt-1 whitespace-pre-wrap rounded-lg bg-gray-50 p-3 font-mono text-xs dark:bg-white/5">{{ $exampleText }}</pre>
    </div>

    <ul class="list-disc space-y-1 ps-5 text-gray-500 dark:text-gray-400">
        <li>{{ $spacesNote }}</li>
        <li>{{ __('A misspelt placeholder is sent unchanged, so check the spelling.') }}</li>
        <li>{{ __('Previews show the placeholders as written; the real values are filled in when sending.') }}</li>
        <li>{{ __('If the message has no accept / decline links of its own, a link to a response page is added at the end automatically.') }}</li>
    </ul>
</div>
