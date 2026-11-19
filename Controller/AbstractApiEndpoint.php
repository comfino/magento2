<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Controller
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Controller;

use Psr\Http\Message\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Abstract base class for API endpoints.
 */
abstract class AbstractApiEndpoint
{
    protected RawFactory $resultRawFactory;

    public function __construct(RawFactory $resultRawFactory)
    {
        $this->resultRawFactory = $resultRawFactory;
    }

    /**
     * Prepares a result object from a PSR-7 response.
     *
     * @param ResponseInterface $psr7Response The PSR-7 response object
     *
     * @return ResultInterface The prepared result object
     */
    protected function prepareResult(ResponseInterface $psr7Response): ResultInterface
    {
        $result = $this->resultRawFactory->create();
        $result->setHttpResponseCode($psr7Response->getStatusCode());

        foreach ($psr7Response->getHeaders() as $headerName => $headerValues) {
            foreach ($headerValues as $headerValue) {
                $result->setHeader($headerName, $headerValue);
            }
        }

        $body = (string) $psr7Response->getBody();

        if ($body === '') {
            // Empty body from the SDK: answer with the reason phrase, labeled as text so no client has to sniff it.
            $body = $psr7Response->getReasonPhrase();

            if (!$psr7Response->hasHeader('Content-Type')) {
                $result->setHeader('Content-Type', 'text/plain; charset=utf-8');
            }
        }

        $result->setContents($body);

        return $result;
    }
}
