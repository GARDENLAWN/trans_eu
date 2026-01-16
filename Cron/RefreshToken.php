<?php
namespace GardenLawn\TransEu\Cron;

use GardenLawn\TransEu\Model\TokenProvider;
use GardenLawn\TransEu\Model\AuthService;
use GardenLawn\Core\Helper\EmailSender;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Psr\Log\LoggerInterface;

class RefreshToken
{
    protected $tokenProvider;
    protected $configWriter;
    protected $cacheTypeList;
    protected $reinitableConfig;
    protected $logger;
    protected $emailSender;

    public function __construct(
        TokenProvider $tokenProvider,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        ReinitableConfigInterface $reinitableConfig,
        LoggerInterface $logger,
        EmailSender $emailSender
    ) {
        $this->tokenProvider = $tokenProvider;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->reinitableConfig = $reinitableConfig;
        $this->logger = $logger;
        $this->emailSender = $emailSender;
    }

    public function execute()
    {
        $this->logger->info("Cron: Starting Trans.eu token refresh...");

        try {
            $token = $this->tokenProvider->getTokenFromPython();

            if ($token) {
                $this->configWriter->save(AuthService::XML_PATH_MANUAL_TOKEN, $token);
                $this->cacheTypeList->cleanType('config');
                $this->reinitableConfig->reinit();
                $this->logger->info("Cron: Token refreshed successfully.");
            } else {
                $this->logger->error("Cron: Failed to refresh token.");
                $this->emailSender->sendTokenRefreshError("Cron job failed to retrieve token via Python script.");
            }
        } catch (\Exception $e) {
            $this->logger->error("Cron: Exception refreshing token: " . $e->getMessage());
            $this->emailSender->sendTokenRefreshError("Cron job exception: " . $e->getMessage());
        }
    }
}
