<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Update
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Update;

use Comfino\Api\Client;
use Comfino\Backend\Cache\CacheManager;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Throwable;

class UpdateManager
{
    /** Base canonical platform slug polled on the Comfino release API; the API resolves the concrete line by User-Agent. */
    private const PLATFORM = 'magento';
    private const CACHE_KEY = 'comfino_github_version_check';
    private const LOCK_KEY = 'comfino_github_version_check_lock';
    private const LOCK_TTL = 300; // 5 minutes
    /* Jittered ~1 day interval (20-28h): shops tend to install/upgrade around the same calendar moments, so a fixed
       24h TTL would make every installation re-check the shared release API at the same clustered hour indefinitely.
       Randomizing lets each installation's check hour drift day to day instead. */
    private const CACHE_TTL_MIN = 72000; // 20 hours
    private const CACHE_TTL_MAX = 100800; // 28 hours

    /**
     * Check for available updates via the Comfino release API.
     *
     * @param string $currentVersion
     * @param Client|null $apiClient Optional client override (used in tests); defaults to the shared plugin instance.
     *
     * @return array{
     *     update_available: bool,
     *     current_version: string,
     *     github_version?: string,
     *     download_url?: string,
     *     release_notes_url?: string,
     *     description_html?: string,
     *     checked_at?: int,
     *     error?: string
     * }
     */
    public static function checkForUpdates(string $currentVersion, ?Client $apiClient = null): array
    {
        $cacheItem = null;

        try {
            $cacheItem = CacheManager::getPool()->getItem(self::CACHE_KEY);

            if ($cacheItem->isHit()) {
                return $cacheItem->get();
            }

            /* Claim a short-lived exclusive lock before hitting the API. Magento's admin notification list polls
               registered messages on essentially every admin page load, so concurrent requests can each observe the
               cache as a miss before the first one writes its result back, firing duplicate release-check calls. */
            $lockItem = CacheManager::getPool()->getItem(self::LOCK_KEY);

            if ($lockItem->isHit()) {
                return ['update_available' => false, 'current_version' => $currentVersion];
            }

            $lockItem->set(true);
            $lockItem->expiresAfter(self::LOCK_TTL);
            CacheManager::getPool()->save($lockItem);
        } catch (Throwable) {
            // Cache is not available - proceed without it.
        }

        $result = self::fetchLatestRelease($currentVersion, $apiClient);

        try {
            if ($cacheItem !== null) {
                $cacheItem->set($result);
                $cacheItem->expiresAfter(random_int(self::CACHE_TTL_MIN, self::CACHE_TTL_MAX));
                CacheManager::getPool()->save($cacheItem);
            }
        } catch (Throwable) {
            // Ignore cache save errors.
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fetchLatestRelease(string $currentVersion, ?Client $apiClient = null): array
    {
        try {
            $release = ($apiClient ?? ApiClient::getInstance())->getLatestPluginRelease(self::PLATFORM);
        } catch (Throwable $e) {
            return [
                'update_available' => false,
                'current_version' => $currentVersion,
                'error' => 'Failed to fetch release information from Comfino API: ' . $e->getMessage(),
                'checked_at' => time(),
            ];
        }

        return [
            'update_available' => version_compare($release->version, $currentVersion, '>'),
            'current_version' => $currentVersion,
            'github_version' => $release->version,
            'download_url' => $release->downloadUrl,
            'release_notes_url' => $release->releaseUrl,
            'description_html' => $release->descriptionHtml,
            'checked_at' => time(),
        ];
    }
}
