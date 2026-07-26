<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests;

use Comfino\ComfinoGateway\Model\Order\ShopStatusManager;
use Comfino\Enum\OrderStatus;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

final class ShopStatusManagerTest extends TestCase
{
    // --- defaultStatusMap() ---

    public function testDefaultStatusMapContainsExpectedApiStatuses(): void
    {
        $map = ShopStatusManager::defaultStatusMap();

        $this->assertArrayHasKey(OrderStatus::ACCEPTED->value, $map);
        $this->assertArrayHasKey(OrderStatus::CANCELLED->value, $map);
        $this->assertArrayHasKey(OrderStatus::REJECTED->value, $map);
        $this->assertArrayHasKey(OrderStatus::CANCELLED_BY_SHOP->value, $map);
    }

    public function testDefaultStatusMapDoesNotContainCreated(): void
    {
        $this->assertArrayNotHasKey(OrderStatus::CREATED->value, ShopStatusManager::defaultStatusMap());
    }

    public function testDefaultStatusMapValuesUseComfinoPrefix(): void
    {
        foreach (ShopStatusManager::defaultStatusMap() as $customCode) {
            $this->assertStringStartsWith(
                'comfino_',
                $customCode,
                "Custom code '$customCode' must start with comfino_"
            );
        }
    }

    // --- customStatusMap() ---

    public function testCustomStatusMapIncludesCreated(): void
    {
        $this->assertArrayHasKey(OrderStatus::CREATED->value, ShopStatusManager::customStatusMap());
    }

    public function testDefaultStatusMapIsSubsetOfCustomStatusMap(): void
    {
        $custom = ShopStatusManager::customStatusMap();

        foreach (ShopStatusManager::defaultStatusMap() as $apiStatus => $customCode) {
            $this->assertArrayHasKey($apiStatus, $custom);
            $this->assertSame($customCode, $custom[$apiStatus]);
        }
    }

    // --- CUSTOM_STATUS_LABELS ---

    public function testEveryCustomStatusCodeHasLabelEntry(): void
    {
        foreach (ShopStatusManager::customStatusMap() as $customCode) {
            $this->assertArrayHasKey(
                $customCode,
                ShopStatusManager::CUSTOM_STATUS_LABELS,
                "No CUSTOM_STATUS_LABELS entry for: $customCode"
            );
        }
    }

    public function testEveryLabelEntryHasRequiredKeys(): void
    {
        foreach (ShopStatusManager::CUSTOM_STATUS_LABELS as $code => $entry) {
            $this->assertArrayHasKey('state', $entry, "Missing 'state' in CUSTOM_STATUS_LABELS[$code]");
            $this->assertArrayHasKey('label', $entry, "Missing 'label' in CUSTOM_STATUS_LABELS[$code]");
            $this->assertArrayHasKey('label_pl', $entry, "Missing 'label_pl' in CUSTOM_STATUS_LABELS[$code]");
        }
    }

    // --- State assignments ---

    public function testCreatedMapsToPendingPaymentState(): void
    {
        $code = ShopStatusManager::customStatusMap()[OrderStatus::CREATED->value];
        $this->assertSame(Order::STATE_PENDING_PAYMENT, ShopStatusManager::CUSTOM_STATUS_LABELS[$code]['state']);
    }

    public function testAcceptedMapsToProcessingState(): void
    {
        $code = ShopStatusManager::defaultStatusMap()[OrderStatus::ACCEPTED->value];
        $this->assertSame(Order::STATE_PROCESSING, ShopStatusManager::CUSTOM_STATUS_LABELS[$code]['state']);
    }

    public function testCancelledStatusesMapToCanceledState(): void
    {
        $default = ShopStatusManager::defaultStatusMap();

        foreach ([OrderStatus::CANCELLED, OrderStatus::REJECTED, OrderStatus::CANCELLED_BY_SHOP] as $status) {
            $code = $default[$status->value];
            $this->assertSame(
                Order::STATE_CANCELED,
                ShopStatusManager::CUSTOM_STATUS_LABELS[$code]['state'],
                "Expected STATE_CANCELED for $status->value"
            );
        }
    }
}
