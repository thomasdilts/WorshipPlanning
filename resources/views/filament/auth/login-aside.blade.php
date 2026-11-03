<section class="wp-login-aside" aria-labelledby="wp-login-aside-heading">
    <p class="wp-login-aside-eyebrow">{{ now()->timezone(config('app.timezone'))->translatedFormat('l, j F') }}</p>
    <h2 id="wp-login-aside-heading" class="wp-login-aside-title">
        {{ __('Plan the service. Staff the order.') }}
    </h2>
    <p class="wp-login-aside-body">
        {{ __('WorshipPlanning keeps the run-of-show clear: assign people and teams, then let volunteers see what is theirs.') }}
    </p>
    <ul class="wp-login-aside-list">
        <li>{{ __('Service orders that match how your church actually runs') }}</li>
        <li>{{ __('My tasks for every volunteer assignment') }}</li>
        <li>{{ __('Modules you turn on only when you need them') }}</li>
    </ul>
    <a href="{{ route('partner.create') }}" class="wp-login-aside-cta">
        {{ __('Request access for your church') }}
    </a>
</section>
