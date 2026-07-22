<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Plugin
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Plugin;

use Comfino\ComfinoGateway\Model\Bootstrap;
use Magento\Framework\App\RequestInterface;
use Magento\Webapi\Controller\Rest;

/**
 * Ensures Comfino SDK Bootstrap::init() runs before any REST API request.
 *
 * The controller_action_predispatch fires only for MVC controllers, not for REST API requests which go through
 * Magento\Webapi\Controller\Rest. This plugin fills that gap.
 */
class RestBootstrapPlugin
{
    public function __construct(private readonly Bootstrap $bootstrap)
    {
    }

    public function beforeDispatch(Rest $subject, RequestInterface $request): array
    {
        $this->bootstrap->init();

        return [$request];
    }
}
