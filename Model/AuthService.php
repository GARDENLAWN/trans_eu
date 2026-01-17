<?php
namespace GardenLawn\TransEu\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Encryption\EncryptorInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use GardenLawn\Core\Helper\EmailSender;

class AuthService
{
    const XML_PATH_ACTIVE = 'trans_eu/general/active';
    const XML_PATH_USERNAME = 'trans_eu/general/username';
    const XML_PATH_PASSWORD = 'trans_eu/general/password';
    const XML_PATH_CLIENT_ID = 'trans_eu/general/client_id';
    const XML_PATH_CLIENT_SECRET = 'trans_eu/general/client_secret';
    const XML_PATH_API_KEY = 'trans_eu/general/api_key';
    const XML_PATH_SCOPES = 'trans_eu/general/scopes';
    const XML_PATH_AUTH_URL = 'trans_eu/general/auth_url';
    const XML_PATH_API_URL = 'trans_eu/general/api_url';
    const XML_PATH_REDIRECT_URI = 'trans_eu/general/redirect_uri';
    const XML_PATH_MANUAL_TOKEN = 'trans_eu/general/manual_token';

    const XML_PATH_ACCESS_TOKEN = 'trans_eu/general/access_token';
    const XML_PATH_REFRESH_TOKEN = 'trans_eu/general/refresh_token';
    const XML_PATH_TOKEN_EXPIRES = 'trans_eu/general/token_expires';

    protected $scopeConfig;
    protected $configWriter;
    protected $curl;
    protected $json;
    protected $encryptor;
    protected $logger;
    protected $reinitableConfig;
    protected $cacheTypeList;
    protected $tokenProvider;
    protected $emailSender;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        Curl $curl,
        Json $json,
        EncryptorInterface $encryptor,
        LoggerInterface $logger,
        ReinitableConfigInterface $reinitableConfig,
        TypeListInterface $cacheTypeList,
        TokenProvider $tokenProvider,
        EmailSender $emailSender
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->curl = $curl;
        $this->json = $json;
        $this->encryptor = $encryptor;
        $this->logger = $logger;
        $this->reinitableConfig = $reinitableConfig;
        $this->cacheTypeList = $cacheTypeList;
        $this->tokenProvider = $tokenProvider;
        $this->emailSender = $emailSender;
    }

    /**
     * Step 1 - Authentication Request
     * Documentation: GET /oauth2/auth
     */
    public function getAuthorizationUrl()
    {
        $authUrl = $this->scopeConfig->getValue(self::XML_PATH_AUTH_URL);
        $clientId = $this->scopeConfig->getValue(self::XML_PATH_CLIENT_ID);
        $redirectUri = $this->scopeConfig->getValue(self::XML_PATH_REDIRECT_URI);
        $scopes = $this->scopeConfig->getValue(self::XML_PATH_SCOPES);

        // State is recommended to mitigate CSRF attacks
        $state = bin2hex(random_bytes(16));

        $params = [
            'client_id' => $clientId,
            'response_type' => 'code',
            'state' => $state,
            'redirect_uri' => $redirectUri
        ];

        if (!empty($scopes)) {
            $params['scope'] = $scopes;
        }

        return $authUrl . '/oauth2/auth?' . http_build_query($params);
    }

    public function getApiKey()
    {
        $apiKey = $this->scopeConfig->getValue(self::XML_PATH_API_KEY);
        if ($apiKey) {
            $apiKey = $this->encryptor->decrypt($apiKey);
            $apiKey = trim($apiKey);
            $apiKey = preg_replace('/[\x00-\x1F\x7F]/', '', $apiKey);
            return $apiKey;
        }
        return null;
    }

    /**
     * Step 2 - Token Request
     * Documentation: POST /ext/auth-api/accounts/token
     */
    public function handleCallback($code)
    {
        $apiUrl = $this->scopeConfig->getValue(self::XML_PATH_API_URL);
        $clientId = $this->scopeConfig->getValue(self::XML_PATH_CLIENT_ID);
        $clientSecret = $this->encryptor->decrypt($this->scopeConfig->getValue(self::XML_PATH_CLIENT_SECRET));
        $apiKey = $this->getApiKey();
        $redirectUri = $this->scopeConfig->getValue(self::XML_PATH_REDIRECT_URI);

        $tokenUrl = $apiUrl . '/ext/auth-api/accounts/token';

        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret
        ];

        $this->logger->info('Trans.eu Token Exchange Request (Callback)');
        $this->logger->info('URL: ' . $tokenUrl);

        $this->curl->setHeaders([]);
        $this->curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $this->curl->addHeader('Api-key', $apiKey);

        try {
            $this->curl->post($tokenUrl, http_build_query($params));

            $response = $this->curl->getBody();
            $statusCode = $this->curl->getStatus();

            $this->logger->info('Response Status: ' . $statusCode);

            if ($statusCode == 200) {
                $data = $this->json->unserialize($response);
                $this->saveTokens($data);
            } else {
                $this->logger->error('Response Body: ' . $response);
                throw new \Exception('Failed to obtain access token: ' . $statusCode . ' ' . $response);
            }
        } catch (\Exception $e) {
            $this->logger->error('Trans.eu Token Exchange Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Repeatable Step - Refresh Token Request
     * Documentation: POST /ext/auth-api/accounts/token
     */
    public function refreshToken()
    {
        $manualToken = $this->getManualToken();
        if ($manualToken) {
            $exp = $this->getTokenExpirationTime($manualToken);
            if ($exp && $exp < time()) {
                $this->logger->info("Manual token expired. Attempting to refresh via Python script...");
                return $this->fetchTokenFromPython();
            }
            return false;
        }

        $refreshToken = $this->scopeConfig->getValue(self::XML_PATH_REFRESH_TOKEN);
        if (!$refreshToken) {
            return false;
        }

        $apiUrl = $this->scopeConfig->getValue(self::XML_PATH_API_URL);
        $clientId = $this->scopeConfig->getValue(self::XML_PATH_CLIENT_ID);
        $clientSecret = $this->encryptor->decrypt($this->scopeConfig->getValue(self::XML_PATH_CLIENT_SECRET));
        $apiKey = $this->getApiKey();

        $tokenUrl = $apiUrl . '/ext/auth-api/accounts/token';

        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret
        ];

        $this->logger->info('Trans.eu Token Refresh Request');

        $this->curl->setHeaders([]);
        $this->curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $this->curl->addHeader('Api-key', $apiKey);

        try {
            $this->curl->post($tokenUrl, http_build_query($params));

            $response = $this->curl->getBody();
            $statusCode = $this->curl->getStatus();

            $this->logger->info('Refresh Response Status: ' . $statusCode);

            if ($statusCode == 200) {
                $data = $this->json->unserialize($response);
                $this->saveTokens($data);
                return $data['access_token'];
            } else {
                $this->logger->error('Refresh Token Failed: ' . $response);
                return false;
            }
        } catch (\Exception $e) {
            $this->logger->error('Trans.eu Refresh Token Error: ' . $e->getMessage());
            return false;
        }
    }

    public function getManualToken()
    {
        $token = $this->scopeConfig->getValue(self::XML_PATH_MANUAL_TOKEN);
        if ($token) {
            return trim($token);
        }
        return null;
    }

    public function getAccessToken()
    {
        // 1. Check Manual Token
        $manualToken = $this->getManualToken();
        if ($manualToken) {
            $exp = $this->getTokenExpirationTime($manualToken);
            if ($exp && $exp < time()) {
                $this->logger->info("Manual token expired. Fetching new one via Python...");
                return $this->fetchTokenFromPython();
            }
            return $manualToken;
        }

        // 2. Check Stored Access Token
        $accessToken = $this->scopeConfig->getValue(self::XML_PATH_ACCESS_TOKEN);
        $expiresAt = $this->scopeConfig->getValue(self::XML_PATH_TOKEN_EXPIRES);

        if ($accessToken && $expiresAt > time()) {
            return $accessToken; // Token is valid
        }

        // 3. Token missing or expired -> Try Refresh Token (API)
        $this->logger->info("Access token expired or missing. Attempting refresh...");
        $refreshedToken = $this->refreshToken();

        if ($refreshedToken) {
            return $refreshedToken;
        }

        // 4. Refresh failed -> Fallback to Python Auto-Login
        $this->logger->info("Refresh token failed. Fallback to Python Auto-Login...");
        return $this->fetchTokenFromPython();
    }

    /**
     * Helper to run Python script and save token
     */
    protected function fetchTokenFromPython()
    {
        $newToken = $this->tokenProvider->getTokenFromPython();
        if ($newToken) {
            $this->configWriter->save(self::XML_PATH_MANUAL_TOKEN, $newToken);
            $this->cacheTypeList->cleanType('config');
            $this->reinitableConfig->reinit();
            return $newToken;
        } else {
            $this->emailSender->sendTokenRefreshError("Python script failed to retrieve new token (on demand).");
            return null;
        }
    }

    protected function saveTokens($data)
    {
        $this->logger->info('Saving tokens to database...');
        try {
            $this->configWriter->save(self::XML_PATH_ACCESS_TOKEN, $data['access_token']);
            $this->configWriter->save(self::XML_PATH_REFRESH_TOKEN, $data['refresh_token']);
            $this->configWriter->save(self::XML_PATH_TOKEN_EXPIRES, time() + $data['expires_in'] - 60);

            $this->logger->info('Tokens saved successfully.');

            $this->cacheTypeList->cleanType('config');
            $this->reinitableConfig->reinit();
            $this->logger->info('Config cache cleaned and reinitialized.');

        } catch (\Exception $e) {
            $this->logger->error('Error saving tokens: ' . $e->getMessage());
        }
    }

    public function getTokenExpirationTime($token)
    {
        if (!$token) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = $parts[1];
        $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $payload));

        if (!$decoded) {
            return null;
        }

        $data = json_decode($decoded, true);
        return isset($data['exp']) ? $data['exp'] : null;
    }
}
