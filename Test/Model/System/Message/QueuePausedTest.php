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
use Comfino\ComfinoGateway\Model\System\Message\QueuePaused;
use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;

final class QueuePausedTest extends TestCase
{
    public function testHiddenWhileNoStoreIsPaused(): void
    {
        $this->assertFalse($this->createMessage([])->isDisplayed());
    }

    public function testNamesThePausedStoresEscaped(): void
    {
        $message = $this->createMessage(['<b>Outlet</b> (ID 3)' => 'credentials_rejected']);

        $this->assertTrue($message->isDisplayed());
        $this->assertStringContainsString('&lt;b&gt;Outlet&lt;/b&gt; (ID 3)', $message->getText());
        $this->assertStringNotContainsString('<b>', $message->getText());
    }

    /**
     * @param array<string, string> $pausedStores
     */
    private function createMessage(array $pausedStores): QueuePaused
    {
        $health = $this->createMock(QueueHealth::class);
        $health->method('getPausedStores')->willReturn($pausedStores);

        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES));

        return new QueuePaused($health, $escaper);
    }
}
