<?php

namespace Modules\BibleVerses\Services;

use App\Core\Modules\ChurchModuleManager;
use App\Models\Church;
use App\Support\OutboundUrlGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZamarBibleClient
{
    public function __construct(
        protected ChurchModuleManager $modules,
    ) {}

    public function configuredFor(?Church $church): bool
    {
        [$baseUrl, $apiKey] = $this->credentials($church);

        return filled($baseUrl) && filled($apiKey);
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function bibles(?Church $church = null): array
    {
        $response = $this->http($church)->get('/api/bibles');

        if (! $response->successful()) {
            throw new RuntimeException('Unable to load bibles from Zamar API ('.$response->status().').');
        }

        $rows = $response->json('data') ?? [];

        return collect($rows)
            ->map(fn (array $row): array => [
                'code' => (string) ($row['id'] ?? $row['code'] ?? ''),
                'name' => (string) ($row['name'] ?? $row['id'] ?? $row['code'] ?? ''),
            ])
            ->filter(fn (array $row): bool => $row['code'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array{reference: string, content: string, verses: list<array<string, mixed>>, copyright: ?string}
     */
    public function passage(string $bibleCode, string $ref, ?Church $church = null): array
    {
        $settings = $this->settings($church);
        $cacheKey = 'zamar.passage.'.hash('xxh3', $settings['base_url'].'|'.$bibleCode.'|'.$ref);

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($bibleCode, $ref, $church): array {
            $encoded = rawurlencode($ref);
            $response = $this->http($church)->get("/api/bibles/{$bibleCode}/passages/{$encoded}");

            if (! $response->successful()) {
                $message = $response->json('message') ?? 'Passage not found.';

                throw new RuntimeException((string) $message);
            }

            $data = $response->json('data') ?? [];

            return [
                'reference' => (string) ($data['reference'] ?? $ref),
                'content' => (string) ($data['content'] ?? ''),
                'verses' => is_array($data['verses'] ?? null) ? $data['verses'] : [],
                'copyright' => isset($data['copyright']) ? (string) $data['copyright'] : null,
            ];
        });
    }

    /**
     * Human-readable passage body for UI previews. Empty string when incomplete / unavailable.
     */
    public function passagePreview(string $bibleCode, string $ref, ?Church $church = null): string
    {
        if ($bibleCode === '' || $ref === '' || ! $this->configuredFor($church)) {
            return '';
        }

        try {
            $passage = $this->passage($bibleCode, $ref, $church);
        } catch (\Throwable) {
            return __('Unable to load verse text.');
        }

        $content = trim($passage['content']);

        if ($content === '') {
            return '';
        }

        if (filled($passage['copyright'])) {
            return $content."\n\n— ".$passage['copyright'];
        }

        return $content;
    }

    /**
     * @return array{base_url: string, api_key: string, default_bible: string}
     */
    public function settings(?Church $church = null): array
    {
        $church ??= auth()->user()?->church;
        $defaults = [
            'base_url' => self::normalizeBaseUrl((string) config('services.zamar_bible.base_url', '')),
            'api_key' => (string) config('services.zamar_bible.api_key', ''),
            'default_bible' => (string) config('services.zamar_bible.default_bible', 'kjv'),
        ];

        if ($church === null) {
            return $defaults;
        }

        $stored = $this->modules->settings($church, 'bible_verses');

        $apiKey = (string) ($stored['api_key'] ?? '');
        if ($apiKey !== '') {
            $apiKey = (string) $this->modules->decryptSecret($apiKey);
        }

        $baseUrl = (string) ($stored['base_url'] ?? '');
        $defaultBible = (string) ($stored['default_bible'] ?? '');

        // Empty church values must fall through to .env — enabling the module
        // snapshots blank defaults into church_modules.settings.
        $effectiveBaseUrl = $baseUrl !== '' ? self::normalizeBaseUrl($baseUrl) : $defaults['base_url'];

        return [
            'base_url' => $effectiveBaseUrl,
            // The platform key only ever goes to the platform's own Bible API. A church that points
            // at another server must bring its own key.
            'api_key' => $apiKey !== '' ? $apiKey : ($effectiveBaseUrl === $defaults['base_url'] ? $defaults['api_key'] : ''),
            'default_bible' => $defaultBible !== '' ? $defaultBible : $defaults['default_bible'],
        ];
    }

    /**
     * Host only — client paths already start with /api/…
     */
    public static function normalizeBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        if (str_ends_with(strtolower($url), '/api')) {
            $url = substr($url, 0, -4);
            $url = rtrim($url, '/');
        }

        return $url;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function credentials(?Church $church): array
    {
        $settings = $this->settings($church);

        return [$settings['base_url'], $settings['api_key']];
    }

    protected function http(?Church $church): PendingRequest
    {
        [$baseUrl, $apiKey] = $this->credentials($church);

        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('Bible API is not configured. Set base URL and API key in Bible settings.');
        }

        // The platform's own address from .env is trusted; an address a church typed in must be public.
        $platformBaseUrl = self::normalizeBaseUrl((string) config('services.zamar_bible.base_url', ''));
        $request = $baseUrl === $platformBaseUrl ? Http::withOptions([]) : app(OutboundUrlGuard::class)->http($baseUrl);

        return $request->baseUrl($baseUrl)
            ->withToken($apiKey)
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->retry(2, 200);
    }
}
