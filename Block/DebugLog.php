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

use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class DebugLog extends Field
{
    private const DEBUG_LOG_NUM_LINES = 200;

    protected $_template = 'Comfino_ComfinoGateway::log/debug.phtml';

    public function render(AbstractElement $element): string
    {
        $this->assign([
            'clearUrl' => $this->getUrl('comfino/log/clear', ['type' => 'debug', 'form_key' => $this->getFormKey()]),
            'textareaId' => 'comfino_debug_log_content',
            'logContent' => DebugLogger::getLoggerInstance()->getDebugLog(self::DEBUG_LOG_NUM_LINES),
            'clearConfirmation' => (string) __('Are you sure you want to clear the log?'),
        ]);

        return $this->toHtml();
    }
}
