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

namespace Comfino\Tests\Block;

use Comfino\Backend\Log\DebugLogger as SdkDebugLogger;
use Comfino\ComfinoGateway\Block\DebugLog;
use Comfino\ComfinoGateway\Logger\Debug as DebugLogger;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Element\Template;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * DebugLog::render() assigns the debug-log content and clear URL to the template, then defers to toHtml(). The block is
 * subclassed to bypass the Magento Template constructor, stub getUrl()/getFormKey()/toHtml(), and expose the assigned
 * _viewVars. The debug log content comes from the SDK debug logger, which is replaced here with a mock so no log file
 * is read.
 */
final class DebugLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $sdkLogger = $this->getMockBuilder(SdkDebugLogger::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDebugLog'])
            ->getMock();
        $sdkLogger->method('getDebugLog')->with(200)->willReturn("line-1\nline-2\n");

        (new ReflectionProperty(DebugLogger::class, 'debugLogger'))->setValue(null, $sdkLogger);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(DebugLogger::class, 'debugLogger'))->setValue(null, null);

        parent::tearDown();
    }

    public function testAssignsLogContentAndClearMetadata(): void
    {
        $block = new class extends DebugLog {
            public function __construct()
            {
            }

            public function getUrl($route = '', $params = [])
            {
                return 'http://shop.test/' . $route . '?type=' . ($params['type'] ?? '');
            }

            public function getFormKey()
            {
                return 'form-key-123';
            }

            public function toHtml(): string
            {
                return '';
            }
        };

        (new ReflectionProperty(Template::class, '_viewVars'))->setValue($block, []);

        $block->render($this->createMock(AbstractElement::class));

        $vars = (new ReflectionProperty(Template::class, '_viewVars'))->getValue($block);

        $this->assertSame('http://shop.test/comfino/log/clear?type=debug', $vars['clearUrl']);
        $this->assertSame('comfino_debug_log_content', $vars['textareaId']);
        $this->assertSame("line-1\nline-2\n", $vars['logContent']);
        $this->assertSame('Are you sure you want to clear the log?', $vars['clearConfirmation']);
    }
}