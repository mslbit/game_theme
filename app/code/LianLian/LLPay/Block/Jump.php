<?php

namespace LianLian\LLPay\Block;

use LianLian\LLPay\Helper\Data;
use lianlianpay\v3sdk\utils\LianLianSign;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Request\Http;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderInterfaceFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Spi\OrderResourceInterface;

class Jump extends Template
{
    public $error_msg = 'Payment Failed';
    public $order_id = '0';
    public $increment_id = '0';
    protected $_checkoutSession;
    protected $_userSession;
    /**
     * @var Http
     */
    protected $_request;

    /**
     * @var LianLianSign
     */
    protected $sign_tools;

    /**
     * @var Data
     */
    protected $_helperData;
    protected $orderResource;
    protected $orderFactory;

    /**
     * @param Template\Context $context
     * @param Session $session
     * @param \Magento\Customer\Model\Session $userSession
     * @param Http $request
     * @param Data $dataLll
     * @param LianLianSign $lianLianSign
     * @param OrderResourceInterface $orderResource
     * @param OrderInterfaceFactory $orderFactory
     * @param array $data
     */
    public function __construct(
        Template\Context $context,
        Session $session,
        \Magento\Customer\Model\Session $userSession,
        Http $request,
        Data $dataLll,
        LianLianSign $lianLianSign,
        OrderResourceInterface $orderResource,
        OrderInterfaceFactory $orderFactory,
        array $data = []
    ) {
        $this->_checkoutSession = $session;
        $this->_userSession = $userSession;
        $this->_request = $request;
        $this->_helperData = $dataLll;
        $this->sign_tools = $lianLianSign;
        $this->orderResource = $orderResource;
        $this->orderFactory = $orderFactory;
        parent::__construct($context, $data);
    }

    public function checkSign(): bool
    {
        //两个入口一个是3ds跳转过来一个是checkout页面跳转过来
        $data = $this->_request->getParams();
        $valid = false;

        if (isset($data['message'])) {
            $this->error_msg = $data['message'];
            $this->order_id = $data['order_id'];
            $this->increment_id = $data['increment_id'];
        }

        if (isset($data['signature'])) {
            $sign = $data['signature'];
            unset($data['signature']);
            $result = $this->sign_tools->verify($data, $sign, $this->_helperData->getLLPubKey());

            if ($result) {
                $increment_id = explode('-', $data['merchant_transaction_id'])[0];
                $order = $this->orderFactory->create();
                $this->orderResource->load($order, $increment_id, OrderInterface::INCREMENT_ID);
                $this->error_msg = $this->_helperData->getLLPayCodeMsg($data['payment_status']);
                $this->order_id = $order->getId();
                $this->increment_id = $order->getIncrementId();

                if ($data['payment_status'] === 'PS') {

                    $orderState = Order::STATE_PROCESSING;
                    $order->setState($orderState)->setStatus($orderState);
                    $order->save();
                    if ($order->getId()) {
                        $this->_checkoutSession->setLastQuoteId($order->getQuoteId());
                        $this->_checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
                        $this->_checkoutSession->setLastOrderId($order->getId());
                        $this->_checkoutSession->setLastRealOrderId($order->getIncrementId());
                        $this->_checkoutSession->setLastOrderStatus($order->getStatus());
                        $valid  = true;
                        $this->_eventManager->dispatch('llpay_callback_after', [
                            'order' => $order,
                            'callback_data' => $data,
                        ]);
                    }
                }
            }
        }
        return $valid;
    }

    /**
     * 返回支付成功的url
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getCheckoutUrl(): string
    {
        return $this->_storeManager->getStore()->getBaseUrl().'checkout/onepage/success';
    }
}
