<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\System\Message
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
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
 * Admin system-message notice about an available module update. Dismissal is keyed to the available version, so a
 * newer release re-shows it; reads UpdateManager's cached release info and makes no extra API calls.
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
            'New ComfinoPay %1 module version is available. You are using %2 version. Please update your ComfinoPay module.',
            $newVersion,
            $this->helper->getModuleVersion()
        );

        $releaseNotesUrl = (string) ($info['release_notes_url'] ?? '');

        if ($releaseNotesUrl !== '' && str_starts_with(strtolower($releaseNotesUrl), 'https://')) {
            $text .= ' <a href="' . htmlspecialchars($releaseNotesUrl, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer">' .
                __('View release notes') . '</a>.';
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
