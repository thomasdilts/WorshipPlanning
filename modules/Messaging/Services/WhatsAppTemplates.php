<?php

namespace Modules\Messaging\Services;

use App\Core\Modules\ChurchModuleManager;
use App\Models\Church;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Messaging\Support\MessagePlaceholders;
use Modules\Messaging\Support\MessagingAvailability;

/**
 * Message templates of the church's WhatsApp Business Account, and the parameters to send one.
 *
 * Body variables use the app's placeholder names ({{task}}, {{date}}, …). Link buttons get the accept and
 * decline links in button order: first link button = {{accept_url}}, second = {{decline_url}}. In a password
 * message the first link button gets {{password_url}} instead.
 *
 * @see https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates
 */
class WhatsAppTemplates
{
    public const STATUS_APPROVED = 'APPROVED';

    protected const BUTTON_LINKS = ['accept_url', 'decline_url'];

    /** @var array<int, string> Why the last template fetch failed, by church ID. */
    protected array $fetchErrors = [];

    public function __construct(
        protected ChurchModuleManager $modules,
    ) {}

    /**
     * Every template on the account; empty when the account ID is missing or Meta cannot be reached
     * (see {@see fetchError()} for why).
     *
     * @return list<array{name: string, language: string, status: string, components: list<array<string, mixed>>}>
     */
    public function all(Church $church): array
    {
        $settings = $this->modules->settings($church, MessagingAvailability::MODULE_WHATSAPP);
        $accountId = (string) ($settings['business_account_id'] ?? '');
        unset($this->fetchErrors[$church->id]);

        if ($accountId === '') {
            return [];
        }

        $cacheKey = 'whatsapp-templates.'.$church->id.'.'.$accountId;

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $version = trim((string) ($settings['api_version'] ?? 'v21.0'), '/');

        try {
            $response = Http::withToken((string) $this->modules->decryptSecret($settings['access_token'] ?? null))
                ->acceptJson()
                ->timeout(15)
                ->get(sprintf('https://graph.facebook.com/%s/%s/message_templates', $version, $accountId), [
                    'fields' => 'name,language,status,components',
                    'limit' => 200,
                ]);
        } catch (\Throwable $e) {
            $this->fetchErrors[$church->id] = $e->getMessage();

            return [];
        }

        if (! $response->successful()) {
            $this->fetchErrors[$church->id] = 'WhatsApp API failed ('.$response->status().'): '.$response->body();

            return [];
        }

        $templates = collect($response->json('data') ?? [])
            ->map(fn (array $template): array => [
                'name' => (string) $template['name'],
                'language' => (string) $template['language'],
                'status' => (string) $template['status'],
                'components' => $template['components'] ?? [],
            ])
            ->values()
            ->all();

        Cache::put($cacheKey, $templates, now()->addMinutes(5));

        return $templates;
    }

    /**
     * Meta's error from the last {@see all()} call for the church, or null when it succeeded.
     */
    public function fetchError(Church $church): ?string
    {
        return $this->fetchErrors[$church->id] ?? null;
    }

    /**
     * Template names for a select, labelled with language and approval status.
     *
     * @return array<string, string>
     */
    public function options(Church $church): array
    {
        return collect($this->all($church))
            ->groupBy('name')
            ->map(fn ($versions, string $name): string => $name.' ('.$versions
                ->map(fn (array $template): string => $template['language'].', '.$template['status'])
                ->implode('; ').')')
            ->sortKeys()
            ->all();
    }

    /**
     * The template by name, in the preferred language when it exists in several.
     *
     * @return array{name: string, language: string, status: string, components: list<array<string, mixed>>}|null
     */
    public function find(Church $church, string $name, ?string $preferredLanguage = null): ?array
    {
        $versions = collect($this->all($church))->where('name', $name);
        $approved = $versions->where('status', self::STATUS_APPROVED);

        return $approved->first(fn (array $template): bool => $preferredLanguage !== null
            && str_starts_with($template['language'], $preferredLanguage))
            ?? $approved->first()
            ?? $versions->first();
    }

    /**
     * Whether any version of the template has {{custom_mess}} in its header or body.
     */
    public function usesCustomMessage(Church $church, ?string $name): bool
    {
        if (blank($name)) {
            return false;
        }

        return collect($this->all($church))
            ->where('name', $name)
            ->flatMap(fn (array $template): array => $template['components'])
            ->contains(fn (array $component): bool => MessagePlaceholders::usesCustomMessage((string) ($component['text'] ?? '')));
    }

    /**
     * Send components with the template's variables filled in.
     *
     * @param  array{components: list<array<string, mixed>>}  $template
     * @param  array<string, string>  $values  Placeholder values, including accept_url / decline_url.
     * @return list<array<string, mixed>>
     *
     * @throws \InvalidArgumentException When a link button has no link to fill in.
     */
    public function components(array $template, array $values): array
    {
        $components = [];

        foreach ($template['components'] as $component) {
            $type = strtolower((string) ($component['type'] ?? ''));

            if (in_array($type, ['header', 'body'], true) && ($component['format'] ?? 'TEXT') === 'TEXT') {
                $parameters = $this->textParameters((string) ($component['text'] ?? ''), $values);

                if ($parameters !== []) {
                    $components[] = ['type' => $type, 'parameters' => $parameters];
                }
            }

            if ($type === 'buttons') {
                array_push($components, ...$this->buttonParameters($component['buttons'] ?? [], $values));
            }
        }

        return $components;
    }

    /**
     * @param  array<string, string>  $values
     * @return list<array{type: string, parameter_name: string, text: string}>
     */
    protected function textParameters(string $text, array $values): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $matches);

        return collect($matches[1])
            ->unique()
            ->map(fn (string $name): array => [
                'type' => 'text',
                'parameter_name' => $name,
                'text' => $this->parameterText($values[$name] ?? null),
            ])
            ->values()
            ->all();
    }

    /**
     * Meta refuses empty parameters and parameters with line breaks, tabs or more than four spaces in a row.
     */
    protected function parameterText(?string $value): string
    {
        $text = trim((string) preg_replace(['/\s*[\r\n\t]+\s*/', '/ {4,}/'], [' ', '   '], (string) $value));

        return $text === '' ? '-' : $text;
    }

    /**
     * @param  list<array<string, mixed>>  $buttons
     * @param  array<string, string>  $values
     * @return list<array<string, mixed>>
     */
    protected function buttonParameters(array $buttons, array $values): array
    {
        $parameters = [];
        $linkButton = 0;
        $linkKeys = filled($values[MessagePlaceholders::PASSWORD_URL] ?? null)
            ? [MessagePlaceholders::PASSWORD_URL]
            : self::BUTTON_LINKS;

        foreach ($buttons as $index => $button) {
            $url = (string) ($button['url'] ?? '');

            if (($button['type'] ?? '') !== 'URL' || ! str_contains($url, '{{1}}')) {
                continue;
            }

            $link = $values[$linkKeys[$linkButton] ?? ''] ?? '';
            $linkButton++;

            if (blank($link)) {
                throw new \InvalidArgumentException(__('The WhatsApp template has link buttons, but this message has no task to answer.'));
            }

            $parameters[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => (string) $index,
                'parameters' => [['type' => 'text', 'text' => $this->urlSuffix($url, $link)]],
            ];
        }

        return $parameters;
    }

    /**
     * The part of the link after the button's fixed base, e.g. "abc23def45gh/yes" for https://…/r/{{1}}.
     * Compares paths only, so local links fill a template approved for the public domain.
     */
    protected function urlSuffix(string $buttonUrl, string $link): string
    {
        $basePath = (string) parse_url(str_replace('{{1}}', '', $buttonUrl), PHP_URL_PATH);
        $linkPath = (string) parse_url($link, PHP_URL_PATH);
        $query = parse_url($link, PHP_URL_QUERY);
        $suffix = $basePath !== '' && str_starts_with($linkPath, $basePath)
            ? substr($linkPath, strlen($basePath))
            : ltrim($linkPath, '/');

        return $suffix.($query ? '?'.$query : '');
    }
}
