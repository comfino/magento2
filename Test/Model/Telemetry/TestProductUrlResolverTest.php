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

namespace Comfino\Tests\Model\Telemetry;

use Comfino\ComfinoGateway\Model\Telemetry\TestProductUrlResolver;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * resolve() returns the absolute URL of the first visible, salable product, null when the catalog is empty, and
 * null when any collaborator throws (the report still records environment facts without triggering a crawl).
 */
final class TestProductUrlResolverTest extends TestCase
{
    private function makeSortOrderBuilder(): SortOrderBuilder
    {
        $sortOrder = $this->createMock(SortOrder::class);

        $builder = $this->createMock(SortOrderBuilder::class);
        $builder->method('setField')->willReturnSelf();
        $builder->method('setAscendingDirection')->willReturnSelf();
        $builder->method('create')->willReturn($sortOrder);

        return $builder;
    }

    private function makeSearchCriteriaBuilder(): SearchCriteriaBuilder
    {
        $criteria = $this->createMock(SearchCriteria::class);

        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('setSortOrders')->willReturnSelf();
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('setCurrentPage')->willReturnSelf();
        $builder->method('create')->willReturn($criteria);

        return $builder;
    }

    /** @param ProductInterface[] $items */
    private function makeRepository(array $items): ProductRepositoryInterface
    {
        $results = $this->createMock(ProductSearchResultsInterface::class);
        $results->method('getItems')->willReturn($items);

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('getList')->willReturn($results);

        return $repository;
    }

    public function testReturnsAbsoluteUrlForFirstVisibleProduct(): void
    {
        $urlKeyAttribute = $this->createMock(AttributeInterface::class);
        $urlKeyAttribute->method('getValue')->willReturn('cool-shirt');

        $product = $this->createMock(ProductInterface::class);
        $product->method('getCustomAttribute')->with('url_key')->willReturn($urlKeyAttribute);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getBaseUrl')
            ->with(['_type' => UrlInterface::URL_TYPE_WEB])
            ->willReturn('https://shop.example/');

        $resolver = new TestProductUrlResolver(
            $this->makeRepository([$product]),
            $this->makeSearchCriteriaBuilder(),
            $this->makeSortOrderBuilder(),
            $urlBuilder
        );

        $this->assertSame('https://shop.example/cool-shirt.html', $resolver->resolve());
    }

    public function testFallsBackToEmptyUrlKeyWhenAttributeMissing(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getCustomAttribute')->with('url_key')->willReturn(null);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getBaseUrl')->willReturn('https://shop.example/');

        $resolver = new TestProductUrlResolver(
            $this->makeRepository([$product]),
            $this->makeSearchCriteriaBuilder(),
            $this->makeSortOrderBuilder(),
            $urlBuilder
        );

        $this->assertSame('https://shop.example/.html', $resolver->resolve());
    }

    public function testReturnsNullWhenCatalogHasNoVisibleProducts(): void
    {
        $resolver = new TestProductUrlResolver(
            $this->makeRepository([]),
            $this->makeSearchCriteriaBuilder(),
            $this->makeSortOrderBuilder(),
            $this->createMock(UrlInterface::class)
        );

        $this->assertNull($resolver->resolve());
    }

    public function testReturnsNullWhenRepositoryThrows(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('getList')->willThrowException(new RuntimeException('catalog unavailable'));

        $resolver = new TestProductUrlResolver(
            $repository,
            $this->makeSearchCriteriaBuilder(),
            $this->makeSortOrderBuilder(),
            $this->createMock(UrlInterface::class)
        );

        $this->assertNull($resolver->resolve());
    }
}