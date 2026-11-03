<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks a Cloudflare Turnstile token with Cloudflare. Fails closed: if Cloudflare can't be
 * reached, the token counts as invalid.
 */
class TurnstileVerifier
{
    public function isEnabled(): bool
    {
        return filled(config('services.turnstile.site_key'))
            && filled(config('services.turnstile.secret_key'));
    }

    public function siteKey(): string
    {
        return (string) config('services.turnstile.site_key');
    }

    public function passes(?string $token, ?string $ipAddress = null): bool
    {
        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post((string) config('services.turnstile.verify_url'), array_filter([
                    'secret' => (string) config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ipAddress,
                ]));
        } catch (Throwable) {
            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
