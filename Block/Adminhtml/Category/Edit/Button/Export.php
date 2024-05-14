<?php

declare(strict_types=1);

namespace Trespass\CategoryProducts\Block\Adminhtml\Category\Edit\Button;

use Magento\Catalog\Block\Adminhtml\Category\AbstractCategory;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\ResourceModel\Category\Tree as CategoryTree;
use Magento\Backend\Block\Widget\Context as WidgetContext;
use Magento\Catalog\Model\Category;
use Trespass\CategoryProducts\Api\ConfigProviderInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

class Export extends AbstractCategory implements ButtonProviderInterface
{
    public const CSV_URL = 'trespass_categoryproducts/export/index';
    private WidgetContext $widgetContext;
    private ConfigProviderInterface $configProvider;
    private StoreManagerInterface $storeManager;

    public function __construct(
        Context $context,
        CategoryTree $categoryTree,
        Registry $registry,
        CategoryFactory $categoryFactory,
        WidgetContext $widgetContext,
        ConfigProviderInterface $configProvider,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $categoryTree, $registry, $categoryFactory, $data);
        $this->widgetContext = $widgetContext;
        $this->configProvider = $configProvider;
        $this->storeManager = $storeManager;
    }

    public function getButtonData(): array
    {
        /** @var Category $category */
        $category = $this->getCategory();
        $categoryId = (int)$category->getId();
        $url = $this->getButtonUrl($categoryId);

        if ($categoryId
            && $this->getShowCategoryProductExport()
            && !in_array($categoryId, $this->getRootIds())
            && $category->isDeleteable()) {
            return [
                'id' => 'category_products_export',
                'label' => __('Export Category Products'),
                'class' => 'view action-secondary',
                'on_click' =>  sprintf("window.open('%s', '_blank');", $url),
                'sort_order' => 10
            ];
        }

        return [];
    }

    public function getButtonUrl(int $id): string
    {
        return $this->widgetContext->getUrlBuilder()->getUrl(
            self::CSV_URL,
            ['category_id' => $id, 'store_id' => $this->getCurrentStoreId()]
        );
    }

    public function getShowCategoryProductExport(): bool
    {
        return $this->configProvider->getEnabledShowCategoryProductExport();
    }

    public function getCurrentStoreId(): int
    {
        return (int)$this->storeManager->getStore()->getId();
    }
}
