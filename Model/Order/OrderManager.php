<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Order;

use Comfino\Shop\Cart;
use Comfino\Shop\Order\CartBuilderInterface;
use Comfino\Shop\Order\Customer;
use Comfino\Shop\Order\CustomerBuilderInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order as MagentoOrder;

/**
 * Thin DI coordinator converting Magento entities to ComfinoPay DTOs via the SDK's MagentoCartBuilder and MagentoCustomerBuilder.
 */
class OrderManager
{
    public function __construct(
        private readonly CartBuilderInterface $cartBuilder,
        private readonly CustomerBuilderInterface $customerBuilder
    ) {
    }

    public function getShopCart(Quote $quote, int $priceModifier = 0): Cart
    {
        return $this->cartBuilder->buildCart($quote, $priceModifier);
    }

    public function getShopCartFromProduct(Product $product): Cart
    {
        return $this->cartBuilder->buildCartFromSingleProduct($product);
    }

    public function getShopCartFromOrder(MagentoOrder $order): Cart
    {
        return $this->cartBuilder->buildCart($order);
    }

    public function getShopCustomerFromOrder(MagentoOrder $order, string $remoteAddress, bool $isLoggedIn): Customer
    {
        return $this->customerBuilder->buildCustomer(
            platformOrder: $order,
            customerIp: $remoteAddress,
            isLogged: $isLoggedIn,
            isRegular: $order->getCustomerId() !== null
        );
    }
}
