<?php
namespace GardenLawn\TransEu\Controller\Adminhtml\Test;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use GardenLawn\TransEu\Model\ApiService;

class FreightsList extends Action
{
    protected $resultJsonFactory;
    protected $apiService;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        ApiService $apiService
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->apiService = $apiService;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            // Example filters (can be expanded later with params from request)
            $filters = [];

            // Use system OAuth token (pass null as token)
            $response = $this->apiService->getFreightsList($filters, null, 1, null);

            return $result->setData([
                'success' => true,
                'response' => $response
            ]);

        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('GardenLawn_TransEu::config');
    }
}
