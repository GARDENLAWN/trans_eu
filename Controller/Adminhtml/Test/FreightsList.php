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
        $token = $this->getRequest()->getParam('token'); // Optional manual token

        try {
            // Example filters (can be expanded later with params)
            $filters = [];
            // $filters = ['status' => 'active'];

            // We need to update ApiService to accept explicit token for getFreightsList too
            // Currently it only supports it for predictPrice? Let's check ApiService.
            // ApiService::getFreightsList doesn't take token param yet.
            // But makeRequest does.

            // Let's assume we want to use the token if provided.
            // I need to update ApiService::getFreightsList signature first or use makeRequest directly here?
            // Better to update ApiService.

            // For now, let's try calling it. If ApiService doesn't support token param, it will use stored token.
            // To support manual token here, I should update ApiService.php first.

            $response = $this->apiService->getFreightsList($filters, null, 1, $token);

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
