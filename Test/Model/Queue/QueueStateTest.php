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

use Comfino\ComfinoGateway\Model\Queue\QueueState;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\TestCase;

final class QueueStateTest extends TestCase
{
    private mixed $flagData = null;

    private function createState(): QueueState
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturnCallback(fn (): mixed => $this->flagData);
        $flagManager->method('saveFlag')->willReturnCallback(function (string $code, mixed $value): bool {
            $this->flagData = $value;

            return true;
        });

        return new QueueState($flagManager);
    }

    public function testCountsDeadLettersPerOperationWithinSevenDays(): void
    {
        $state = $this->createState();
        $now = 1_700_000_000;

        $state->recordDeadLetter('cancel_order', $now - 8 * 86400);
        $state->recordDeadLetter('cancel_order', $now - 3600);
        $state->recordDeadLetter('report_error', $now - 60);
        $state->recordDeadLetter('cancel_order', $now);

        $this->assertSame(['cancel_order' => 2, 'report_error' => 1], $state->getDeadLetterCounts($now));
    }

    public function testListsAStorePausedWithinTheLastHour(): void
    {
        $state = $this->createState();
        $now = 1_700_000_000;

        $state->recordPause('store:2', 'credentials_rejected', $now - 600);
        $state->recordPause('store:3', 'consecutive_failures', $now - 7200);
        $state->recordPause(null, 'consecutive_failures', $now);

        $this->assertSame(
            ['store:2' => ['reason' => 'credentials_rejected', 'at' => $now - 600], '' => ['reason' => 'consecutive_failures', 'at' => $now]],
            $state->getPausedTenants($now)
        );
    }

    public function testIgnoresMalformedStoredData(): void
    {
        $this->flagData = ['deadLetters' => ['x', ['operation' => 'cancel_order']], 'paused' => 'broken'];

        $state = $this->createState();

        $this->assertSame([], $state->getDeadLetterCounts());
        $this->assertSame([], $state->getPausedTenants());
    }
}
