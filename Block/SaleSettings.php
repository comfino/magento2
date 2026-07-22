<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Block
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Block;

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\ComfinoGateway\Model\CategoryTree\BuildStrategy;
use Comfino\ComfinoGateway\Model\Configuration\SettingsManager;
use Comfino\Shop\Product\CategoryTree;
use Comfino\Shop\Product\CategoryTree\NodeIterator;
use Magento\Backend\Block\Template;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class SaleSettings extends Field
{
    private const TREE_CLOSE_DEPTH = 3;

    protected $_template = 'Comfino_ComfinoGateway::sale-settings.phtml';
    private CollectionFactory $collectionFactory;

    /** @param array<string, mixed> $data */
    public function __construct(Template\Context $context, CollectionFactory $collectionFactory, array $data = [])
    {
        parent::__construct($context, $data);

        $this->collectionFactory = $collectionFactory;
    }

    public function render(AbstractElement $element): string
    {
        $categoriesTree = new CategoryTree(new BuildStrategy($this->collectionFactory));
        $allCategoryIds = $this->collectLeafIds($categoriesTree->getNodes());

        $availableProductTypes = SettingsManager::getCatFilterAvailProdTypes();
        $productCategoryFilters = SettingsManager::getProductCategoryFilters();

        if (empty($availableProductTypes)) {
            return '<tr id="row_' . $element->getHtmlId() . '"><td colspan="5"><p>'
                . __('No financial product types available. Please check your API key configuration.')
                . '</p></td></tr>';
        }

        $treeJsUrl = $this->getViewFileUrl('Comfino_ComfinoGateway::js/tree.min.js');
        $serializer = new JsonSerializer();

        $productTypeTrees = [];

        foreach ($availableProductTypes as $prodTypeCode => $prodTypeName) {
            $selectedCategories = isset($productCategoryFilters[$prodTypeCode])
                ? array_diff($allCategoryIds, $productCategoryFilters[$prodTypeCode])
                : $allCategoryIds;

            $treeNodes = $serializer->serialize(
                $this->buildTreeNodes($categoriesTree->getNodes(), array_values($selectedCategories))
            ) ?: '[]';
            $containerId = 'product_categories_' . $prodTypeCode;
            $inputId = $containerId . '_input';

            $productTypeTrees[] = [
                'name' => $prodTypeName,
                'container_id' => $containerId,
                'input_id' => $inputId,
                'prod_type_code' => $prodTypeCode,
                'tree_nodes_json' => $treeNodes,
            ];
        }

        $this->assign([
            'aggregateFieldId' => $element->getHtmlId(),
            'aggregateFieldName' => $element->getName(),
            'productTypeTrees' => $productTypeTrees,
            'treeJsUrl' => $treeJsUrl,
            'treeCloseDepth' => self::TREE_CLOSE_DEPTH,
            'allCategoryIdsJson' => $serializer->serialize($allCategoryIds),
        ]);

        return $this->toHtml();
    }

    /**
     * Recursively collects IDs of leaf nodes (nodes without children) from the category tree.
     *
     * Only leaf IDs are used for comfinoAllCategoryIds in JS because tree.min.js::getValues() returns only leaf node
     * IDs - parent nodes are never included in this.values regardless of their check state. Using all node IDs
     * (including parents) would cause parent IDs to always appear in the excluded set.
     *
     * @param NodeIterator $nodes
     *
     * @return int[]
     */
    private function collectLeafIds(NodeIterator $nodes): array
    {
        $leafIds = [];

        foreach ($nodes as $node) {
            if ($node->hasChildren()) {
                $leafIds = array_merge($leafIds, $this->collectLeafIds($node->getChildren()));
            } else {
                $leafIds[] = $node->getId();
            }
        }

        return $leafIds;
    }

    /**
     * Recursively converts CategoryTree nodes into the tree.min.js data format.
     *
     * @param array<int> $selectedCategories IDs of leaf categories that should be checked (not excluded)
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTreeNodes(NodeIterator $nodes, array $selectedCategories): array
    {
        $treeData = [];

        foreach ($nodes as $node) {
            $treeNode = ['id' => $node->getId(), 'text' => $node->getName()];

            if ($node->hasChildren()) {
                $treeNode['children'] = $this->buildTreeNodes($node->getChildren(), $selectedCategories);
            } elseif (in_array($node->getId(), $selectedCategories, true)) {
                $treeNode['checked'] = true;
            }

            $treeData[] = $treeNode;
        }

        return $treeData;
    }
}
