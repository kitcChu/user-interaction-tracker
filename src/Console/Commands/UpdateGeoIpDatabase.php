<?php

declare(strict_types=1);

namespace Kitcchu\UserInteractionTracker\Console\Commands;

use Kitcchu\UserInteractionTracker\Services\GeoIpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Downloads / refreshes the MaxMind GeoLite2-City database.
 *
 *   php artisan geoip:update
 *
 * Requires MAXMIND_LICENSE_KEY (https://www.maxmind.com/en/geolite2/signup).
 */
class UpdateGeoIpDatabase extends Command
{
    protected $signature = 'geoip:update
                            {--key= : MaxMind license key (overrides MAXMIND_LICENSE_KEY)}
                            {--force : Download even if the DB is already present}';

    protected $description = 'Download/update the MaxMind GeoLite2-City database for IP geolocation.';

    private const EDITION_ID = 'GeoLite2-City';

    public function handle(): int
    {
        $cfg = config('user-interaction-tracker.geoip', []);
        $dbPath = $cfg['db_path'] ?? storage_path('app/geoip/GeoLite2-City.mmdb');
        $key = $this->option('key') ?: ($cfg['maxmind_key'] ?? env('MAXMIND_LICENSE_KEY'));

        if (!$key) {
            $this->error('No MaxMind license key found.');
            $this->info('Create a free key at https://www.maxmind.com/en/geolite2/signup');
            $this->info('Then set MAXMIND_LICENSE_KEY in your .env and re-run.');
            $this->newLine();
            $this->warn('Note: GeoIP lookups still work via the free ip-api.com fallback until the local DB is installed.');
            return self::FAILURE;
        }

        if (!$this->option('force') && is_file($dbPath)) {
            $this->info('GeoLite2 database already present: ' . $dbPath);
            if (!$this->confirm('Download a fresh copy now?')) {
                return self::SUCCESS;
            }
        }

        $dir = dirname($dbPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->error("Cannot create directory: {$dir} (check permissions).");
            return self::FAILURE;
        }

        $url = sprintf(
            'https://download.maxmind.com/app/geoip_download?edition_id=%s&license_key=%s&suffix=tar.gz',
            self::EDITION_ID,
            urlencode($key)
        );

        $this->info('Downloading GeoLite2-City database from MaxMind...');
        $tmpTar = $dir . '/geolite2-city.tar.gz';

        try {
            $this->downloadWithRedirects($url, $tmpTar);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            @unlink($tmpTar);
            return self::FAILURE;
        }

        if (!is_file($tmpTar) || filesize($tmpTar) < 100000) {
            $msg = trim(file_get_contents($tmpTar) ?: '');
            $this->error('Downloaded file is too small or invalid (likely a bad license key).');
            if ($msg !== '') {
                $this->line(substr($msg, 0, 500));
            }
            @unlink($tmpTar);
            return self::FAILURE;
        }

        $this->info('Extracting database...');
        $extracted = $this->extractMmdb($tmpTar, $dir);
        @unlink($tmpTar);

        if (!$extracted) {
            $this->error('Could not find a .mmdb file inside the archive.');
            return self::FAILURE;
        }

        is_file($dbPath)
            ? $this->info("GeoLite2 database updated: {$dbPath}")
            : $this->info("GeoLite2 database installed: {$dbPath}");

        return self::SUCCESS;
    }

    private function downloadWithRedirects(string $url, string $dest): void
    {
        $resp = Http::withOptions([
            'sink'            => $dest,
            'allow_redirects' => true,
            'connect_timeout' => 15,
            'timeout'         => 180,
        ])->get($url);

        if ($resp->status() === 401) {
            throw new RuntimeException('MaxMind rejected the license key (401 Unauthorized). Check MAXMIND_LICENSE_KEY.');
        }
        if (!$resp->successful()) {
            throw new RuntimeException("Download failed (HTTP {$resp->status()}).");
        }
    }

    private function extractMmdb(string $tarGz, string $dir): bool
    {
        try {
            $phar = new \PharData($tarGz);
            $iterator = new \RecursiveIteratorIterator($phar);
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'mmdb') {
                    copy($file->getPathname(), $dir . '/' . basename($file->getFilename()));
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return false;
    }
}
