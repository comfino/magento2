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

namespace Comfino\Tests\Model\Adminhtml\System\Config\Source;

use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Settings\LanguageProviderInterface;
use Comfino\Backend\Settings\SettingsManager as SdkSettingsManager;
use Comfino\ComfinoGateway\Model\Adminhtml\System\Config\Source\WidgetOfferType;
use Comfino\Enum\ProductListType;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Platform\PlatformInfoInterface;
use Comfino\Tests\Support\ConfigManagerHarness;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionProperty;

/**
 * WidgetOfferType delegates to SettingsManager::getProductTypesSelectList(ProductListType::WIDGET), which calls the
 * (final) SDK SettingsManager singleton. The SDK class cannot be doubled, so a real instance is built through
 * getInstance() with mocked PSR collaborators, and its in-memory productTypesCache is pre-seeded under the
 * widget/language cache key so getProductTypes() returns deterministically without any API call.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class WidgetOfferTypeTest extends TestCase
{
    protected function tearDown(): void
    {
        SdkSettingsManager::reset();
        ConfigManagerHarness::reset();

        foreach (['httpClient', 'requestFactory', 'streamFactory', 'platformInfo', 'languageProvider'] as $property) {
            (new ReflectionProperty(SdkBootstrap::class, $property))->setValue(null, null);
        }

        parent::tearDown();
    }

    private function seedCacheMiss(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        CacheManager::init($pool);
    }

    private function makeLanguageProvider(): LanguageProviderInterface
    {
        $languageProvider = $this->createMock(LanguageProviderInterface::class);
        $languageProvider->method('getLanguage')->willReturn('pl');

        return $languageProvider;
    }

    private function seedBootstrap(LanguageProviderInterface $languageProvider): PlatformInfoInterface
    {
        $platformInfo = $this->createMock(PlatformInfoInterface::class);
        $platformInfo->method('getCode')->willReturn('MG');

        (new ReflectionProperty(SdkBootstrap::class, 'platformInfo'))->setValue(null, $platformInfo);
        (new ReflectionProperty(SdkBootstrap::class, 'httpClient'))->setValue(null, $this->createMock(ClientInterface::class));
        (new ReflectionProperty(SdkBootstrap::class, 'requestFactory'))->setValue(null, $this->createMock(RequestFactoryInterface::class));
        (new ReflectionProperty(SdkBootstrap::class, 'streamFactory'))->setValue(null, $this->createMock(StreamFactoryInterface::class));
        (new ReflectionProperty(SdkBootstrap::class, 'languageProvider'))->setValue(null, $languageProvider);

        return $platformInfo;
    }

    private function buildSdk(LanguageProviderInterface $languageProvider, PlatformInfoInterface $platformInfo, string $apiKey): SdkSettingsManager
    {
        return SdkSettingsManager::getInstance(
            $languageProvider,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class),
            $platformInfo,
            null,
            $apiKey,
            false,
            null
        );
    }

    public function testReturnsWidgetOfferTypesFromSdkSettingsManager(): void
    {
        ConfigManagerHarness::install([]);

        $languageProvider = $this->makeLanguageProvider();
        $platformInfo = $this->seedBootstrap($languageProvider);
        $sdk = $this->buildSdk($languageProvider, $platformInfo, 'api-key');

        $cacheKey = 'product_types.' . ProductListType::WIDGET->value . '.pl';
        (new ReflectionProperty(SdkSettingsManager::class, 'productTypesCache'))->setValue(
            $sdk,
            [$cacheKey => ['CONVENIENT_INSTALLMENTS' => 'Raty miesięczne', 'PAY_LATER' => 'Płatność za 30 dni']]
        );

        $this->assertSame(
            [
                ['value' => 'CONVENIENT_INSTALLMENTS', 'label' => 'Raty miesięczne'],
                ['value' => 'PAY_LATER', 'label' => 'Płatność za 30 dni'],
            ],
            (new WidgetOfferType())->toOptionArray()
        );
    }

    public function testReturnsEmptySentinelWhenApiUnavailable(): void
    {
        /* An empty API key makes getProductTypes() return null after a cache miss, so the SDK emits the single
           empty-value sentinel, which the Magento layer wraps in a translation. */
        ConfigManagerHarness::install([]);
        $this->seedCacheMiss();

        $languageProvider = $this->makeLanguageProvider();
        $platformInfo = $this->seedBootstrap($languageProvider);
        $this->buildSdk($languageProvider, $platformInfo, '');

        $options = (new WidgetOfferType())->toOptionArray();

        $this->assertCount(1, $options);
        $this->assertSame('', $options[0]['value']);
    }
}