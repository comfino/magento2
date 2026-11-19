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

use Closure;
use Comfino\Backend\Configuration\Constraint\ConfigValueConstraintInterface;
use Comfino\Backend\Configuration\Constraint\InvalidConfigValue;

/**
 * Accepts an empty value or a stylesheet URL on the shop's own host: https only (http too when dev
 * overrides are on), no credentials, no characters that could break out of an HTML attribute.
 */
final class CssUrlConstraint implements ConfigValueConstraintInterface
{
    /**
     * @param Closure(): string[] $shopHosts Hosts ("host" or "host:port") of the shop's base URLs
     * @param Closure(): bool $allowHttp Whether plain http is accepted
     */
    public function __construct(private readonly Closure $shopHosts, private readonly Closure $allowHttp)
    {
    }

    /**
     * @param string[] $shopHosts
     */
    public static function isValid(mixed $url, array $shopHosts, bool $allowHttp): bool
    {
        if ($url === null || (is_string($url) && trim($url) === '')) {
            return true;
        }

        if (!is_string($url) || preg_match('/[\x00-\x20"\'<>\\\\`]/', $url = trim($url)) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if ($scheme !== 'https' && !($scheme === 'http' && $allowHttp)) {
            return false;
        }

        return in_array(self::normalizeHost($parts['host'], $parts['port'] ?? null), $shopHosts, true);
    }

    public static function normalizeHost(string $host, ?int $port): string
    {
        $host = strtolower($host);

        return $port !== null && !in_array($port, [80, 443], true) ? "$host:$port" : $host;
    }

    public function apply(string $optionName, mixed $value): mixed
    {
        if (!self::isValid($value, ($this->shopHosts)(), ($this->allowHttp)())) {
            throw new InvalidConfigValue("$optionName must be an https:// URL on the shop's own domain.");
        }

        return is_string($value) ? trim($value) : '';
    }
}
