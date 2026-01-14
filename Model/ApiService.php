<?php
namespace GardenLawn\TransEu\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use GardenLawn\TransEu\Model\AuthService;
use GardenLawn\TransEu\Api\Data\PricePredictionRequestInterface;

class ApiService
{
    protected $curl;
    protected $json;
    protected $logger;
    protected $authService;

    // Endpoints
    const ENDPOINT_PRICE_PREDICTION = '/app/price-recommendation/api/rest/v2/predictions/transaction-price';

    public function __construct(
        Curl $curl,
        Json $json,
        LoggerInterface $logger,
        AuthService $authService
    ) {
        $this->curl = $curl;
        $this->json = $json;
        $this->logger = $logger;
        $this->authService = $authService;
    }

    /**
     * Generic method to make authenticated requests to Trans.eu API
     *
     * @param string $method GET, POST, PUT, DELETE
     * @param string $endpoint Relative path (e.g. /app/...)
     * @param array $data Payload for POST/PUT
     * @return array
     * @throws \Exception
     */
    public function makeRequest($method, $endpoint, $data = [])
    {
        $token = $this->authService->getAccessToken();
        if (!$token) {
            throw new \Exception('No access token available. Please authorize the module.');
        }

        $baseUrl = 'https://api-platform.trans.eu';
        $url = $baseUrl . $endpoint;

        $this->curl->setHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ]);

        $this->logger->info("Trans.eu API Request [$method]: $url");

        try {
            switch (strtoupper($method)) {
                case 'POST':
                    $this->curl->post($url, $this->json->serialize($data));
                    break;
                case 'GET':
                    $this->curl->get($url);
                    break;
                case 'PUT':
                    $this->curl->put($url, $this->json->serialize($data));
                    break;
            }

            $responseBody = $this->curl->getBody();
            $statusCode = $this->curl->getStatus();

            $this->logger->info("Trans.eu API Response [$statusCode]");

            if ($statusCode >= 200 && $statusCode < 300) {
                return $this->json->unserialize($responseBody);
            } elseif ($statusCode == 401) {
                throw new \Exception('Unauthorized (401). Token might be expired.');
            } else {
                throw new \Exception("API Error ($statusCode): " . $responseBody);
            }

        } catch (\Exception $e) {
            $this->logger->error('Trans.eu API Exception: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get price prediction
     *
     * @param PricePredictionRequestInterface $request
     * @return array
     * @throws \Exception
     */
    public function predictPrice(PricePredictionRequestInterface $request)
    {
        return $this->makeRequest('POST', self::ENDPOINT_PRICE_PREDICTION, $request->toArray());
    }
}
