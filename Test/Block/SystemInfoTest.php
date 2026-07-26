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

namespace Comfino\Tests\Block;

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\StorageAdapterInterface;
use Comfino\ComfinoGateway\Block\SystemInfo;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Platform\PlatformInfoInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionClass;
use ReflectionProperty;

/**
 * SystemInfo::render() assembles diagnostic messages and ends in toHtml(); the rendered markup needs the full Magento
 * layout, so each test uses a subclass that overrides toHtml() to expose the assigned data instead. The block also
 * touches several SDK singletons (ApiClient, CacheManager, the SDK Bootstrap PSR stack) — these are seeded with mocks
 * so no network or cache is involved. The two API-key-absent branches are covered; the branches that call
 * isShopAccountActive() hit the live API and belong to integration tests.
 *
 * Each test runs in a separate process because ApiClient / CacheManager / ConfigManager / the SDK Bootstrap keep
 * process-wide static state that cannot otherwise be reset cleanly.
 */
final class SystemInfoTest extends TestCase
{
    /**
     * @param array<string, mixed> $values
     */
    private function seedConfig(array $values): void
    {
        $storage = new class ($values) implements StorageAdapterInterface {
            /** @param array<string, mixed> $values */
            public function __construct(private array $values)
            {
            }

            public function load(): array
            {
                return $this->values;
            }

            public function save(array $configurationOptions): void
            {
            }
        };

        $manager = ConfigurationManager::getInstance(
            array_merge(...array_values(ConfigManager::CONFIG_OPTIONS)),
            ConfigManager::ACCESSIBLE_CONFIG_OPTIONS,
            ConfigurationManager::OPT_SERIALIZE_ARRAYS,
            $storage,
            new JsonSerializer()
        );
        $manager->setDefaults(ConfigManager::getDefaultConfigurationValues());

        (new ReflectionProperty(ConfigManager::class, 'configurationManager'))->setValue(null, $manager);
    }

    private function seedSdkBootstrap(): void
    {
        $platformInfo = $this->createMock(PlatformInfoInterface::class);
        $platformInfo->method('getCode')->willReturn('MG');
        $platformInfo->method('getName')->willReturn('Magento');
        $platformInfo->method('getVersion')->willReturn('2.4.8');
        $platformInfo->method('getPhpVersion')->willReturn(PHP_VERSION);
        $platformInfo->method('getPluginVersion')->willReturn('4.0.0');
        $platformInfo->method('getDomain')->willReturn('shop.example.test');
        $platformInfo->method('getLanguage')->willReturn('pl');

        $request = $this->createMock(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        (new ReflectionProperty(SdkBootstrap::class, 'platformInfo'))->setValue(null, $platformInfo);
        (new ReflectionProperty(SdkBootstrap::class, 'httpClient'))->setValue(null, $this->createMock(ClientInterface::class));
        (new ReflectionProperty(SdkBootstrap::class, 'requestFactory'))->setValue(null, $requestFactory);
        (new ReflectionProperty(SdkBootstrap::class, 'streamFactory'))->setValue(null, $this->createMock(StreamFactoryInterface::class));
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
        $helper->method('getShopDomain')->willReturn('shop.example.test');

        return $helper;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderAndCapture(array $configValues): array
    {
        $this->seedConfig($configValues);
        $this->seedSdkBootstrap();
        $this->seedCacheHit();

        /* Reset the shared ApiClient singleton so each test builds it fresh from the seeded Bootstrap. */
        (new ReflectionProperty(ApiClient::class, 'apiClient'))->setValue(null, null);

        $helper = $this->makeHelper();

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

        (new ReflectionProperty(SystemInfo::class, 'helper'))->setValue($block, $helper);

        $element = $this->createMock(AbstractElement::class);

        $block->render($element);

        return $block->captured;
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testProductionModeWithoutApiKeyReportsMissingKey(): void
    {
        $data = $this->renderAndCapture([
            'COMFINO_IS_SANDBOX' => false,
            'COMFINO_API_KEY' => '',
        ]);

        $this->assertContains('Production API key not present.', $this->toStrings($data['error_messages']));
        $this->assertSame([], $data['success_messages']);
        $this->assertSame('4.0.0', $data['module_version']);
        $this->assertFalse($data['dev_env_active']);
        $this->assertIsArray($data['info_messages']);
        $this->assertNotEmpty($data['info_messages']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSandboxModeWithoutApiKeyReportsDevWarningAndMissingKey(): void
    {
        $data = $this->renderAndCapture([
            'COMFINO_IS_SANDBOX' => true,
            'COMFINO_SANDBOX_API_KEY' => '',
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
            'COMFINO_IS_SANDBOX' => false,
            'COMFINO_API_KEY' => '',
        ]);

        $info = implode("\n", $this->toStrings($data['info_messages']));

        $this->assertStringContainsString('4.0.0', $info);
        $this->assertStringContainsString('shop.example.test', $info);
        $this->assertStringContainsString('https://', $info);
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