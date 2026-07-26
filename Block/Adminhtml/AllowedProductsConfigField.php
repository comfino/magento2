<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Block\Adminhtml
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Block\Adminhtml;

use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Configuration\SettingsManager;
use Comfino\Enum\ProductListType;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the structured table for `payment/comfino/allowed_products_config`. One row per paywall product type with
 * minTerm / maxTerm / terms inputs. A small inline JS reads the rows on every change and writes the canonical JSON
 * into a hidden aggregator input — the existing backend model validates that JSON on save.
 */
class AllowedProductsConfigField extends Field
{
    protected $_template = 'Comfino_ComfinoGateway::allowed-products-config.phtml';

    public function render(AbstractElement $element): string
    {
        if (!$this->isFeatureEnabled()) {
            return '';
        }

        $productTypes = $this->fetchProductTypes();
        $savedConfig = $this->fetchSavedConfig() ?? [];

        $savedByType = [];

        foreach ($savedConfig as $entry) {
            if (isset($entry['type']) && is_string($entry['type'])) {
                $savedByType[$entry['type']] = $entry;
            }
        }

        $this->assign([
            'aggregateFieldId' => $element->getHtmlId(),
            'aggregateFieldName' => $element->getName(),
            'productTypes' => $productTypes,
            'savedByType' => $savedByType,
            'currentValueJson' => $savedConfig !== [] ? (string) json_encode($savedConfig) : '',
            'apiUnavailable' => $productTypes === [],
        ]);

        return $this->toHtml();
    }

    protected function isFeatureEnabled(): bool
    {
        return (bool) ConfigManager::getConfigurationValue('COMFINO_ALLOWED_PRODUCTS_CONFIG_ENABLED');
    }

    /**
     * @return array<string, string>
     */
    protected function fetchProductTypes(): array
    {
        return SettingsManager::getProductTypes(ProductListType::PAYWALL->value);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function fetchSavedConfig(): ?array
    {
        return SettingsManager::getAllowedProductsConfigForFrontend();
    }
}
