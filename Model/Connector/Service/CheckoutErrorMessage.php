<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Connector\Service
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Connector\Service;

/**
 * Shopper-facing messages for order data the ComfinoPay API rejected: a fixed translated message per named field, or a
 * generic one. The API's own text is untranslated and may quote submitted data, so it goes to logs only.
 */
final class CheckoutErrorMessage
{
    /**
     * @param array<int|string, mixed> $fieldErrors Field path => message(s), as from RequestValidationError::getFieldErrors()
     *                                              or ValidateOrder::$errors; only the field paths are used
     */
    public static function forFieldErrors(array $fieldErrors): string
    {
        $messages = [];

        foreach (array_keys($fieldErrors) as $fieldPath) {
            if (($message = self::forField((string) $fieldPath)) !== null) {
                $messages[$message] = true;
            }
        }

        if ($messages === []) {
            return (string) __('ComfinoPay could not accept the order data. Please check your billing and delivery details and try again.');
        }

        return implode(' ', array_keys($messages));
    }

    /**
     * Message for one field path such as "customer.address.postalCode" or "customer[phoneNumber]", or null
     * for a field without a dedicated message. Only the last segment of the path is matched.
     */
    private static function forField(string $fieldPath): ?string
    {
        $segments = preg_split('/[.\[\]\/]+/', $fieldPath, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $field = strtolower((string) end($segments));

        $message = match ($field) {
            'email' => __('Invalid customer e-mail address. Please check your account contact data.'),
            'phonenumber', 'phone' => __('Phone number is required. Please add a phone number to your billing or delivery address.'),
            'firstname' => __('First name is required.'),
            'lastname' => __('Last name is required.'),
            'postalcode' => __('Postal code is required.'),
            'city' => __('City is required.'),
            'street', 'buildingnumber', 'address' => __('Delivery address is required.'),
            default => null,
        };

        return $message !== null ? (string) $message : null;
    }
}
