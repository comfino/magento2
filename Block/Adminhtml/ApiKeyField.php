<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Block\Adminhtml
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Block\Adminhtml;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders a masked API key field with `autocomplete="new-password"`, so the browser does not fill the admin password
 * into it. The `obscure` element does not output the attribute itself.
 */
class ApiKeyField extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        return preg_replace('/<input\b/i', '<input autocomplete="new-password"', $html = parent::_getElementHtml($element), 1) ?? $html;
    }
}
