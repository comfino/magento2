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

use Comfino\ComfinoGateway\Helper\Data;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds CANCELLED_BY_SHOP to stored forbidden-status lists that still equal the pre-4.1.0 default (RESIGN only), in every
 * scope. A shop's own cancellation must not be echoed back as a status change. Lists a merchant has edited are kept.
 */
class AddCancelledByShopToForbiddenStatuses implements DataPatchInterface
{
    public const OLD_DEFAULT = 'RESIGN';
    public const NEW_DEFAULT = 'RESIGN,CANCELLED_BY_SHOP';

    private ModuleDataSetupInterface $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $connection->update(
            $this->moduleDataSetup->getTable('core_config_data'),
            ['value' => self::NEW_DEFAULT],
            ['path = ?' => Data::XML_PATH_FORBIDDEN_STATUSES, 'TRIM(value) = ?' => self::OLD_DEFAULT]
        );

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
