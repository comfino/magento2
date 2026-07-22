<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Controller\CacheInvalidate
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Controller\CacheInvalidate;

use Comfino\ComfinoGateway\Controller\AbstractApiEndpoint;
use Comfino\ComfinoGateway\Gateway\Http\ApiService;
use Comfino\ComfinoGateway\Logger\Error as ErrorLogger;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;

class Index extends AbstractApiEndpoint implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function execute(): ResultInterface
    {
        ErrorLogger::init();

        return $this->prepareResult(ApiService::processRequest('cacheInvalidate'));
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
