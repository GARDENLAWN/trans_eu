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

class AutoLogin extends Action
{
    protected $resultJsonFactory;
    protected $tokenProvider;
    protected $configWriter;
    protected $cacheTypeList;
    protected $reinitableConfig;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        TokenProvider $tokenProvider,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        ReinitableConfigInterface $reinitableConfig
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->tokenProvider = $tokenProvider;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->reinitableConfig = $reinitableConfig;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $mfaCode = $this->getRequest()->getParam('mfa_code');

        try {
            // Call Python script (with optional MFA code)
            $response = $this->tokenProvider->getTokenFromPython($mfaCode);

            // Case 1: MFA Required
            if (is_array($response) && isset($response['mfa_required']) && $response['mfa_required']) {
                return $result->setData([
                    'success' => false,
                    'mfa_required' => true,
                    'message' => 'MFA Code Required. Please check your email/SMS.'
                ]);
            }

            // Case 2: Error from Python
            if (is_array($response) && isset($response['error'])) {
                return $result->setData([
                    'success' => false,
                    'message' => 'Python Error: ' . $response['error']
                ]);
            }

            // Case 3: Success (Token returned as string)
            if ($response && is_string($response)) {
                $token = $response;

                // Save token
                $this->configWriter->save(AuthService::XML_PATH_MANUAL_TOKEN, $token);
                $this->cacheTypeList->cleanType('config');
                $this->reinitableConfig->reinit();

                return $result->setData([
                    'success' => true,
                    'message' => 'Token retrieved and saved successfully.',
                    'token' => $token
                ]);
            }

            // Case 4: Unknown failure
            else {
                return $result->setData([
                    'success' => false,
                    'message' => 'Failed to retrieve token via Python script. Check logs.'
                ]);
            }
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage()
            ]);
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('GardenLawn_TransEu::config');
    }
}
