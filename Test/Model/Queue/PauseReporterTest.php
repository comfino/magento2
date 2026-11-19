<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Queue;

use Comfino\Backend\Queue\QueuedRequest;
use Comfino\ComfinoGateway\Model\Queue\PauseReporter;
use Comfino\ComfinoGateway\Model\Queue\QueueState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PauseReporterTest extends TestCase
{
    public function testRecordsThePausedStore(): void
    {
        $queueState = $this->createMock(QueueState::class);
        $queueState->expects($this->once())->method('recordPause')->with('store:4', 'credentials_rejected');

        (new PauseReporter($queueState))->reportPaused(
            'store:4',
            new QueuedRequest(1, 'cancel_order', ['orderId' => '1'], 1, 1748000000),
            new RuntimeException('401'),
            'credentials_rejected'
        );
    }

    public function testNeverThrows(): void
    {
        $queueState = $this->createMock(QueueState::class);
        $queueState->method('recordPause')->willThrowException(new RuntimeException('db gone'));

        (new PauseReporter($queueState))->reportPaused(null, new QueuedRequest(1, 'cancel_order', [], 1, 1748000000), new RuntimeException('x'), 'r');

        $this->expectNotToPerformAssertions();
    }
}
