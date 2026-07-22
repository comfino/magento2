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

namespace Magento\Catalog\Model\ResourceModel\Category {
    /* DI-generated factory not shipped in vendor; define a guarded stand-in so the SaleSettings dependency type
       resolves under unit tests. Every test injects a mock that overrides create(). */
    if (!class_exists(CollectionFactory::class, false)) {
        class CollectionFactory
        {
            public function create(): Collection
            {
                throw new \LogicException('stub');
            }
        }
    }
}

namespace Comfino\Tests\Block {

    use ArrayIterator;
    use Comfino\Api\Serializer\Json as JsonSerializer;
    use Comfino\Backend\Configuration\ConfigurationManager;
    use Comfino\Backend\Configuration\StorageAdapterInterface;
    use Comfino\ComfinoGateway\Block\SaleSettings;
    use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
    use Magento\Catalog\Model\ResourceModel\Category\Collection;
    use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
    use Magento\Framework\Data\Form\Element\AbstractElement;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;

    /**
     * SaleSettings::render() builds a per-product-type category tree and ends in toHtml(), which needs the full
     * Magento layout/asset stack. Only the early-return branch — "no financial product types available" — is reachable
     * in a pure unit test: with the SDK unbootstrapped, SettingsManager::getProductTypes() swallows the resulting
     * Throwable and returns [], so render() returns the placeholder row before reaching toHtml() or the asset
     * repository. The populated branch is covered by integration tests.
     */
    final class SaleSettingsTest extends TestCase
    {
        protected function tearDown(): void
        {
            ConfigurationManager::reset();
            (new ReflectionProperty(ConfigManager::class, 'configurationManager'))->setValue(null, null);

            parent::tearDown();
        }

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

        /**
         * Builds a SaleSettings instance without the Magento Field/Template constructor chain, injecting a category
         * collection factory whose collection yields the supplied flat rows.
         *
         * @param array<int, array{id: int, name: string, parent_id: int, position: int}> $rows
         */
        private function makeBlock(array $rows): SaleSettings
        {
            $items = [];

            foreach ($rows as $row) {
                $items[] = new \Magento\Framework\DataObject($row);
            }

            $collection = $this->createMock(Collection::class);
            $collection->method('addAttributeToFilter')->willReturnSelf();
            $collection->method('addAttributeToSelect')->willReturnSelf();
            $collection->method('setOrder')->willReturnSelf();
            $collection->method('getIterator')->willReturn(new ArrayIterator($items));

            $factory = $this->createMock(CollectionFactory::class);
            $factory->method('create')->willReturn($collection);

            $block = (new \ReflectionClass(SaleSettings::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(SaleSettings::class, 'collectionFactory'))->setValue($block, $factory);

            return $block;
        }

        private function makeElement(string $htmlId): AbstractElement
        {
            $element = $this->createMock(AbstractElement::class);
            $element->method('getHtmlId')->willReturn($htmlId);
            $element->method('getName')->willReturn('groups[x][fields][y][value]');

            return $element;
        }

        public function testRendersPlaceholderRowWhenNoProductTypesAvailable(): void
        {
            $this->seedConfig([]);

            $html = $this->makeBlock([
                ['id' => 10, 'name' => 'Electronics', 'parent_id' => 2, 'position' => 1],
            ])->render($this->makeElement('the_field_id'));

            $this->assertStringContainsString('row_the_field_id', $html);
            $this->assertStringContainsString('colspan="5"', $html);
            $this->assertStringContainsString('No financial product types available', $html);
        }
    }
}