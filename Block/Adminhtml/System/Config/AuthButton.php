<?php
namespace GardenLawn\TransEu\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use GardenLawn\TransEu\Model\AuthService;
use Magento\Backend\Block\Template\Context;

class AuthButton extends Field
{
    /**
     * @var AuthService
     */
    protected $authService;

    /**
     * @param Context $context
     * @param AuthService $authService
     * @param array $data
     */
    public function __construct(
        Context $context,
        AuthService $authService,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->authService = $authService;
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        try {
            $url = $this->authService->getAuthorizationUrl();
        } catch (\Exception $e) {
            return '<div class="message message-error error"><div>' . __('Error generating auth URL: %1', $e->getMessage()) . '</div></div>';
        }

        /** @var \Magento\Backend\Block\Widget\Button $button */
        $button = $this->getLayout()->createBlock(
            \Magento\Backend\Block\Widget\Button::class
        );

        $button->setData([
            'id' => 'trans_eu_auth_btn',
            'label' => __('Authorize with Trans.eu'),
            'onclick' => 'window.open(\'' . $url . '\', \'_blank\')',
            'class' => 'action-default primary'
        ]);

        $html = $button->toHtml();
        $html .= '<p class="note"><span>' . __('Clicking this button will open the Trans.eu authorization page in a new tab.') . '</span></p>';

        return $html;
    }
}
