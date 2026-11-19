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
 * Accepts only an unambiguous boolean (true/false, 1/0, "1"/"0", "true"/"false", "yes"/"no", "") and stores a bool.
 */
final class BooleanConstraint implements ConfigValueConstraintInterface
{
    private const TRUE_VALUES = [true, 1, '1', 'true', 'yes'];
    private const FALSE_VALUES = [false, 0, '0', 'false', 'no', ''];

    public function apply(string $optionName, mixed $value): mixed
    {
        $normalized = is_string($value) ? strtolower(trim($value)) : $value;

        if (in_array($normalized, self::TRUE_VALUES, true)) {
            return true;
        }

        if (in_array($normalized, self::FALSE_VALUES, true)) {
            return false;
        }

        throw new InvalidConfigValue("$optionName must be a boolean.");
    }
}
