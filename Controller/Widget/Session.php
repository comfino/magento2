<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Controller\Widget
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Controller\Widget;

use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * GET comfino/widget/session: the per-visitor values of the product widget (track ID, logging token), which must not be
 * embedded in full-page-cached product pages. Never cached.
 */
class Session implements HttpGetActionInterface
{
    public function __construct(private readonly JsonFactory $jsonFactory)
    {
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private', true);
        $result->setHeader('Pragma', 'no-cache', true);

        if (!ConfigManager::isWidgetEnabled()) {
            return $result->setData([]);
        }

        return $result->setData(array_filter(ConfigManager::getWidgetSessionValues(), static fn (?string $value): bool => $value !== null));
    }
}
