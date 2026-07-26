<?php

/**
 * Comfino Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\System\Message
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\System\Message;

use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Update\UpdateManager;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Notification\MessageInterface;
use Throwable;

/**
 * Admin system-message notice about an available Comfino module update, mirroring the PrestaShop dashboard update
 * notice. Shown in the admin system-messages area until dismissed; dismissal is keyed to the available version
 * (stored via FlagManager), so a newer release re-shows the notice. Reads UpdateManager's own 24h-cached release
 * info, so it never drifts from the config-header note nor triggers extra release-API calls.
 */
class UpdateAvailable implements MessageInterface
{
    /** Flag storing the release version the admin last dismissed. */
    public const DISMISS_FLAG = 'comfino_update_notice_dismissed';

    private Data $helper;
    private UrlInterface $backendUrl;
    private FlagManager $flagManager;

    /** @var array<string, mixed>|null */
    private ?array $updateInfo = null;

    public function __construct(Data $helper, UrlInterface $backendUrl, FlagManager $flagManager)
    {
        $this->helper = $helper;
        $this->backendUrl = $backendUrl;
        $this->flagManager = $flagManager;
    }

    public function getIdentity()
    {
        return 'comfino_update_notice_' . md5((string) ($this->getUpdateInfo()['github_version'] ?? ''));
    }

    public function isDisplayed()
    {
        $info = $this->getUpdateInfo();

        if (empty($info['update_available']) || empty($info['github_version'])) {
            return false;
        }

        return $this->flagManager->getFlagData(self::DISMISS_FLAG) !== $info['github_version'];
    }

    public function getText()
    {
        $info = $this->getUpdateInfo();
        $newVersion = (string) ($info['github_version'] ?? '');

        $text = (string) __(
            'New Comfino %1 module version is available. You are using %2 version. Please update your Comfino module.',
            $newVersion,
            $this->helper->getModuleVersion()
        );

        $releaseNotesUrl = (string) ($info['release_notes_url'] ?? '');

        if ($releaseNotesUrl !== '') {
            $text .= ' <a href="' . htmlspecialchars($releaseNotesUrl, ENT_QUOTES) . '" target="_blank">'
                . __('View release notes') . '</a>.';
        }

        $dismissUrl = $this->backendUrl->getUrl('comfino/update/dismiss', ['version' => $newVersion]);
        $text .= ' <a href="' . htmlspecialchars($dismissUrl, ENT_QUOTES) . '">' . __('Dismiss') . '</a>';

        return $text;
    }

    public function getSeverity()
    {
        return self::SEVERITY_NOTICE;
    }

    /**
     * @return array<string, mixed>
     */
    private function getUpdateInfo(): array
    {
        if ($this->updateInfo === null) {
            try {
                $this->updateInfo = UpdateManager::checkForUpdates($this->helper->getModuleVersion());
            } catch (Throwable $e) {
                $this->updateInfo = [];
            }
        }

        return $this->updateInfo;
    }
}
