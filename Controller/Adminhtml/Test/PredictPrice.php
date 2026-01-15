<?php
namespace GardenLawn\TransEu\Controller\Adminhtml\Test;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use GardenLawn\TransEu\Model\ApiService;
use GardenLawn\TransEu\Model\Data\PricePredictionRequestFactory;

class PredictPrice extends Action
{
    protected $resultJsonFactory;
    protected $apiService;
    protected $requestFactory;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        ApiService $apiService,
        PricePredictionRequestFactory $requestFactory
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->apiService = $apiService;
        $this->requestFactory = $requestFactory;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $params = $this->getRequest()->getParams();
        $token = $this->getRequest()->getParam('token'); // Optional manual token from JS

        try {
            /** @var \GardenLawn\TransEu\Model\Data\PricePredictionRequest $requestModel */
            $requestModel = $this->requestFactory->create();

            $requestModel->setCompanyId((int)$params['company_id']);
            $requestModel->setUserId((int)$params['user_id']);
            $requestModel->setDistance((float)$params['distance']);
            $requestModel->setCurrency('EUR');

            // Default load structure to match working example
            $defaultLoad = [
                "amount" => 5,
                "length" => 1.2,
                "name" => "Ładunek 1",
                "type_of_load" => "2_europalette",
                "width" => 0.8
            ];

            // Helper function for strict date format: 2026-01-15T13:00:00.000Z
            $formatDate = function($dateStr) {
                return gmdate('Y-m-d\TH:i:s.000\Z', strtotime($dateStr));
            };

            $spots = [
                [
                    "operations" => [
                        [
                            "loads" => [$defaultLoad]
                        ]
                    ],
                    "place" => [
                        "address" => [
                            "locality" => $params['source_city'],
                            "postal_code" => $params['source_zip']
                        ],
                        "coordinates" => [
                            "latitude" => (float)$params['source_lat'],
                            "longitude" => (float)$params['source_lon']
                        ],
                        "country" => "PL"
                    ],
                    "timespans" => [
                        "begin" => $formatDate($params['source_date'] . ' 13:00:00'),
                        "end" => $formatDate($params['source_date'] . ' 13:00:00')
                    ],
                    "type" => "loading"
                ],
                [
                    "operations" => [
                        [
                            "loads" => [$defaultLoad]
                        ]
                    ],
                    "place" => [
                        "address" => [
                            "locality" => $params['dest_city'],
                            "postal_code" => $params['dest_zip']
                        ],
                        "coordinates" => [
                            "latitude" => (float)$params['dest_lat'],
                            "longitude" => (float)$params['dest_lon']
                        ],
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
                "capacity" => 15,
                "gps" => true,
                "other_requirements" => [],
                "required_truck_bodies" => [$params['vehicle_body']],
                "required_ways_of_loading" => [],
                "vehicle_size_id" => "14_double_trailer_lorry_solo",
                "transport_type" => "ftl"
            ];
            $requestModel->setVehicleRequirements($vehicleRequirements);

            $requestModel->setData('length', 2);

            // Call API Service with optional explicit token
            $response = $this->apiService->predictPrice($requestModel, $token);

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
