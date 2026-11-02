<?php

namespace App\Core\Modules;

use App\Models\Church;
use App\Models\ChurchModule;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

class ChurchModuleManager
{
    public function __construct(
        protected ModuleRegistry $registry,
    ) {}

    public function isEnabled(Church $church, string $key): bool
    {
        return $this->registry->enabledFor($church, $key);
    }

    public function enable(Church $church, string $key, ?array $settings = null): ChurchModule
    {
        $definition = $this->registry->get($key);

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown module [{$key}].");
        }

        if ($definition->required) {
            throw new InvalidArgumentException("Module [{$key}] is always enabled.");
        }

        $row = ChurchModule::query()->firstOrNew([
            'church_id' => $church->id,
            'module_key' => $key,
        ]);

        $row->enabled = true;

        if ($settings !== null) {
            $row->settings = $settings;
        } elseif ($row->settings === null) {
            $row->settings = $this->defaultSettings($key);
        }

        $row->save();

        return $row;
    }

    public function disable(Church $church, string $key): void
    {
        $definition = $this->registry->get($key);

        if ($definition?->required) {
            throw new InvalidArgumentException("Module [{$key}] cannot be disabled.");
        }

        ChurchModule::query()
            ->where('church_id', $church->id)
            ->where('module_key', $key)
            ->update(['enabled' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(Church $church, string $key): array
    {
        $row = ChurchModule::query()
            ->where('church_id', $church->id)
            ->where('module_key', $key)
            ->first();

        return $row?->settings ?? $this->defaultSettings($key);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function updateSettings(Church $church, string $key, array $settings): ChurchModule
    {
        $row = ChurchModule::query()->firstOrNew([
            'church_id' => $church->id,
            'module_key' => $key,
        ]);

        if (! $row->exists) {
            $row->enabled = $this->registry->get($key)?->required ?? false;
        }

        $row->settings = $settings;
        $row->save();

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultSettings(string $key): array
    {
        return match ($key) {
            'messaging' => [
                'email' => [
                    'host' => '',
                    'port' => 587,
                    'username' => '',
                    'password' => '',
                    'encryption' => 'tls',
                    'from_address' => '',
                    'from_name' => '',
                ],
            ],
            'messaging_sms' => [
                'api_base' => 'https://api.ip1sms.com/v2',
                'api_token' => '',
                'sender' => '',
            ],
            'messaging_whatsapp' => [
                'api_version' => 'v21.0',
                'access_token' => '',
                'phone_number_id' => '',
            ],
            'messaging_ntfy' => [
                'server_url' => 'https://ntfy.sh',
                'access_token' => '',
            ],
            'messaging_telegram' => [
                'bot_token' => '',
                'bot_username' => '',
                'webhook_secret' => '',
            ],
            'bible_verses' => [
                'base_url' => (string) config('services.zamar_bible.base_url', ''),
                'api_key' => '',
                'default_bible' => (string) config('services.zamar_bible.default_bible', 'kjv'),
            ],
            default => [],
        };
    }

    public function encryptSecret(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return Crypt::encryptString($value);
    }

    public function decryptSecret(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            // Already plaintext (legacy / seed) — return as-is
            return $value;
        }
    }
}
