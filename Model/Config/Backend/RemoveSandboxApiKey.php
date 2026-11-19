<?php

/**
 * ComfinoPay Payment Gateway for Magento 2
 *
 * @package Comfino\ComfinoGateway\Model\Config\Backend
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License 3.0
 * @link https://github.com/comfino/magento2
 */

declare(strict_types=1);

namespace Comfino\ComfinoGateway\Model\Config\Backend;

use Comfino\ComfinoGateway\Helper\Data;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * "Remove saved test environment API key" switch: when saved as Yes, the sandbox key of the scope being saved is deleted
 * and the switch itself is stored as No again.
 */
class RemoveSandboxApiKey extends Value
{
    private bool $removeRequested = false;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly WriterInterface $configWriter,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return RemoveSandboxApiKey
     */
    public function beforeSave()
    {
        $this->removeRequested = (bool) $this->getValue();
        $this->setValue('0');

        return parent::beforeSave();
    }

    /**
     * @return RemoveSandboxApiKey
     */
    public function afterSave()
    {
        if ($this->removeRequested) {
            $this->configWriter->delete(Data::XML_PATH_SANDBOX_API_KEY, (string) $this->getScope(), (int) $this->getScopeId());
        }

        return parent::afterSave();
    }
}
