<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Setup
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Setup;

use Comfino\Backend\Queue\OutboundRequestQueueProcessor;
use Comfino\ComfinoGateway\Model\Order\LoanSelection;
use Comfino\ComfinoGateway\Model\Order\ShopStatusManager;
use Comfino\ComfinoGateway\Model\System\Message\UpdateAvailable;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use Throwable;
use Zend_Db_Expr;

/**
 * Removes the module's data on `module:uninstall --remove-data`: settings (including API keys and the error reporting
 * token), update notice flag, queued requests, cached data, log files and unused custom order statuses. Orders are kept.
 */
class Uninstall implements UninstallInterface
{
    public const CONFIG_PATH_PREFIX = 'payment/comfino/';
    public const QUEUE_TABLE = 'comfino_request_queue';
    public const LOG_FILE_PATTERNS = ['comfino_debug*.log', 'comfino_errors*.log'];

    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly File $fileDriver,
        private readonly CacheInterface $cache,
        private readonly ConfigCache $configCache
    ) {
    }

    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $connection = $setup->getConnection();

        $setup->startSetup();

        $this->removeOrderStatuses($setup, $connection);

        $connection->delete($setup->getTable('core_config_data'), ['path LIKE ?' => self::CONFIG_PATH_PREFIX . '%']);
        $connection->delete($setup->getTable('flag'), ['flag_code = ?' => UpdateAvailable::DISMISS_FLAG]);

        if ($connection->isTableExists($queueTable = $setup->getTable(self::QUEUE_TABLE))) {
            $connection->delete($queueTable);
        }

        $setup->endSetup();

        $this->removeCachedData();
        $this->removeLogFiles();
    }

    private function removeCachedData(): void
    {
        try {
            $this->configCache->clean(\Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG, ['comfino', OutboundRequestQueueProcessor::CACHE_TAG]);
            $this->cache->clean([LoanSelection::CACHE_TAG]);
        } catch (Throwable) {
            // Cached entries expire on their own.
        }
    }

    private function removeLogFiles(): void
    {
        try {
            $logDir = $this->directoryList->getPath(DirectoryList::LOG);

            foreach (self::LOG_FILE_PATTERNS as $pattern) {
                foreach ($this->fileDriver->search($pattern, $logDir) as $logFile) {
                    $this->fileDriver->deleteFile($logFile);
                }
            }
        } catch (Throwable) {
            // A log file that cannot be removed must not stop the uninstall.
        }
    }

    private function removeOrderStatuses(SchemaSetupInterface $setup, AdapterInterface $connection): void
    {
        $statusCodes = array_keys(ShopStatusManager::CUSTOM_STATUS_LABELS);
        $statusTable = $setup->getTable('sales_order_status');
        $statusStateTable = $setup->getTable('sales_order_status_state');
        $orderTable = $setup->getTable('sales_order');
        $historyTable = $setup->getTable('sales_order_status_history');

        // Always unassigned from states - prevents future use regardless of history.
        $connection->delete($statusStateTable, ['status IN (?)' => $statusCodes]);

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
    }
}
