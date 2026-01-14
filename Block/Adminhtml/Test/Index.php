<?php
namespace GardenLawn\TransEu\Block\Adminhtml\Test;

use Magento\Backend\Block\Template;
use GardenLawn\TransEu\Model\AuthService;
use GardenLawn\TransEu\Model\ApiService;
use GardenLawn\TransEu\Model\Data\PricePredictionRequestFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;

class Index extends Template
{
    protected $authService;
    protected $apiService;
    protected $scopeConfig;
    protected $curl;
    protected $json;
    protected $requestFactory;

    public function __construct(
        Template\Context $context,
        AuthService $authService,
        ApiService $apiService,
        ScopeConfigInterface $scopeConfig,
        Curl $curl,
        Json $json,
        PricePredictionRequestFactory $requestFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->authService = $authService;
        $this->apiService = $apiService;
        $this->scopeConfig = $scopeConfig;
        $this->curl = $curl;
        $this->json = $json;
        $this->requestFactory = $requestFactory;
    }

    public function getConfigData()
    {
        return [
            'trans_eu/general/active' => 'Module Active',
            'trans_eu/general/client_id' => 'Client ID',
            'trans_eu/general/client_secret' => 'Client Secret',
            'trans_eu/general/api_key' => 'API Key',
            'trans_eu/general/trans_id' => 'Trans ID',
            'trans_eu/general/auth_url' => 'Auth URL',
            'trans_eu/general/api_url' => 'API URL',
            'trans_eu/general/redirect_uri' => 'Redirect URI',
        ];
    }

    public function getConfigValue($path)
    {
        return $this->scopeConfig->getValue($path);
    }

    public function getTokenInfo()
    {
        return [
            'access_token' => $this->scopeConfig->getValue('trans_eu/general/access_token'),
            'refresh_token' => $this->scopeConfig->getValue('trans_eu/general/refresh_token'),
            'expires_at' => $this->scopeConfig->getValue('trans_eu/general/token_expires')
        ];
    }

    public function testTokenRetrieval()
    {
        try {
            $start = microtime(true);
            $token = $this->authService->getAccessToken();
            $end = microtime(true);

            return [
                'success' => (bool)$token,
                'token' => $token,
                'duration' => round($end - $start, 4),
                'message' => $token ? 'Token retrieved successfully.' : 'Failed to retrieve token.'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }

    /**
     * Get form values (defaults or from POST)
     */
    public function getFormValues()
    {
        $request = $this->getRequest();

        return [
            'company_id' => $request->getParam('company_id', '1242549'),
            'user_id' => $request->getParam('user_id', '1903733'),
            'distance' => $request->getParam('distance', '458245.5'),

            'source_city' => $request->getParam('source_city', 'Szczecinek'),
            'source_zip' => $request->getParam('source_zip', '78-400'),
            'source_lat' => $request->getParam('source_lat', '53.708021'),
            'source_lon' => $request->getParam('source_lon', '16.6943922'),
            'source_date' => $request->getParam('source_date', date('Y-m-d', strtotime('+1 day'))),

            'dest_city' => $request->getParam('dest_city', 'Opole'),
            'dest_zip' => $request->getParam('dest_zip', '46-081'),
            'dest_lat' => $request->getParam('dest_lat', '50.75644296'),
            'dest_lon' => $request->getParam('dest_lon', '17.879288038'),
            'dest_date' => $request->getParam('dest_date', date('Y-m-d', strtotime('+2 days'))),

            'vehicle_body' => $request->getParam('vehicle_body', '9_curtainsider')
        ];
    }

    /**
     * Execute the API call if form was submitted
     * @return array|null
     */
    public function getApiResult()
    {
        if (!$this->getRequest()->isPost()) {
            return null;
        }

        $action = $this->getRequest()->getParam('action');

        if ($action == 'freights_list') {
            return $this->getFreightsListResult();
        } elseif ($action == 'price_prediction') {
            return $this->getPricePredictionResult();
        }

        return null;
    }

    protected function getPricePredictionResult()
    {
        $params = $this->getFormValues();

        /** @var \GardenLawn\TransEu\Model\Data\PricePredictionRequest $requestModel */
        $requestModel = $this->requestFactory->create();

        $requestModel->setCompanyId((int)$params['company_id']);
        $requestModel->setUserId((int)$params['user_id']);
        $requestModel->setDistance((float)$params['distance']);
        $requestModel->setCurrency('EUR');

        $spots = [
            [
                "operations" => [["loads" => []]],
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
                    "begin" => date('c', strtotime($params['source_date'] . ' 08:00:00')),
                    "end" => date('c', strtotime($params['source_date'] . ' 16:00:00'))
                ],
                "type" => "loading"
            ],
            [
                "operations" => [["loads" => []]],
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
                    "begin" => date('c', strtotime($params['dest_date'] . ' 08:00:00')),
                    "end" => date('c', strtotime($params['dest_date'] . ' 16:00:00'))
                ],
                "type" => "unloading"
            ]
        ];
        $requestModel->setSpots($spots);

        $vehicleRequirements = [
            "capacity" => 10,
            "gps" => true,
            "other_requirements" => [],
            "required_truck_bodies" => [$params['vehicle_body']],
            "required_ways_of_loading" => [],
            "vehicle_size_id" => "13_bus_lorry_solo",
            "transport_type" => "ftl"
        ];
        $requestModel->setVehicleRequirements($vehicleRequirements);

        try {
            $response = $this->apiService->predictPrice($requestModel);

            return [
                'type' => 'price_prediction',
                'success' => true,
                'status' => 200,
                'response' => $response,
                'request_payload' => $requestModel->toArray()
            ];

        } catch (\Exception $e) {
            return [
                'type' => 'price_prediction',
                'success' => false,
                'status' => 'Error',
                'message' => $e->getMessage(),
                'request_payload' => $requestModel->toArray()
            ];
        }
    }

    protected function getFreightsListResult()
    {
        try {
            // Example filters (can be expanded with form inputs later)
            $filters = [];
            // $filters = ['status' => 'active'];

            $response = $this->apiService->getFreightsList($filters);

            return [
                'type' => 'freights_list',
                'success' => true,
                'status' => 200,
                'response' => $response
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'freights_list',
                'success' => false,
                'status' => 'Error',
                'message' => $e->getMessage()
            ];
        }
    }
}
