<?php

namespace Modules\Messaging\Support;

use App\Models\Church;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Connect Telegram" deep links (https://t.me/<bot>?start=<token>). A bot cannot message anyone who has
 * not pressed Start, so the token in the link tells the webhook which person pressed it. Tokens are
 * single use and expire after {@see self::TTL_MINUTES}; the same token is reused until then.
 */
class TelegramLinks
{
    public const TTL_MINUTES = 30;

    /** Telegram allows up to 64 characters from A–Z, a–z, 0–9, _ and - in the start parameter. */
    public const TOKEN_LENGTH = 32;

    public function __construct(
        protected TelegramBotApi $api,
    ) {}

    public function url(User $user): ?string
    {
        $church = $user->church;
        $bot = $church ? $this->api->botUsername($church) : null;

        if ($bot === null || $user->getKey() === null) {
            return null;
        }

        return 'https://t.me/'.$bot.'?start='.$this->tokenFor($user);
    }

    /**
     * The person the token was made for, if it is still valid and belongs to this church. The token is used up.
     */
    public function consume(Church $church, string $token): ?User
    {
        if (! preg_match('/^[A-Za-z0-9]{'.self::TOKEN_LENGTH.'}$/', $token)) {
            return null;
        }

        $userId = Cache::pull($this->tokenKey($token));

        if ($userId === null) {
            return null;
        }

        Cache::forget($this->userKey((int) $userId));

        return User::query()->whereKey($userId)->where('church_id', $church->getKey())->first();
    }

    protected function tokenFor(User $user): string
    {
        $existing = Cache::get($this->userKey((int) $user->getKey()));

        if (is_string($existing) && Cache::has($this->tokenKey($existing))) {
            return $existing;
        }

        $token = Str::random(self::TOKEN_LENGTH);
        $expires = now()->addMinutes(self::TTL_MINUTES);
        Cache::put($this->tokenKey($token), (int) $user->getKey(), $expires);
        Cache::put($this->userKey((int) $user->getKey()), $token, $expires);

        return $token;
    }

    protected function tokenKey(string $token): string
    {
        return 'telegram-link:token:'.$token;
    }

    protected function userKey(int $userId): string
    {
        return 'telegram-link:user:'.$userId;
    }
}
