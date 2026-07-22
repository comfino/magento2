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

use Magento\Framework\Data\OptionSourceInterface;

class WidgetEmbedMethod implements OptionSourceInterface
{
    /** @return array<int, array<string, string>> */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'INSERT_INTO_FIRST', 'label' => 'INSERT_INTO_FIRST'],
            ['value' => 'INSERT_INTO_LAST', 'label' => 'INSERT_INTO_LAST'],
            ['value' => 'INSERT_BEFORE', 'label' => 'INSERT_BEFORE'],
            ['value' => 'INSERT_AFTER', 'label' => 'INSERT_AFTER'],
        ];
    }
}
