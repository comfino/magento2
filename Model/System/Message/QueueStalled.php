<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\System\Message
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\System\Message;

use Comfino\ComfinoGateway\Model\Queue\QueueHealth;
use Magento\Framework\Notification\MessageInterface;

/**
 * Admin system message shown while queued ComfinoPay requests are overdue (Magento cron is not running); it disappears once the queue is drained.
 */
class QueueStalled implements MessageInterface
{
    public function __construct(private readonly QueueHealth $queueHealth)
    {
    }

    public function getIdentity()
    {
        return 'comfino_queue_stalled';
    }

    public function isDisplayed()
    {
        return $this->queueHealth->getOverdueCount() > 0;
    }

    public function getText()
    {
        return (string) __(
            'ComfinoPay: %1 queued request(s), such as order cancellations, have been waiting to be sent for more than %2 minutes. They are sent by Magento cron. Check that cron is running (bin/magento cron:install).',
            $this->queueHealth->getOverdueCount(),
            (int) (QueueHealth::OVERDUE_SECONDS / 60)
        );
    }

    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }
}
