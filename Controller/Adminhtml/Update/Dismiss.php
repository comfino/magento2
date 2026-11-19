<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Controller\Adminhtml\Update
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Controller\Adminhtml\Update;

use Comfino\ComfinoGateway\Model\System\Message\UpdateAvailable;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\FlagManager;

/**
 * Dismisses the "update available" admin message for a version, until a newer release appears. The backend URL carries
 * the admin secret key, so the GET is CSRF-protected.
 */
class Dismiss extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Config::config';

    private FlagManager $flagManager;

    public function __construct(Context $context, FlagManager $flagManager)
    {
        parent::__construct($context);

        $this->flagManager = $flagManager;
    }

    public function execute()
    {
        $version = trim((string) $this->getRequest()->getParam('version', ''));

        if ($version !== '') {
            $this->flagManager->saveFlag(UpdateAvailable::DISMISS_FLAG, $version);
        }

        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();

        return $resultRedirect->setRefererOrBaseUrl();
    }
}
