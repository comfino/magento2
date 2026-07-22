<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Cron
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Cron;

use Comfino\ComfinoGateway\Model\Bootstrap;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Throwable;

/**
 * Scheduled drain of the outbound request queue (every 5 minutes).
 */
class ProcessRequestQueue
{
    public function execute(): void
    {
        if (!ConfigManager::isRetryQueueEnabled()) {
            return;
        }

        try {
            Bootstrap::getQueueProcessor()->process();
        } catch (Throwable) {
            // Drain errors are already logged inside the processor; swallow here so cron remains healthy.
        }
    }
}
