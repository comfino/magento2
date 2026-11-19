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

namespace Comfino\Tests\Model\Configuration;

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\Constraint\ConfigValueConstraintInterface;
use Comfino\Backend\Configuration\Constraint\InvalidConfigValue;
use Comfino\Backend\Configuration\StorageAdapterInterface;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Configuration\RemoteValueConstraints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RemoteValueConstraintsTest extends TestCase
{
    /** @var array<string, ConfigValueConstraintInterface> */
    private array $constraints;

    protected function setUp(): void
    {
        parent::setUp();

        $this->constraints = RemoteValueConstraints::build(
            static fn (): array => ['shop.example.test', 'shop.example.test:8443'],
            static fn (): bool => false
        );
    }

    protected function tearDown(): void
    {
        ConfigurationManager::reset();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: mixed}>
     */
    public static function acceptedValueProvider(): array
    {
        return [
            'connect timeout clamped' => ['COMFINO_API_CONNECT_TIMEOUT', 60, 5],
            'transfer timeout clamped' => ['COMFINO_API_TIMEOUT', '300', 15],
            'zero attempts raised' => ['COMFINO_API_CONNECT_NUM_ATTEMPTS', 0, 1],
            'attempts lowered' => ['COMFINO_API_CONNECT_NUM_ATTEMPTS', 10, 3],
            'queue timeout clamped' => ['COMFINO_API_QUEUE_TIMEOUT', -5, 1],
            'boolean string' => ['COMFINO_DEBUG', 'true', true],
            'boolean zero' => ['COMFINO_IS_SANDBOX', '0', false],
            'empty CSS URL' => ['COMFINO_PAYWALL_CUSTOM_CSS_URL', '', ''],
            'CSS URL on the shop host' => ['COMFINO_PAYWALL_CUSTOM_CSS_URL', ' https://shop.example.test/a.css ', 'https://shop.example.test/a.css'],
            'CSS URL on the shop host and port' => ['COMFINO_WIDGET_CUSTOM_BANNER_CSS_URL', 'https://shop.example.test:8443/b.css', 'https://shop.example.test:8443/b.css'],
            'status list as string' => ['COMFINO_FORBIDDEN_STATUSES', 'RESIGN, CANCELLED_BY_SHOP', ['RESIGN', 'CANCELLED_BY_SHOP']],
            'empty status list' => ['COMFINO_IGNORED_STATUSES', [], []],
            'status map' => ['COMFINO_STATUS_MAP', ['ACCEPTED' => 'comfino_accepted'], ['ACCEPTED' => 'comfino_accepted']],
            'status map as JSON' => ['COMFINO_STATUS_MAP', '{"REJECTED":"comfino_rejected"}', ['REJECTED' => 'comfino_rejected']],
            'initial status' => ['COMFINO_INITIAL_ORDER_STATUS', 'comfino_created', 'comfino_created'],
            'widget offer types' => ['COMFINO_WIDGET_OFFER_TYPES', ['PAY_LATER', 'NEW_PRODUCT'], ['PAY_LATER', 'NEW_PRODUCT']],
            'remote flags' => ['COMFINO_REMOTE_FLAGS', 'PAYMENT_ITEM_PROMOTION,FLAG_2', ['PAYMENT_ITEM_PROMOTION', 'FLAG_2']],
        ];
    }

    #[DataProvider('acceptedValueProvider')]
    public function testAcceptsOrNormalizes(string $optionName, mixed $value, mixed $expected): void
    {
        $this->assertSame($expected, $this->constraints[$optionName]->apply($optionName, $value));
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function rejectedValueProvider(): array
    {
        return [
            'non-numeric timeout' => ['COMFINO_API_TIMEOUT', 'fast'],
            'ambiguous boolean' => ['COMFINO_DEBUG', 'maybe'],
            'array as boolean' => ['COMFINO_SERVICE_MODE', [1]],
            'CSS URL on another host' => ['COMFINO_PAYWALL_CUSTOM_CSS_URL', 'https://cdn.example.org/a.css'],
            'CSS URL over http' => ['COMFINO_WIDGET_CUSTOM_CALCULATOR_CSS_URL', 'http://shop.example.test/a.css'],
            'CSS URL with credentials' => ['COMFINO_PAYWALL_CUSTOM_CSS_URL', 'https://u:p@shop.example.test/a.css'],
            'CSS URL breaking out of an attribute' => ['COMFINO_PAYWALL_CUSTOM_CSS_URL', 'https://shop.example.test/a.css"onload="x'],
            'look-alike CSS host' => ['COMFINO_PAYWALL_CUSTOM_CSS_URL', 'https://shop.example.test.evil.example/a.css'],
            'unknown ComfinoPay status' => ['COMFINO_FORBIDDEN_STATUSES', 'RESIGN,FOO'],
            'status map to a core status' => ['COMFINO_STATUS_MAP', ['ACCEPTED' => 'complete']],
            'status map with an unknown key' => ['COMFINO_STATUS_MAP', ['FOO' => 'comfino_accepted']],
            'empty status map' => ['COMFINO_STATUS_MAP', []],
            'unknown initial status' => ['COMFINO_INITIAL_ORDER_STATUS', 'complete'],
            'malformed offer type' => ['COMFINO_WIDGET_OFFER_TYPES', ['pay_later<script>']],
            'malformed remote flag' => ['COMFINO_REMOTE_FLAGS', ['PROMO', 'promo"><img>']],
        ];
    }

    #[DataProvider('rejectedValueProvider')]
    public function testRejects(string $optionName, mixed $value): void
    {
        $this->expectException(InvalidConfigValue::class);

        $this->constraints[$optionName]->apply($optionName, $value);
    }

    public function testEveryRemotelyWritableBooleanIsStrict(): void
    {
        $optionTypes = array_merge(...array_values(ConfigManager::CONFIG_OPTIONS));
        $remoteBooleans = array_filter(
            ConfigManager::ACCESSIBLE_CONFIG_OPTIONS,
            static fn (string $optionName): bool => $optionTypes[$optionName] === ConfigurationManager::OPT_VALUE_TYPE_BOOL
        );

        $this->assertSame([], array_diff($remoteBooleans, array_keys($this->constraints)));
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: mixed, 3: array{0: int, 1: int, 2: int}}>
     */
    public static function timeoutProvider(): array
    {
        return [
            'defaults' => [3, 5, 3, [3, 5, 3]],
            'too large' => [30, 60, 9, [5, 15, 3]],
            'zero and garbage' => [0, 'x', null, [1, 2, 1]],
            'transfer not longer than connect' => [5, 5, 1, [5, 6, 1]],
        ];
    }

    /**
     * @param array{0: int, 1: int, 2: int} $expected
     */
    #[DataProvider('timeoutProvider')]
    public function testClampTimeouts(mixed $connect, mixed $transfer, mixed $attempts, array $expected): void
    {
        $this->assertSame($expected, RemoteValueConstraints::clampTimeouts($connect, $transfer, $attempts));
    }

    /**
     * A remote write through the SDK ConfigurationManager stores the valid values of
     * a batch and keeps the stored value of every rejected option.
     */
    public function testARemoteWriteKeepsTheStoredValueOfRejectedOptions(): void
    {
        $manager = ConfigurationManager::getInstance(
            array_merge(...array_values(ConfigManager::CONFIG_OPTIONS)),
            ConfigManager::ACCESSIBLE_CONFIG_OPTIONS,
            ConfigurationManager::OPT_SERIALIZE_ARRAYS,
            new class implements StorageAdapterInterface {
                public function load(): array
                {
                    return ['COMFINO_PAYWALL_CUSTOM_CSS_URL' => '', 'COMFINO_DEBUG' => false, 'COMFINO_API_TIMEOUT' => 5];
                }

                public function save(array $configurationOptions): void
                {
                }
            },
            new JsonSerializer()
        );
        $manager->setValueConstraints($this->constraints);

        $rejected = $manager->updateConfigurationOptions([
            'COMFINO_PAYWALL_CUSTOM_CSS_URL' => 'https://cdn.example.org/a.css',
            'COMFINO_DEBUG' => 'maybe',
            'COMFINO_API_TIMEOUT' => 120,
            'COMFINO_API_KEY' => 'not-remotely-writable',
        ]);

        $this->assertSame(['COMFINO_PAYWALL_CUSTOM_CSS_URL', 'COMFINO_DEBUG'], $rejected);
        $this->assertSame('', $manager->getConfigurationValue('COMFINO_PAYWALL_CUSTOM_CSS_URL'));
        $this->assertFalse($manager->getConfigurationValue('COMFINO_DEBUG'));
        $this->assertSame(15, $manager->getConfigurationValue('COMFINO_API_TIMEOUT'));
        $this->assertNull($manager->getConfigurationValue('COMFINO_API_KEY'));
    }
}
