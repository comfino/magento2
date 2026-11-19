<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Model
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model;

use Comfino\Backend\Log\ErrorLogger;
use Comfino\Backend\Settings\LanguageProviderInterface;
use Comfino\ComfinoGateway\Model\Bootstrap;
use Comfino\Frontend\ThemeFamilyRules;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Platform\PlatformInfoInterface;
use Comfino\Tests\Support\ConfigManagerHarness;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;

/**
 * Bootstrap::init() guards itself with a method-local static, so each test runs in a separate process to start clean.
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

    private function makeBootstrap(?LoggerInterface $errorLogger = null): Bootstrap
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
            $storeManager,
            $this->makeAppState(),
            $errorLogger
        );
    }

    /**
     * App state reporting the frontend area, so init() skips the error-logging token claim (it would need the uninitialized ObjectManager).
     */
    private function makeAppState(): State
    {
        $appState = $this->createMock(State::class);
        $appState->method('getAreaCode')->willReturn(Area::AREA_FRONTEND);

        return $appState;
    }

    /**
     * Clears the SDK Bootstrap statics so we can observe whether a subsequent plugin init() re-runs SDK init().
     *
     * @throws ReflectionException
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
     * Errors get their own channel (the file the admin "Errors log" viewer reads), not the SDK's debug one.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @throws NoSuchEntityException
     */
    public function testInitGivesTheErrorLoggerItsOwnChannel(): void
    {
        $errorLogger = $this->createMock(LoggerInterface::class);

        $this->makeBootstrap($errorLogger)->init();

        $sdkErrorLogger = ErrorLogger::getInstance(
            $this->createMock(\Comfino\Api\ClientInterface::class),
            sys_get_temp_dir() . '/comfino_errors.log',
            'example.com',
            'Magento',
            'Comfino_ComfinoGateway',
            []
        );

        $this->assertSame($errorLogger, $sdkErrorLogger->getLogger());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @throws NoSuchEntityException
     * @throws ReflectionException
     */
    public function testInitIsIdempotentAndSkipsSecondInitialization(): void
    {
        $bootstrap = $this->makeBootstrap();
        $bootstrap->init();

        /* Wipe the SDK state; a guarded second init() must not re-populate it because the method-local static short
           circuits before reaching SdkBootstrap::init(). */
        $this->resetSdkBootstrapState();

        $bootstrap->init();

        $this->assertNull((new ReflectionClass(SdkBootstrap::class))->getProperty('httpClient')->getValue());
    }

    /**
     * A queued error report of a store without an API key must stay queued, not go out with another store's key.
     */
    public function testErrorReportOfAStoreWithoutAnApiKeyIsNotDeliveredWithAnotherKey(): void
    {
        ConfigManagerHarness::install(['COMFINO_API_KEY' => 'default-store-key']);
        ConfigManagerHarness::installForStore(6, ['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => '']);

        $factory = (new \ReflectionMethod(Bootstrap::class, 'tenantClientFactory'))->invoke(null);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $factory('store:6');
        } finally {
            ConfigManagerHarness::reset();
        }
    }
}
