<?php
namespace GardenLawn\TransEu\Controller\Index;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use GardenLawn\TransEu\Model\AuthService;

class Index extends Action
{
    protected $authService;

    public function __construct(
        Context $context,
        AuthService $authService
    ) {
        parent::__construct($context);
        $this->authService = $authService;
    }

    public function execute()
    {
        $code = $this->getRequest()->getParam('code');
        $error = $this->getRequest()->getParam('error');
        $errorDescription = $this->getRequest()->getParam('error_description');

        /** @var \Magento\Framework\Controller\Result\Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHeader('Content-Type', 'text/html');

        $html = '<!DOCTYPE html><html><head><title>Trans.eu Authorization</title><style>body{font-family:sans-serif;text-align:center;padding:50px;}</style></head><body>';

        if ($error) {
            $html .= '<h1 style="color:red;">Authorization Failed</h1>';
            $html .= '<p>Error: ' . htmlspecialchars($error) . '</p>';
            if ($errorDescription) {
                $html .= '<p>Description: ' . htmlspecialchars($errorDescription) . '</p>';
            }
        } elseif ($code) {
            try {
                $this->authService->handleCallback($code);
                $html .= '<h1 style="color:green;">Authorization Successful</h1>';
                $html .= '<p>You have successfully connected to Trans.eu.</p>';
                $html .= '<p>You can now close this window and refresh the Magento configuration page.</p>';
                $html .= '<script>setTimeout(function(){ window.close(); }, 3000);</script>';
            } catch (\Exception $e) {
                $html .= '<h1 style="color:red;">Error</h1>';
                $html .= '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            }
        } else {
            $html .= '<h1 style="color:red;">Invalid Request</h1>';
        }

        $html .= '</body></html>';
        $result->setContents($html);

        return $result;
    }
}
