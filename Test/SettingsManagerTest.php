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

namespace Comfino\Tests;

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\StorageAdapterInterface;
use Comfino\Backend\Payment\ProductTypeFilterManager;
use Comfino\Backend\Settings\SettingsManager as SdkSettingsManager;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Configuration\SettingsManager;
use Comfino\Enum\LoanTypeInterface;
use Comfino\Enum\ProductListType;
use Comfino\Backend\Settings\LanguageProviderInterface;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Platform\PlatformInfoInterface;
use Comfino\Shop\Cart;
use Comfino\Tests\Support\LoggerHarnessTrait;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

final class SettingsManagerTest extends TestCase
{
    use LoggerHarnessTrait;

    protected function tearDown(): void
    {
        // The static $filterManagers cache leaks between tests; reset so later tests see a fresh state.
        (new ReflectionProperty(SettingsManager::class, 'filterManagers'))->setValue(null, []);

        /* getFilterManager() clones the shared ProductTypeFilterManager singleton, and several methods read
           config through ConfigManager; reset all of those static singletons so tests stay isolated. */
        ProductTypeFilterManager::reset();
        (new ReflectionProperty(ConfigManager::class, 'configurationManager'))->setValue(null, null);
        ConfigurationManager::reset();

        /* SDK-backed tests below seed the SDK SettingsManager singleton, the SDK Bootstrap PSR stack, and the
           CacheManager pool; reset them all so they never leak into other tests. */
        SdkSettingsManager::reset();
        CacheManager::reset();

        foreach (['httpClient', 'requestFactory', 'streamFactory', 'platformInfo', 'languageProvider'] as $name) {
            (new ReflectionProperty(SdkBootstrap::class, $name))->setValue(null, null);
        }

        parent::tearDown();
    }

    /**
     * Seeds the SDK Bootstrap PSR stack with mocks and primes the CacheManager with a hit for the product-types and
     * widget-types cache keys, so SettingsManager::sdk() resolves and the SDK returns the seeded lists from the cache 
     * without any API call. The language provider returns 'pl', matching the cache keys built below.
     *
     * When $cacheHits is false, the pool misses every key, so with an empty API key the SDK returns its error
     * sentinel instead of a list.
     *
     * @param array<string, string> $productTypes Map of product-type code => display name (PAYWALL list).
     * @param array<string, string> $widgetTypes  Map of widget-type code => display name.
     */
    private function primeSdk(array $productTypes, array $widgetTypes = [], bool $cacheHits = true): void
    {
        $languageProvider = $this->createMock(LanguageProviderInterface::class);
        $languageProvider->method('getLanguage')->willReturn('pl');

        (new ReflectionProperty(SdkBootstrap::class, 'httpClient'))
            ->setValue(null, $this->createMock(PsrClientInterface::class));
        (new ReflectionProperty(SdkBootstrap::class, 'requestFactory'))
            ->setValue(null, $this->createMock(RequestFactoryInterface::class));
        (new ReflectionProperty(SdkBootstrap::class, 'streamFactory'))
            ->setValue(null, $this->createMock(StreamFactoryInterface::class));
        (new ReflectionProperty(SdkBootstrap::class, 'platformInfo'))
            ->setValue(null, $this->createMock(PlatformInfoInterface::class));
        (new ReflectionProperty(SdkBootstrap::class, 'languageProvider'))
            ->setValue(null, $languageProvider);

        $cached = [
            'product_types.' . ProductListType::PAYWALL->value . '.pl' => $productTypes,
            'product_types.' . ProductListType::WIDGET->value . '.pl' => $productTypes,
            'widget_types.pl' => $widgetTypes,
        ];

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturnCallback(
            function (string $key) use ($cached, $cacheHits): CacheItemInterface {
                $item = $this->createMock(CacheItemInterface::class);
                $isHit = $cacheHits && array_key_exists($key, $cached);
                $item->method('isHit')->willReturn($isHit);
                $item->method('get')->willReturn($isHit ? $cached[$key] : null);
                $item->method('set')->willReturnSelf();
                $item->method('expiresAfter')->willReturnSelf();

                return $item;
            }
        );
        $pool->method('save')->willReturn(true);

        CacheManager::init($pool);
    }

    /**
     * Injects a real ConfigurationManager backed by an in-memory storage adapter into the ConfigManager facade
     * so SettingsManager's config reads resolve deterministically without Magento's ObjectManager.
     *
     * @param array<string, mixed> $storedValues
     */
    private function primeConfig(array $storedValues): void
    {
        $storage = new class ($storedValues) implements StorageAdapterInterface {
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
                $this->values = array_merge($this->values, $configurationOptions);
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

    // --- productCategoryFiltersActive() ---

    public function testFiltersNotActiveWhenInputIsEmpty(): void
    {
        $this->assertFalse(SettingsManager::productCategoryFiltersActive([]));
    }

    public function testFiltersNotActiveWhenAllCategoryIdListsAreEmpty(): void
    {
        $this->assertFalse(SettingsManager::productCategoryFiltersActive([
            'INSTALLMENTS_ZERO_PERCENT' => [],
            'PAY_LATER' => [],
        ]));
    }

    public function testFiltersActiveWhenAtLeastOneCategoryListIsNonEmpty(): void
    {
        $this->assertTrue(SettingsManager::productCategoryFiltersActive([
            'INSTALLMENTS_ZERO_PERCENT' => [],
            'PAY_LATER' => [10, 20],
        ]));
    }

    public function testFiltersActiveWhenAllCategoryListsAreNonEmpty(): void
    {
        $this->assertTrue(SettingsManager::productCategoryFiltersActive([
            'INSTALLMENTS_ZERO_PERCENT' => [5],
            'PAY_LATER' => [10],
        ]));
    }

    public function testFiltersActiveWithSingleEntryHavingCategories(): void
    {
        $this->assertTrue(SettingsManager::productCategoryFiltersActive([
            'COMPANY_BNPL' => [42],
        ]));
    }

    public function testFiltersActiveAcceptsStringCommaListValue(): void
    {
        /* getProductCategoryFilters() falls back to a comma-split string when COMFINO_PRODUCT_CATEGORY_FILTERS is
           persisted as a string instead of an array. productCategoryFiltersActive() must still treat non-empty
           string-array values as active to avoid silently disabling the filter after a config migration. */
        $this->assertTrue(SettingsManager::productCategoryFiltersActive([
            'INSTALLMENTS_ZERO_PERCENT' => ['10', '20'],
        ]));
    }

    // --- $filterManagers static cache ---

    public function testFilterManagersStaticCacheIsResettableViaReflection(): void
    {
        /* Regression guard: getFilterManager() memoizes per-listType in a static array, so any test that exercises
           it must reset the static between runs (this class does so in tearDown). If a future refactor renames
           the property or changes its shape, this assertion will fail and force the tearDown to be updated, rather
           than silently leaking stale managers into other tests. */
        $property = new ReflectionProperty(SettingsManager::class, 'filterManagers');

        $this->assertTrue($property->isStatic(), 'filterManagers must remain static for tearDown reset to work.');

        $sentinel = new stdClass();

        $property->setValue(null, ['PAYWALL' => $sentinel]);

        $this->assertSame(['PAYWALL' => $sentinel], $property->getValue());

        $property->setValue(null, []);

        $this->assertSame([], $property->getValue());
    }

    public function testSettingsManagerExposesGetAllowedProductTypesEntryPoints(): void
    {
        /* Pins the public surface that the paywall block (Block/Payment/Comfino) and the widget block
           (Block/Widget/Init) call into. A rename or signature change here would break BOTH renderers silently
           (paywall iframe + product-page widget banner), so this test guards the contract. */
        $class = new ReflectionClass(SettingsManager::class);

        $this->assertTrue($class->hasMethod('getAllowedProductTypes'));

        $method = $class->getMethod('getAllowedProductTypes');

        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());

        $params = $method->getParameters();

        $this->assertCount(3, $params);
        $this->assertSame('listType', $params[0]->getName());
        $this->assertSame('cart', $params[1]->getName());
        $this->assertSame('returnOnlyArray', $params[2]->getName());
        $this->assertTrue($params[2]->isDefaultValueAvailable());
        $this->assertFalse($params[2]->getDefaultValue());

        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull(), 'null return signals "no restriction" to renderers.');
    }

    // --- SDK delegation error paths (Bootstrap uninitialized -> Throwable swallowed) ---

    public function testGetCreditorsReturnsEmptyArrayWhenSdkUnavailable(): void
    {
        $this->primeConfig([]);

        $this->assertSame([], SettingsManager::getCreditors());
    }

    public function testGetAllowedProductsConfigReturnsNullWhenSdkUnavailable(): void
    {
        $this->primeConfig([]);

        $this->assertNull(SettingsManager::getAllowedProductsConfig());
    }

    public function testGetAllowedProductsConfigForFrontendReturnsNullWhenSdkUnavailable(): void
    {
        $this->primeConfig([]);

        $this->assertNull(SettingsManager::getAllowedProductsConfigForFrontend());
    }

    public function testGetProductTypesReturnsEmptyArrayByDefaultWhenSdkUnavailable(): void
    {
        $this->primeConfig([]);

        $this->assertSame([], SettingsManager::getProductTypes(ProductListType::PAYWALL->value));
    }

    public function testGetProductTypesReturnsErrorEntryWhenRequestedAndSdkUnavailable(): void
    {
        $this->primeConfig([]);

        $result = SettingsManager::getProductTypes(ProductListType::PAYWALL->value, true);

        $this->assertArrayHasKey('error', $result);
        $this->assertNotSame('', $result['error']);
    }

    public function testGetProductTypesStringsReturnsEmptyArrayWhenSdkUnavailable(): void
    {
        $this->primeConfig([]);

        $this->assertSame([], SettingsManager::getProductTypesStrings(ProductListType::PAYWALL->value));
    }

    public function testGetProductTypesEnumsReturnsEmptyArrayWhenSdkUnavailable(): void
    {
        $this->primeConfig([]);

        $this->assertSame([], SettingsManager::getProductTypesEnums(ProductListType::WIDGET->value));
    }

    public function testGetWidgetTypesReturnsEmptyArrayByDefaultWhenSdkUnavailable(): void
    {
        $this->primeConfig([]);

        $this->assertSame([], SettingsManager::getWidgetTypes());
    }

    public function testGetWidgetTypesReturnsErrorEntryWhenRequestedAndSdkUnavailable(): void
    {
        $this->primeConfig([]);

        $result = SettingsManager::getWidgetTypes(true);

        $this->assertArrayHasKey('error', $result);
    }

    public function testGetCatFilterAvailProdTypesReturnsEmptyArrayWhenNoProductTypes(): void
    {
        /* getProductTypes() returns [] when the SDK is unavailable, so the method short-circuits before any
           intersection with the configured available types. */
        $this->primeConfig([]);

        $this->assertSame([], SettingsManager::getCatFilterAvailProdTypes());
    }

    // --- config-backed list readers ---

    public function testGetProductCategoryFiltersReturnsStoredJsonMap(): void
    {
        $this->primeConfig(['COMFINO_PRODUCT_CATEGORY_FILTERS' => '{"PAY_LATER":[10,20]}']);

        $this->assertSame(['PAY_LATER' => [10, 20]], SettingsManager::getProductCategoryFilters());
    }

    public function testGetProductCategoryFiltersSplitsEmptyStringDefaultFallback(): void
    {
        /* The default for COMFINO_PRODUCT_CATEGORY_FILTERS is an empty string. Since it is not an array the
           reader takes the comma-split fallback branch, which on an empty string yields a single empty element.
           productCategoryFiltersActive() then treats this as inactive. */
        $this->primeConfig([]);

        $filters = SettingsManager::getProductCategoryFilters();

        $this->assertSame([''], $filters);
        $this->assertFalse(SettingsManager::productCategoryFiltersActive($filters));
    }

    public function testGetProductCategoryFiltersAvailProductTypesReturnsArray(): void
    {
        $this->primeConfig(['COMFINO_CAT_FILTER_AVAIL_PROD_TYPES' => 'PAY_LATER,COMPANY_BNPL']);

        $this->assertSame(['PAY_LATER', 'COMPANY_BNPL'], SettingsManager::getProductCategoryFiltersAvailProductTypes());
    }

    public function testGetProductCategoryFiltersAvailProductTypesFallsBackToDefault(): void
    {
        /* Unset value resolves to the COMFINO_CAT_FILTER_AVAIL_PROD_TYPES default, which is stored as a
           comma-separated string and exposed here as a string-array. */
        $this->primeConfig([]);

        $this->assertContains('PAY_LATER', SettingsManager::getProductCategoryFiltersAvailProductTypes());
    }

    // --- getAllowedProductTypes() ---

    public function testGetAllowedProductTypesReturnsNullWhenNoFiltersActive(): void
    {
        /* Zero minimal cart amount and no category filters means buildFiltersList() produces no filters, so the
           manager reports no active filters and the method signals "no restriction" with null. */
        $this->primeConfig([
            'COMFINO_MINIMAL_CART_AMOUNT' => '0',
            'COMFINO_PRODUCT_CATEGORY_FILTERS' => '',
            'COMFINO_DEBUG' => '0',
        ]);

        $cart = $this->createMock(Cart::class);

        $this->assertNull(SettingsManager::getAllowedProductTypes(ProductListType::PAYWALL->value, $cart));
    }

    public function testGetAllowedProductTypesReturnsArrayWhenCartValueFilterActive(): void
    {
        /* A positive minimal cart amount installs a FilterByCartValueLowerLimit, so filters are active. With the
           SDK unavailable, the available product-type list is empty, the filtered list is therefore also empty,
           and returnOnlyArray short-circuits to that empty array (never null). */
        $this->primeConfig([
            'COMFINO_MINIMAL_CART_AMOUNT' => '30',
            'COMFINO_PRODUCT_CATEGORY_FILTERS' => '',
            'COMFINO_DEBUG' => '0',
        ]);

        $cart = $this->createMock(Cart::class);

        $this->assertSame(
            [],
            SettingsManager::getAllowedProductTypes(ProductListType::PAYWALL->value, $cart, true)
        );
    }

    public function testGetAllowedProductTypesReturnsNullWhenCountsMatchWithoutReturnOnlyArray(): void
    {
        $this->primeConfig([
            'COMFINO_MINIMAL_CART_AMOUNT' => '30',
            'COMFINO_PRODUCT_CATEGORY_FILTERS' => '',
            'COMFINO_DEBUG' => '0',
        ]);

        $cart = $this->createMock(Cart::class);

        /* available and allowed are both empty, so their counts match and the method returns null. */
        $this->assertNull(SettingsManager::getAllowedProductTypes(ProductListType::PAYWALL->value, $cart, false));
    }

    public function testGetFilterManagerMemoizesPerListType(): void
    {
        $this->primeConfig([
            'COMFINO_MINIMAL_CART_AMOUNT' => '30',
            'COMFINO_PRODUCT_CATEGORY_FILTERS' => '',
            'COMFINO_DEBUG' => '0',
        ]);

        $cart = $this->createMock(Cart::class);

        SettingsManager::getAllowedProductTypes(ProductListType::PAYWALL->value, $cart, true);

        $managers = (new ReflectionProperty(SettingsManager::class, 'filterManagers'))->getValue();

        $this->assertArrayHasKey(ProductListType::PAYWALL->value, $managers);
        $this->assertInstanceOf(ProductTypeFilterManager::class, $managers[ProductListType::PAYWALL->value]);
    }

    // --- SDK-backed success paths (product/widget types resolved from a primed cache) ---

    public function testGetProductTypesReturnsCachedMapWhenSdkAvailable(): void
    {
        $this->primeConfig([]);
        $this->primeSdk(['PAY_LATER' => 'Pay later', 'INSTALLMENTS_ZERO_PERCENT' => '0% installments']);

        $this->assertSame(
            ['PAY_LATER' => 'Pay later', 'INSTALLMENTS_ZERO_PERCENT' => '0% installments'],
            SettingsManager::getProductTypes(ProductListType::PAYWALL->value)
        );
    }

    public function testGetProductTypesStringsReturnsKeysWhenSdkAvailable(): void
    {
        $this->primeConfig([]);
        $this->primeSdk(['PAY_LATER' => 'Pay later', 'COMPANY_BNPL' => 'BNPL']);

        $this->assertSame(
            ['PAY_LATER', 'COMPANY_BNPL'],
            SettingsManager::getProductTypesStrings(ProductListType::PAYWALL->value)
        );
    }

    public function testGetProductTypesEnumsMapsKeysToLoanTypesWhenSdkAvailable(): void
    {
        $this->primeConfig([]);
        $this->primeSdk(['PAY_LATER' => 'Pay later']);

        $enums = SettingsManager::getProductTypesEnums(ProductListType::PAYWALL->value);

        $this->assertCount(1, $enums);
        $this->assertInstanceOf(LoanTypeInterface::class, $enums[0]);
    }

    public function testGetWidgetTypesReturnsCachedMapWhenSdkAvailable(): void
    {
        $this->primeConfig([]);
        $this->primeSdk([], ['standard' => 'Standard banner', 'mini' => 'Mini']);

        $this->assertSame(
            ['standard' => 'Standard banner', 'mini' => 'Mini'],
            SettingsManager::getWidgetTypes()
        );
    }

    public function testGetCatFilterAvailProdTypesIntersectsWithConfiguredTypes(): void
    {
        $this->primeConfig(['COMFINO_CAT_FILTER_AVAIL_PROD_TYPES' => 'PAY_LATER']);
        $this->primeSdk(['PAY_LATER' => 'Pay later', 'COMPANY_BNPL' => 'BNPL']);

        $this->assertSame(['PAY_LATER' => 'Pay later'], SettingsManager::getCatFilterAvailProdTypes());
    }

    public function testGetCatFilterAvailProdTypesFallsBackToAllWhenIntersectionEmpty(): void
    {
        /* The configured available type is not among the SDK product types, so the intersection is empty and the
           method falls back to the full product-type map. */
        $this->primeConfig(['COMFINO_CAT_FILTER_AVAIL_PROD_TYPES' => 'NOT_PRESENT']);
        $this->primeSdk(['PAY_LATER' => 'Pay later', 'COMPANY_BNPL' => 'BNPL']);

        $this->assertSame(
            ['PAY_LATER' => 'Pay later', 'COMPANY_BNPL' => 'BNPL'],
            SettingsManager::getCatFilterAvailProdTypes()
        );
    }

    public function testGetProductTypesSelectListMapsCachedTypes(): void
    {
        $this->primeConfig([]);
        $this->primeSdk(['PAY_LATER' => 'Pay later', 'COMPANY_BNPL' => 'BNPL']);

        $this->assertSame(
            [
                ['value' => 'PAY_LATER', 'label' => 'Pay later'],
                ['value' => 'COMPANY_BNPL', 'label' => 'BNPL'],
            ],
            SettingsManager::getProductTypesSelectList(ProductListType::PAYWALL->value)
        );
    }

    public function testGetProductTypesSelectListWrapsApiKeyErrorSentinel(): void
    {
        /* No cache hit, and an empty API key make the SDK return its single empty-value sentinel; the wrapper passes
           the label through Magento translation while preserving the empty value used by required-entry validation. */
        $this->primeConfig([]);
        $this->primeSdk([], [], false);

        $result = SettingsManager::getProductTypesSelectList(ProductListType::PAYWALL->value);

        $this->assertCount(1, $result);
        $this->assertSame('', $result[0]['value']);
        $this->assertNotSame('', $result[0]['label']);
    }

    public function testGetWidgetTypesSelectListMapsCachedTypes(): void
    {
        $this->primeConfig([]);
        $this->primeSdk([], ['standard' => 'Standard banner']);

        $this->assertSame(
            [['value' => 'standard', 'label' => 'Standard banner']],
            SettingsManager::getWidgetTypesSelectList()
        );
    }

    public function testGetWidgetTypesSelectListWrapsApiKeyErrorSentinel(): void
    {
        $this->primeConfig([]);
        $this->primeSdk([], [], false);

        $result = SettingsManager::getWidgetTypesSelectList();

        $this->assertCount(1, $result);
        $this->assertSame('', $result[0]['value']);
        $this->assertNotSame('', $result[0]['label']);
    }

    public function testGetAllowedProductTypesLogsInDebugModeWithCartValueFilter(): void
    {
        /* Debug mode on + a positive minimal cart amount installs a FilterByCartValueLowerLimit and exercises the
           debug-logging block of getAllowedProductTypes. Product types are primed from cache so the available list is
           non-empty and the filter manager actually narrows it. The category-filter branch is intentionally left out
           because ConfigManager::getCategoriesTree() requires the Magento ObjectManager. */
        $this->primeConfig([
            'COMFINO_MINIMAL_CART_AMOUNT' => '30',
            'COMFINO_PRODUCT_CATEGORY_FILTERS' => '',
            'COMFINO_DEBUG' => '1',
            'COMFINO_SERVICE_MODE' => '0',
        ]);
        $this->primeSdk(['PAY_LATER' => 'Pay later', 'COMPANY_BNPL' => 'BNPL']);
        $this->installLoggerHarness();

        $cart = $this->createMock(Cart::class);

        $result = SettingsManager::getAllowedProductTypes(ProductListType::PAYWALL->value, $cart, true);

        $this->assertIsArray($result);

        $this->resetLoggerHarness();
    }
}
