<?php

declare(strict_types=1);

namespace Kitcchu\UserInteractionTracker\Services;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Resolves an IP address to a geographic location.
 *
 * Two-tier strategy:
 *  1) LOCAL  – MaxMind GeoLite2-City.mmdb if present (fast, no rate limit).
 *  2) API    – ip-api.com free HTTP endpoint as a fallback.
 */
class GeoIpService
{
    public function __construct(
        private readonly string $dbPath = '',
    ) {}

    public function locate(?string $ip): ?array
    {
        if (!$ip || !$this->isPublicIp($ip)) {
            return null;
        }
        $local = $this->locateWithLocalDb($ip);
        if ($local !== null) {
            return $local;
        }
        return $this->locateWithApi($ip);
    }

    public function hasLocalDatabase(): bool
    {
        return is_file($this->dbPath);
    }

    public function dbPath(): string
    {
        return $this->dbPath;
    }

    private function locateWithLocalDb(string $ip): ?array
    {
        if (!$this->hasLocalDatabase()) {
            return null;
        }
        try {
            $reader = new Reader($this->dbPath);
            $record = $reader->city($ip);
            $reader->close();
            return [
                'country'      => $record->country->name,
                'country_code' => $record->country->isoCode,
                'region'       => $record->mostSpecificSubdivision->name,
                'city'         => $record->city->name,
                'lat'          => $record->location->latitude,
                'lng'          => $record->location->longitude,
                'source'       => 'mmdb',
            ];
        } catch (AddressNotFoundException $e) {
            return null;
        } catch (InvalidArgumentException $e) {
            Log::error('GeoIp: local db invalid: ' . $e->getMessage());
            return null;
        } catch (\Throwable $e) {
            Log::error('GeoIp: local db error: ' . $e->getMessage());
            return null;
        }
    }

    private function locateWithApi(string $ip): ?array
    {
        try {
            $resp = Http::withoutVerifying()
                ->timeout(5)
                ->get("http://ip-api.com/json/{$ip}", [
                    'fields' => 'status,country,countryCode,regionName,city,lat,lon',
                    'lang'   => 'en',
                ]);
            if (!$resp->successful()) {
                return null;
            }
            $d = $resp->json();
            if (($d['status'] ?? null) !== 'success') {
                return null;
            }
            return [
                'country'      => $d['country'] ?? null,
                'country_code' => $d['countryCode'] ?? null,
                'region'       => $d['regionName'] ?? null,
                'city'         => $d['city'] ?? null,
                'lat'          => $d['lat'] ?? null,
                'lng'          => $d['lon'] ?? null,
                'source'       => 'api',
            ];
        } catch (\Throwable $e) {
            Log::error("GeoIp: ip-api exception for {$ip}: " . $e->getMessage());
            return null;
        }
    }

    private function isPublicIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '' || $ip === '0.0.0.0') {
            return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
