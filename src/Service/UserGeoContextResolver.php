<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class UserGeoContextResolver
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @return array{
     *   ip: string|null,
     *   country_code: string|null,
     *   country_name: string|null,
     *   region_name: string|null,
     *   city_name: string|null,
     *   vpn_suspected: bool
     * }
     */
    public function resolve(Request $request): array
    {
        $ip = $this->resolveClientIp($request);
        if ($ip === null) {
            return $this->emptyContext();
        }

        $headerHint = $this->resolveFromTrustedHeaders($request, $ip);
        $resolved = null;

        $providers = [
            fn (string $targetIp): ?array => $this->lookupWithIpWhoIs($targetIp),
            fn (string $targetIp): ?array => $this->lookupWithIpApiCo($targetIp),
            fn (string $targetIp): ?array => $this->lookupWithIpApiCom($targetIp),
        ];

        foreach ($providers as $provider) {
            try {
                $candidate = $provider($ip);
                if (!is_array($candidate)) {
                    continue;
                }

                if (($candidate['country_code'] ?? null) !== null || ($candidate['country_name'] ?? null) !== null) {
                    $resolved = $candidate;
                }

                if (($candidate['region_name'] ?? null) !== null || ($candidate['city_name'] ?? null) !== null) {
                    $resolved = $candidate;
                    break;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $base = [
            'ip' => $ip,
            'country_code' => null,
            'country_name' => null,
            'region_name' => null,
            'city_name' => null,
            'vpn_suspected' => false,
        ];

        if (is_array($headerHint)) {
            $base = array_merge($base, $headerHint);
        }

        if (is_array($resolved)) {
            $base = array_merge($base, array_filter(
                $resolved,
                static fn (mixed $value): bool => $value !== null
            ));
        }

        return $base;
    }

    private function resolveClientIp(Request $request): ?string
    {
        // Prefer proxy/CDN forwarded IPs when app is reached through a tunnel/reverse proxy.
        $headerCandidates = [
            (string) $request->headers->get('CF-Connecting-IP', ''),
            (string) $request->headers->get('True-Client-IP', ''),
            (string) $request->headers->get('X-Forwarded-For', ''),
            (string) $request->headers->get('X-Real-IP', ''),
        ];

        foreach ($headerCandidates as $raw) {
            $candidate = $this->extractBestIpFromHeader($raw);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        $ip = $request->getClientIp();
        if (!is_string($ip) || $ip === '') {
            return null;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        return $ip;
    }

    private function extractBestIpFromHeader(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $raw));
        foreach ($parts as $part) {
            if ($part === '' || !filter_var($part, FILTER_VALIDATE_IP)) {
                continue;
            }

            if (!$this->isLoopbackIp($part)) {
                return $part;
            }
        }

        foreach ($parts as $part) {
            if ($part !== '' && filter_var($part, FILTER_VALIDATE_IP)) {
                return $part;
            }
        }

        return null;
    }

    private function isLoopbackIp(string $ip): bool
    {
        return $ip === '127.0.0.1' || $ip === '::1';
    }

    /**
     * If app is behind reverse proxy/CDN, these headers can carry geo inferred from exit IP (incl. VPN egress).
     *
     * @return array{
     *   ip: string|null,
     *   country_code: string|null,
     *   country_name: string|null,
     *   region_name: string|null,
     *   city_name: string|null,
     *   vpn_suspected: bool
     * }|null
     */
    private function resolveFromTrustedHeaders(Request $request, string $ip): ?array
    {
        $cfCountry = strtoupper(trim((string) $request->headers->get('CF-IPCountry', '')));
        if ($cfCountry === '' || $cfCountry === 'XX' || $cfCountry === 'T1') {
            return null;
        }

        return [
            'ip' => $ip,
            'country_code' => $this->cleanString($cfCountry, 8),
            'country_name' => null,
            'region_name' => null,
            'city_name' => null,
            'vpn_suspected' => false,
        ];
    }

    private function env(string $name, string $default = ''): string
    {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }

        $fromEnv = (string) ($_ENV[$name] ?? '');
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $fromServer = (string) ($_SERVER[$name] ?? '');
        if ($fromServer !== '') {
            return $fromServer;
        }

        return $default;
    }

    /**
     * @return array{
     *   ip: string,
     *   country_code: string|null,
     *   country_name: string|null,
     *   region_name: string|null,
     *   city_name: string|null,
     *   vpn_suspected: bool
     * }|null
     */
    private function lookupWithIpWhoIs(string $ip): ?array
    {
        $endpoint = $this->env('GEO_LOOKUP_ENDPOINT', 'https://ipwho.is/%s');
        $url = str_contains($endpoint, '%s')
            ? sprintf($endpoint, rawurlencode($ip))
            : rtrim($endpoint, '/') . '/' . rawurlencode($ip);

        $response = $this->httpClient->request('GET', $url, ['timeout' => 8]);
        $data = $response->toArray(false);

        $security = is_array($data['security'] ?? null) ? $data['security'] : [];

        return [
            'ip' => $ip,
            'country_code' => $this->cleanString($data['country_code'] ?? null, 8),
            'country_name' => $this->cleanString($data['country'] ?? null, 120),
            'region_name' => $this->cleanString($data['region'] ?? null, 120),
            'city_name' => $this->cleanString($data['city'] ?? null, 120),
            'vpn_suspected' => (bool) ($security['vpn'] ?? false)
                || (bool) ($security['proxy'] ?? false)
                || (bool) ($security['tor'] ?? false)
                || (bool) ($security['relay'] ?? false),
        ];
    }

    /**
     * @return array{
     *   ip: string,
     *   country_code: string|null,
     *   country_name: string|null,
     *   region_name: string|null,
     *   city_name: string|null,
     *   vpn_suspected: bool
     * }|null
     */
    private function lookupWithIpApiCo(string $ip): ?array
    {
        $url = sprintf('https://ipapi.co/%s/json/', rawurlencode($ip));
        $response = $this->httpClient->request('GET', $url, ['timeout' => 8]);
        $data = $response->toArray(false);

        if (!empty($data['error'])) {
            return null;
        }

        return [
            'ip' => $ip,
            'country_code' => $this->cleanString($data['country_code'] ?? null, 8),
            'country_name' => $this->cleanString($data['country_name'] ?? null, 120),
            'region_name' => $this->cleanString($data['region'] ?? null, 120),
            'city_name' => $this->cleanString($data['city'] ?? null, 120),
            'vpn_suspected' => false,
        ];
    }

    /**
     * @return array{
     *   ip: string,
     *   country_code: string|null,
     *   country_name: string|null,
     *   region_name: string|null,
     *   city_name: string|null,
     *   vpn_suspected: bool
     * }|null
     */
    private function lookupWithIpApiCom(string $ip): ?array
    {
        $url = sprintf('http://ip-api.com/json/%s?fields=status,country,countryCode,regionName,city', rawurlencode($ip));
        $response = $this->httpClient->request('GET', $url, ['timeout' => 8]);
        $data = $response->toArray(false);

        if (($data['status'] ?? 'fail') !== 'success') {
            return null;
        }

        return [
            'ip' => $ip,
            'country_code' => $this->cleanString($data['countryCode'] ?? null, 8),
            'country_name' => $this->cleanString($data['country'] ?? null, 120),
            'region_name' => $this->cleanString($data['regionName'] ?? null, 120),
            'city_name' => $this->cleanString($data['city'] ?? null, 120),
            'vpn_suspected' => false,
        ];
    }

    private function cleanString(mixed $value, int $maxLen): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (strlen($value) > $maxLen) {
            $value = substr($value, 0, $maxLen);
        }

        return $value;
    }

    /**
     * @return array{
     *   ip: string|null,
     *   country_code: string|null,
     *   country_name: string|null,
     *   region_name: string|null,
     *   city_name: string|null,
     *   vpn_suspected: bool
     * }
     */
    private function emptyContext(): array
    {
        return [
            'ip' => null,
            'country_code' => null,
            'country_name' => null,
            'region_name' => null,
            'city_name' => null,
            'vpn_suspected' => false,
        ];
    }
}
