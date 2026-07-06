<?php

declare(strict_types=1);

namespace Kitcchu\UserInteractionTracker\Support;

class BotDetector
{
    public function __construct(
        private readonly bool $filterLan = true,
        private readonly array $excludeIps = [],
        private readonly array $botTokens = [],
        private readonly string $botRegex = '/(bot|crawl|spider|slurp|scanner|fetcher|preview| indexer|httpclient|libwww|python-requests|curl|wget|go-http-client|java\/|okhttp|node-fetch|headless)/i',
    ) {}

    /**
     * @param array{filter_lan?: bool, exclude_ips?: string[], bot_tokens?: string[], bot_regex?: string} $cfg
     */
    public static function fromConfig(array $cfg): self
    {
        return new self(
            filterLan: $cfg['filter_lan'] ?? true,
            excludeIps: $cfg['exclude_ips'] ?? [],
            botTokens: $cfg['bot_tokens'] ?? [],
            botRegex: $cfg['bot_regex'] ?? '/(bot|crawl|spider|slurp|scanner|fetcher|preview| indexer|httpclient|libwww|python-requests|curl|wget|go-http-client|java\/|okhttp|node-fetch|headless)/i',
        );
    }

    public function isBot(?string $userAgent): bool
    {
        $ua = strtolower(trim((string) $userAgent));
        if ($ua === '') {
            return true;
        }
        foreach ($this->botTokens as $token) {
            if (str_contains($ua, strtolower($token))) {
                return true;
            }
        }
        return (bool) preg_match($this->botRegex, $ua);
    }

    /**
     * True for traffic we don't want in the digest: bots, internal/LAN, or the
     * configured IP/subnet blocklist.
     */
    public function shouldFilter(?string $ip, ?string $userAgent): bool
    {
        if ($this->isBot($userAgent)) {
            return true;
        }
        $ip = trim((string) $ip);
        if ($ip === '') {
            return true;
        }
        if ($this->filterLan && $this->isPrivate($ip)) {
            return true;
        }
        return $this->isExcludedIp($ip);
    }

    private function isPrivate(string $ip): bool
    {
        return str_starts_with($ip, '192.168.')
            || str_starts_with($ip, '10.')
            || str_starts_with($ip, '127.')
            || str_starts_with($ip, '172.')   // 172.16.0.0–172.31.255.255
            || $ip === '::1'
            || str_starts_with($ip, 'fc')
            || str_starts_with($ip, 'fd');
    }

    public function isExcludedIp(string $ip): bool
    {
        if (empty($this->excludeIps)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }
        $ipIsV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;

        foreach ($this->excludeIps as $rule) {
            if (str_contains($rule, '/')) {
                [$subnet, $mask] = explode('/', $rule, 2);
                $subnetIsV4 = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
                if ($subnetIsV4 !== $ipIsV4) {
                    continue;
                }
                $subnetBin = @inet_pton($subnet);
                if ($subnetBin === false) {
                    continue;
                }
                $mask = (int) $mask;
                $bits = strlen($subnetBin) * 8;
                if ($mask < 0 || $mask > $bits) {
                    continue;
                }
                if ($mask === 0) {
                    return true;
                }
                $full = intdiv($mask, 8);
                $partial = $mask % 8;
                $match = true;
                if ($full > 0 && substr($ipBin, 0, $full) !== substr($subnetBin, 0, $full)) {
                    $match = false;
                }
                if ($match && $partial > 0) {
                    $m = (0xFF << (8 - $partial)) & 0xFF;
                    if ((ord($ipBin[$full]) & $m) !== (ord($subnetBin[$full]) & $m)) {
                        $match = false;
                    }
                }
                if ($match) {
                    return true;
                }
            } elseif ($ip === $rule) {
                return true;
            }
        }
        return false;
    }
}
