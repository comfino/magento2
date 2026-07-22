<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Configuration
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Configuration;

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Backend\Payment\Filter\FilterByCartValueLowerLimit;
use Comfino\Backend\Payment\Filter\FilterByExcludedCategory;
use Comfino\Backend\Payment\ProductTypeFilterInterface;
use Comfino\Backend\Payment\ProductTypeFilterManager;
use Comfino\Backend\Settings\SettingsManager as SdkSettingsManager;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\Enum\LoanType;
use Comfino\Enum\LoanTypeInterface;
use Comfino\Enum\ProductListType;
use Comfino\Magento\Bootstrap as SdkBootstrap;
use Comfino\Shop\Cart;
use Comfino\Shop\Product\CategoryFilter;
use Throwable;

/**
 * Thin delegate over the SDK SettingsManager for product/widget type fetching and filtering.
 *
 * The SDK handles API calls and caching. Filter building from Magento configuration remains here.
 */
class SettingsManager
{
    /** @var ProductTypeFilterManager[] Keyed by list type */
    private static array $filterManagers = [];

    private static function sdk(): SdkSettingsManager
    {
        return SdkSettingsManager::getInstance(
            SdkBootstrap::getLanguageProvider(),
            SdkBootstrap::getHttpClient(),
            SdkBootstrap::getRequestFactory(),
            SdkBootstrap::getStreamFactory(),
            SdkBootstrap::getPlatformInfo(),
            ConfigManager::getInstance(),
            ConfigManager::getApiKey() ?? '',
            ConfigManager::isSandboxMode(),
            ConfigManager::getApiHost() ?: null
        );
    }

    /**
     * Returns the creditors map keyed by product type code, or empty array on error.
     *
     * @return array<string, string[]>
     */
    public static function getCreditors(): array
    {
        try {
            return self::sdk()->getCreditors() ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Returns the per-product-type term-limit DTO list, or null when none configured.
     */
    public static function getAllowedProductsConfig(): ?array
    {
        try {
            return self::sdk()->getAllowedProductsConfig();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Returns the per-product-type term-limit config as a plain frontend-serializable array, or null when none.
     */
    public static function getAllowedProductsConfigForFrontend(): ?array
    {
        try {
            return self::sdk()->getAllowedProductsConfigForFrontend();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Builds the admin dropdown for offer/product types.
     *
     * Empty value sentinel: when the upstream API call fails (most commonly because the API key has not been saved
     * yet), a single option with `value=''` is returned so that combined with `<validate>required-entry</validate>`
     * on the field Magento's admin form refuses to save.
     *
     * @return array<int, array<string, string>>
     */
    public static function getProductTypesSelectList(string $listType): array
    {
        $selectList = self::sdk()->getProductTypesSelectList($listType);

        // SDK returns error message if API unavailable; wrap in Magento translation.
        if (count($selectList) === 1 && $selectList[0]['value'] === '') {
            return [['value' => '', 'label' => __($selectList[0]['label'])->render()]];
        }

        return $selectList;
    }

    /**
     * Builds the admin dropdown for widget types.
     *
     * @return array<int, array<string, string>>
     */
    public static function getWidgetTypesSelectList(): array
    {
        $selectList = self::sdk()->getWidgetTypesSelectList();

        // SDK returns error message if API unavailable; wrap in Magento translation.
        if (count($selectList) === 1 && $selectList[0]['value'] === '') {
            return [['value' => '', 'label' => __($selectList[0]['label'])->render()]];
        }

        return $selectList;
    }

    /**
     * @return string[]
     */
    public static function getProductTypes(string $listType, bool $returnErrors = false): array
    {
        try {
            return self::sdk()->getProductTypes($listType)
                ?? ($returnErrors ? ['error' => 'API not available. Check API key.'] : []);
        } catch (Throwable $e) {
            return $returnErrors ? ['error' => $e->getMessage()] : [];
        }
    }

    /**
     * @return string[]
     */
    public static function getProductTypesStrings(string $listType): array
    {
        $productTypes = self::getProductTypes($listType);

        if (isset($productTypes['error'])) {
            return [];
        }

        return array_keys($productTypes);
    }

    /**
     * @return LoanTypeInterface[]
     */
    public static function getProductTypesEnums(string $listType): array
    {
        $productTypes = self::getProductTypes($listType);

        if (isset($productTypes['error'])) {
            return [];
        }

        return array_map(
            static function (string $productType): LoanTypeInterface {
                return LoanType::fromApiValue($productType);
            },
            array_keys($productTypes)
        );
    }

    /**
     * @return string[]
     */
    public static function getWidgetTypes(bool $returnErrors = false): array
    {
        try {
            return self::sdk()->getWidgetTypes()
                ?? ($returnErrors ? ['error' => 'API not available. Check API key.'] : []);
        } catch (Throwable $e) {
            return $returnErrors ? ['error' => $e->getMessage()] : [];
        }
    }

    /**
     * @return LoanTypeInterface[]|null
     */
    public static function getAllowedProductTypes(string $listType, Cart $cart, bool $returnOnlyArray = false): ?array
    {
        $filterManager = self::getFilterManager($listType);

        if (!$filterManager->filtersActive()) {
            return null;
        }

        $availableProductTypes = self::getProductTypesEnums($listType);
        $allowedProductTypes = $filterManager->getAllowedProductTypes($availableProductTypes, $cart);

        if (ConfigManager::isDebugMode()) {
            $serializer = new JsonSerializer();
            $activeFilters = array_map(
                static function (ProductTypeFilterInterface $filter) use ($serializer): string {
                    return get_class($filter) . ': ' . $serializer->serialize($filter->getAsArray());
                },
                $filterManager->getFilters()
            );

            DebugLogger::logEvent(
                'getAllowedProductTypes',
                [
                    '$activeFilters' => $activeFilters,
                    '$availableProductTypes' => $availableProductTypes,
                    '$allowedProductTypes' => $allowedProductTypes,
                ],
                'PAYWALL'
            );
        }

        if ($returnOnlyArray) {
            return $allowedProductTypes;
        }

        return count($availableProductTypes) !== count($allowedProductTypes) ? $allowedProductTypes : null;
    }

    /** @return array<string, array<int>|string> */
    public static function getProductCategoryFilters(): array
    {
        if (!is_array($catFilters = ConfigManager::getConfigurationValue('COMFINO_PRODUCT_CATEGORY_FILTERS', []))) {
            $catFilters = array_map('trim', explode(',', $catFilters));
        }

        return $catFilters;
    }

    /** @return array<string> */
    public static function getProductCategoryFiltersAvailProductTypes(): array
    {
        if (!is_array($availProds = ConfigManager::getConfigurationValue('COMFINO_CAT_FILTER_AVAIL_PROD_TYPES', []))) {
            $availProds = array_map('trim', explode(',', $availProds));
        }

        return $availProds;
    }

    /**
     * @param array<string, array<int|string>|string> $productCategoryFilters
     */
    public static function productCategoryFiltersActive(array $productCategoryFilters): bool
    {
        if (empty($productCategoryFilters)) {
            return false;
        }

        foreach ($productCategoryFilters as $excludedCategoryIds) {
            if (!empty($excludedCategoryIds)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns product types available for category filters, intersected with the configured
     * COMFINO_CAT_FILTER_AVAIL_PROD_TYPES list. Falls back to all paywall types on empty intersection.
     *
     * @return string[] ['PRODUCT_TYPE_CODE' => 'Product type name', ...]
     */
    public static function getCatFilterAvailProdTypes(): array
    {
        $productTypes = self::getProductTypes(ProductListType::PAYWALL->value);

        if (empty($productTypes)) {
            return [];
        }

        $categoryFilterAvailProductTypes = [];

        foreach (self::getProductCategoryFiltersAvailProductTypes() as $productType) {
            $categoryFilterAvailProductTypes[$productType] = null;
        }

        if (empty($availProductTypes = array_intersect_key($productTypes, $categoryFilterAvailProductTypes))) {
            $availProductTypes = $productTypes;
        }

        return $availProductTypes;
    }

    private static function getFilterManager(string $listType): ProductTypeFilterManager
    {
        if (!isset(self::$filterManagers[$listType])) {
            $manager = clone ProductTypeFilterManager::getInstance();

            foreach (self::buildFiltersList($listType) as $filter) {
                $manager->addFilter($filter);
            }

            self::$filterManagers[$listType] = $manager;
        }

        return self::$filterManagers[$listType];
    }

    /**
     * @return ProductTypeFilterInterface[]
     */
    private static function buildFiltersList(string $listType): array
    {
        $filters = [];
        $minAmount = (int) (round(ConfigManager::getConfigurationValue('COMFINO_MINIMAL_CART_AMOUNT', 0), 2) * 100);

        if ($minAmount > 0) {
            $availableProductTypes = self::getProductTypesStrings($listType);
            $filters[] = new FilterByCartValueLowerLimit(
                array_combine($availableProductTypes, array_fill(0, count($availableProductTypes), $minAmount))
            );
        }

        if (self::productCategoryFiltersActive($productCategoryFilters = self::getProductCategoryFilters())) {
            $filters[] = new FilterByExcludedCategory(
                new CategoryFilter(ConfigManager::getCategoriesTree()),
                $productCategoryFilters
            );
        }

        return $filters;
    }
}
