<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Block
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Block;

use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class ErrorLog extends Field
{
    private const ERROR_LOG_NUM_LINES = 100;

    protected $_template = 'Comfino_ComfinoGateway::log/error.phtml';

    public function render(AbstractElement $element): string
    {
        $this->assign([
            'clearUrl' => $this->getUrl('comfino/log/clear', ['type' => 'error', 'form_key' => $this->getFormKey()]),
            'textareaId' => 'comfino_error_log_content',
            'logContent' => ErrorLogger::getErrorLog(self::ERROR_LOG_NUM_LINES),
            'clearConfirmation' => (string) __('Are you sure you want to clear the log?'),
            'buttonLabel' => (string) __('Clear errors log'),
        ]);

        return $this->toHtml();
    }
}
