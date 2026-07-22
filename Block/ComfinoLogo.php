<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Block
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

namespace Comfino\ComfinoGateway\Block;

use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Update\UpdateManager;
use Comfino\Frontend\FrontendHelper;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class ComfinoLogo extends Field
{
    /**
     * Allow-list for the release "what's new" HTML, mirroring the server-side sanitizer. Magento's escapeHtml keeps
     * these tags (and their safe attributes) and escapes everything else.
     */
    private const RELEASE_DESCRIPTION_ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 'b', 'i', 'ul', 'ol', 'li', 'h3', 'h4', 'code', 'pre', 'a', 'span', 'div', 'img'
    ];

    private Data $helper;

    /** @param array<string, mixed> $data */
    public function __construct(Data $helper, Context $context, array $data = [])
    {
        parent::__construct($context, $data);

        $this->helper = $helper;
    }

    public function render(AbstractElement $element): string
    {
        $logoHtml = FrontendHelper::renderAdminLogo(
            ApiClient::getInstance()->getApiBaseUrl(),
            'MG',
            $this->helper->getShopVersion(),
            $this->helper->getModuleVersion(),
            Data::BUILD_TS,
            'width: 300px; display: block',
            'Comfino logo'
        );

        $versionHtml = '<span style="font-weight: bold; font-size: 16px; margin-left: 10px">' .
            htmlspecialchars($this->helper->getModuleVersion(), ENT_QUOTES) . '</span>';

        $hyvaVersion = $this->helper->getHyvaCheckoutModuleVersion();

        if ($hyvaVersion !== null) {
            $versionHtml .= ' <span style="font-size: 12px; color: #666; margin-left: 8px">' .
                htmlspecialchars(__('Comfino Hyvä Checkout compatibility extension: %1', $hyvaVersion)->render(), ENT_QUOTES) . '</span>';
        }

        /* Logo + version in a flex row, mirroring the PrestaShop config header (views/templates/admin/configuration.tpl). */
        return '<div style="display: flex; align-items: center; overflow: hidden">' . $logoHtml . $versionHtml . '</div>' .
            $this->renderReleaseDescription();
    }

    /**
     * "What's new" HTML of the latest available release, shown under the logo/version - but only when a newer version
     * is available (hidden when up to date). Server-sanitized already; re-escaped here with Magento's escapeHtml
     * allow-list so the output stays safe per marketplace requirements.
     */
    private function renderReleaseDescription(): string
    {
        $updateInfo = UpdateManager::checkForUpdates($this->helper->getModuleVersion());

        if (empty($updateInfo['update_available']) || empty($updateInfo['description_html'])) {
            return '';
        }

        $updateAvailableMessage = '<div class="message message-warning comfino-update-available-message" style="margin-top: 10px">' .
            $this->escapeHtml(__(
                'New Comfino %1 module version is available. You are using %2 version. Please update your Comfino module.',
                $updateInfo['github_version'],
                $this->helper->getModuleVersion()
            )->render()) . '</div>';

        return $updateAvailableMessage . '<div class="comfino-release-description" style="margin-top: 10px">' .
            $this->escapeHtml($updateInfo['description_html'], self::RELEASE_DESCRIPTION_ALLOWED_TAGS) . '</div>';
    }
}
