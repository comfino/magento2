<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Setup\Patch\Data
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Setup\Patch\Data;

use Comfino\ComfinoGateway\Model\Order\ShopStatusManager;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Comfino is now ComfinoPay: renames order status labels and the payment method title/text in every scope, only where
 * still a known old default (compared trimmed). Merchant-edited texts, status codes and config paths are unchanged.
 */
class RebrandToComfinoPay implements DataPatchInterface
{
    public const OLD_NAME = 'Comfino';
    public const NEW_NAME = 'ComfinoPay';
    public const PAYMENT_NAME_PATHS = ['payment/comfino/title', 'payment/comfino/payment_text'];

    /** Defaults shipped by earlier versions and development builds; anything else is a merchant's own text. */
    public const OLD_DEFAULT_NAMES = [
        self::OLD_NAME,
        '(Raty | Kup Teraz, Zapłać Później | Finansowanie dla Firm)',
        self::OLD_NAME . ' (Raty | Kup Teraz, Zapłać Później | Finansowanie dla Firm)',
    ];

    public function __construct(private readonly ModuleDataSetupInterface $moduleDataSetup)
    {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $statusTable = $this->moduleDataSetup->getTable('sales_order_status');

        foreach (ShopStatusManager::CUSTOM_STATUS_LABELS as $statusCode => $details) {
            $connection->update(
                $statusTable,
                ['label' => $details['label']],
                ['status = ?' => $statusCode, 'label = ?' => self::oldLabel($details['label'])]
            );
        }

        $connection->update(
            $this->moduleDataSetup->getTable('core_config_data'),
            ['value' => self::NEW_NAME],
            ['path IN (?)' => self::PAYMENT_NAME_PATHS, 'TRIM(value) IN (?)' => self::OLD_DEFAULT_NAMES]
        );

        $connection->endSetup();

        return $this;
    }

    /**
     * The label a status was created with before the rebrand: "(ComfinoPay)" → "(Comfino)".
     */
    public static function oldLabel(string $label): string
    {
        return str_replace('(' . self::NEW_NAME . ')', '(' . self::OLD_NAME . ')', $label);
    }

    public static function getDependencies(): array
    {
        return [AddComfinoOrderStatuses::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
