<?php

namespace App\Services\WebResearch;

use App\Services\WebResearch\Exceptions\WebResearchException;

final class WebUrlGuard
{
    /**
     * @var list<string>
     */
    private const BLOCKED_HOSTS = [
        'localhost',
        'metadata.google.internal',
        'metadata.google.com',
        'metadata',
        'instance-data',
        'kubernetes.default',
        'kubernetes.default.svc',
    ];

    /**
     * @var list<string>
     */
    private const BLOCKED_SUFFIXES = [
        '.localhost',
        '.local',
        '.internal',
        '.lan',
        '.home',
        '.corp',
        '.localdomain',
    ];

    public function assertSafeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2048) {
            throw new WebResearchException('web_invalid_url', 'URL is invalid.');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'])) {
            throw new WebResearchException('web_invalid_url', 'URL is invalid.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $allowed = array_map('strval', (array) config('web_research.allowed_schemes', ['http', 'https']));

        if (! in_array($scheme, $allowed, true)) {
            throw new WebResearchException('web_fetch_forbidden', 'URL scheme is not allowed.');
        }

        if (! isset($parts['host'])) {
            throw new WebResearchException('web_invalid_url', 'URL is invalid.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new WebResearchException('web_fetch_forbidden', 'URL credentials are not allowed.');
        }

        $host = $this->normalizeHost((string) $parts['host']);
        $this->assertHostAllowed($host);

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if ($port <= 0 || $port > 65535) {
            throw new WebResearchException('web_invalid_url', 'URL port is invalid.');
        }

        if ((bool) config('web_research.deny_private_networks', true)) {
            foreach ($this->resolveIps($host) as $ip) {
                $this->assertPublicIp($ip);
            }
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = '';

        return $scheme.'://'.$this->hostForUrl($host).($this->isDefaultPort($scheme, $port) ? '' : ':'.$port).$path.$query.$fragment;
    }

    public function domainOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $this->normalizeHost($host) : '';
    }

    private function normalizeHost(string $host): string
    {
        $host = trim($host, '[]');
        $host = rtrim(mb_strtolower($host), '.');

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        return $host;
    }

    private function hostForUrl(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return '['.$host.']';
        }

        return $host;
    }

    private function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }

    private function assertHostAllowed(string $host): void
    {
        if ($host === '' || $host === '*' || str_contains($host, ' ')) {
            throw new WebResearchException('web_invalid_url', 'URL host is invalid.');
        }

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new WebResearchException('web_fetch_forbidden', 'URL host is not allowed.');
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new WebResearchException('web_fetch_forbidden', 'URL host is not allowed.');
            }
        }

        if (! str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new WebResearchException('web_fetch_forbidden', 'Internal hostnames are not allowed.');
        }

        if (ctype_digit($host)) {
            throw new WebResearchException('web_fetch_forbidden', 'Numeric hosts are not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && (bool) config('web_research.deny_private_networks', true)) {
            $this->assertPublicIp($host);
        }
    }

    /**
     * @return list<string>
     */
    private function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        $ips = [];

        $aRecords = @dns_get_record($host, DNS_A);
        if (is_array($aRecords)) {
            foreach ($aRecords as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }

        $aaaaRecords = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaaRecords)) {
            foreach ($aaaaRecords as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = array_merge($ips, $v4);
            }
        }

        $ips = array_values(array_unique($ips));

        if ($ips === []) {
            throw new WebResearchException('web_fetch_forbidden', 'Host could not be resolved safely.');
        }

        return $ips;
    }

    private function assertPublicIp(string $ip): void
    {
        $ip = trim($ip, '[]');

        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $mapped = substr($ip, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $this->assertPublicIp($mapped);

                return;
            }
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new WebResearchException('web_fetch_forbidden', 'Address is not allowed.');
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new WebResearchException('web_fetch_forbidden', 'Private or reserved addresses are not allowed.');
        }

        if ($ip === '169.254.169.254' || $ip === 'metadata.google.internal') {
            throw new WebResearchException('web_fetch_forbidden', 'Private or reserved addresses are not allowed.');
        }
    }
}
