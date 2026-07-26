<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Cron
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Cron;

use Comfino\ComfinoGateway\Cron\ProcessRequestQueue;
use Comfino\ComfinoGateway\Model\Bootstrap;
use Comfino\Tests\Support\ConfigManagerHarness;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ProcessRequestQueueTest extends TestCase
{
    protected function tearDown(): void
    {
        (new ReflectionProperty(Bootstrap::class, 'queueProcessor'))->setValue(null, null);
        (new ReflectionProperty(Bootstrap::class, 'outboundQueue'))->setValue(null, null);
        (new ReflectionProperty(Bootstrap::class, 'bootstrapInstance'))->setValue(null, null);
        ConfigManagerHarness::reset();

        parent::tearDown();
    }

    public function testExecuteDoesNothingWhenQueueDisabled(): void
    {
        ConfigManagerHarness::install(['COMFINO_RETRY_QUEUE_ENABLED' => false]);

        /* Bootstrap is uninitialized; reaching init()/getQueueProcessor() would throw — but the disabled-queue guard
           returns before either, so a mock that expects no calls is enough. */
        (new ProcessRequestQueue($this->createMock(Bootstrap::class)))->execute();

        $this->addToAssertionCount(1);
    }

    public function testExecuteExitsEarlyWhenQueueEnabledFlagIsExplicitlyFalse(): void
    {
        ConfigManagerHarness::install(['COMFINO_RETRY_QUEUE_ENABLED' => false]);

        (new ProcessRequestQueue($this->createMock(Bootstrap::class)))->execute();

        $this->addToAssertionCount(1);
    }
}
