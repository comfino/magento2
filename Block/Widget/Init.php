<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Block\Widget
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
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
 * Product-page widget initialization block: builds the `#comfino-widget-config` JSON and resolves the CDN widget script URL.
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
     * Builds the `#comfino-widget-config` JSON, or an empty string (widget suppressed) when the widget is disabled, its key is
     * missing or no product type is allowed. Encoded with the SDK's defensive flags so admin strings cannot break the script.
     */
    public function getWidgetConfigJson(): string
    {
        if (!ConfigManager::isWidgetEnabled() || ConfigManager::getWidgetKey() === '') {
            return '';
        }

        $productId = (int) $this->request->getParam('id');
        $product = null;
        $shopCart = null;

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
            ConfigManager::getWidgetConfig($productId ?: null, $product, $shopCart),
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );

        return $json === false ? '' : $json;
    }

    /**
     * URL of the endpoint that returns the per-visitor widget values (track ID, logging token).
     */
    public function getWidgetSessionUrl(): string
    {
        return $this->getUrl('comfino/widget/session');
    }

    /**
     * URL of the CDN per-platform product widget script that reads the config block and bootstraps the SDK widget.
     */
    public function getProductWidgetScriptUrl(): string
    {
        return ConfigManager::getProductWidgetScriptUrl();
    }
}
