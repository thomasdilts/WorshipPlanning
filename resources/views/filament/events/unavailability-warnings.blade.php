@if ($messages !== [])
    <div class="fi-section-content-ctn mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-600 dark:bg-amber-950/40 dark:text-amber-100">
        <p class="mb-1 font-semibold">{{ __('Conflicts with unavailable dates') }}</p>
        <ul class="list-disc space-y-1 ps-5 font-bold">
            @foreach ($messages as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
