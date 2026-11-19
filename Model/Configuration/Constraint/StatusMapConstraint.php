<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Configuration\Constraint
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Configuration\Constraint;

use Comfino\Backend\Configuration\Constraint\ConfigValueConstraintInterface;
use Comfino\Backend\Configuration\Constraint\InvalidConfigValue;

/**
 * Accepts a ComfinoPay status => shop order status map (array or JSON object) whose every key is a known ComfinoPay
 * status and every value one of the allowed shop statuses. The whole map is rejected when any entry is not acceptable.
 */
final class StatusMapConstraint implements ConfigValueConstraintInterface
{
    /**
     * @param string[] $comfinoStatuses
     * @param string[] $shopStatuses
     */
    public function __construct(private readonly array $comfinoStatuses, private readonly array $shopStatuses)
    {
    }

    public function apply(string $optionName, mixed $value): mixed
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (!is_array($value) || $value === []) {
            throw new InvalidConfigValue("$optionName must be a non-empty status map.");
        }

        foreach ($value as $comfinoStatus => $shopStatus) {
            if (!is_string($shopStatus) ||
                !in_array((string) $comfinoStatus, $this->comfinoStatuses, true) ||
                !in_array($shopStatus, $this->shopStatuses, true)
            ) {
                throw new InvalidConfigValue("$optionName contains an entry that is not allowed.");
            }
        }

        return $value;
    }
}
