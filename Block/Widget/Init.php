<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Block\Widget
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Block\Widget;

use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Configuration\SettingsManager;
use Comfino\ComfinoGateway\Model\Order\OrderManager;
use Comfino\Enum\ProductListType;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Product-page widget initialization block for the Comfino payment gateway.
 *
 * Builds the `#comfino-widget-config` JSON block and resolves the CDN product widget script URL for the currently
 * viewed product (WIDGET_BRIDGE_MIGRATION_PLAN §3). {@see getWidgetConfigJson()} returns an empty string —
 * suppressing the widget — when it is disabled, the widget key is missing, or all product types are filtered out by
 * the active SettingsManager rules for the WIDGET list type.
 */
class Init extends Template
{
    /** @param array<string, mixed> $data */
    public function __construct(
        Context $context,
        private readonly RequestInterface $request,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly OrderManager $orderManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Builds the JSON config for the `#comfino-widget-config` block consumed by the CDN product widget script
     * (WIDGET_BRIDGE_MIGRATION_PLAN §3). Returns an empty string — suppressing the widget — when it is disabled,
     * the widget key is missing, or all product types are filtered out for the viewed product. Encoded with the
     * SDK init helpers' defensive flags so admin-controlled strings cannot break out of the script context.
     */
    public function getWidgetConfigJson(): string
    {
        if (!ConfigManager::isWidgetEnabled() || ConfigManager::getWidgetKey() === '') {
            return '';
        }

        $productId = (int) $this->request->getParam('id');

        if ($productId) {
            try {
                /** @var Product $product */
                $product = $this->productRepository->getById($productId);
                $shopCart = $this->orderManager->getShopCartFromProduct($product);

                if (SettingsManager::getAllowedProductTypes(ProductListType::WIDGET->value, $shopCart) === []) {
                    // All product types filtered out for this product.
                    return '';
                }
            } catch (LocalizedException) {
                // Product not found or error - render with unfiltered product types.
            }
        }

        $json = json_encode(
            ConfigManager::getWidgetConfig($productId ?: null),
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );

        return $json === false ? '' : $json;
    }

    /**
     * URL of the CDN-hosted per-platform product widget script (comfino-magento-widget.min.js) that reads the
     * config block, imports the SDK, and calls sdk.bootstrapWidget().
     */
    public function getProductWidgetScriptUrl(): string
    {
        return ConfigManager::getProductWidgetScriptUrl();
    }
}
