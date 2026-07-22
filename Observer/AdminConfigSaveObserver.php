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

use Comfino\ComfinoGateway\Model\Telemetry\ShopEnvironmentReporter;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Triggers a shop environment report to the Comfino API whenever the admin saves the payment section.
 *
 * This refreshes the API-side knowledge base when the merchant changes the API key, sandbox toggle, or any other
 * configuration that might reflect a theme or platform change. Fire-and-forget: any failure is swallowed inside
 * ShopEnvironmentReporter.
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
