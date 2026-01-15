<?php
namespace GardenLawn\TransEu\Controller\Adminhtml\Test;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use GardenLawn\TransEu\Model\ApiService;
use GardenLawn\TransEu\Model\Data\PricePredictionRequestFactory;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Store\Model\StoreManagerInterface;

class PredictPrice extends Action
{
    protected $resultJsonFactory;
    protected $apiService;
    protected $requestFactory;
    protected $currencyFactory;
    protected $storeManager;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        ApiService $apiService,
        PricePredictionRequestFactory $requestFactory,
        CurrencyFactory $currencyFactory,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->apiService = $apiService;
        $this->requestFactory = $requestFactory;
        $this->currencyFactory = $currencyFactory;
        $this->storeManager = $storeManager;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $params = $this->getRequest()->getParams();
        $token = $this->getRequest()->getParam('token');

        try {
            /** @var \GardenLawn\TransEu\Model\Data\PricePredictionRequest $requestModel */
            $requestModel = $this->requestFactory->create();

            $requestModel->setCompanyId((int)$params['company_id']);
            $requestModel->setUserId((int)$params['user_id']);
            $requestModel->setDistance((float)$params['distance']);
            $requestModel->setCurrency('EUR');

            $formatDate = function($dateStr) {
                return gmdate('Y-m-d\TH:i:s.000\Z', strtotime($dateStr));
            };

            // Dynamic load structure
            $defaultLoad = [
                "amount" => (float)$params['load_amount'],
                "length" => (float)$params['load_length'],
                "name" => $params['load_name'],
                "type_of_load" => $params['load_type'],
                "width" => (float)$params['load_width']
            ];

            $spots = [
                [
                    "operations" => [["loads" => [$defaultLoad]]],
                    "place" => [
                        "address" => ["locality" => $params['source_city'], "postal_code" => $params['source_zip']],
                        "coordinates" => ["latitude" => (float)$params['source_lat'], "longitude" => (float)$params['source_lon']],
                        "country" => "PL"
                    ],
                    "timespans" => [
                        "begin" => $formatDate($params['source_date'] . ' 13:00:00'),
                        "end" => $formatDate($params['source_date'] . ' 13:00:00')
                    ],
                    "type" => "loading"
                ],
                [
                    "operations" => [["loads" => [$defaultLoad]]],
                    "place" => [
                        "address" => ["locality" => $params['dest_city'], "postal_code" => $params['dest_zip']],
                        "coordinates" => ["latitude" => (float)$params['dest_lat'], "longitude" => (float)$params['dest_lon']],
                        "country" => "PL"
                    ],
                    "timespans" => [
                        "begin" => $formatDate($params['dest_date'] . ' 07:00:00'),
                        "end" => $formatDate($params['dest_date'] . ' 07:00:00')
                    ],
                    "type" => "unloading"
                ]
            ];
            $requestModel->setSpots($spots);

            $vehicleRequirements = [
                "capacity" => (float)$params['capacity'],
                "gps" => true,
                "other_requirements" => [],
                "required_truck_bodies" => [$params['vehicle_body']],
                "required_ways_of_loading" => [],
                "vehicle_size_id" => $params['vehicle_size'],
                "transport_type" => "ftl"
            ];
            $requestModel->setVehicleRequirements($vehicleRequirements);

            $requestModel->setData('length', (float)$params['total_length']);

            $response = $this->apiService->predictPrice($requestModel, $token);

            // Convert Currency
            if (isset($response['prediction'][0]) && isset($response['currency']) && $response['currency'] == 'EUR') {
                $priceEur = $response['prediction'][0];

                try {
                    $baseCurrencyCode = $this->storeManager->getStore()->getBaseCurrencyCode();

                    if ($baseCurrencyCode == 'PLN') {
                        $currencyPln = $this->currencyFactory->create()->load('PLN');
                        $ratePlnToEur = $currencyPln->getRate('EUR');

                        if ($ratePlnToEur && $ratePlnToEur > 0) {
                            $rateEurToPln = 1 / $ratePlnToEur;
                            $pricePln = $priceEur * $rateEurToPln;

                            $response['prediction_pln'] = round($pricePln, 2);
                            $response['rate_eur_pln'] = round($rateEurToPln, 4);
                            $response['currency_converted'] = 'PLN';
                        } else {
                            $currencyEur = $this->currencyFactory->create()->load('EUR');
                            $rateEurToPln = $currencyEur->getRate('PLN');

                            if ($rateEurToPln) {
                                $pricePln = $priceEur * $rateEurToPln;
                                $response['prediction_pln'] = round($pricePln, 2);
                                $response['rate_eur_pln'] = $rateEurToPln;
                                $response['currency_converted'] = 'PLN';
                            } else {
                                $response['conversion_error'] = 'Rate EUR->PLN or PLN->EUR not found.';
                            }
                        }
                    } else {
                        $currencyEur = $this->currencyFactory->create()->load('EUR');
                        $rate = $currencyEur->getRate('PLN');
                        if ($rate) {
                            $pricePln = $priceEur * $rate;
                            $response['prediction_pln'] = round($pricePln, 2);
                            $response['rate_eur_pln'] = $rate;
                            $response['currency_converted'] = 'PLN';
                        } else {
                            $response['conversion_error'] = 'Rate EUR->PLN not found.';
                        }
                    }

                } catch (\Exception $e) {
                    $response['conversion_error'] = $e->getMessage();
                }
            }

            return $result->setData([
                'success' => true,
                'response' => $response,
                'request_payload' => $requestModel->toArray()
            ]);

        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
                'request_payload' => isset($requestModel) ? $requestModel->toArray() : []
            ]);
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('GardenLawn_TransEu::config');
    }
}
