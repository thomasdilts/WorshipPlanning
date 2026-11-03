<div class="mb-6">
    <h1 class="text-xl font-semibold tracking-tight text-gray-950 dark:text-white">
        {{ __('Sign in') }}
    </h1>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        {{ __('Use your church account to open today’s service order and tasks.') }}
    </p>

    @if (config('demo.enabled'))
        <div class="mt-4 rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm text-primary-900 dark:border-primary-800 dark:bg-primary-950 dark:text-primary-100">
            <p class="font-semibold">{{ __('This is a demo. Everything is reset every night.') }}</p>
            <p class="mt-1">{{ __('Sign in as admin@example.com, planner@example.com, leader@example.com or member@example.com with the password :password.', ['password' => config('demo.password')]) }}</p>
        </div>
    @endif
</div>
