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

use Comfino\ComfinoGateway\Model\Telemetry\ShopEnvironmentReporter;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Triggers a shop environment report to the ComfinoPay API whenever the admin saves the payment section.
 * Fire-and-forget: failures are swallowed inside ShopEnvironmentReporter.
 */
class AdminConfigSaveObserver implements ObserverInterface
{
    public function __construct(private readonly ShopEnvironmentReporter $shopEnvironmentReporter)
    {
    }

    public function execute(Observer $observer): void
    {
        $this->shopEnvironmentReporter->report();
    }
}
