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

        $carrierCode = $params['carrier_code'] ?? 'direct_no_lift'; // Default carrier
        $origin = $params['origin'] ?? 'Szczecinek, 78-400';
        $destination = $params['destination'] ?? 'Opole, 46-081';
        $distance = (float)($params['distance'] ?? 450.0);
        $qty = (float)($params['qty_m2'] ?? 100.0);

        try {
            // We need to temporarily inject the manual token into AuthService if provided?
            // TransEuQuoteService uses ApiService which uses AuthService.
            // AuthService checks config for manual token.
            // So if manual token is saved in config, it will be used.

            $price = $this->quoteService->getPrice(
                $carrierCode,
                $origin,
                $destination,
                $distance,
                $qty
            );

            if ($price !== null) {
                return $result->setData([
                    'success' => true,
                    'price' => $price,
                    'message' => "Price calculated successfully: $price"
                ]);
            } else {
                return $result->setData([
                    'success' => false,
                    'message' => 'Price calculation returned null (check logs for details, maybe no matching rule or API error).'
                ]);
            }

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
