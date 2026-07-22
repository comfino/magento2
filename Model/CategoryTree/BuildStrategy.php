<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\CategoryTree
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\CategoryTree;

use Comfino\Shop\Product\Category;
use Comfino\Shop\Product\CategoryManager;
use Comfino\Shop\Product\CategoryTree\BuildStrategyInterface;
use Comfino\Shop\Product\CategoryTree\Descriptor;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Magento-specific implementation of CategoryTree build strategy.
 */
class BuildStrategy implements BuildStrategyInterface
{
    private ?Descriptor $descriptor = null;

    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    public function build(): Descriptor
    {
        if ($this->descriptor === null) {
            $this->descriptor = CategoryManager::buildCategoryTree($this->getNestedCategories());
        }

        return $this->descriptor;
    }

    /**
     * @return Category[]
     */
    private function getNestedCategories(): array
    {
        static $categories = null;

        if ($categories !== null) {
            return $categories;
        }

        try {
            $collection = $this->collectionFactory->create();
            $collection
                ->addAttributeToFilter('is_active', ['eq' => 1])
                ->addAttributeToFilter('level', ['gteq' => 2])
                ->addAttributeToSelect(['name', 'parent_id', 'position'])
                ->setOrder('level', 'ASC')
                ->setOrder('position', 'ASC');
        } catch (LocalizedException) {
            return [];
        }

        $flatList = [];
        $idSet = [];

        foreach ($collection as $item) {
            $id = (int) $item->getId();
            $flatList[$id] = [
                'id' => $id,
                'name' => (string) $item->getName(),
                'parent_id' => (int) $item->getParentId(),
                'position' => (int) $item->getPosition(),
            ];
            $idSet[$id] = true;
        }

        /* Detect root parent IDs: parents that are not in our fetched set (they are the excluded level-0/1
           system/store root categories). */
        $rootParentIds = [];

        foreach ($flatList as $entry) {
            if (!isset($idSet[$entry['parent_id']])) {
                $rootParentIds[$entry['parent_id']] = true;
            }
        }

        $categories = [];

        foreach (array_keys($rootParentIds) as $rootParentId) {
            foreach ($this->buildChildren($flatList, $rootParentId) as $category) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    /**
     * @param array<int, array<string, mixed>> $flatList
     *
     * @return Category[]
     */
    private function buildChildren(array $flatList, int $parentId): array
    {
        $children = [];

        foreach ($flatList as $entry) {
            if ($entry['parent_id'] === $parentId) {
                $children[] = new Category(
                    $entry['id'],
                    $entry['name'],
                    $entry['position'],
                    $this->buildChildren($flatList, $entry['id'])
                );
            }
        }

        return $children;
    }
}
