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

namespace Comfino\Tests\Model\Config\Comment;

use Comfino\ComfinoGateway\Model\Config\Comment\ApiKeyComment;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

final class ApiKeyCommentTest extends TestCase
{
    public function testShowsOnlyTheEndOfTheSavedKey(): void
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('0123456789abcdef0123456789xyzw');

        $comment = (new ApiKeyComment($encryptor))->getCommentText('0:3:encrypted');

        $this->assertSame('Saved key ending in …xyzw', $comment);
        $this->assertStringNotContainsString('0123456789', $comment);
    }

    public function testNoCommentWithoutASavedKey(): void
    {
        $this->assertSame('', (new ApiKeyComment($this->createMock(EncryptorInterface::class)))->getCommentText(''));
    }
}
