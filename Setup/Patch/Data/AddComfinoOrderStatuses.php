<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Setup\Patch\Data
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Setup\Patch\Data;

use Comfino\ComfinoGateway\Model\Order\ShopStatusManager;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddComfinoOrderStatuses implements DataPatchInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $statusTable = $this->moduleDataSetup->getTable('sales_order_status');
        $statusStateTable = $this->moduleDataSetup->getTable('sales_order_status_state');

        foreach (ShopStatusManager::CUSTOM_STATUS_LABELS as $statusCode => $details) {
            // Insert into sales_order_status only if code does not exist yet.
            $existing = $connection->fetchOne(
                $connection->select()
                    ->from($statusTable, ['status'])
                    ->where('status = ?', $statusCode)
            );

            if (!$existing) {
                $connection->insert($statusTable, [
                    'status' => $statusCode,
                    'label' => $details['label'],
                ]);
            }

            // Assign to state only if not already assigned.
            $assignedState = $connection->fetchOne(
                $connection->select()
                    ->from($statusStateTable, ['state'])
                    ->where('status = ?', $statusCode)
                    ->where('state = ?', $details['state'])
            );

            if (!$assignedState) {
                $connection->insert($statusStateTable, [
                    'status' => $statusCode,
                    'state' => $details['state'],
                    'is_default' => 0,
                    'visible_on_front' => 1,
                ]);
            }
        }

        $connection->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
