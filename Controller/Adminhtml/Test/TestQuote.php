<?php
namespace GardenLawn\TransEu\Controller\Adminhtml\Test;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use GardenLawn\Delivery\Service\TransEuQuoteService;
use Magento\Framework\App\Config\ScopeConfigInterface;

class TestQuote extends Action
{
    protected $resultJsonFactory;
    protected $quoteService;
    protected $scopeConfig;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        TransEuQuoteService $quoteService,
        ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->quoteService = $quoteService;
        $this->scopeConfig = $scopeConfig;
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

        // Check if Trans.eu is enabled for this carrier
        if (!$this->scopeConfig->isSetFlag("carriers/$carrierCode/use_transeu_api")) {
            return $result->setData([
                'success' => false,
                'message' => "Trans.eu Price Prediction is DISABLED for carrier '$carrierCode'. Enable it in configuration.",
                'debug_info' => []
            ]);
        }

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
                    'message' => 'Price calculation returned null (check debug info).',
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

        // Check if Trans.eu is enabled for this carrier (optional for simulation, but good to know)
        $isEnabled = $this->scopeConfig->isSetFlag("carriers/$carrierCode/use_transeu_api");

        try {
            $resolved = $this->quoteService->prepareRequestParams($carrierCode, $qty);

            if (!$isEnabled) {
                $resolved['debug'][] = "WARNING: Trans.eu Price Prediction is DISABLED for this carrier in config.";
            }

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
