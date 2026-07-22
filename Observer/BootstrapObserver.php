<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Observer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Observer;

use Comfino\ComfinoGateway\Model\Bootstrap;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Triggers SDK initialization early in the Magento request lifecycle.
 *
 * Fired on `controller_action_predispatch` — fires before every controller action in all areas (frontend, adminhtml).
 * Ensures that CacheManager, DebugLogger, ErrorLogger, and the HTTP client are all set up before any controller,
 * block, or observer that uses the Comfino API is invoked. Note: controller_front_init_before does not exist in
 * Magento 2.4.x; controller_action_predispatch is the earliest reliable substitute.
 */
class BootstrapObserver implements ObserverInterface
{
    public function __construct(private readonly Bootstrap $bootstrap)
    {
    }

    public function execute(Observer $observer): void
    {
        $this->bootstrap->init();
    }
}
