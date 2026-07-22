<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Controller\Adminhtml\Log
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Controller\Adminhtml\Log;

use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Throwable;

class Clear extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Config::config';

    private JsonFactory $resultJsonFactory;

    public function __construct(Context $context, JsonFactory $resultJsonFactory)
    {
        parent::__construct($context);

        $this->resultJsonFactory = $resultJsonFactory;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $type = $this->getRequest()->getParam('type');

        try {
            if ($type === 'error') {
                ErrorLogger::clearLogs();
            } elseif ($type === 'debug') {
                DebugLogger::clearLogs();
            } else {
                return $result->setData(['success' => false, 'message' => 'Unknown log type.']);
            }

            return $result->setData(['success' => true]);
        } catch (Throwable $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
