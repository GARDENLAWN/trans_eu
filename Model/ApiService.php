<?php
namespace GardenLawn\TransEu\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use GardenLawn\TransEu\Model\AuthService;

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

        // Base URL is usually https://api-platform.trans.eu, but we can use the one from config or hardcode the platform base
        // Using the one from AuthService config logic would be best, but for now let's assume the platform URL.
        // The config 'api_url' in AuthService is used for auth token (https://api.platform.trans.eu).
        // The price prediction is on https://api-platform.trans.eu (slightly different subdomain often, or same).
        // Let's use the base from config if possible, or default to the standard platform API.

        // Note: In previous steps we used https://api-platform.trans.eu for prediction
        // and https://api.platform.trans.eu for auth.
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
                // Add others as needed
            }

            $responseBody = $this->curl->getBody();
            $statusCode = $this->curl->getStatus();

            $this->logger->info("Trans.eu API Response [$statusCode]");

            if ($statusCode >= 200 && $statusCode < 300) {
                return $this->json->unserialize($responseBody);
            } elseif ($statusCode == 401) {
                // Token might be expired, try to refresh once?
                // For now, let's just throw exception, but in robust app we would retry.
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
     * @param array $payload
     * @return array
     * @throws \Exception
     */
    public function predictPrice(array $payload)
    {
        return $this->makeRequest('POST', self::ENDPOINT_PRICE_PREDICTION, $payload);
    }
}
