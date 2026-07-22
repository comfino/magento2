<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Adminhtml\System\Config\Source
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Model\Adminhtml\System\Config\Source;

use Comfino\ComfinoGateway\Model\Configuration\SettingsManager;
use Comfino\Enum\ProductListType;
use Magento\Framework\Data\OptionSourceInterface;

class WidgetOfferType implements OptionSourceInterface
{
    /** @return array<int, array<string, string>> */
    public function toOptionArray(): array
    {
        return SettingsManager::getProductTypesSelectList(ProductListType::WIDGET->value);
    }
}
