<?php

declare(strict_types=1);

namespace Trespass\CategoryProducts\Model;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\CategoryRepository;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;
use Exception;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Model\ResourceModel\Iterator;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\App\ResourceConnection;

class Export
{
    public const FILE_PREFIX = 'trespass_category_products_id_';
    private StoreManagerInterface $storeManager;
    private CategoryRepository $categoryRepository;
    private WriteInterface $directory;
    private FileFactory $fileFactory;
    private LoggerInterface $logger;
    private TimezoneInterface $timezoneInterface;
    private Configurable $configurable;
    private CollectionFactory $productCollectionFactory;
    private CategoryCollectionFactory $categoryCollectionFactory;
    private Iterator $iterator;
    private AdapterInterface $connection;
    private ResourceConnection $resource;
    private array $productData = [];
    private array $productImageCount = [];

    public function __construct(
        StoreManagerInterface $storeManager,
        CategoryRepository $categoryRepository,
        Filesystem $filesystem,
        FileFactory $fileFactory,
        LoggerInterface $logger,
        TimezoneInterface $timezoneInterface,
        Configurable $configurable,
        CollectionFactory $productCollectionFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        Iterator $iterator,
        ResourceConnection $resource
    ) {
        $this->storeManager = $storeManager;
        $this->categoryRepository = $categoryRepository;
        $this->directory = $filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $this->fileFactory = $fileFactory;
        $this->logger = $logger;
        $this->timezoneInterface = $timezoneInterface;
        $this->configurable = $configurable;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->iterator = $iterator;
        $this->connection = $resource->getConnection();
        $this->resource = $resource;
    }

    public function exportCategoryProducts(int $categoryId, int $storeId)
    {
        $productsWithCustomSort = [];
        $productsWithoutCustomSort = [];
        $category = $this->getCategory($categoryId);
        if ($category) {
            $category->setStoreId($storeId);
            $categoryProductIds = $category->getProductCollection()
                ->getAllIds();
            if (!empty($categoryProductIds)) {
                $allProductIds = $this->getAllProductDataToExport($categoryProductIds, $storeId);
                $this->getProductImages($allProductIds);
                $this->getProductDetails($allProductIds, $storeId);
            }
            $categoryPathString = $this->getCategoryPathString($category);
            $productCustomSortPositions = $this->getProductSortPositions($category);
            foreach (array_unique($categoryProductIds) as $categoryProductId) {
                $sortPosition = null;
                if (array_key_exists($categoryProductId, $productCustomSortPositions)) {
                    $sortPosition = $productCustomSortPositions[$categoryProductId];
                    $productsWithCustomSort[] = $this
                        ->getProductRowData($categoryProductId, $category, $sortPosition, $categoryPathString);
                } else {
                    $productsWithoutCustomSort[] = $this
                        ->getProductRowData($categoryProductId, $category, $sortPosition, $categoryPathString);
                }
            }
            array_multisort(
                array_column($productsWithCustomSort, 'position'),
                SORT_ASC,
                $productsWithCustomSort
            );
            array_multisort(
                array_column($productsWithoutCustomSort, 'product_id'),
                SORT_DESC,
                $productsWithoutCustomSort
            );
        }
        $dataToExport = array_merge($productsWithCustomSort, $productsWithoutCustomSort);
        return $this->createCsv($dataToExport, $categoryId, $storeId);
    }

    protected function getCategory(int $categoryId)
    {
        try {
            return $this->categoryRepository->get($categoryId, $this->storeManager->getStore()->getId());
        } catch (Exception $exception) {
            $this->logger->critical($exception->getMessage());
        }
    }

    protected function getAllProductDataToExport(array $categoryProductIds, int $storeId): array
    {
        $allChildIds = [];
        foreach ($categoryProductIds as $categoryProductId) {
            if ($childIds = $this->hasChildrenProduct((int)$categoryProductId)) {
                foreach ($childIds as $childId) {
                    $allChildIds[] = $childId;
                }
            }
        }
        return array_merge($categoryProductIds, $allChildIds);
    }

    protected function createCsv(array $dataToExport, int $categoryId, int $storeId)
    {
        $timeStamp = $this->timezoneInterface->date()->format('YmdHis');
        $fileName = self::FILE_PREFIX . '_' . $storeId . '_' . $categoryId . '_'. $timeStamp .'.csv';
        $file = 'export/' . $fileName;
        try {
            $this->directory->create('export');
            $stream = $this->directory->openFile($file, 'w+');
            $stream->lock();
            $stream->writeCsv($this->getHeaders($dataToExport));
            foreach ($dataToExport as $record) {
                $stream->writeCsv($record);
                $productId = (int)$record['product_id'];
                if ($childIds = $this->hasChildrenProduct($productId)) {
                    $childProductData = [];
                    foreach ($childIds as $childId) {
                        $childProductRecord = $this->getProductRowData($childId);
                        $childProductData[$childProductRecord['sku']] = $childProductRecord;
                    }
                    ksort($childProductData);
                    foreach ($childProductData as $childProduct) {
                        $stream->writeCsv($childProduct);
                    }
                }
            }
            $stream->unlock();
            $stream->close();
            return $this->fileFactory->create(
                $fileName,
                [
                    'type' => 'filename',
                    'value' => $file,
                    'rm' => true
                ],
                'var'
            );
        } catch (Exception $exception) {
            $this->logger->critical($exception->getMessage());
        }
    }

    protected function getHeaders(array $dataToExport): array
    {
        if (!empty($dataToExport)) {
            return array_keys($dataToExport[0]);
        }
        return [];
    }

    protected function getProductRowData($productId, $category = null, $sortPosition = null, $categoryPath = ''): array
    {
        $rowData = [
            'category_id' => ($category)? $category->getId(): '',
            'category_path' => $categoryPath,
            'position' => $sortPosition
        ];
        if (array_key_exists($productId, $this->productData)) {
            $rowData = array_merge($rowData, $this->productData[$productId]);
        }
        return $rowData;
    }

    protected function hasChildrenProduct(int $productId)
    {
        $childIds = $this->configurable->getChildrenIds($productId);
        if (!empty($childIds)) {
            return $childIds[0];
        }
        return false;
    }

    protected function getProductDetails(array $productIds, int $storeId): void
    {
        $productRows = [];
        $productCollection = $this->productCollectionFactory->create()
            ->setStoreId($storeId)
            ->joinField(
                'qty',
                'cataloginventory_stock_item',
                'qty',
                'product_id=entity_id',
                '{{table}}.stock_id=1',
                'left'
            )->joinTable('cataloginventory_stock_item', 'product_id=entity_id', ['stock_status' => 'is_in_stock'])
            ->addAttributeToSelect(['name','status'], 'inner')
            ->addAttributeToFilter('entity_id', ['in',$productIds]);
        $this->iterator->walk($productCollection->getSelect(), [[$this, 'productCollectionCallback']]);
    }

    public function productCollectionCallback($args)
    {
        $data = $args['row'];
        $this->productData[$data['entity_id']] = [
            'product_id' => $data['entity_id'],
            'sku' => $data['sku'],
            'name' =>  $data['name'],
            'status' => ($data['status'] == 1)? 'Enabled': 'Disabled',
            'qty' => $data['qty'],
            'is_in_stock' => ($data['stock_status'] == 1)? 'In Stock': 'Out of Stock'
        ];
        if (array_key_exists($data['entity_id'], $this->productImageCount)) {
            $this->productData[$data['entity_id']]['image_count'] = $this->productImageCount[$data['entity_id']];
        } else {
            $this->productData[$data['entity_id']]['image_count'] = 0;
        }
    }

    protected function getCategoryPathString($category): string
    {
        $categoryPathNames = [];
        $pathIds = array_slice(explode('/', $category->getPath()), 2);//remove root paths
        if (!empty($pathIds)) {
            $categoryData = [];
            $categoryCollection = $this->categoryCollectionFactory->create()
                ->addAttributeToSelect('name')
                ->addAttributeToFilter('entity_id', ['in', $pathIds]);
            foreach ($categoryCollection as $category) {
                $categoryData[$category->getId()] = $category->getName();
            }
            foreach ($pathIds as $id) {
                $categoryPathNames[] = $categoryData[$id];
            }
        }
        return implode('/', $categoryPathNames);
    }

    protected function getProductSortPositions($category) :array
    {
        return $category->getProductsPosition();
    }

    protected function getProductImages($allProductIds)
    {
        $productTable = 'catalog_product_entity';
        $imageTable = 'catalog_product_entity_media_gallery_value_to_entity';
        $select = $this->connection->select()
            ->from(['product' => $productTable], ['product_id' => 'product.entity_id'])
            ->joinLeft(['gallery' =>$imageTable], 'product.row_id = gallery.row_id', ['image_count' => 'count(gallery.value_id)'])
            ->where('product.entity_id IN (?)', $allProductIds)
            ->group('product_id');

        $result = $this->connection->fetchAll($select);
        foreach ($result as $data) {
            $this->productImageCount[$data['product_id']] = $data['image_count'];
        }
    }
}
