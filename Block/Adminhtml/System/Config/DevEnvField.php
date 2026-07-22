<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Block\Adminhtml\System\Config
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Custom field for displaying content only in the development environment.
 */
class DevEnvField extends Field
{
    public function render(AbstractElement $element): string
    {
        if (getenv('COMFINO_DEV_ENV') !== 'TRUE') {
            return '';
        }

        return parent::render($element);
    }
}
