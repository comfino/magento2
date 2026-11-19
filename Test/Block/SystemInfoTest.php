<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Block;

use Comfino\Api\Client;
use Comfino\Backend\Cache\CacheManager;
use Comfino\ComfinoGateway\Block\SystemInfo;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Http\Psr17Factory;
use Comfino\ComfinoGateway\Model\Configuration\ScopedApiCredentials;
use Comfino\ComfinoGateway\Model\Queue\QueueHealth;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Tests\Support\ConfigManagerHarness;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\RequestInterface as AppRequestInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionException;
use ReflectionProperty;

/**
 * SystemInfo::render() assembles diagnostic messages and ends in toHtml(), so each test uses a subclass that exposes the assigned
 * data. SDK singletons are seeded with mocks; the account check goes to a mocked PSR-18 transport that records each request.
 *
 * The panel reports on the scope picked in the admin scope switcher, read through a scope config mock keyed by 'default',
 * 'websites/<id>' or 'stores/<id>'. Each test runs in a separate process because the SDK keeps static state.
 */
final class SystemInfoTest extends TestCase
{
    /** @var list<array{apiKey: string, baseUrl: string}> Requests that reached the mocked API transport. */
    private array $apiRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        /* The API host override is read from the environment; run without it (the dev container sets it) unless a test
           opts in through enableDevApiHost(). */
        putenv('COMFINO_DEV_ENV');
        putenv('COMFINO_DEV_API_HOST');
    }

    /**
     * Seeds the shared ApiClient with a real SDK client over a mocked PSR-18 transport that accepts every key except the
     * rejected ones (401), recording each request's Api-Key and base URL.
     *
     * @param list<string> $rejectedKeys
     */
    private function seedApiClient(array $rejectedKeys): void
    {
        $psrClient = $this->createMock(ClientInterface::class);
        $psrClient->method('sendRequest')->willReturnCallback(
            function (RequestInterface $request) use ($rejectedKeys): Response {
                $apiKey = $request->getHeaderLine('Api-Key');
                $uri = $request->getUri();

                $this->apiRequests[] = [
                    'apiKey' => $apiKey,
                    'baseUrl' => $uri->getScheme() . '://' . $uri->getAuthority(),
                ];

                return in_array($apiKey, $rejectedKeys, true)
                    ? new Response(401, ['Content-Type' => 'application/json'], '{"message":"Invalid credentials."}')
                    : new Response(200, ['Content-Type' => 'application/json'], 'true');
            }
        );

        $factory = new Psr17Factory();

        (new ReflectionProperty(ApiClient::class, 'apiClient'))
            ->setValue(null, new Client($psrClient, $factory, $factory, 'seed-key'));
        (new ReflectionProperty(SdkBootstrap::class, 'httpClient'))->setValue(null, $psrClient);
    }

    /**
     * Seeds CacheManager with a cache hit so UpdateManager::checkForUpdates short-circuits without a GitHub request.
     */
    private function seedCacheHit(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn([
            'update_available' => false,
            'current_version' => '4.0.0',
        ]);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        CacheManager::init($pool);
    }

    private function makeHelper(): Data
    {
        $helper = $this->createMock(Data::class);
        $helper->method('getModuleVersion')->willReturn('4.0.0');
        $helper->method('getShopVersion')->willReturn('2.4.8');
        $helper->method('getDatabaseInfo')->willReturn('MariaDB 10.6');

        return $helper;
    }

    /**
     * @param array<string, array<string, mixed>> $valuesByScope Config values keyed by 'default', 'websites/<id>' or
     *                                                           'stores/<id>'; a path missing from a website/store
     *                                                           entry falls back to the default entry
     */
    private function makeScopeConfig(array $valuesByScope): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (
                string $path,
                string $scope = 'default',
                mixed $scopeCode = null
            ) use ($valuesByScope): mixed {
                $key = $scope === 'default' ? 'default' : $scope . '/' . $scopeCode;

                return $valuesByScope[$key][$path] ?? $valuesByScope['default'][$path] ?? null;
            }
        );

        return $scopeConfig;
    }

    private function makeStoreManager(): StoreManagerInterface
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function (mixed $storeId): StoreInterface {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn((int) $storeId);

            return $store;
        });
        $storeManager->method('getWebsite')->willReturnCallback(function (mixed $websiteId): WebsiteInterface {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn((int) $websiteId);

            return $website;
        });

        return $storeManager;
    }

    /**
     * Renders the block for the given admin scope-switcher selection and returns the data handed to the template.
     *
     * @param array<string, array<string, mixed>> $valuesByScope See makeScopeConfig()
     * @param list<string> $rejectedKeys API keys the mocked API answers with 401
     * @param int $overdueRequests Overdue queued requests reported by QueueHealth
     *
     * @return array<string, mixed>
     *
     * @throws ReflectionException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws Exception
     */
    private function renderAndCapture(
        array $valuesByScope,
        string $website = '',
        string $store = '',
        array $rejectedKeys = [],
        int $overdueRequests = 0
    ): array {
        ConfigManagerHarness::install([]);
        $this->seedApiClient($rejectedKeys);
        $this->seedCacheHit();

        $scopeConfig = $this->makeScopeConfig($valuesByScope);

        $request = $this->createMock(AppRequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $name, mixed $default = null): mixed => match ($name) {
                'website' => $website,
                'store' => $store,
                default => $default,
            }
        );

        $block = new class extends SystemInfo {
            /** @var array<string, mixed> */
            public array $captured = [];

            public function __construct()
            {
            }

            public function toHtml(): string
            {
                $this->captured = $this->getData();

                return '';
            }
        };

        (new ReflectionProperty(SystemInfo::class, 'helper'))->setValue($block, $this->makeHelper());
        (new ReflectionProperty(SystemInfo::class, 'apiCredentials'))
            ->setValue($block, new ScopedApiCredentials($scopeConfig, $this->makeStoreManager(), $this->createMock(EncryptorInterface::class)));
        $queueHealth = $this->createMock(QueueHealth::class);
        $queueHealth->method('getOverdueCount')->willReturn($overdueRequests);
        (new ReflectionProperty(SystemInfo::class, 'queueHealth'))->setValue($block, $queueHealth);
        (new ReflectionProperty($block, '_scopeConfig'))->setValue($block, $scopeConfig);
        (new ReflectionProperty($block, '_request'))->setValue($block, $request);

        $block->render($this->createMock(AbstractElement::class));

        return $block->captured;
    }

    /**
     * Turns the dev API host override on at the environment level, as in the local dev container.
     */
    private function enableDevApiHost(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_API_HOST=http://ecommerce');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testProductionModeWithoutApiKeyReportsMissingKey(): void
    {
        $data = $this->renderAndCapture([
            'default' => [Data::XML_PATH_SANDBOX_ENABLED => false, Data::XML_PATH_API_KEY => ''],
        ]);

        $this->assertContains('Production API key not present.', $this->toStrings($data['error_messages']));
        $this->assertSame([], $data['success_messages']);
        $this->assertSame('4.0.0', $data['module_version']);
        $this->assertFalse($data['dev_env_active']);
        $this->assertIsArray($data['info_messages']);
        $this->assertNotEmpty($data['info_messages']);
        $this->assertSame([], $this->apiRequests);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testWarnsAboutOverdueQueuedRequests(): void
    {
        $data = $this->renderAndCapture(
            ['default' => [Data::XML_PATH_SANDBOX_ENABLED => false, Data::XML_PATH_API_KEY => '']],
            overdueRequests: 5
        );

        $this->assertContains(
            'Queued requests waiting for more than 30 minutes: 5. Check that Magento cron is running.',
            $this->toStrings($data['warning_messages'])
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSandboxModeWithoutApiKeyReportsDevWarningAndMissingKey(): void
    {
        $data = $this->renderAndCapture([
            'default' => [Data::XML_PATH_SANDBOX_ENABLED => true, Data::XML_PATH_SANDBOX_API_KEY => ''],
        ]);

        $warnings = $this->toStrings($data['warning_messages']);
        $errors = $this->toStrings($data['error_messages']);

        $this->assertContains('Developer mode is active. You are using test environment.', $warnings);
        $this->assertContains('Test API key not present.', $errors);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInfoMessagesIncludeModuleVersionAndApiBaseUrl(): void
    {
        $data = $this->renderAndCapture([
            'default' => [
                Data::XML_PATH_SANDBOX_ENABLED => false,
                Data::XML_PATH_API_KEY => '',
                Store::XML_PATH_UNSECURE_BASE_URL => 'https://shop.example.test/',
            ],
        ]);

        $info = implode("\n", $this->toStrings($data['info_messages']));

        $this->assertStringContainsString('4.0.0', $info);
        $this->assertStringContainsString('shop.example.test', $info);
        $this->assertStringContainsString('https://', $info);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDefaultConfigScopeShowsSandboxHostAndChecksSandboxAccount(): void
    {
        $data = $this->renderAndCapture([
            'default' => [
                Data::XML_PATH_SANDBOX_ENABLED => true,
                Data::XML_PATH_SANDBOX_API_KEY => 'sandbox-key',
                Data::XML_PATH_WIDGET_KEY => 'sandbox-widget-key',
            ],
        ]);

        $this->assertSame(Client::SANDBOX_API_BASE_URL, $data['info_messages'][1]);
        $this->assertSame('sandbox-widget-key', $data['info_messages'][4]);
        $this->assertContains('Test account is active.', $this->toStrings($data['success_messages']));
        $this->assertSame(
            [['apiKey' => 'sandbox-key', 'baseUrl' => Client::SANDBOX_API_BASE_URL]],
            $this->apiRequests
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testShowsApiHostAccountWidgetKeyAndDomainOfSelectedWebsite(): void
    {
        /* Regression: the panel always reported the default store view (the backend's ambient store) — here a sandbox
           account on the standard sandbox host — even with a website with its own account on the dev API selected. */
        $this->enableDevApiHost();
        ConfigManagerHarness::installForScope(
            ScopeInterface::SCOPE_WEBSITES,
            2,
            ['COMFINO_SDK_SCRIPT_VERSION' => '7']
        );

        $data = $this->renderAndCapture(
            [
                'default' => [
                    Data::XML_PATH_SANDBOX_ENABLED => true,
                    Data::XML_PATH_SANDBOX_API_KEY => 'sandbox-key',
                    Data::XML_PATH_DEV_ENV_VARS => false,
                    Data::XML_PATH_WIDGET_KEY => 'sandbox-widget-key',
                    Store::XML_PATH_UNSECURE_BASE_URL => 'https://shop.example.test/',
                ],
                'websites/2' => [
                    Data::XML_PATH_SANDBOX_ENABLED => false,
                    Data::XML_PATH_API_KEY => 'website-2-key',
                    Data::XML_PATH_DEV_ENV_VARS => true,
                    Data::XML_PATH_WIDGET_KEY => 'website-2-widget-key',
                    Store::XML_PATH_UNSECURE_BASE_URL => 'http://second.shop.example.test:8086/',
                ],
            ],
            website: '2'
        );

        $this->assertSame('http://ecommerce', $data['info_messages'][1]);
        $this->assertSame('second.shop.example.test', $data['info_messages'][3]);
        $this->assertSame('website-2-widget-key', $data['info_messages'][4]);
        $this->assertContains('Production account is active.', $this->toStrings($data['success_messages']));
        $this->assertSame([['apiKey' => 'website-2-key', 'baseUrl' => 'http://ecommerce']], $this->apiRequests);
        $this->assertTrue($data['use_dev_env_vars']);
        $this->assertSame('7', $data['hidden_items']['COMFINO_SDK_SCRIPT_VERSION']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testChecksSelectedStoreViewAccountWithItsOwnKey(): void
    {
        $data = $this->renderAndCapture(
            [
                'default' => [Data::XML_PATH_SANDBOX_ENABLED => false, Data::XML_PATH_API_KEY => 'default-key'],
                'stores/5' => [Data::XML_PATH_API_KEY => 'rejected-key'],
            ],
            store: '5',
            rejectedKeys: ['rejected-key']
        );

        $this->assertContains('Invalid production API key.', $this->toStrings($data['error_messages']));
        $this->assertSame(['rejected-key'], array_column($this->apiRequests, 'apiKey'));
    }

    /**
     * Normalizes Magento Phrase objects (from __()) and plain strings to an array of strings for assertions.
     *
     * @param array<int, mixed> $messages
     * @return string[]
     */
    private function toStrings(array $messages): array
    {
        return array_map(static fn ($message): string => (string) $message, $messages);
    }
}
