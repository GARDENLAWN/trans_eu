<?php
namespace GardenLawn\TransEu\Block\Adminhtml\Test;

use Magento\Backend\Block\Template;
use GardenLawn\TransEu\Model\AuthService;
use GardenLawn\TransEu\Model\ApiService;
use GardenLawn\TransEu\Model\Data\PricePredictionRequestFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use GardenLawn\Delivery\Model\Config\Source\VehicleBody;
use GardenLawn\Delivery\Model\Config\Source\VehicleSize;
use GardenLawn\Delivery\Model\Config\Source\FreightType;
use GardenLawn\Delivery\Model\Config\Source\LoadType;

class Index extends Template
{
    protected $authService;
    protected $apiService;
    protected $scopeConfig;
    protected $curl;
    protected $json;
    protected $requestFactory;

    protected $vehicleBodySource;
    protected $vehicleSizeSource;
    protected $freightTypeSource;
    protected $loadTypeSource;

    public function __construct(
        Template\Context $context,
        AuthService $authService,
        ApiService $apiService,
        ScopeConfigInterface $scopeConfig,
        Curl $curl,
        Json $json,
        PricePredictionRequestFactory $requestFactory,
        VehicleBody $vehicleBodySource,
        VehicleSize $vehicleSizeSource,
        FreightType $freightTypeSource,
        LoadType $loadTypeSource,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->authService = $authService;
        $this->apiService = $apiService;
        $this->scopeConfig = $scopeConfig;
        $this->curl = $curl;
        $this->json = $json;
        $this->requestFactory = $requestFactory;
        $this->vehicleBodySource = $vehicleBodySource;
        $this->vehicleSizeSource = $vehicleSizeSource;
        $this->freightTypeSource = $freightTypeSource;
        $this->loadTypeSource = $loadTypeSource;
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

    /**
     * Get expiration timestamp from JWT token string
     * @param string $token
     * @return int|null
     */
    public function getManualTokenExpiration($token)
    {
        return $this->authService->getTokenExpirationTime($token);
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
            'total_length' => $request->getParam('total_length', '2'),

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

            'vehicle_body' => $request->getParam('vehicle_body', '9_curtainsider'),
            'vehicle_size' => $request->getParam('vehicle_size', '14_double_trailer_lorry_solo'),
            'capacity' => $request->getParam('capacity', '15'),
            'freight_type' => $request->getParam('freight_type', 'ftl'),

            // Load details
            'qty_m2' => $request->getParam('qty_m2', '250'), // Default 250 m2
            'load_amount' => $request->getParam('load_amount', '5'),
            'load_length' => $request->getParam('load_length', '1.2'),
            'load_width' => $request->getParam('load_width', '0.8'),
            'load_name' => $request->getParam('load_name', 'Ładunek 1'),
            'load_type' => $request->getParam('load_type', '2_europalette'),
        ];
    }

    public function getVehicleBodyOptions() { return $this->vehicleBodySource->toOptionArray(); }
    public function getVehicleSizeOptions() { return $this->vehicleSizeSource->toOptionArray(); }
    public function getFreightTypeOptions() { return $this->freightTypeSource->toOptionArray(); }
    public function getLoadTypeOptions() { return $this->loadTypeSource->toOptionArray(); }

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
        // This method is kept for non-AJAX fallback, but mainly we use AJAX now.
        // Logic is duplicated in PredictPrice controller.
        return null;
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
