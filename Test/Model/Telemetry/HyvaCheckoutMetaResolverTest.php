<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\Tests\Model\Telemetry;

use Comfino\ComfinoGateway\Model\Telemetry\HyvaCheckoutMetaResolver;
use PHPUnit\Framework\TestCase;

/**
 * The test suite's dependency set never installs Hyvä Checkout/Theme, so resolve() must degrade to an empty array
 * rather than throwing - the same path a plain Luma/Blank shop takes in production.
 */
final class HyvaCheckoutMetaResolverTest extends TestCase
{
    public function testResolveReturnsEmptyArrayWhenHyvaIsNotInstalled(): void
    {
        $this->assertSame([], (new HyvaCheckoutMetaResolver())->resolve());
    }
}