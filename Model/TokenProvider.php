<?php
namespace GardenLawn\TransEu\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;

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

    public function getTokenFromPython()
    {
        // Get credentials from config (we might need to add username/password fields if they differ from client_id)
        // Assuming client_id is username and we need a password field, OR we use hardcoded for now as requested.
        // Ideally, add 'username' and 'password' fields to system.xml.

        // For now, using the credentials you provided in chat (hardcoded for safety test, but should be config)
        $username = '1242549-1';
        $password = 'Ktm450sx-f@!';

        // Locate the script
        $modulePath = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, 'GardenLawn_TransEu');
        $scriptPath = $modulePath . '/scripts/get_token.py';

        if (!file_exists($scriptPath)) {
            $this->logger->error("Trans.eu Python script not found at: $scriptPath");
            return null;
        }

        // Build command with stderr redirection
        // Ensure python3 is in path or use full path
        $cmd = "python3 " . escapeshellarg($scriptPath) . " " . escapeshellarg($username) . " " . escapeshellarg($password) . " 2>&1";

        $this->logger->info("Executing Python script: $cmd");

        $output = shell_exec($cmd);

        $this->logger->info("Python script output: " . $output);

        if (!$output) {
            $this->logger->error("Python script returned no output.");
            return null;
        }

        try {
            // Try to find JSON in output (in case there are warnings before it)
            if (preg_match('/\{.*\}/s', $output, $matches)) {
                $jsonOutput = $matches[0];
                $result = $this->json->unserialize($jsonOutput);

                if (isset($result['success']) && $result['success'] && isset($result['token'])) {
                    return $result['token'];
                } else {
                    $this->logger->error("Python script failed: " . ($result['message'] ?? 'Unknown error'));
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
