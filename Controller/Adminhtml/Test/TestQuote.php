<?php
namespace GardenLawn\TransEu\Controller\Adminhtml\Test;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use GardenLawn\Delivery\Service\TransEuQuoteService;

class TestQuote extends Action
{
    protected $resultJsonFactory;
    protected $quoteService;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        TransEuQuoteService $quoteService
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->quoteService = $quoteService;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $params = $this->getRequest()->getParams();
        $action = $params['action'] ?? 'quote';

        if ($action === 'simulate') {
            return $this->executeSimulate($result, $params);
        }

        // Default quote logic (Full Flow Test)
        $carrierCode = $params['carrier_code'] ?? 'direct_no_lift';
        $origin = $params['origin'] ?? 'Szczecinek, 78-400';
        $destination = $params['destination'] ?? 'Opole, 46-081';
        $distance = (float)($params['distance'] ?? 450.0);
        $qty = (float)($params['qty_m2'] ?? 100.0);

        try {
            $price = $this->quoteService->getPrice(
                $carrierCode,
                $origin,
                $destination,
                $distance,
                $qty
            );

            $debugInfo = $this->quoteService->getDebugInfo();

            if ($price !== null) {
                return $result->setData([
                    'success' => true,
                    'price' => $price,
                    'message' => "Price calculated successfully: $price",
                    'debug_info' => $debugInfo
                ]);
            } else {
                return $result->setData([
                    'success' => false,
                    'message' => 'Price calculation returned null.',
                    'debug_info' => $debugInfo
                ]);
            }

        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
                'debug_info' => isset($this->quoteService) ? $this->quoteService->getDebugInfo() : []
            ]);
        }
    }

    protected function executeSimulate($result, $params)
    {
        $carrierCode = $params['carrier_code'] ?? 'direct_no_lift';
        $qty = (float)($params['qty_m2'] ?? 100.0);

        try {
            $resolved = $this->quoteService->prepareRequestParams($carrierCode, $qty);
            return $result->setData($resolved);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('GardenLawn_TransEu::config');
    }
}
