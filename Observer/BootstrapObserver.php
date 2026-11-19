<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Observer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Observer;

use Comfino\ComfinoGateway\Model\Bootstrap;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Triggers SDK initialization on `controller_action_predispatch`, before any controller, block or observer uses the API.
 * `controller_front_init_before` does not exist in Magento 2.4.x, so this is the earliest reliable event.
 */
class BootstrapObserver implements ObserverInterface
{
    public function __construct(private readonly Bootstrap $bootstrap)
    {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function execute(Observer $observer): void
    {
        $this->bootstrap->init();
    }
}
