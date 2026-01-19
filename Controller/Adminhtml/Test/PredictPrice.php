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
                "name" => $params['load_name'],
                "type_of_load" => $params['load_type'],
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

            // Handle vehicle_body which can be array or string
            $vehicleBodies = $params['vehicle_body'] ?? [];
            if (!is_array($vehicleBodies)) {
                $vehicleBodies = [$vehicleBodies];
            }
            $vehicleBodies = array_filter($vehicleBodies);

            // Handle vehicle_size with mapping logic
            $vehicleSizes = $params['vehicle_size'] ?? [];
            if (!is_array($vehicleSizes)) {
                $vehicleSizes = [$vehicleSizes];
            }
            $vehicleSizes = array_filter($vehicleSizes);

            $vehicleSize = $this->resolveVehicleSizeId($vehicleSizes);

            if (!$vehicleSize && !empty($vehicleSizes)) {
                // Fallback if resolution fails but we have sizes (e.g. custom single value)
                $vehicleSize = reset($vehicleSizes);
            }

            // Handle other_requirements
            $otherRequirements = $params['other_requirements'] ?? [];
            if (!is_array($otherRequirements)) {
                $otherRequirements = [$otherRequirements];
            }
            $otherRequirements = array_filter($otherRequirements);

            $vehicleRequirements = [
                "capacity" => (float)$params['capacity'],
                "gps" => true,
                "other_requirements" => $otherRequirements,
                "required_truck_bodies" => $vehicleBodies,
                "required_ways_of_loading" => [],
                "vehicle_size_id" => $vehicleSize,
                "transport_type" => $params['freight_type'] ?? 'ftl'
            ];
            $requestModel->setVehicleRequirements($vehicleRequirements);

            if ((float)$params['total_length'] > 0) {
                $requestModel->setData('length', (float)$params['total_length']);
            }

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

    protected function resolveVehicleSizeId(array $sizes)
    {
        $sizes = array_map('trim', $sizes);
        $sizes = array_filter(array_unique($sizes));

        $hasLorry = in_array('3_lorry', $sizes);
        $hasSolo = in_array('5_solo', $sizes);
        $hasDouble = in_array('2_double_trailer', $sizes);

        if ($hasLorry && $hasSolo && $hasDouble) {
            return '14_double_trailer_lorry_solo';
        }
        if ($hasLorry && $hasSolo) {
            return '8_lorry_solo';
        }
        if ($hasLorry && $hasDouble) {
            return '7_double_trailer_lorry';
        }
        if ($hasSolo && $hasDouble) {
            return '11_double_trailer_solo';
        }
        if ($hasLorry) return '3_lorry';
        if ($hasSolo) return '5_solo';
        if ($hasDouble) return '2_double_trailer';

        // Fallback: if only one size is selected and it's not one of the above
        if (count($sizes) === 1) {
            return reset($sizes);
        }

        return null;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('GardenLawn_TransEu::config');
    }
}
