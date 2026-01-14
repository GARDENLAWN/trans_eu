<?php
namespace GardenLawn\TransEu\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Encryption\EncryptorInterface;

class AuthService
{
    const XML_PATH_ACTIVE = 'trans_eu/general/active';
    const XML_PATH_CLIENT_ID = 'trans_eu/general/client_id';
    const XML_PATH_CLIENT_SECRET = 'trans_eu/general/client_secret';
    const XML_PATH_API_KEY = 'trans_eu/general/api_key';
    const XML_PATH_AUTH_URL = 'trans_eu/general/auth_url';
    const XML_PATH_API_URL = 'trans_eu/general/api_url';
    const XML_PATH_REDIRECT_URI = 'trans_eu/general/redirect_uri';

    const XML_PATH_ACCESS_TOKEN = 'trans_eu/general/access_token';
    const XML_PATH_REFRESH_TOKEN = 'trans_eu/general/refresh_token';
    const XML_PATH_TOKEN_EXPIRES = 'trans_eu/general/token_expires';

    protected $scopeConfig;
    protected $configWriter;
    protected $curl;
    protected $json;
    protected $encryptor;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        Curl $curl,
        Json $json,
        EncryptorInterface $encryptor
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->curl = $curl;
        $this->json = $json;
        $this->encryptor = $encryptor;
    }

    public function getAuthorizationUrl()
    {
        $authUrl = $this->scopeConfig->getValue(self::XML_PATH_AUTH_URL);
        $clientId = $this->scopeConfig->getValue(self::XML_PATH_CLIENT_ID);
        $redirectUri = $this->scopeConfig->getValue(self::XML_PATH_REDIRECT_URI);

        $state = bin2hex(random_bytes(16));

        $params = [
            'client_id' => $clientId,
            'response_type' => 'code',
            'state' => $state,
            'redirect_uri' => $redirectUri
        ];

        return $authUrl . '/oauth2/auth?' . http_build_query($params);
    }

    public function handleCallback($code)
    {
        $apiUrl = $this->scopeConfig->getValue(self::XML_PATH_API_URL);
        $clientId = $this->scopeConfig->getValue(self::XML_PATH_CLIENT_ID);
        $clientSecret = $this->encryptor->decrypt($this->scopeConfig->getValue(self::XML_PATH_CLIENT_SECRET));
        $apiKey = $this->encryptor->decrypt($this->scopeConfig->getValue(self::XML_PATH_API_KEY));
        $redirectUri = $this->scopeConfig->getValue(self::XML_PATH_REDIRECT_URI);

        $tokenUrl = $apiUrl . '/ext/auth-api/accounts/token';

        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret
        ];

        // Ensure headers are clean
        $this->curl->setHeaders([]);
        $this->curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');

        // Trim API Key to avoid "invalid header value" error
        $apiKey = trim($apiKey);
        $this->curl->addHeader('Api-key', $apiKey);

        $this->curl->post($tokenUrl, http_build_query($params));

        $response = $this->curl->getBody();
        $statusCode = $this->curl->getStatus();

        if ($statusCode == 200) {
            $data = $this->json->unserialize($response);
            $this->saveTokens($data);
        } else {
            throw new \Exception('Failed to obtain access token: ' . $statusCode . ' ' . $response);
        }
    }

    public function refreshToken()
    {
        $refreshToken = $this->scopeConfig->getValue(self::XML_PATH_REFRESH_TOKEN);
        if (!$refreshToken) {
            return false;
        }

        $apiUrl = $this->scopeConfig->getValue(self::XML_PATH_API_URL);
        $clientId = $this->scopeConfig->getValue(self::XML_PATH_CLIENT_ID);
        $clientSecret = $this->encryptor->decrypt($this->scopeConfig->getValue(self::XML_PATH_CLIENT_SECRET));
        $apiKey = $this->encryptor->decrypt($this->scopeConfig->getValue(self::XML_PATH_API_KEY));

        $tokenUrl = $apiUrl . '/ext/auth-api/accounts/token';

        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret
        ];

        // Ensure headers are clean
        $this->curl->setHeaders([]);
        $this->curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');

        // Trim API Key
        $apiKey = trim($apiKey);
        $this->curl->addHeader('Api-key', $apiKey);

        $this->curl->post($tokenUrl, http_build_query($params));

        $response = $this->curl->getBody();
        $statusCode = $this->curl->getStatus();

        if ($statusCode == 200) {
            $data = $this->json->unserialize($response);
            $this->saveTokens($data);
            return $data['access_token'];
        } else {
            return false;
        }
    }

    public function getAccessToken()
    {
        $accessToken = $this->scopeConfig->getValue(self::XML_PATH_ACCESS_TOKEN);
        $expiresAt = $this->scopeConfig->getValue(self::XML_PATH_TOKEN_EXPIRES);

        if ($accessToken && $expiresAt > time()) {
            return $accessToken;
        }

        return $this->refreshToken();
    }

    protected function saveTokens($data)
    {
        $this->configWriter->save(self::XML_PATH_ACCESS_TOKEN, $data['access_token']);
        $this->configWriter->save(self::XML_PATH_REFRESH_TOKEN, $data['refresh_token']);
        $this->configWriter->save(self::XML_PATH_TOKEN_EXPIRES, time() + $data['expires_in'] - 60);
    }
}
