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
    /* The DI-generated Magento factory class is not shipped in vendor; define a minimal stand-in in its own namespace
       so the BuildStrategy constructor type-hint resolves under unit tests. The body is irrelevant — every test
       injects a mock that overrides create(). */
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

namespace Comfino\Tests\Model\CategoryTree {

    use ArrayIterator;
    use Comfino\ComfinoGateway\Model\CategoryTree\BuildStrategy;
    use Comfino\Shop\Product\CategoryTree\Descriptor;
    use Comfino\Shop\Product\CategoryTree\Node;
    use Magento\Catalog\Model\ResourceModel\Category\Collection;
    use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
    use Magento\Framework\DataObject;
    use Magento\Framework\Exception\LocalizedException;
    use PHPUnit\Framework\TestCase;

    /**
     * getNestedCategories() memoizes its result in a method-local static that is shared for the whole process and
     * cannot be reset between tests. Each data-driven test therefore runs in a separate process so it builds the tree
     * from its own mocked collection without seeing another test's cached result.
     */
    final class BuildStrategyTest extends TestCase
    {
        /**
         * Builds a category collection mock that yields the supplied flat rows when iterated.
         *
         * @param array<int, array{id: int, name: string, parent_id: int, position: int}> $rows
         */
        private function makeCollection(array $rows): Collection
        {
            $items = [];

            foreach ($rows as $row) {
                $items[] = new DataObject([
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'parent_id' => $row['parent_id'],
                    'position' => $row['position'],
                ]);
            }

            $collection = $this->createMock(Collection::class);
            $collection->method('addAttributeToFilter')->willReturnSelf();
            $collection->method('addAttributeToSelect')->willReturnSelf();
            $collection->method('setOrder')->willReturnSelf();
            $collection->method('getIterator')->willReturn(new ArrayIterator($items));

            return $collection;
        }

        /**
         * @param array<int, array{id: int, name: string, parent_id: int, position: int}> $rows
         */
        private function makeStrategy(array $rows): BuildStrategy
        {
            $factory = $this->createMock(CollectionFactory::class);
            $factory->method('create')->willReturn($this->makeCollection($rows));

            return new BuildStrategy($factory);
        }

        /**
         * @runInSeparateProcess
         * @preserveGlobalState disabled
         */
        public function testBuildReturnsDescriptorWithRootNodes(): void
        {
            $strategy = $this->makeStrategy([
                ['id' => 10, 'name' => 'Electronics', 'parent_id' => 2, 'position' => 1],
                ['id' => 11, 'name' => 'Clothing', 'parent_id' => 2, 'position' => 2],
            ]);

            $descriptor = $strategy->build();

            $this->assertInstanceOf(Descriptor::class, $descriptor);
            $this->assertCount(2, $descriptor->nodes);

            $names = [];
            foreach ($descriptor->nodes as $node) {
                $this->assertInstanceOf(Node::class, $node);
                $names[$node->getId()] = $node->getName();
            }

            $this->assertSame(['Electronics', 'Clothing'], array_values($names));
        }

        /**
         * @runInSeparateProcess
         * @preserveGlobalState disabled
         */
        public function testBuildNestsChildrenUnderParents(): void
        {
            $strategy = $this->makeStrategy([
                ['id' => 10, 'name' => 'Electronics', 'parent_id' => 2, 'position' => 1],
                ['id' => 20, 'name' => 'Phones', 'parent_id' => 10, 'position' => 1],
                ['id' => 21, 'name' => 'Laptops', 'parent_id' => 10, 'position' => 2],
            ]);

            $descriptor = $strategy->build();

            $this->assertCount(1, $descriptor->nodes);

            $root = null;
            foreach ($descriptor->nodes as $node) {
                $root = $node;
            }

            $this->assertNotNull($root);
            $this->assertTrue($root->hasChildren());

            $childIds = [];
            foreach ($root->getChildren() as $child) {
                $childIds[] = $child->getId();
            }

            $this->assertSame([20, 21], $childIds);
        }

        /**
         * @runInSeparateProcess
         * @preserveGlobalState disabled
         */
        public function testBuildMemoizesDescriptorAcrossCalls(): void
        {
            $strategy = $this->makeStrategy([
                ['id' => 10, 'name' => 'Electronics', 'parent_id' => 2, 'position' => 1],
            ]);

            $first = $strategy->build();
            $second = $strategy->build();

            $this->assertSame($first, $second);
        }

        /**
         * @runInSeparateProcess
         * @preserveGlobalState disabled
         */
        public function testBuildReturnsEmptyTreeOnLocalizedException(): void
        {
            $collection = $this->createMock(Collection::class);
            $collection->method('addAttributeToFilter')->willThrowException(new LocalizedException(__('boom')));

            $factory = $this->createMock(CollectionFactory::class);
            $factory->method('create')->willReturn($collection);

            $descriptor = (new BuildStrategy($factory))->build();

            $this->assertInstanceOf(Descriptor::class, $descriptor);
            $this->assertCount(0, $descriptor->nodes);
        }

        /**
         * @runInSeparateProcess
         * @preserveGlobalState disabled
         */
        public function testIndexContainsEveryBuiltNode(): void
        {
            $strategy = $this->makeStrategy([
                ['id' => 10, 'name' => 'Electronics', 'parent_id' => 2, 'position' => 1],
                ['id' => 20, 'name' => 'Phones', 'parent_id' => 10, 'position' => 1],
            ]);

            $descriptor = $strategy->build();

            $this->assertIsArray($descriptor->index);
            $this->assertArrayHasKey(10, $descriptor->index);
            $this->assertArrayHasKey(20, $descriptor->index);
        }
    }
}