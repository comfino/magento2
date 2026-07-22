<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\Tests\Plugin
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Magento\Webapi\Controller {
    /* Magento\Webapi\Controller\Rest belongs to the optional module-webapi package, which is not present in the test
       dependency set. A minimal guarded stand-in is declared so the plugin's type hint resolves; the plugin never
       reads $subject, so the stub needs no behavior. */
    if (!class_exists(Rest::class, false)) {
        class Rest
        {
        }
    }
}

namespace Comfino\Tests\Plugin {
    use Comfino\ComfinoGateway\Model\Bootstrap;
    use Comfino\ComfinoGateway\Plugin\RestBootstrapPlugin;
    use Magento\Framework\App\RequestInterface;
    use Magento\Webapi\Controller\Rest;
    use PHPUnit\Framework\TestCase;

    final class RestBootstrapPluginTest extends TestCase
    {
        public function testBeforeDispatchInitializesBootstrapAndPassesRequestThrough(): void
        {
            $bootstrap = $this->createMock(Bootstrap::class);
            $bootstrap->expects($this->once())->method('init');

            $subject = new Rest();
            $request = $this->createMock(RequestInterface::class);

            $result = (new RestBootstrapPlugin($bootstrap))->beforeDispatch($subject, $request);

            $this->assertSame([$request], $result);
        }
    }
}