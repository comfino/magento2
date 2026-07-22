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

namespace Comfino\Tests\Model\Config\Backend;

use Comfino\ComfinoGateway\Model\Config\Backend\OptionalAbsoluteUrl;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * OptionalAbsoluteUrl allows an empty value but requires a syntactically valid absolute URL otherwise. Built via
 * newInstanceWithoutConstructor() to bypass the Value constructor; see NumericFieldTest for the rationale.
 */
final class OptionalAbsoluteUrlTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     */
    private function makeField(array $data): OptionalAbsoluteUrl
    {
        $field = (new ReflectionClass(OptionalAbsoluteUrl::class))->newInstanceWithoutConstructor();

        (new ReflectionProperty($field, '_data'))->setValue($field, $data);
        (new ReflectionProperty($field, '_eventManager'))->setValue($field, $this->createMock(ManagerInterface::class));

        return $field;
    }

    public function testAcceptsEmptyValue(): void
    {
        $field = $this->makeField(['value' => '   ']);

        $this->assertSame($field, $field->beforeSave());
    }

    public function testAcceptsValidAbsoluteUrl(): void
    {
        $field = $this->makeField(['value' => 'https://api.example.test/path']);

        $this->assertSame($field, $field->beforeSave());
    }

    public function testThrowsWhenValueNotAValidUrl(): void
    {
        $field = $this->makeField(['value' => 'not a url']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"not a url" is not a valid URL.');

        $field->beforeSave();
    }

    /**
     * filter_var() accepts a scheme-relative / hostless string such as "mailto:foo" as a valid URL, but parse_url()
     * reports no host, so the second guard fires.
     */
    public function testThrowsWhenUrlHasNoHost(): void
    {
        $field = $this->makeField(['value' => 'mailto:user@example.test']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be an absolute URL.');

        $field->beforeSave();
    }
}