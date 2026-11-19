<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Block\Adminhtml
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Block\Adminhtml;

use Comfino\ComfinoGateway\Block\Adminhtml\ApiKeyField;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

final class ApiKeyFieldTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    private function render(string $elementHtml): string
    {
        $element = $this->createMock(AbstractElement::class);
        $element->method('getElementHtml')->willReturn($elementHtml);

        $block = (new ReflectionClass(ApiKeyField::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod($block, '_getElementHtml'))->invoke($block, $element);
    }

    /**
     * @throws ReflectionException
     */
    public function testTheInputGetsTheNewPasswordAutocompleteHint(): void
    {
        $html = $this->render('<input id="api_key" name="groups[api_key]" type="password" value="******"/><span>x</span>');

        $this->assertStringContainsString('<input autocomplete="new-password" id="api_key"', $html);
        $this->assertSame(1, substr_count($html, 'autocomplete='));
    }

    /**
     * @throws ReflectionException
     */
    public function testMarkupWithoutAnInputIsReturnedUnchanged(): void
    {
        $this->assertSame('<div>none</div>', $this->render('<div>none</div>'));
    }
}
