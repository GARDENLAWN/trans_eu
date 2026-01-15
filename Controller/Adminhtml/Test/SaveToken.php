<?php
namespace GardenLawn\TransEu\Controller\Adminhtml\Test;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use GardenLawn\TransEu\Model\AuthService;

class SaveToken extends Action
{
    protected $resultJsonFactory;
    protected $configWriter;
    protected $cacheTypeList;
    protected $reinitableConfig;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        ReinitableConfigInterface $reinitableConfig
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->reinitableConfig = $reinitableConfig;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $token = $this->getRequest()->getParam('token');

        if (!$token) {
            return $result->setData([
                'success' => false,
                'message' => 'No token provided.'
            ]);
        }

        try {
            // Save to manual token path
            $this->configWriter->save(AuthService::XML_PATH_MANUAL_TOKEN, $token);

            // Clear cache
            $this->cacheTypeList->cleanType('config');
            $this->reinitableConfig->reinit();

            return $result->setData([
                'success' => true,
                'message' => 'Token saved successfully.'
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => 'Error saving token: ' . $e->getMessage()
            ]);
        }
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('GardenLawn_TransEu::config');
    }
}
