<?php
namespace GardenLawn\TransEu\Cron;

use GardenLawn\TransEu\Model\TokenProvider;
use GardenLawn\TransEu\Model\AuthService;
use GardenLawn\Core\Helper\EmailSender;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class RefreshToken
{
    protected $tokenProvider;
    protected $configWriter;
    protected $cacheTypeList;
    protected $reinitableConfig;
    protected $logger;
    protected $emailSender;
    protected $scopeConfig;
    protected $authService;

    public function __construct(
        TokenProvider $tokenProvider,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        ReinitableConfigInterface $reinitableConfig,
        LoggerInterface $logger,
        EmailSender $emailSender,
        ScopeConfigInterface $scopeConfig,
        AuthService $authService
    ) {
        $this->tokenProvider = $tokenProvider;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->reinitableConfig = $reinitableConfig;
        $this->logger = $logger;
        $this->emailSender = $emailSender;
        $this->scopeConfig = $scopeConfig;
        $this->authService = $authService;
    }

    public function execute()
    {
        // Check if module is active
        if (!$this->scopeConfig->isSetFlag(AuthService::XML_PATH_ACTIVE)) {
            return;
        }

        $this->logger->info("Cron Job [TransEu]: Checking Web Token status...");

        // Check current token expiration
        $currentToken = $this->scopeConfig->getValue(AuthService::XML_PATH_MANUAL_TOKEN);
        $shouldRefresh = true;

        if ($currentToken) {
            $exp = $this->authService->getTokenExpirationTime($currentToken);
            // If token is valid for at least another 2 hours, skip refresh to avoid spamming Selenium
            if ($exp && ($exp - time()) > 7200) {
                $this->logger->info("Cron Job [TransEu]: Web Token is valid for > 2 hours. Skipping refresh.");
                $shouldRefresh = false;
            }
        }

        if ($shouldRefresh) {
            $this->logger->info("Cron Job [TransEu]: Web Token missing or expiring soon. Starting refresh via Python...");
            try {
                $token = $this->tokenProvider->getTokenFromPython();

                if ($token) {
                    $this->configWriter->save(AuthService::XML_PATH_MANUAL_TOKEN, $token);
                    $this->cacheTypeList->cleanType('config');
                    $this->reinitableConfig->reinit();
                    $this->logger->info("Cron Job [TransEu]: Web Token refreshed successfully.");
                } else {
                    $this->logger->error("Cron Job [TransEu]: Failed to refresh Web Token (Python script returned null).");
                    // Only send email if we really have no valid token
                    if (!$currentToken || ($exp && $exp < time())) {
                        $this->emailSender->sendTokenRefreshError("Cron job failed to retrieve Web Token via Python script.");
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error("Cron Job [TransEu]: Exception refreshing Web Token: " . $e->getMessage());
            }
        }

        // Also refresh OAuth token if needed (with 2h buffer)
        $this->logger->info("Cron Job [TransEu]: Checking OAuth Token status...");
        try {
            $this->authService->checkAndRefreshOAuthToken(7200);
        } catch (\Exception $e) {
             $this->logger->error("Cron Job [TransEu]: OAuth refresh error: " . $e->getMessage());
        }
    }
}
