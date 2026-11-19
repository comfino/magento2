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
use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;

/**
 * Admin system message shown while queued ComfinoPay requests of a store are paused, usually because the API rejected
 * that store's API key. It disappears an hour after the last pause; the requests are retried and nothing is dropped.
 */
class QueuePaused implements MessageInterface
{
    public function __construct(private readonly QueueHealth $queueHealth, private readonly Escaper $escaper)
    {
    }

    public function getIdentity()
    {
        return 'comfino_queue_paused';
    }

    public function isDisplayed()
    {
        return $this->queueHealth->getPausedStores() !== [];
    }

    public function getText()
    {
        return (string) __(
            'ComfinoPay: sending queued requests (such as order cancellations) is paused for: %1. Usually ComfinoPay rejected the API key configured for that store view; check the key in its ComfinoPay settings. The requests are retried automatically.',
            $this->escaper->escapeHtml(implode(', ', array_keys($this->queueHealth->getPausedStores())))
        );
    }

    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }
}
