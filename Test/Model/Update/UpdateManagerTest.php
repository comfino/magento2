<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Update;

use Comfino\Api\Client;
use Comfino\Api\Response\GetLatestPluginRelease;
use Comfino\Backend\Cache\CacheManager;
use Comfino\ComfinoGateway\Model\Update\UpdateManager;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * UpdateManager::checkForUpdates resolves the latest release through the centralized Comfino release API instead of
 * polling GitHub. The Comfino API client is injected as a mock (checkForUpdates accepts an optional client override),
 * and the PSR-6 cache pool is seeded via CacheManager::init so no real network or cache is touched.
 */
final class UpdateManagerTest extends TestCase
{
    /**
     * Initializes CacheManager with a pool whose single item never hits, so checkForUpdates falls through to the
     * API path. set()/save() are accepted but irrelevant to assertions.
     */
    private function seedMissingCache(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnSelf();

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);
        $pool->method('save')->willReturn(true);

        CacheManager::init($pool);
    }

    /**
     * Builds a GetLatestPluginRelease response DTO without invoking its PSR-response constructor, setting the readonly
     * public fields exercised by UpdateManager directly.
     *
     * @param array<string, mixed> $fields
     */
    private function makeRelease(array $fields): GetLatestPluginRelease
    {
        $reflection = new ReflectionClass(GetLatestPluginRelease::class);
        $release = $reflection->newInstanceWithoutConstructor();

        foreach ($fields as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setValue($release, $value);
        }

        return $release;
    }

    public function testReturnsCachedResultOnHit(): void
    {
        $cached = [
            'update_available' => true,
            'current_version' => '4.0.0',
            'github_version' => '4.1.0',
        ];

        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($cached);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        CacheManager::init($pool);

        // A cache hit must short-circuit before the API client is ever consulted.
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('getLatestPluginRelease');

        $this->assertSame($cached, UpdateManager::checkForUpdates('4.0.0', $client));
    }

    public function testReportsUpdateAvailableWhenApiVersionIsNewer(): void
    {
        $this->seedMissingCache();

        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('getLatestPluginRelease')
            ->with('magento')
            ->willReturn($this->makeRelease([
                'platform' => 'magento',
                'version' => '4.1.0',
                'releaseUrl' => 'https://github.com/comfino/magento2/releases/tag/4.1.0',
                'downloadUrl' => 'https://github.com/comfino/magento2/releases/download/4.1.0/comfino.zip',
                'descriptionHtml' => '<p>What\'s new</p>',
            ]));

        $result = UpdateManager::checkForUpdates('4.0.0', $client);

        $this->assertTrue($result['update_available']);
        $this->assertSame('4.0.0', $result['current_version']);
        $this->assertSame('4.1.0', $result['github_version']);
        $this->assertSame('https://github.com/comfino/magento2/releases/tag/4.1.0', $result['release_notes_url']);
        $this->assertSame('https://github.com/comfino/magento2/releases/download/4.1.0/comfino.zip', $result['download_url']);
        $this->assertSame('<p>What\'s new</p>', $result['description_html']);
        $this->assertArrayHasKey('checked_at', $result);
    }

    public function testReportsNoUpdateWhenApiVersionIsNotNewer(): void
    {
        $this->seedMissingCache();

        $client = $this->createMock(Client::class);
        $client->method('getLatestPluginRelease')->willReturn($this->makeRelease([
            'platform' => 'magento',
            'version' => '4.0.0',
            'releaseUrl' => 'https://github.com/comfino/magento2/releases/tag/4.0.0',
            'downloadUrl' => null,
            'descriptionHtml' => null,
        ]));

        $result = UpdateManager::checkForUpdates('4.0.0', $client);

        $this->assertFalse($result['update_available']);
        $this->assertSame('4.0.0', $result['github_version']);
        $this->assertSame('https://github.com/comfino/magento2/releases/tag/4.0.0', $result['release_notes_url']);
    }

    public function testReportsErrorWhenApiClientThrows(): void
    {
        $this->seedMissingCache();

        $client = $this->createMock(Client::class);
        $client->method('getLatestPluginRelease')->willThrowException(new RuntimeException('connection refused'));

        $result = UpdateManager::checkForUpdates('4.0.0', $client);

        $this->assertFalse($result['update_available']);
        $this->assertStringContainsString('Failed to fetch release information from Comfino API', $result['error']);
        $this->assertStringContainsString('connection refused', $result['error']);
    }
}