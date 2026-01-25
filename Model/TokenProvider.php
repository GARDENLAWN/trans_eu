<?php
namespace GardenLawn\TransEu\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use GardenLawn\TransEu\Model\AuthService;

class TokenProvider
{
    protected $scopeConfig;
    protected $encryptor;
    protected $logger;
    protected $json;
    protected $componentRegistrar;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        LoggerInterface $logger,
        Json $json,
        ComponentRegistrarInterface $componentRegistrar
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->logger = $logger;
        $this->json = $json;
        $this->componentRegistrar = $componentRegistrar;
    }

    public function getTokenFromPython($mfaCode = null)
    {
        // Get credentials from config
        $username = $this->scopeConfig->getValue(AuthService::XML_PATH_USERNAME);
        $password = $this->scopeConfig->getValue(AuthService::XML_PATH_PASSWORD);

        if (!$username || !$password) {
            $this->logger->error("Trans.eu Auto-Login: Username or Password not configured.");
            return null;
        }

        // Decrypt password
        try {
            $password = $this->encryptor->decrypt($password);
        } catch (\Exception $e) {
            $this->logger->error("Trans.eu Auto-Login: Failed to decrypt password: " . $e->getMessage());
            return null;
        }

        // Locate the script
        $modulePath = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, 'GardenLawn_TransEu');
        $scriptPath = $modulePath . '/scripts/get_token.py';

        if (!file_exists($scriptPath)) {
            $this->logger->error("Trans.eu Python script not found at: $scriptPath");
            return null;
        }

        // Build command with stderr redirection
        // Ensure python3 is in path or use full path
        $cmd = "python3 " . escapeshellarg($scriptPath) . " " . escapeshellarg($username) . " " . escapeshellarg($password);

        // Add MFA code if provided
        if ($mfaCode) {
            $cmd .= " " . escapeshellarg($mfaCode);
        }

        $cmd .= " 2>&1";

        $this->logger->info("Executing Python script: python3 ... " . escapeshellarg($username) . " [PASSWORD HIDDEN]" . ($mfaCode ? " [MFA CODE]" : ""));

        $output = shell_exec($cmd);

        // Log output but be careful not to log sensitive info if script echoes it back (it shouldn't)
        $this->logger->info("Python script output: " . substr($output, 0, 500) . "...");

        if (!$output) {
            $this->logger->error("Python script returned no output.");
            return null;
        }

        try {
            // Try to find JSON in output (in case there are warnings before it)
            if (preg_match('/\{.*\}/s', $output, $matches)) {
                $jsonOutput = $matches[0];
                $result = $this->json->unserialize($jsonOutput);

                // Check for MFA requirement
                if (isset($result['mfa_required']) && $result['mfa_required']) {
                    return ['mfa_required' => true];
                }

                if (isset($result['success']) && $result['success'] && isset($result['token'])) {
                    return $result['token'];
                } else {
                    $this->logger->error("Python script failed: " . ($result['message'] ?? 'Unknown error'));
                    return ['error' => $result['message'] ?? 'Unknown error'];
                }
            } else {
                $this->logger->error("Python script output is not valid JSON: " . $output);
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to parse Python output: " . $e->getMessage());
        }

        return null;
    }
}
