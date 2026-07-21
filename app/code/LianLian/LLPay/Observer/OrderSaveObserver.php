<?php

namespace LianLian\LLPay\Observer;
use LianLian\LLPay\Helper\Data;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterfaceFactory;
use Magento\Sales\Model\ResourceModel\Order\Address\CollectionFactory;
use Magento\Sales\Model\Spi\OrderResourceInterface;

class OrderSaveObserver implements ObserverInterface
{
    protected $addressCollection;
    protected $productRepository;
    protected $_data;
    protected $orderResource;
    protected $orderFactory;
    protected $checkoutSession;

    protected $_request;

    public function __construct(
        CollectionFactory $addressCollection,
        ProductRepositoryInterface $ProductRepositoryInterface,
        \Magento\Framework\App\RequestInterface $request,
        OrderResourceInterface $orderResource,
        OrderInterfaceFactory $orderFactory,
        Session $checkoutSession,
        Data $data
    ) {
        $this->addressCollection = $addressCollection;
        $this->productRepository = $ProductRepositoryInterface;
        $this->_request = $request;
        $this->_data = $data;
        $this->orderResource = $orderResource;
        $this->orderFactory = $orderFactory;
        $this->checkoutSession = $checkoutSession;
    }
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $this->_data->_logger->log('INFO', 'OrderSaveObserver->execute1');
            $order = $observer->getEvent()->getOrder();
            if (isset($order)){
                $this->_data->_logger->log('INFO', 'OrderSaveObserver->execute2 order 已设置');
            }else{
                $this->_data->_logger->log('INFO', 'OrderSaveObserver->execute2 order 未设置');
            }
            if (isset($order) && $order->getCanSendNewEmailFlag()!==null) {
                $this->_data->_logger->log('INFO', 'OrderSaveObserver->execute6 getCanSendNewEmailFlag 不为空');
                $this->_data->_logger->log('INFO', 'OrderSaveObserver->execute6->order-SendNewEmailFlag1:'.strval($order->getCanSendNewEmailFlag()));
                $order->setCanSendNewEmailFlag(false);
                $this->_data->_logger->log('INFO', 'OrderSaveObserver->execute6->order-SendNewEmailFlag2:'.strval($order->getCanSendNewEmailFlag()));
            }else{
                $this->_data->_logger->log('INFO', 'OrderSaveObserver->execute7');
            }


            $this->_data->_logger->log('INFO', 'OrderSaveObserver->execute8');
        } catch (\Exception $e) {
            $this->_data->_logger->log('INFO', 'OrderSaveObserver->execute9');
            $this->logger->critical($e);
        }
    }

}
