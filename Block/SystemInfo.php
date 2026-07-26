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

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Block;

use Comfino\Api\Exception\AccessDenied;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\ComfinoGateway\Gateway\Http\ApiClient;
use Comfino\ComfinoGateway\Helper\Data;
use Comfino\ComfinoGateway\Model\Configuration\ConfigManager;
use Comfino\ComfinoGateway\Model\Update\UpdateManager;
use DateTime;
use Magento\Backend\Block\Template;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Throwable;

class SystemInfo extends Field
{
    private Data $helper;

    protected $_template = 'Comfino_ComfinoGateway::system-info.phtml';

    /** @param array<string, mixed> $data */
    public function __construct(Data $helper, Template\Context $context, array $data = [])
    {
        parent::__construct($context, $data);

        $this->helper = $helper;
    }

    public function render(AbstractElement $element): string
    {
        $moduleVersion = $this->helper->getModuleVersion();
        $buildTime = DateTime::createFromFormat('U', (string) Data::BUILD_TS)->format('Y-m-d H:i:s');

        $serverSoftware = getenv('SERVER_SOFTWARE');

        if ($serverSoftware === false) {
            $serverSoftware = 'n/a';
        }

        $infoMessages = [
            sprintf(
                'Magento Comfino %s, Magento %s, PHP %s, web server %s, database %s',
                $moduleVersion,
                $this->helper->getShopVersion(),
                PHP_VERSION,
                $serverSoftware,
                $this->helper->getDatabaseInfo()
            ),
            ApiClient::getInstance()->getApiBaseUrl(),
            $buildTime,
            $this->helper->getShopDomain(),
            ConfigManager::getWidgetKey(),
        ];

        $successMessages = [];
        $warningMessages = [];
        $errorMessages = [];

        $updateInfo = UpdateManager::checkForUpdates($moduleVersion);

        if (ConfigManager::isSandboxMode()) {
            $warningMessages[] = __('Developer mode is active. You are using test environment.');
            if (!empty(ConfigManager::getApiKey())) {
                try {
                    ApiClient::getInstance()->isShopAccountActive();
                    $successMessages[] = __('Test account is active.');
                } catch (AuthorizationError | AccessDenied $e) {
                    $errorMessages[] = $e->getMessage();
                    $errorMessages[] = __('Invalid test API key.');
                } catch (Throwable) {
                    $warningMessages[] = __('Test account is not active.');
                }
            } else {
                $errorMessages[] = __('Test API key not present.');
            }
        } elseif (!empty(ConfigManager::getApiKey())) {
            $successMessages[] = __('Production mode is active.');
            try {
                ApiClient::getInstance()->isShopAccountActive();
                $successMessages[] = __('Production account is active.');
            } catch (AuthorizationError | AccessDenied $e) {
                $errorMessages[] = $e->getMessage();
                $errorMessages[] = __('Invalid production API key.');
            } catch (Throwable) {
                $warningMessages[] = __('Production account is not active.');
            }
        } else {
            $errorMessages[] = __('Production API key not present.');
        }

        $devEnvActive = getenv('COMFINO_DEV_ENV') === 'TRUE';
        $devEnvVars = [];
        $hiddenItems = [];

        if ($devEnvActive) {
            $varNames = [
                'COMFINO_DEV_ENV',
                'COMFINO_DEV_API_HOST',
                'COMFINO_DEV_SDK_CDN_BASE_URL',
            ];

            foreach ($varNames as $varName) {
                $devEnvVars[$varName] = getenv($varName) ?: '';
            }

            $serializer = new JsonSerializer();

            foreach (ConfigManager::getConfigurationValues('hidden_settings') as $optionName => $optionValue) {
                if (is_array($optionValue) || is_bool($optionValue)) {
                    $optionValue = $serializer->serialize($optionValue);
                }

                $optionValue = (string) $optionValue;

                if (strlen($optionValue) > 200) {
                    $optionValue = substr($optionValue, 0, 200) . '...';
                }

                $hiddenItems[$optionName] = $optionValue;
            }
        }

        $this->setData([
            'info_messages' => $infoMessages,
            'success_messages' => $successMessages,
            'warning_messages' => $warningMessages,
            'error_messages' => $errorMessages,
            'update_info' => $updateInfo,
            'module_version' => $moduleVersion,
            'hyva_checkout_module_version' => $this->helper->getHyvaCheckoutModuleVersion(),
            'dev_env_active' => $devEnvActive,
            'dev_env_vars' => $devEnvVars,
            'hidden_items' => $hiddenItems,
            'use_dev_env_vars' => ConfigManager::useDevEnvVars(),
        ]);

        return $this->toHtml();
    }
}
