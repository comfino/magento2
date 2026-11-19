<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Telemetry;

use Comfino\ComfinoGateway\Model\Telemetry\HyvaCheckoutMetaResolver;
use PHPUnit\Framework\TestCase;

/**
 * Hyvä is never installed in the test dependency set, so resolve() must return an empty array, as on a Luma/Blank shop.
 */
final class HyvaCheckoutMetaResolverTest extends TestCase
{
    public function testResolveReturnsEmptyArrayWhenHyvaIsNotInstalled(): void
    {
        $this->assertSame([], (new HyvaCheckoutMetaResolver())->resolve());
    }
}
