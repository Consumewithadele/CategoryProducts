<?php

declare(strict_types=1);

namespace Trespass\CategoryProducts\Controller\Adminhtml\Export;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Exception;
use Trespass\CategoryProducts\Model\Export as ExporterModel;

class Index extends Action
{
    private RequestInterface $request;
    private ExporterModel $exporter;

    public function __construct(
        Context $context,
        RequestInterface $request,
        ExporterModel $exporter
    ) {
        $this->request = $request;
        $this->exporter = $exporter;
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $categoryId = (int)$this->request->getParam('category_id');
            if ($categoryId) {
                $this->exporter->exportCategoryProducts($categoryId, (int)$this->request->getParam('store_id'));
            }
        } catch (Exception $e) {
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $this->messageManager->addErrorMessage(
                __($e->getMessage())
            );
            $resultRedirect->setUrl($this->_redirect->getRefererUrl());
            return $resultRedirect;
        }
    }
}
