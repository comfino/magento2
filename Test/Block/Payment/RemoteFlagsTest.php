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

namespace Comfino\Tests\Block\Payment;

use Comfino\ComfinoGateway\Block\Payment\RemoteFlags;
use PHPUnit\Framework\TestCase;

/**
 * The flags map comes from the API unfiltered and is written into an HTML page: the encoding is the only protection.
 */
final class RemoteFlagsTest extends TestCase
{
    public function testFlagValuesCannotEndTheElementOrOpenAComment(): void
    {
        $json = RemoteFlags::encode([
            'flags' => ['PAYMENT_ITEM_PROMOTION'],
            'flagAttributes' => ['PROMO' => ['amount' => 5000, 'label' => "</script><!-- 'x' & \"y\""]],
        ]);

        $this->assertStringNotContainsString('</script>', $json);
        $this->assertStringNotContainsString('<!--', $json);
        $this->assertStringNotContainsString("'", $json);
        $this->assertStringNotContainsString('&', $json);
        $this->assertSame(
            "</script><!-- 'x' & \"y\"",
            json_decode($json, true, 512, JSON_THROW_ON_ERROR)['flagAttributes']['PROMO']['label']
        );
    }

    public function testKeepsNumbersAndObjectsAsTheApiSentThem(): void
    {
        $decoded = json_decode(
            RemoteFlags::encode(['flags' => ['PROMO_A'], 'flagAttributes' => ['PROMO' => ['amount' => 1500, 'rate' => 1.0, 'allowedTypes' => []]]]),
            false,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(1500, $decoded->flagAttributes->PROMO->amount);
        $this->assertSame(1.0, $decoded->flagAttributes->PROMO->rate);
    }

    public function testNothingIsWrittenWithoutFlags(): void
    {
        $this->assertSame('', RemoteFlags::encode(['flags' => null, 'flagAttributes' => null]));
    }
}
