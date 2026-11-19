<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Observer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Observer;

use Comfino\ComfinoGateway\Model\Bootstrap;
use Comfino\ComfinoGateway\Observer\BootstrapObserver;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

final class BootstrapObserverTest extends TestCase
{
    /**
     * @throws NoSuchEntityException
     */
    public function testExecuteDelegatesToBootstrapInit(): void
    {
        $bootstrap = $this->createMock(Bootstrap::class);
        $bootstrap->expects($this->once())->method('init');

        (new BootstrapObserver($bootstrap))->execute(new Observer());
    }
}
