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
    public function __construct(private readonly Bootstrap $bootstrap)
    {
    }

    public function execute(): void
    {
        if (!ConfigManager::isRetryQueueEnabled()) {
            return;
        }

        /* controller_action_predispatch never fires in the crontab area, so BootstrapObserver does not run here.
           Without this call Bootstrap::getQueueProcessor() reads a null instance, and the drain dies on a swallowed
           error, leaving the queue to grow untouched between webhook-triggered drains. */
        $this->bootstrap->init();

        try {
            Bootstrap::getQueueProcessor()->process();
        } catch (Throwable) {
            // Drain errors are already logged inside the processor; swallow here so cron remains healthy.
        }
    }
}
