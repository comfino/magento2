<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Controller\TransactionStatus
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Controller\TransactionStatus;

use Comfino\ComfinoGateway\Controller\AbstractApiEndpoint;
use Comfino\ComfinoGateway\Gateway\Http\ApiService;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use Comfino\ComfinoGateway\Model\Bootstrap;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Magento\Framework\App\Action\HttpPutActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Throwable;

class Index extends AbstractApiEndpoint implements HttpPutActionInterface, CsrfAwareActionInterface
{
    public function execute(): ResultInterface
    {
        ErrorLogger::init();

        $result = $this->prepareResult(ApiService::processRequest('transactionStatus'));

        if (ConfigManager::isRetryQueueEnabled()) {
            try {
                Bootstrap::getQueueProcessor()->process(5);
            } catch (Throwable) {
                // Best-effort drain; errors are already logged inside the processor.
            }
        }

        return $result;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
