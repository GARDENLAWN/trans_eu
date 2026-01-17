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
    const ENDPOINT_FREIGHTS_LIST = '/ext/freights-api/v1/freights';

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
     * @param string|null $explicitToken Optional token to use instead of stored one
     * @return array
     * @throws \Exception
     */
    public function makeRequest($method, $endpoint, $data = [], $explicitToken = null)
    {
        // Determine which token to use based on endpoint
        if ($explicitToken) {
            $token = $explicitToken;
        } elseif (strpos($endpoint, '/ext/') === 0) {
            // Official Partner API -> Use OAuth Token
            $token = $this->authService->getOAuthToken();
        } else {
            // Internal Web API -> Use Web Token (Manual/Python)
            $token = $this->authService->getWebToken();
        }

        if (!$token) {
            throw new \Exception('No access token available for endpoint: ' . $endpoint);
        }

        // Determine Base URL
        $baseUrl = 'https://api-platform.trans.eu';
        if (strpos($endpoint, '/ext/') === 0) {
             $baseUrl = 'https://api.platform.trans.eu';
        }
        $url = $baseUrl . $endpoint;

        // Add query params for GET
        if ($method == 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        $this->curl->setHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ]);

        // Add Api-key ONLY for /ext/ endpoints (Partner API)
        if (strpos($endpoint, '/ext/') === 0) {
            $apiKey = $this->authService->getApiKey();
            if ($apiKey) {
                 $this->curl->addHeader('Api-key', $apiKey);
            }
        }

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
                $this->logger->error("Unauthorized (401). Response: " . $responseBody);

                // Retry logic
                if (!$explicitToken) {
                    $newToken = null;
                    if (strpos($endpoint, '/ext/') === 0) {
                        // Refresh OAuth Token
                        $newToken = $this->authService->refreshToken();
                    } else {
                        // Refresh Web Token (Python)
                        // Note: getWebToken() already tries to refresh if expired, but maybe it was valid but rejected?
                        // Force refresh via Python not directly exposed here, but we can try calling getWebToken again if we assume it checks validity.
                        // However, if the token was valid by date but rejected by server, we might need a force refresh flag.
                        // For now, let's just log it.
                        $this->logger->warning("Web token rejected by server (401).");
                    }

                    if ($newToken && $newToken !== $token) {
                        $this->logger->info("Token refreshed, retrying request...");

                        $this->curl->setHeaders([
                            'Authorization' => 'Bearer ' . $newToken,
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json'
                        ]);

                        if (strpos($endpoint, '/ext/') === 0) {
                            $apiKey = $this->authService->getApiKey();
                            if ($apiKey) {
                                 $this->curl->addHeader('Api-key', $apiKey);
                            }
                        }

                        if (strtoupper($method) == 'POST') {
                             $this->curl->post($url, $this->json->serialize($data));
                        } elseif (strtoupper($method) == 'GET') {
                            $this->curl->get($url);
                        }

                        $responseBody = $this->curl->getBody();
                        $statusCode = $this->curl->getStatus();
                        if ($statusCode >= 200 && $statusCode < 300) {
                            return $this->json->unserialize($responseBody);
                        }
                    }
                }

                throw new \Exception('Unauthorized (401). Check logs for details. Response: ' . $responseBody);
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
     * @param string|null $token
     * @return array
     * @throws \Exception
     */
    public function predictPrice(PricePredictionRequestInterface $request, $token = null)
    {
        return $this->makeRequest('POST', self::ENDPOINT_PRICE_PREDICTION, $request->toArray(), $token);
    }

    /**
     * Get freights list
     *
     * @param array $filters
     * @param string|null $sortBy
     * @param int $page
     * @param string|null $token
     * @return array
     * @throws \Exception
     */
    public function getFreightsList(array $filters = [], $sortBy = null, $page = 1, $token = null)
    {
        $params = [
            'page' => $page
        ];

        if (!empty($filters)) {
            $params['filter'] = $this->json->serialize($filters);
        }

        if ($sortBy) {
            $params['sortBy'] = $sortBy;
        }

        return $this->makeRequest('GET', self::ENDPOINT_FREIGHTS_LIST, $params, $token);
    }
}
