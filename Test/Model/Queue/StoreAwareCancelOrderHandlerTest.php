<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Model\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Queue;

use Comfino\Api\Client;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Backend\Log\DebugLogger as SdkDebugLogger;
use Comfino\Backend\Settings\LanguageProviderInterface;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Logger\Debug;
use Comfino\ComfinoGateway\Model\Order\CancellationGuard;
use Comfino\ComfinoGateway\Model\Queue\StoreAwareCancelOrderHandler;
use Comfino\ComfinoGateway\Model\Queue\TenantKey;
use Comfino\Frontend\ThemeFamilyRules;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Platform\PlatformInfoInterface;
use Comfino\Tests\Support\ConfigManagerHarness;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Throwable;

/**
 * Regression cover: a cancellation enqueued by a store with its own API key must be resent with that store's credentials,
 * not the default-store ones the crontab area resolves.
 */
final class StoreAwareCancelOrderHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->initSdkBootstrap();
    }

    /**
     * @throws ReflectionException
     */
    protected function tearDown(): void
    {
        (new ReflectionProperty(ApiClient::class, 'minimalTimeoutClient'))->setValue(null, null);
        (new ReflectionProperty(Debug::class, 'debugLogger'))->setValue(null, null);
        (new ReflectionProperty(Debug::class, 'cookieChecker'))->setValue(null, null);

        $reflectionClass = new ReflectionClass(SdkBootstrap::class);

        foreach (['httpClient', 'requestFactory', 'streamFactory', 'platformInfo', 'languageProvider', 'themeFamilyRules'] as $prop) {
            $reflectionClass->getProperty($prop)->setValue(null, null);
        }

        ConfigManagerHarness::reset();
        SdkDebugLogger::reset();

        putenv('COMFINO_DEV_ENV');
        putenv('COMFINO_DEV_API_HOST');

        parent::tearDown();
    }

    private function initSdkBootstrap(): void
    {
        $platformInfo = $this->createMock(PlatformInfoInterface::class);
        $platformInfo->method('getCode')->willReturn('MG');
        $platformInfo->method('getName')->willReturn('Magento');
        $platformInfo->method('getVersion')->willReturn('2.4.7');
        $platformInfo->method('getLanguage')->willReturn('en');
        $platformInfo->method('getCurrency')->willReturn('PLN');
        $platformInfo->method('getDomain')->willReturn('shop.example');
        $platformInfo->method('getDatabaseVersion')->willReturn('8.0');
        $platformInfo->method('getPhpVersion')->willReturn(PHP_VERSION);
        $platformInfo->method('getPluginVersion')->willReturn('4.0.0');

        SdkBootstrap::init(
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createMock(LoggerInterface::class),
            $platformInfo,
            $this->createMock(LanguageProviderInterface::class),
            new ThemeFamilyRules()
        );
    }

    /**
     * Pre-seeds the cached minimal-timeout client, so the test can observe which key / sandbox flag the handler applied.
     */
    private function injectMinimalTimeoutClient(): Client&MockObject
    {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setApiKey', 'setCustomApiBaseUrl', 'enableSandboxMode', 'disableSandboxMode', 'cancelOrder'])
            ->getMock();

        (new ReflectionProperty(ApiClient::class, 'minimalTimeoutClient'))->setValue(null, $client);

        return $client;
    }

    public function testUsesTheApiKeyOfTheStoreRecordedInThePayload(): void
    {
        // Ambient scope (what cron would otherwise resolve) carries a different key.
        ConfigManagerHarness::install(['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => 'default-store-key']);
        ConfigManagerHarness::installForStore(7, ['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => 'store-7-key']);

        $client = $this->injectMinimalTimeoutClient();
        $client->expects($this->once())->method('setApiKey')->with('store-7-key');
        $client->expects($this->once())->method('disableSandboxMode');
        $client->expects($this->never())->method('enableSandboxMode');
        $client->expects($this->once())->method('cancelOrder')->with('100000042');

        (new StoreAwareCancelOrderHandler())->execute(['orderId' => '100000042', 'storeId' => 7]);
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     * @throws ClientExceptionInterface
     */
    public function testUsesTheApiHostOverrideOfTheStoreRecordedInThePayload(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_API_HOST=https://api-ecommerce.craty.pl');

        // The ambient store (default store view in cron) has no dev override; store 7 does.
        ConfigManagerHarness::install(['COMFINO_API_KEY' => 'default-store-key', 'COMFINO_DEV_ENV_VARS' => false]);
        ConfigManagerHarness::installForStore(7, ['COMFINO_API_KEY' => 'store-7-key', 'COMFINO_DEV_ENV_VARS' => true]);

        $client = $this->injectMinimalTimeoutClient();
        $client->expects($this->once())->method('setCustomApiBaseUrl')->with('https://api-ecommerce.craty.pl');
        $client->expects($this->once())->method('cancelOrder')->with('100000042');

        (new StoreAwareCancelOrderHandler())->execute(['orderId' => '100000042', 'storeId' => 7]);
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     * @throws ClientExceptionInterface
     */
    public function testResetsTheApiHostOverrideForAStoreWithoutIt(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_API_HOST=https://api-ecommerce.craty.pl');

        ConfigManagerHarness::install(['COMFINO_DEV_ENV_VARS' => true]);
        ConfigManagerHarness::installForStore(7, ['COMFINO_API_KEY' => 'store-7-key', 'COMFINO_DEV_ENV_VARS' => false]);

        // The shared client may still carry the override of a previously served store.
        $client = $this->injectMinimalTimeoutClient();
        $client->expects($this->once())->method('setCustomApiBaseUrl')->with(null);
        $client->expects($this->once())->method('cancelOrder')->with('100000042');

        (new StoreAwareCancelOrderHandler())->execute(['orderId' => '100000042', 'storeId' => 7]);
    }

    /**
     * Rows enqueued from 4.1.0 name their store in the tenant key; it wins over a payload storeId.
     *
     * @throws ClientExceptionInterface
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     */
    public function testUsesTheApiKeyOfTheStoreNamedByTheTenantKey(): void
    {
        ConfigManagerHarness::install(['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => 'default-store-key']);
        ConfigManagerHarness::installForStore(5, ['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => 'store-5-key']);
        ConfigManagerHarness::installForStore(7, ['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => 'store-7-key']);

        $client = $this->injectMinimalTimeoutClient();
        $client->expects($this->once())->method('setApiKey')->with('store-5-key');
        $client->expects($this->once())->method('cancelOrder')->with('100000050');

        (new StoreAwareCancelOrderHandler())->executeForTenant(['orderId' => '100000050', 'storeId' => 7], TenantKey::forStore(5));
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     * @throws ClientExceptionInterface
     */
    public function testATenantKeyWithoutAStoreFallsBackToThePayload(): void
    {
        ConfigManagerHarness::install(['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => 'default-store-key']);
        ConfigManagerHarness::installForStore(7, ['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => 'store-7-key']);

        $client = $this->injectMinimalTimeoutClient();
        $client->expects($this->once())->method('setApiKey')->with('store-7-key');
        $client->expects($this->once())->method('cancelOrder');

        (new StoreAwareCancelOrderHandler())->executeForTenant(['orderId' => '100000051', 'storeId' => 7], null);
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     * @throws ClientExceptionInterface
     */
    public function testUsesTheSandboxKeyAndHostWhenTheStoreRunsInSandboxMode(): void
    {
        ConfigManagerHarness::install(['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => 'default-store-key']);
        ConfigManagerHarness::installForStore(9, ['COMFINO_IS_SANDBOX' => true, 'COMFINO_SANDBOX_API_KEY' => 'store-9-sandbox-key']);

        $client = $this->injectMinimalTimeoutClient();
        $client->expects($this->once())->method('setApiKey')->with('store-9-sandbox-key');
        $client->expects($this->once())->method('enableSandboxMode');
        $client->expects($this->never())->method('disableSandboxMode');
        $client->expects($this->once())->method('cancelOrder')->with('9000000001');

        (new StoreAwareCancelOrderHandler())->execute(['orderId' => '9000000001', 'storeId' => 9]);
    }

    public function testFallsBackToAmbientScopeForLegacyPayloadsWithoutAStoreId(): void
    {
        ConfigManagerHarness::install(['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => 'ambient-key']);

        $client = $this->injectMinimalTimeoutClient();
        $client->expects($this->once())->method('setApiKey')->with('ambient-key');
        $client->expects($this->once())->method('cancelOrder')->with('55');

        // Rows enqueued before the fix carry no storeId - they must still be delivered, not dropped.
        (new StoreAwareCancelOrderHandler())->execute(['orderId' => '55']);
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     * @throws ClientExceptionInterface
     */
    public function testThrowsWithoutSendingWhenTheStoreHasNoApiKeyConfigured(): void
    {
        ConfigManagerHarness::install(['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => 'default-store-key']);
        ConfigManagerHarness::installForStore(3, ['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => '']);

        $client = $this->injectMinimalTimeoutClient();
        /* The whole point: it must NOT fall through to the default store's key, which would let the cancellation be
           accepted against the wrong Comfino account. */
        $client->expects($this->never())->method('cancelOrder');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No ComfinoPay API key is configured for store 3.');

        (new StoreAwareCancelOrderHandler())->execute(['orderId' => '77', 'storeId' => 3]);
    }

    /**
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testThrowsOnAnEmptyOrderId(): void
    {
        $client = $this->injectMinimalTimeoutClient();
        $client->expects($this->never())->method('cancelOrder');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty "orderId"');

        (new StoreAwareCancelOrderHandler())->execute(['storeId' => 7]);
    }

    /**
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testQueuedCancelOfAnOrderClosedAtComfinoPayIsNotSent(): void
    {
        ConfigManagerHarness::install(['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => 'default-store-key']);
        ConfigManagerHarness::installForStore(7, ['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => 'store-7-key']);

        $client = $this->injectMinimalTimeoutClient();
        $client->expects($this->never())->method('cancelOrder');

        $guard = $this->createMock(CancellationGuard::class);
        $guard->expects($this->once())->method('getQueuedCancelSuppressionReason')->with('100000042')
            ->willReturn(CancellationGuard::GUARD_TERMINAL);

        (new StoreAwareCancelOrderHandler($guard))->execute(['orderId' => '100000042', 'storeId' => 7]);
    }

    /**
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testQueuedCancelOfAnOpenOrderIsSentOnce(): void
    {
        ConfigManagerHarness::install(['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => 'default-store-key']);
        ConfigManagerHarness::installForStore(7, ['COMFINO_IS_SANDBOX' => false, 'COMFINO_API_KEY' => 'store-7-key']);

        $client = $this->injectMinimalTimeoutClient();
        $client->expects($this->once())->method('cancelOrder')->with('100000042');

        $guard = $this->createMock(CancellationGuard::class);
        $guard->method('getQueuedCancelSuppressionReason')->willReturn(null);

        (new StoreAwareCancelOrderHandler($guard))->execute(['orderId' => '100000042', 'storeId' => 7]);
    }
}
