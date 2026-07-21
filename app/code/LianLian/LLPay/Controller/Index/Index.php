<?php

namespace LianLian\LLPay\Controller\Index;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class Index extends Action
{
    /**
     * @var Session
     */
    protected $session;
    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    public function __construct(Context $context, JsonFactory $resultJsonFactory, Session $session)
    {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->session = $session;
        parent::__construct($context);
    }

    public function execute()
    {
        // TODO: Implement execute() method.
        $result = $this->resultJsonFactory->create();
        if ($this->getRequest()->isAjax()) {
            $response = [
                'order_id' => $this->session->getOrderId(),
                'increment_id' => $this->session->getIncrementId(),
                'status' => $this->session->getLLPaymentStatus(),
                'message' => $this->session->getLLPaymentMessage(),
                'll_order_status' => $this->session->getLLOrderStatus(),
                'tds' => $this->session->getTds(),
                'payment_url' => $this->session->getPaymentUrl()
            ];
            return $result->setData($response);
        }
    }


}
