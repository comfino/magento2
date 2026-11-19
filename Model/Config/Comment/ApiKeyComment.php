<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Config\Comment
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Config\Comment;

use Comfino\ComfinoGateway\Model\Configuration\ApiKeyCrypt;
use Magento\Config\Model\Config\CommentInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Comment under a masked API key field: which saved key is in use ("Saved key ending in …abcd"), so the merchant can
 * tell keys apart without the key being sent to the page. Only the last characters are shown.
 */
class ApiKeyComment implements CommentInterface
{
    public function __construct(private readonly EncryptorInterface $encryptor)
    {
    }

    /**
     * @param string $elementValue Stored value of the field (decrypted by the backend model, or still encrypted)
     */
    public function getCommentText($elementValue)
    {
        $apiKey = ApiKeyCrypt::decrypt($elementValue, $this->encryptor);

        if ($apiKey === '' || ($tail = ApiKeyCrypt::tail($apiKey)) === '') {
            return '';
        }

        return (string) __('Saved key ending in …%1', $tail);
    }
}
