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

namespace Comfino\Tests\Model\Config\Backend;

use Comfino\ComfinoGateway\Model\Config\Backend\OptionalAbsoluteUrl;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\Tests\Support\ConfigManagerHarness;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

/**
 * OptionalAbsoluteUrl allows an empty value but requires an https:// URL on the shop's domain otherwise. Built via
 * newInstanceWithoutConstructor() to bypass the Value constructor; see NumericFieldTest for the rationale.
 */
final class OptionalAbsoluteUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ConfigManagerHarness::install(['COMFINO_DEV_ENV_VARS' => false]);
        (new ReflectionProperty(ConfigManager::class, 'shopHosts'))->setValue(null, ['shop.example.test']);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(ConfigManager::class, 'shopHosts'))->setValue(null, null);
        ConfigManagerHarness::reset();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws ReflectionException
     */
    private function makeField(array $data): OptionalAbsoluteUrl
    {
        $field = (new ReflectionClass(OptionalAbsoluteUrl::class))->newInstanceWithoutConstructor();

        (new ReflectionProperty($field, '_data'))->setValue($field, $data);
        (new ReflectionProperty($field, '_eventManager'))->setValue($field, $this->createMock(ManagerInterface::class));

        return $field;
    }

    /**
     * @throws ReflectionException
     * @throws LocalizedException
     */
    public function testAcceptsEmptyValue(): void
    {
        $field = $this->makeField(['value' => '   ']);

        $this->assertSame($field, $field->beforeSave());
    }

    /**
     * @throws ReflectionException
     * @throws LocalizedException
     */
    public function testAcceptsValidAbsoluteUrl(): void
    {
        $field = $this->makeField(['value' => 'https://shop.example.test/media/paywall.css']);

        $this->assertSame($field, $field->beforeSave());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function foreignOrInsecureUrlProvider(): array
    {
        return [
            'another domain' => ['https://cdn.example.org/paywall.css'],
            'plain http' => ['http://shop.example.test/paywall.css'],
            'look-alike host' => ['https://shop.example.test.evil.example/paywall.css'],
        ];
    }

    /**
     * @throws ReflectionException
     */
    #[DataProvider('foreignOrInsecureUrlProvider')]
    public function testThrowsWhenUrlIsNotHttpsOnTheShopDomain(string $url): void
    {
        $field = $this->makeField(['value' => $url]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage("must be an https:// URL on your shop's domain.");

        $field->beforeSave();
    }

    /**
     * @throws ReflectionException
     */
    public function testThrowsWhenValueNotAValidUrl(): void
    {
        $field = $this->makeField(['value' => 'not a url']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"not a url" is not a valid URL.');

        $field->beforeSave();
    }

    /**
     * filter_var() accepts a hostless string such as "mailto:foo", but parse_url() reports no host, so the second guard fires.
     * @throws ReflectionException
     */
    public function testThrowsWhenUrlHasNoHost(): void
    {
        $field = $this->makeField(['value' => 'mailto:user@example.test']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be an absolute URL.');

        $field->beforeSave();
    }
}
