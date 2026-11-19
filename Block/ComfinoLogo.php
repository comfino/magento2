<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Block
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Block;

use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Configuration\ScopedApiCredentials;
use Comfino\ComfinoGateway\Model\Update\UpdateManager;
use Comfino\Frontend\FrontendHelper;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Logo, module version and "what's new" of an available update at the top of the ComfinoPay configuration section.
 */
class ComfinoLogo extends Field
{
    /**
     * Allow-list for the release "what's new" HTML. Magento's escapeHtml keeps these tags (and their safe attributes)
     * and escapes everything else.
     */
    public const RELEASE_DESCRIPTION_ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 'b', 'i', 'ul', 'ol', 'li', 'h3', 'h4', 'code', 'pre', 'a', 'span', 'div', 'img'
    ];

    protected $_template = 'Comfino_ComfinoGateway::comfino-logo.phtml';

    private Data $helper;
    private ScopedApiCredentials $apiCredentials;

    /** @param array<string, mixed> $data */
    public function __construct(Data $helper, ScopedApiCredentials $apiCredentials, Context $context, array $data = [])
    {
        parent::__construct($context, $data);

        $this->helper = $helper;
        $this->apiCredentials = $apiCredentials;
    }

    /**
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function render(AbstractElement $element): string
    {
        // The API host of the scope being edited, not of the ambient store (always the default store view in the backend).
        [$scope, $scopeId] = $this->apiCredentials->resolveScope(
            (string) $this->getRequest()->getParam('website', ''),
            (string) $this->getRequest()->getParam('store', '')
        );
        $apiClient = $this->apiCredentials->getApiClient($this->apiCredentials->getCredentials($scope, $scopeId));

        $moduleVersion = $this->helper->getModuleVersion();
        $updateInfo = UpdateManager::checkForUpdates($moduleVersion);

        $this->setData([
            // Built by the SDK, which escapes every attribute value it writes.
            'logo_html' => FrontendHelper::renderAdminLogo(
                $apiClient->getApiBaseUrl(),
                'MG',
                $this->helper->getShopVersion(),
                $moduleVersion,
                Data::BUILD_TS,
                'width: 300px; display: block',
                'ComfinoPay logo'
            ),
            'module_version' => $moduleVersion,
            'hyva_checkout_module_version' => $this->helper->getHyvaCheckoutModuleVersion(),
            'available_version' => !empty($updateInfo['update_available']) ? (string) ($updateInfo['github_version'] ?? '') : '',
            'release_description_html' => !empty($updateInfo['update_available']) ? (string) ($updateInfo['description_html'] ?? '') : '',
        ]);

        return $this->toHtml();
    }
}
