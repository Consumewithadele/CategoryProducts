<?php

declare(strict_types=1);

namespace Trespass\CategoryProducts\Model;

use Trespass\CategoryProducts\Api\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class ConfigProvider implements ConfigProviderInterface
{
    public const XML_PATH_SHOW_CATEGORY_EXPORT_BUTTON = 'categoryproducts/settings/display_category_product_export';

    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    public function getEnabledShowCategoryProductExport(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_CATEGORY_EXPORT_BUTTON,
            ScopeInterface::SCOPE_STORE
        );
    }
}
