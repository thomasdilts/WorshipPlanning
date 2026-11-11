<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Checks addresses that church admins configure (Bible API, ntfy, SMS, SMTP) before this server
 * connects to them. Only the public internet is allowed: loopback, private, link-local (cloud
 * metadata) and reserved addresses are refused, and HTTP requests are pinned to the address that
 * was checked so a second DNS answer cannot point somewhere else.
 */
class OutboundUrlGuard
{
    /**
     * Ports a church's SMTP server may use.
     *
     * @var list<int>
     */
    public const SMTP_PORTS = [25, 465, 587, 2525];

    public function allowsPrivateHosts(): bool
    {
        return (bool) config('services.outbound.allow_private_hosts', false);
    }

    /**
     * Why the URL may not be used, or null when it is fine.
     */
    public function problemWithUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return __('Use a web address that starts with https://.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return __('Do not put a user name or password in the web address.');
        }

        return $this->problemWithHost($host);
    }

    /**
     * Why the host may not be used, or null when it is fine.
     */
    public function problemWithHost(string $host): ?string
    {
        if ($this->allowsPrivateHosts()) {
            return null;
        }

        $addresses = $this->resolve($host);

        if ($addresses === []) {
            return __('This address could not be found.');
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                return __('This address points at a private or local network, which is not allowed.');
            }
        }

        return null;
    }

    /**
     * Validation rule for a URL field.
     */
    public function urlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (blank($value)) {
                return;
            }

            if (($problem = $this->problemWithUrl((string) $value)) !== null) {
                $fail($problem);
            }
        };
    }

    /**
     * Validation rule for an SMTP host field.
     */
    public function hostRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (blank($value)) {
                return;
            }

            if (($problem = $this->problemWithHost(trim((string) $value))) !== null) {
                $fail($problem);
            }
        };
    }

    /**
     * An HTTP client for a church-configured URL: refuses private destinations, pins the checked
     * address and does not follow redirects (a redirect could lead back into the private network).
     *
     * @throws RuntimeException when the URL is not allowed
     */
    public function http(string $url): PendingRequest
    {
        if (($problem = $this->problemWithUrl($url)) !== null) {
            throw new RuntimeException($problem);
        }

        $request = Http::withOptions(['allow_redirects' => false]);

        if ($this->allowsPrivateHosts()) {
            return $request;
        }

        $parts = parse_url(trim($url));
        $host = trim((string) $parts['host'], '[]');
        $port = (int) ($parts['port'] ?? (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80));
        $address = $this->resolve($host)[0];

        return $request->withOptions([
            'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".(str_contains($address, ':') ? "[{$address}]" : $address)]],
        ]);
    }

    /**
     * Every IPv4 and IPv6 address the host name points at (an IP literal resolves to itself).
     *
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    protected function isPublicAddress(string $address): bool
    {
        $public = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        /* PHP leaves these out of its ranges: carrier-grade NAT and IPv4-mapped IPv6. */
        if ($public && str_starts_with($address, '100.')) {
            $second = (int) explode('.', $address)[1];
            $public = $second < 64 || $second > 127;
        }

        if ($public && str_starts_with(strtolower($address), '::ffff:')) {
            return $this->isPublicAddress(substr($address, 7));
        }

        return $public;
    }
}
