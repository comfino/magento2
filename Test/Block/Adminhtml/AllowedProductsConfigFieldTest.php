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

namespace Comfino\Tests\Block\Adminhtml;

use Comfino\Backend\Payment\ProductTypeFilterManager;
use Comfino\ComfinoGateway\Block\Adminhtml\AllowedProductsConfigField;
use Comfino\ComfinoGateway\Model\Configuration\SettingsManager;
use Comfino\Tests\Support\ConfigManagerHarness;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Element\Template;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class AllowedProductsConfigFieldTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigManagerHarness::reset();
        (new ReflectionProperty(SettingsManager::class, 'filterManagers'))->setValue(null, []);
        ProductTypeFilterManager::reset();

        parent::tearDown();
    }

    /**
     * Subclass that bypasses the Magento Template constructor and overrides the SettingsManager lookups so render()
     * can be exercised without a live API or DI container. toHtml() is also stubbed because we assert on the assigned
     * _viewVars rather than rendered HTML.
     *
     * @param array<string, string> $productTypes
     * @param array<int, array<string, mixed>>|null $savedConfig
     */
    private function makeBlock(array $productTypes, ?array $savedConfig): AllowedProductsConfigField
    {
        $block = new class ($productTypes, $savedConfig) extends AllowedProductsConfigField {
            /** @param array<string, string> $productTypes */
            /** @param array<int, array<string, mixed>>|null $savedConfig */
            public function __construct(private readonly array $productTypes, private readonly ?array $savedConfig)
            {
            }

            protected function fetchProductTypes(): array
            {
                return $this->productTypes;
            }

            protected function fetchSavedConfig(): ?array
            {
                return $this->savedConfig;
            }

            protected function isFeatureEnabled(): bool
            {
                return true;
            }

            // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
            protected function _toHtml(): string
            {
                return '';
            }

            public function toHtml(): string
            {
                return '';
            }
        };

        /* Initialize the _viewVars storage that assign() writes into; the bypassed parent constructor would normally
           do this. */
        $prop = new ReflectionProperty(Template::class, '_viewVars');
        $prop->setValue($block, []);

        return $block;
    }

    private function makeElement(string $htmlId, string $name): AbstractElement
    {
        $element = $this->createMock(AbstractElement::class);
        $element->method('getHtmlId')->willReturn($htmlId);
        $element->method('getName')->willReturn($name);

        return $element;
    }

    /**
     * @return array<string, mixed>
     */
    private function getAssigned(AllowedProductsConfigField $block): array
    {
        return (new ReflectionProperty(Template::class, '_viewVars'))->getValue($block);
    }

    public function testAssignsOneProductTypeRowPerEntry(): void
    {
        $block = $this->makeBlock(
            ['PAY_LATER' => 'Płatność za 30 dni', 'CONVENIENT_INSTALLMENTS' => 'Raty miesięczne'],
            null,
        );

        $block->render($this->makeElement('field_id', 'field_name'));

        $vars = $this->getAssigned($block);

        $this->assertSame(
            ['PAY_LATER' => 'Płatność za 30 dni', 'CONVENIENT_INSTALLMENTS' => 'Raty miesięczne'],
            $vars['productTypes']
        );
        $this->assertSame([], $vars['savedByType']);
        $this->assertSame('', $vars['currentValueJson']);
        $this->assertFalse($vars['apiUnavailable']);
    }

    public function testIndexesSavedConfigByType(): void
    {
        $saved = [['type' => 'PAY_LATER', 'minTerm' => 3, 'maxTerm' => 6, 'terms' => [3, 6]]];
        $block = $this->makeBlock(['PAY_LATER' => 'Płatność za 30 dni'], $saved);

        $block->render($this->makeElement('field_id', 'field_name'));

        $vars = $this->getAssigned($block);

        $this->assertArrayHasKey('PAY_LATER', $vars['savedByType']);
        $this->assertSame($saved[0], $vars['savedByType']['PAY_LATER']);
        $this->assertSame(json_encode($saved), $vars['currentValueJson']);
    }

    public function testFlagsApiUnavailableWhenProductTypesEmpty(): void
    {
        $block = $this->makeBlock([], null);

        $block->render($this->makeElement('field_id', 'field_name'));

        $vars = $this->getAssigned($block);

        $this->assertTrue($vars['apiUnavailable']);
    }

    public function testSeedsHiddenInputFieldIdsFromElement(): void
    {
        $block = $this->makeBlock(['PAY_LATER' => 'Płatność za 30 dni'], null);

        $block->render($this->makeElement('the_html_id', 'groups[foo][fields][bar][value]'));

        $vars = $this->getAssigned($block);

        $this->assertSame('the_html_id', $vars['aggregateFieldId']);
        $this->assertSame('groups[foo][fields][bar][value]', $vars['aggregateFieldName']);
    }

    public function testSkipsSavedEntriesWithoutTypeKey(): void
    {
        $block = $this->makeBlock(
            ['PAY_LATER' => 'Płatność za 30 dni'],
            [['minTerm' => 3]], // malformed: no type
        );

        $block->render($this->makeElement('field_id', 'field_name'));

        $vars = $this->getAssigned($block);

        $this->assertSame([], $vars['savedByType']);
    }

    /**
     * Exercises the production isFeatureEnabled() early-return branch using a real (non-overridden) block. The feature
     * flag is read through ConfigManager, primed here as disabled, so render() returns '' before any SettingsManager
     * lookup.
     */
    public function testRenderReturnsEmptyStringWhenFeatureDisabled(): void
    {
        ConfigManagerHarness::install(['COMFINO_ALLOWED_PRODUCTS_CONFIG_ENABLED' => false]);

        $block = (new ReflectionClass(AllowedProductsConfigField::class))->newInstanceWithoutConstructor();

        $this->assertSame('', $block->render($this->makeElement('field_id', 'field_name')));
    }

    /**
     * Pins the production isFeatureEnabled() true branch (the flag resolves through ConfigManager).
     */
    public function testIsFeatureEnabledReadsConfigFlag(): void
    {
        ConfigManagerHarness::install(['COMFINO_ALLOWED_PRODUCTS_CONFIG_ENABLED' => true]);

        $block = (new ReflectionClass(AllowedProductsConfigField::class))->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(AllowedProductsConfigField::class, 'isFeatureEnabled');

        $this->assertTrue($method->invoke($block));
    }

    /**
     * The production fetchProductTypes() / fetchSavedConfig() delegate to SettingsManager, which degrades gracefully
     * to [] / null when the SDK is unavailable (no Bootstrap). This pins that fail-soft contract end to end.
     */
    public function testFetchHelpersDegradeGracefullyWhenSdkUnavailable(): void
    {
        ConfigManagerHarness::install([]);

        $block = (new ReflectionClass(AllowedProductsConfigField::class))->newInstanceWithoutConstructor();

        $fetchProductTypes = new ReflectionMethod(AllowedProductsConfigField::class, 'fetchProductTypes');
        $fetchSavedConfig = new ReflectionMethod(AllowedProductsConfigField::class, 'fetchSavedConfig');

        $this->assertSame([], $fetchProductTypes->invoke($block));
        $this->assertNull($fetchSavedConfig->invoke($block));
    }
}
