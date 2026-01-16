<?php
namespace GardenLawn\TransEu\Controller\Adminhtml\Test;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use GardenLawn\TransEu\Model\TokenProvider;
use GardenLawn\TransEu\Model\AuthService;
use GardenLawn\Core\Helper\EmailSender;

class AutoLogin extends Action
{
    protected $resultJsonFactory;
    protected $tokenProvider;
    protected $configWriter;
    protected $cacheTypeList;
    protected $reinitableConfig;
    protected $emailSender;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        TokenProvider $tokenProvider,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        ReinitableConfigInterface $reinitableConfig,
        EmailSender $emailSender
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->tokenProvider = $tokenProvider;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->reinitableConfig = $reinitableConfig;
        $this->emailSender = $emailSender;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            $token = $this->tokenProvider->getTokenFromPython();

            if ($token) {
                // Save token
                $this->configWriter->save(AuthService::XML_PATH_MANUAL_TOKEN, $token);
                $this->cacheTypeList->cleanType('config');
                $this->reinitableConfig->reinit();

                return $result->setData([
                    'success' => true,
                    'message' => 'Token retrieved and saved successfully.',
                    'token' => $token
                ]);
            } else {
                $msg = 'Failed to retrieve token via Python script (Manual Trigger). Check logs.';
                $this->emailSender->sendTokenRefreshError($msg);

                return $result->setData([
                    'success' => false,
                    'message' => $msg
                ]);
            }
        } catch (\Exception $e) {
            $msg = 'Exception during manual token refresh: ' . $e->getMessage();
            $this->emailSender->sendTokenRefreshError($msg);

            return $result->setData([
                'success' => false,
                'message' => $msg
            ]);
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('GardenLawn_TransEu::config');
    }
}
