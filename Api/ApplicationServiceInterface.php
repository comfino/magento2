<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Api
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Api;

interface ApplicationServiceInterface
{
    /**
     * Creates an application in the Comfino API and returns the redirect URL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function save(): array;
}
