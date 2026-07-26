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

use Comfino\ComfinoGateway\Block\ErrorLog;
use Comfino\Tests\Support\LoggerHarnessTrait;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Element\Template;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * ErrorLog::render() assigns the error-log content and clear URL to the template, then defers to toHtml(). The block is
 * subclassed to bypass the Magento Template constructor, stub getUrl()/getFormKey()/toHtml(), and expose the assigned
 * _viewVars. The error-log content comes from the SDK error logger, which the logger harness replaces with an
 * API-stubbed instance backed by a temp log file (so getErrorLog() simply returns '').
 */
final class ErrorLogTest extends TestCase
{
    use LoggerHarnessTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installLoggerHarness();
    }

    protected function tearDown(): void
    {
        $this->resetLoggerHarness();

        parent::tearDown();
    }

    public function testAssignsLogContentAndClearMetadata(): void
    {
        $block = new class extends ErrorLog {
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

        $this->assertSame('http://shop.test/comfino/log/clear?type=error', $vars['clearUrl']);
        $this->assertSame('comfino_error_log_content', $vars['textareaId']);
        $this->assertSame('', $vars['logContent']);
        $this->assertSame('Are you sure you want to clear the log?', $vars['clearConfirmation']);
        $this->assertSame('Clear errors log', $vars['buttonLabel']);
    }
}