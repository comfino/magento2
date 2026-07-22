<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Model
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model;

use Comfino\Backend\Settings\LanguageProviderInterface;
use Comfino\ComfinoGateway\Model\Bootstrap;
use Comfino\Frontend\ThemeFamilyRules;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Platform\PlatformInfoInterface;
use Comfino\Tests\Support\ConfigManagerHarness;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Bootstrap::init() guards itself with a method-local static, so each test runs in a separate process to start from a
 * clean (uninitialized) state. The plugin wrapper merely forwards its DI-injected PSR services to the SDK Bootstrap.
 */
final class BootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /* init() calls ConfigManager::refreshErrorLoggingTokenIfNeeded(); an empty API key makes it short-circuit at
           its first guard, so it never resolves an ApiClient through the (uninitialized) ObjectManager. */
        ConfigManagerHarness::install(['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => '']);
    }

    protected function tearDown(): void
    {
        ConfigManagerHarness::reset();

        parent::tearDown();
    }

    private function makeBootstrap(): Bootstrap
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new Bootstrap(
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(PlatformInfoInterface::class),
            $this->createMock(LanguageProviderInterface::class),
            new ThemeFamilyRules(),
            $this->createMock(ResourceConnection::class),
            $storeManager
        );
    }

    /**
     * Clears the SDK Bootstrap statics so we can observe whether a subsequent plugin init() re-runs SDK init().
     */
    private function resetSdkBootstrapState(): void
    {
        $ref = new ReflectionClass(SdkBootstrap::class);

        $props = ['httpClient', 'requestFactory', 'streamFactory', 'platformInfo', 'languageProvider', 'themeFamilyRules'];

        foreach ($props as $prop) {
            $ref->getProperty($prop)->setValue(null, null);
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInitWiresServicesIntoSdkBootstrap(): void
    {
        $this->makeBootstrap()->init();

        /* After init the SDK Bootstrap exposes the wired services and the theme family rules are registered. */
        $this->assertInstanceOf(ClientInterface::class, SdkBootstrap::getHttpClient());
        $this->assertSame('hyva', SdkBootstrap::getThemeFamilyRules()->resolveFamily(['hyva/default']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInitIsIdempotentAndSkipsSecondInitialization(): void
    {
        $bootstrap = $this->makeBootstrap();

        $bootstrap->init();
        $this->assertInstanceOf(ClientInterface::class, SdkBootstrap::getHttpClient());

        /* Wipe the SDK state; a guarded second init() must not re-populate it because the method-local static short
           circuits before reaching SdkBootstrap::init(). */
        $this->resetSdkBootstrapState();

        $bootstrap->init();

        $ref = new ReflectionClass(SdkBootstrap::class);
        $this->assertNull($ref->getProperty('httpClient')->getValue());
    }
}