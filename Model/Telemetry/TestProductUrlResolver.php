<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Telemetry
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Telemetry;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\UrlInterface;
use Throwable;

/**
 * Resolves a URL of a visible, salable product to pass to the Comfino environment report as a test URL that enables
 * the backend crawler to verify selector auto-detection.
 *
 * Returns null when no visible product exists (empty catalog, staging, etc.), so the report still records the
 * environment facts without triggering a crawl.
 */
class TestProductUrlResolver
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function resolve(): ?string
    {
        try {
            $sortOrder = $this->sortOrderBuilder
                ->setField('entity_id')
                ->setAscendingDirection()
                ->create();

            $criteria = $this->searchCriteriaBuilder
                ->addFilter('status', 1)
                ->addFilter('visibility', [2, 3, 4], 'in')
                ->setSortOrders([$sortOrder])
                ->setPageSize(1)
                ->setCurrentPage(1)
                ->create();

            $items = $this->productRepository->getList($criteria)->getItems();

            if (empty($items)) {
                return null;
            }

            /** @var ProductInterface $product */
            $product = reset($items);

            $urlKey = $product->getCustomAttribute('url_key')?->getValue() ?? '';

            return $this->urlBuilder->getBaseUrl(['_type' => UrlInterface::URL_TYPE_WEB]) . $urlKey . '.html';
        } catch (Throwable) {
            return null;
        }
    }
}
