<?php

namespace LianLian\LLPay\Observer;

use LianLian\LLPay\Helper\Data;
use lianlianpay\v3sdk\core\PaySDK;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;

class LLShipments implements ObserverInterface
{

    protected $_data;
    protected $_manager;
    protected $_order;

    public function __construct(Data $data, ManagerInterface $manager, Order $order)
    {
        $this->_data = $data;
        $this->_manager = $manager;
        $this->_order = $order;
    }

    public function execute(Observer $observer)
    {
        // TODO: Implement execute() method.

        /* @var $shipmentObj \Magento\Sales\Model\Order\Shipment */
        $shipmentObj = $observer->getEvent()->getShipment();

        /** @var \Magento\Sales\Model\Order $order */
        $order = $shipmentObj->getOrder();
        $payment = $order->getPayment();
        $method = $payment->getMethodInstance();
        $paymentCode = $method->getCode();
        if ($paymentCode != 'llpay_custompaymentoption') {
            return;
        }
        $tracksCollection = $shipmentObj->getTracksCollection();
        $arr = null;
        foreach ($tracksCollection->getItems() as $track) {
            $track_number = $track->getTrackNumber();
            $carrier_code = $track->getCarrierCode();
            $arr[] = [
                'tracking_no' => $track_number,
                'carrier_code' => $carrier_code
            ];
        }
        if (empty($arr)) {
            return ;
        }

        $pay_sdk = PaySDK::getInstance();
        $pay_sdk->init($this->_data->getIsSandBoxEnv());

        $merchant_id = $this->_data->getMerchantId();
        $order = $this->_order->load($shipmentObj->getOrderId());

        $private_key = $this->_data->getMerchantPriKey();
        $public_key = $this->_data->getLLPubKey();

        $shipments = $arr;

        $shippingUploadRequest = new \lianlianpay\v3sdk\model\ShippingUploadRequest();
        $shippingUploadRequest->shipments = $shipments;
        $shippingUploadRequest->merchant_id = $merchant_id;
        $shippingUploadRequest->merchant_transaction_id = $order->getIncrementId() ;

        $shippingUpload = new \lianlianpay\v3sdk\service\ShippingUpload();
        $shippingUpload_response = $shippingUpload->upload($shippingUploadRequest, $private_key, $public_key);

        //响应结果
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/ll_shipment.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info('物流信息：' . json_encode($shippingUpload_response), JSON_UNESCAPED_UNICODE);

        if ($shippingUpload_response['return_code']!= 'SUCCESS') {
            $this->_manager->addErrorMessage('连连物流: ' . $shippingUpload_response['return_message']);
        }
    }
}
