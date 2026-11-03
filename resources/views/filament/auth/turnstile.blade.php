{{-- Cloudflare Turnstile (non-interactive). The token goes to the Login page's $turnstileToken. --}}
<div
    wire:ignore
    x-data="{
        widgetId: null,
        render() {
            this.widgetId = window.turnstile.render(this.$refs.widget, {
                sitekey: @js($siteKey),
                callback: (token) => $wire.set('turnstileToken', token, false),
                'expired-callback': () => $wire.set('turnstileToken', null, false),
                'error-callback': () => $wire.set('turnstileToken', null, false),
            });
        },
    }"
    x-init="window.turnstile ? render() : window.addEventListener('turnstile-loaded', () => render(), { once: true })"
    x-on:turnstile-reset.window="widgetId !== null && window.turnstile.reset(widgetId)"
    class="mb-4"
>
    <div x-ref="widget"></div>
</div>

<script>
    window.onTurnstileLoaded = () => window.dispatchEvent(new Event('turnstile-loaded'));
</script>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=onTurnstileLoaded" async defer></script>
