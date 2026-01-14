<?php
namespace GardenLawn\TransEu\Block\Adminhtml\Test;

use Magento\Backend\Block\Template;
use GardenLawn\TransEu\Model\AuthService;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Index extends Template
{
    protected $authService;
    protected $scopeConfig;

    public function __construct(
        Template\Context $context,
        AuthService $authService,
        ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->authService = $authService;
        $this->scopeConfig = $scopeConfig;
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
}
