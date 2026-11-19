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

namespace Comfino\Tests\Model\System\Message;

use Comfino\ComfinoGateway\Model\Queue\QueueHealth;
use Comfino\ComfinoGateway\Model\System\Message\QueueStalled;
use Magento\Framework\Notification\MessageInterface;
use PHPUnit\Framework\TestCase;

final class QueueStalledTest extends TestCase
{
    public function testHiddenWhileNothingIsOverdue(): void
    {
        $this->assertFalse($this->createMessage(0)->isDisplayed());
    }

    public function testShownWithTheCountAndTheCronHintWhileRequestsAreOverdue(): void
    {
        $message = $this->createMessage(2);

        $this->assertTrue($message->isDisplayed());
        $this->assertSame(MessageInterface::SEVERITY_MAJOR, $message->getSeverity());
        $this->assertStringContainsString('2 queued request(s)', $message->getText());
        $this->assertStringContainsString('more than 30 minutes', $message->getText());
        $this->assertStringContainsString('cron', $message->getText());
    }

    private function createMessage(int $overdueCount): QueueStalled
    {
        $health = $this->createMock(QueueHealth::class);
        $health->method('getOverdueCount')->willReturn($overdueCount);

        return new QueueStalled($health);
    }
}
