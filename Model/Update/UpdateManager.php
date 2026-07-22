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
    private const CACHE_TTL = 86400; // 24 hours

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
        } catch (Throwable) {
            // Cache is not available - proceed without it.
        }

        $result = self::fetchLatestRelease($currentVersion, $apiClient);

        try {
            if ($cacheItem !== null) {
                $cacheItem->set($result);
                $cacheItem->expiresAfter(self::CACHE_TTL);
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
