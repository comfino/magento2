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
 * Accepts a list (array or comma-separated string) whose every item is one of the allowed values, or matches the pattern
 * when one is given instead. The whole value is rejected when any item is not acceptable. Stores an array.
 */
final class ListConstraint implements ConfigValueConstraintInterface
{
    /**
     * @param string[] $allowedValues Allowed items; ignored when $pattern is given
     * @param string|null $pattern Regular expression every item must match
     */
    public function __construct(private readonly array $allowedValues = [], private readonly ?string $pattern = null)
    {
    }

    public static function toList(mixed $value): ?array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return null;
        }

        $items = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                return null;
            }

            if (($item = trim($item)) !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function apply(string $optionName, mixed $value): mixed
    {
        if (($items = self::toList($value)) === null) {
            throw new InvalidConfigValue("$optionName must be a list of strings.");
        }

        foreach ($items as $item) {
            $valid = $this->pattern !== null
                ? preg_match($this->pattern, $item) === 1
                : in_array($item, $this->allowedValues, true);

            if (!$valid) {
                throw new InvalidConfigValue("$optionName contains a value that is not allowed.");
            }
        }

        return array_values(array_unique($items));
    }
}
