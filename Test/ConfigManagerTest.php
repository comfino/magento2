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
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\StorageAdapterInterface;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\Enum\OrderStatus;
use Comfino\Shop\Order\StatusManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class ConfigManagerTest extends TestCase
{
    /** @var string[] Env vars the dev-override branches read; cleared between tests to keep them deterministic. */
    private const DEV_ENV_VARS = [
        'COMFINO_DEV_ENV',
        'COMFINO_DEV_API_HOST',
        'COMFINO_DEV_SDK_CDN_BASE_URL',
    ];

    protected function tearDown(): void
    {
        /* ConfigManager memoizes its ConfigurationManager facade in a static; reset both the facade and the
           SDK singleton so each test starts from a clean configuration state. */
        (new ReflectionProperty(ConfigManager::class, 'configurationManager'))->setValue(null, null);
        ConfigurationManager::reset();

        foreach (self::DEV_ENV_VARS as $envVar) {
            putenv($envVar);
        }

        parent::tearDown();
    }

    /**
     * Builds a real ConfigurationManager backed by an in-memory storage adapter and injects it into the
     * ConfigManager facade, so the static config getters can be exercised without Magento's ObjectManager.
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

    // --- CONFIG_OPTIONS structure ---

    public function testConfigOptionsContainsFiveGroups(): void
    {
        $this->assertCount(5, ConfigManager::CONFIG_OPTIONS);
        $this->assertArrayHasKey('payment_settings', ConfigManager::CONFIG_OPTIONS);
        $this->assertArrayHasKey('sale_settings', ConfigManager::CONFIG_OPTIONS);
        $this->assertArrayHasKey('widget_settings', ConfigManager::CONFIG_OPTIONS);
        $this->assertArrayHasKey('developer_settings', ConfigManager::CONFIG_OPTIONS);
        $this->assertArrayHasKey('hidden_settings', ConfigManager::CONFIG_OPTIONS);
    }

    public function testConfigOptionsValuesAreValidValueTypeConstants(): void
    {
        $validTypes = [
            ConfigurationManager::OPT_VALUE_TYPE_STRING,
            ConfigurationManager::OPT_VALUE_TYPE_BOOL,
            ConfigurationManager::OPT_VALUE_TYPE_INT,
            ConfigurationManager::OPT_VALUE_TYPE_FLOAT,
            ConfigurationManager::OPT_VALUE_TYPE_JSON,
            ConfigurationManager::OPT_VALUE_TYPE_STRING_ARRAY,
        ];

        foreach (ConfigManager::CONFIG_OPTIONS as $group => $options) {
            foreach ($options as $key => $type) {
                $this->assertContains($type, $validTypes, "Invalid value type for $group/$key");
            }
        }
    }

    // --- ACCESSIBLE_CONFIG_OPTIONS ---

    public function testAccessibleConfigOptionsExcludesApiKeys(): void
    {
        $accessible = ConfigManager::ACCESSIBLE_CONFIG_OPTIONS;

        $this->assertNotContains('COMFINO_API_KEY', $accessible);
        $this->assertNotContains('COMFINO_SANDBOX_API_KEY', $accessible);
    }

    public function testAccessibleConfigOptionsAreSubsetOfAllConfigOptionKeys(): void
    {
        $allKeys = array_keys(array_merge(...array_values(ConfigManager::CONFIG_OPTIONS)));

        foreach (ConfigManager::ACCESSIBLE_CONFIG_OPTIONS as $key) {
            $this->assertContains($key, $allKeys, "ACCESSIBLE_CONFIG_OPTIONS key '$key' not in CONFIG_OPTIONS");
        }
    }

    // --- getConfigurationValues() ---

    public function testGetConfigurationValuesReturnsEmptyArrayForUnknownGroup(): void
    {
        $this->assertSame([], ConfigManager::getConfigurationValues('nonexistent_group'));
    }

    // --- getDefaultConfigurationValues() ---

    public function testGetDefaultConfigurationValuesContainsExpectedKeys(): void
    {
        $defaults = ConfigManager::getDefaultConfigurationValues();

        foreach (
            [
                'COMFINO_MINIMAL_CART_AMOUNT',
                'COMFINO_IS_SANDBOX',
                'COMFINO_DEBUG',
                'COMFINO_WIDGET_ENABLED',
                'COMFINO_WIDGET_KEY',
                'COMFINO_INITIAL_ORDER_STATUS',
                'COMFINO_API_CONNECT_TIMEOUT',
                'COMFINO_API_TIMEOUT',
                'COMFINO_API_CONNECT_NUM_ATTEMPTS',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $defaults, "Missing default for $key");
        }
    }

    public function testDefaultBooleanOptionsAreBoolType(): void
    {
        $defaults = ConfigManager::getDefaultConfigurationValues();

        foreach (['COMFINO_IS_SANDBOX', 'COMFINO_DEBUG', 'COMFINO_WIDGET_ENABLED', 'COMFINO_SERVICE_MODE'] as $key) {
            $this->assertIsBool($defaults[$key], "Default for $key must be bool");
        }
    }

    public function testDefaultMinimalCartAmountIsPositive(): void
    {
        $this->assertGreaterThan(0, ConfigManager::getDefaultConfigurationValues()['COMFINO_MINIMAL_CART_AMOUNT']);
    }

    public function testDefaultTimeoutsArePositiveIntegers(): void
    {
        $defaults = ConfigManager::getDefaultConfigurationValues();

        $this->assertGreaterThan(0, $defaults['COMFINO_API_CONNECT_TIMEOUT']);
        $this->assertGreaterThan(0, $defaults['COMFINO_API_TIMEOUT']);
        $this->assertGreaterThan(0, $defaults['COMFINO_API_CONNECT_NUM_ATTEMPTS']);
    }

    public function testDefaultsKeysAreSubsetOfAllConfigOptionKeys(): void
    {
        $allKeys = array_keys(array_merge(...array_values(ConfigManager::CONFIG_OPTIONS)));

        foreach (array_keys(ConfigManager::getDefaultConfigurationValues()) as $defaultKey) {
            $this->assertContains($defaultKey, $allKeys, "Default key '$defaultKey' missing from CONFIG_OPTIONS");
        }
    }

    public function testApiKeysHaveNoDefaults(): void
    {
        $defaults = ConfigManager::getDefaultConfigurationValues();

        $this->assertArrayNotHasKey('COMFINO_API_KEY', $defaults);
        $this->assertArrayNotHasKey('COMFINO_SANDBOX_API_KEY', $defaults);
    }

    // --- getConfigurationValue() + boolean accessors ---

    public function testGetConfigurationValueReturnsStoredValue(): void
    {
        $this->primeConfig(['COMFINO_WIDGET_KEY' => 'stored_widget_key']);

        $this->assertSame('stored_widget_key', ConfigManager::getConfigurationValue('COMFINO_WIDGET_KEY'));
    }

    public function testGetConfigurationValueFallsBackToPassedDefaultForUnsetOption(): void
    {
        /* COMFINO_API_KEY has no registered default, so getOptionWithDefault() yields null and the passed
           default is returned. */
        $this->primeConfig([]);

        $this->assertSame('fallback', ConfigManager::getConfigurationValue('COMFINO_API_KEY', 'fallback'));
    }

    public function testBooleanAccessorsReflectStoredFlags(): void
    {
        $this->primeConfig([
            'COMFINO_IS_SANDBOX' => '1',
            'COMFINO_WIDGET_ENABLED' => '1',
            'COMFINO_DEBUG' => '1',
            'COMFINO_SERVICE_MODE' => '1',
            'COMFINO_USE_ORDER_REFERENCE' => '1',
        ]);

        $this->assertTrue(ConfigManager::isSandboxMode());
        $this->assertTrue(ConfigManager::isWidgetEnabled());
        $this->assertTrue(ConfigManager::isDebugMode());
        $this->assertTrue(ConfigManager::isServiceMode());
        $this->assertTrue(ConfigManager::isUseOrderReference());
    }

    public function testBooleanAccessorsFallBackToDefaultsWhenUnset(): void
    {
        $this->primeConfig([]);

        $this->assertFalse(ConfigManager::isSandboxMode());
        $this->assertFalse(ConfigManager::isWidgetEnabled());
        $this->assertFalse(ConfigManager::isDebugMode());
        $this->assertFalse(ConfigManager::isServiceMode());
        $this->assertFalse(ConfigManager::isUseOrderReference());
    }

    // --- API key selection ---

    public function testGetApiKeyReturnsProductionKeyOutsideSandbox(): void
    {
        $this->primeConfig([
            'COMFINO_IS_SANDBOX' => '0',
            'COMFINO_API_KEY' => 'prod_key',
            'COMFINO_SANDBOX_API_KEY' => 'sandbox_key',
        ]);

        $this->assertSame('prod_key', ConfigManager::getApiKey());
    }

    public function testGetApiKeyReturnsSandboxKeyInSandboxMode(): void
    {
        $this->primeConfig([
            'COMFINO_IS_SANDBOX' => '1',
            'COMFINO_API_KEY' => 'prod_key',
            'COMFINO_SANDBOX_API_KEY' => 'sandbox_key',
        ]);

        $this->assertSame('sandbox_key', ConfigManager::getApiKey());
    }

    public function testGetWidgetKeyReturnsStoredValue(): void
    {
        $this->primeConfig(['COMFINO_WIDGET_KEY' => 'wk']);

        $this->assertSame('wk', ConfigManager::getWidgetKey());
    }

    // --- useDevEnvVars() / getApiHost() / SDK script resolution ---

    public function testUseDevEnvVarsFalseWhenEnvFlagMissing(): void
    {
        $this->primeConfig(['COMFINO_DEV_ENV_VARS' => '1']);
        putenv('COMFINO_DEV_ENV');

        $this->assertFalse(ConfigManager::useDevEnvVars());
    }

    public function testUseDevEnvVarsFalseWhenConfigFlagDisabled(): void
    {
        $this->primeConfig(['COMFINO_DEV_ENV_VARS' => '0']);
        putenv('COMFINO_DEV_ENV=TRUE');

        $this->assertFalse(ConfigManager::useDevEnvVars());
    }

    public function testUseDevEnvVarsTrueWhenEnvAndConfigEnabled(): void
    {
        $this->primeConfig(['COMFINO_DEV_ENV_VARS' => '1']);
        putenv('COMFINO_DEV_ENV=TRUE');

        $this->assertTrue(ConfigManager::useDevEnvVars());
    }

    public function testGetApiHostReturnsNullWithoutDevOverride(): void
    {
        $this->primeConfig([]);

        $this->assertNull(ConfigManager::getApiHost());
    }

    public function testGetApiHostReturnsDevOverrideWhenEnabled(): void
    {
        $this->primeConfig(['COMFINO_DEV_ENV_VARS' => '1']);
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_API_HOST=https://api-ecommerce.craty.pl');

        $this->assertSame('https://api-ecommerce.craty.pl', ConfigManager::getApiHost());
    }

    public function testGetApiHostRejectsNonAllowListedDevOverride(): void
    {
        $this->primeConfig(['COMFINO_DEV_ENV_VARS' => '1']);
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_API_HOST=https://attacker.example.com');

        $this->assertNull(ConfigManager::getApiHost());
    }

    public function testGetSdkScriptUrlReturnsProductionUrlByDefault(): void
    {
        $this->primeConfig(['COMFINO_IS_SANDBOX' => '0']);

        $this->assertSame(
            'https://sdk.comfino.pl/sdk/v1/comfino-sdk.min.js',
            ConfigManager::getSdkScriptUrl()
        );
    }

    public function testGetSdkScriptUrlReturnsSandboxUrlInSandboxMode(): void
    {
        $this->primeConfig(['COMFINO_IS_SANDBOX' => '1']);

        $this->assertSame(
            'https://sdk.craty.pl/sdk/v1/comfino-sdk.min.js',
            ConfigManager::getSdkScriptUrl()
        );
    }

    public function testGetSdkScriptUrlUsesConfiguredScriptVersion(): void
    {
        $this->primeConfig(['COMFINO_IS_SANDBOX' => '0', 'COMFINO_SDK_SCRIPT_VERSION' => '2']);

        $this->assertSame(
            'https://sdk.comfino.pl/sdk/v2/comfino-sdk.min.js',
            ConfigManager::getSdkScriptUrl()
        );
    }

    public function testGetSdkScriptUrlHonorsDevOverride(): void
    {
        $this->primeConfig(['COMFINO_DEV_ENV_VARS' => '1']);
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_SDK_CDN_BASE_URL=https://widget.craty.pl');

        $this->assertSame(
            'https://widget.craty.pl/sdk/v1/comfino-sdk.min.js',
            ConfigManager::getSdkScriptUrl()
        );
    }

    public function testGetSdkScriptUrlIgnoresNonAllowListedDevOverride(): void
    {
        $this->primeConfig(['COMFINO_IS_SANDBOX' => '0', 'COMFINO_DEV_ENV_VARS' => '1']);
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_SDK_CDN_BASE_URL=https://dev-cdn.example');

        $this->assertSame(
            'https://sdk.comfino.pl/sdk/v1/comfino-sdk.min.js',
            ConfigManager::getSdkScriptUrl()
        );
    }

    public function testGetDefaultLogoUrlReturnsProductionOrSandboxHost(): void
    {
        $this->primeConfig(['COMFINO_IS_SANDBOX' => '0']);

        $this->assertSame(
            'https://sdk.comfino.pl/images/comfino/comfino_logo.svg',
            ConfigManager::getDefaultLogoUrl()
        );
    }

    public function testGetDefaultLogoUrlHonorsDevOverride(): void
    {
        $this->primeConfig(['COMFINO_DEV_ENV_VARS' => '1']);
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_SDK_CDN_BASE_URL=http://sdk-comfino.test:8081');

        $this->assertSame(
            'http://sdk-comfino.test:8081/images/comfino/comfino_logo.svg',
            ConfigManager::getDefaultLogoUrl()
        );
    }

    public function testGetDefaultLogoUrlIgnoresNonAllowListedDevOverride(): void
    {
        $this->primeConfig(['COMFINO_IS_SANDBOX' => '0', 'COMFINO_DEV_ENV_VARS' => '1']);
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_SDK_CDN_BASE_URL=https://attacker.example');

        $this->assertSame(
            'https://sdk.comfino.pl/images/comfino/comfino_logo.svg',
            ConfigManager::getDefaultLogoUrl()
        );
    }

    // --- status accessors ---

    public function testGetIgnoredStatusesReturnsStoredArray(): void
    {
        $this->primeConfig(['COMFINO_IGNORED_STATUSES' => 'FOO,BAR']);

        $this->assertSame(['FOO', 'BAR'], ConfigManager::getIgnoredStatuses());
    }

    public function testGetIgnoredStatusesFallsBackToSdkDefaults(): void
    {
        $this->primeConfig([]);

        $expected = array_map(
            static fn ($status): string => $status->getValue(),
            StatusManager::DEFAULT_IGNORED_STATUSES
        );

        $this->assertSame($expected, ConfigManager::getIgnoredStatuses());
    }

    public function testGetForbiddenStatusesReturnsStoredArray(): void
    {
        $this->primeConfig(['COMFINO_FORBIDDEN_STATUSES' => 'X,Y,Z']);

        $this->assertSame(['X', 'Y', 'Z'], ConfigManager::getForbiddenStatuses());
    }

    public function testGetForbiddenStatusesFallsBackToSdkDefaults(): void
    {
        $this->primeConfig([]);

        $expected = array_map(
            static fn ($status): string => $status->getValue(),
            StatusManager::DEFAULT_FORBIDDEN_STATUSES
        );

        $this->assertSame($expected, ConfigManager::getForbiddenStatuses());
    }

    public function testGetStatusMapReturnsStoredJsonMap(): void
    {
        $this->primeConfig(['COMFINO_STATUS_MAP' => '{"ACCEPTED":"processing"}']);

        $this->assertSame(['ACCEPTED' => 'processing'], ConfigManager::getStatusMap());
    }

    public function testGetStatusMapFallsBackToDefaultWhenUnset(): void
    {
        $this->primeConfig([]);

        $this->assertNotEmpty(ConfigManager::getStatusMap());
    }

    public function testGetInitialOrderStatusReturnsStoredValue(): void
    {
        $this->primeConfig(['COMFINO_INITIAL_ORDER_STATUS' => 'custom_status']);

        $this->assertSame('custom_status', ConfigManager::getInitialOrderStatus());
    }

    public function testGetInitialOrderStatusFallsBackToCreatedMapping(): void
    {
        $this->primeConfig([]);

        /* The default applied by setDefaults already maps to the CREATED custom status, so the stored default
           and the fallback expression resolve to the same non-empty status code. */
        $this->assertNotSame('', ConfigManager::getInitialOrderStatus());
    }

    // --- getConfigurationValues() ---

    public function testGetConfigurationValuesReturnsWholeGroupWhenNoSubsetRequested(): void
    {
        $this->primeConfig(['COMFINO_WIDGET_ENABLED' => '1', 'COMFINO_WIDGET_KEY' => 'k']);

        $values = ConfigManager::getConfigurationValues('widget_settings');

        $this->assertArrayHasKey('COMFINO_WIDGET_ENABLED', $values);
        $this->assertArrayHasKey('COMFINO_WIDGET_KEY', $values);
        $this->assertTrue($values['COMFINO_WIDGET_ENABLED']);
    }

    public function testGetConfigurationValuesReturnsOnlyRequestedSubset(): void
    {
        $this->primeConfig(['COMFINO_WIDGET_ENABLED' => '1', 'COMFINO_WIDGET_KEY' => 'k']);

        $values = ConfigManager::getConfigurationValues('widget_settings', ['COMFINO_WIDGET_KEY']);

        $this->assertSame(['COMFINO_WIDGET_KEY'], array_keys($values));
        $this->assertSame('k', $values['COMFINO_WIDGET_KEY']);
    }

    // --- getInstance() facade memoization ---

    public function testGetInstanceReturnsInjectedFacade(): void
    {
        $this->primeConfig([]);

        $first = ConfigManager::getInstance();

        $this->assertInstanceOf(ConfigurationManager::class, $first);
        $this->assertSame($first, ConfigManager::getInstance());
    }

    // --- CREATED status default is a real, non-empty mapping ---

    public function testCreatedStatusDefaultIsNonEmpty(): void
    {
        $this->assertArrayHasKey(OrderStatus::CREATED->value, ['CREATED' => 1]);
        $this->assertNotSame('', ConfigManager::getDefaultConfigurationValues()['COMFINO_INITIAL_ORDER_STATUS']);
    }

    // --- Widget banner target selector is theme-family-aware ---

    /**
     * On Hyva, a shop left on any of the Luma defaults (or with no selector at all) gets the Hyva add-to-cart-form
     * selector so the banner lands in the buy box instead of the page bottom.
     */
    public function testResolveWidgetTargetSelectorSubstitutesHyvaDefault(): void
    {
        $resolve = new ReflectionMethod(ConfigManager::class, 'resolveWidgetTargetSelector');

        foreach (['div.product-info-main', 'div.product-add-form', '', null] as $configured) {
            $this->assertSame(
                '#product_addtocart_form',
                $resolve->invoke(null, $configured, 'hyva'),
                sprintf('Hyva default should replace configured value %s', var_export($configured, true))
            );
        }
    }

    /**
     * A merchant's explicitly customized selector is never overridden, whatever the theme family.
     */
    public function testResolveWidgetTargetSelectorKeepsCustomSelectorOnHyva(): void
    {
        $resolve = new ReflectionMethod(ConfigManager::class, 'resolveWidgetTargetSelector');

        $this->assertSame('.my-custom-anchor', $resolve->invoke(null, '.my-custom-anchor', 'hyva'));
    }

    /**
     * Non-Hyva families (luma, custom, unknown/null) keep the stored selector untouched.
     */
    public function testResolveWidgetTargetSelectorLeavesNonHyvaFamiliesUntouched(): void
    {
        $resolve = new ReflectionMethod(ConfigManager::class, 'resolveWidgetTargetSelector');

        $this->assertSame('div.product-info-main', $resolve->invoke(null, 'div.product-info-main', 'luma'));
        $this->assertSame('div.product-info-main', $resolve->invoke(null, 'div.product-info-main', 'custom'));
        $this->assertSame('div.product-info-main', $resolve->invoke(null, 'div.product-info-main', null));
    }
}
