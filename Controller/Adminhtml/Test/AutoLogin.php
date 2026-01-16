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
