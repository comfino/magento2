<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Setup
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Setup;

use Comfino\ComfinoGateway\Model\Order\ShopStatusManager;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use Zend_Db_Expr;

class Uninstall implements UninstallInterface
{
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $connection = $setup->getConnection();

        $setup->startSetup();

        $statusCodes = array_keys(ShopStatusManager::CUSTOM_STATUS_LABELS);
        $statusTable = $setup->getTable('sales_order_status');
        $statusStateTable = $setup->getTable('sales_order_status_state');
        $orderTable = $setup->getTable('sales_order');
        $historyTable = $setup->getTable('sales_order_status_history');

        // Always unassigned from states - prevents future use regardless of history.
        $connection->delete(
            $statusStateTable,
            ['status IN (?)' => $statusCodes]
        );

        // Hard-delete from sales_order_status only for statuses that no order references.
        foreach ($statusCodes as $statusCode) {
            $usedInOrders = (int) $connection->fetchOne(
                $connection->select()
                    ->from($orderTable, [new Zend_Db_Expr('COUNT(*)')])
                    ->where('status = ?', $statusCode)
            );

            $usedInHistory = (int) $connection->fetchOne(
                $connection->select()
                    ->from($historyTable, [new Zend_Db_Expr('COUNT(*)')])
                    ->where('status = ?', $statusCode)
            );

            if ($usedInOrders === 0 && $usedInHistory === 0) {
                $connection->delete($statusTable, ['status = ?' => $statusCode]);
            }
            // If still in use: leave row in sales_order_status so historical labels remain visible.
        }

        $setup->endSetup();
    }
}
