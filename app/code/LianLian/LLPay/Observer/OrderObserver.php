<?php
/**
 * Created by
 * User：罗志禹
 * Date：2022/3/9
 * Time：22:20
 */

namespace LianLian\LLPay\Observer;

use LianLian\LLPay\Helper\Data;
use lianlianpay\v3sdk\model\Address;
use lianlianpay\v3sdk\model\Card;
use lianlianpay\v3sdk\model\Customer;
use lianlianpay\v3sdk\model\MerchantOrder;
use lianlianpay\v3sdk\model\PayRequest;
use lianlianpay\v3sdk\model\RequestPaymentData;
use lianlianpay\v3sdk\service\Payment;
use lianlianpay\v3sdk\core\PaySDK;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderInterfaceFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Address\CollectionFactory;
use Magento\Sales\Model\Spi\OrderResourceInterface;

/**
 * Class OrderManagement
 */
class OrderObserver implements ObserverInterface
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
        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();
        $orderId = $order->getIncrementId();
        $payment = $order->getPayment();
        $method = $payment->getMethodInstance();
        $paymentCode = $method->getCode();
        if ($paymentCode != 'llpay_custompaymentoption') {
            return;
        }
        if ($orderId) {
            // your logic
            $pay_sdk = PaySDK::getInstance();
            $pay_sdk->init($this->_data->getIsSandBoxEnv());

            $this->pay($order);
        }
    }

    /**
     * @param $order
     * @return object
     */
    public function getBillingAddress($order): object
    {
        $orderBillingId = $order->getBillingAddressId();
        return $this->addressCollection->create()->addFieldToFilter('entity_id', [$orderBillingId])->getFirstItem();
    }

    /**
     * @param $order
     * @return \Magento\Framework\DataObject|void
     */
    public function getShippingAddress($order)
    {
        if (!$order->getIsVirtual()) {
            $orderShippingId = $order->getShippingAddressId();
            return $this->addressCollection->create()
                ->addFieldToFilter('entity_id', [$orderShippingId])->getFirstItem();
        }
    }

    /**
     * @param $result
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Zend_Log_Exception
     */
    public function pay($result)
    {
        //get payment additional information from order
        /** @var Order $result */
        $additionalInformation = $result->getPayment()->getAdditionalInformation();
        $card_token = isset($additionalInformation['post_ll_data_value']['additional_data']['card_token'])??'';

        if (!$card_token) {
            return;
        }

        $pay_request = new PayRequest();
        $pay_request->merchant_id = $this->_data->getMerchantId();
        //二级商户号,若有二级商户号必填
        $pay_request->biz_code = 'EC';
        $pay_request->country = $this->_data->getConfigValue('general/country/default');
        $redirect_url=$this->_data->redirectUrl();
        $pay_request->redirect_url = $redirect_url;
        $notification_url=$this->_data->notificationUrl();
        $pay_request->notification_url = $notification_url;

        $time = date('YmdHis', time());
        $merchant_transaction_id = $result->getIncrementId();
        $pay_request->merchant_transaction_id = $merchant_transaction_id;
        $pay_request->payment_method = 'inter_credit_card';

        $shippingAddress1 = $this->getShippingAddress($result);

        $address = new Address();
        $address->city = $shippingAddress1->getCity();
        $address->country = $shippingAddress1->getCountryId();
        $address->line1 = $shippingAddress1->getStreet()[0];
        $address->line2 = '无';
        $address->state = $shippingAddress1->getRegion();
        $address->postal_code = $shippingAddress1->getPostcode();

        $customer = new Customer();
        $customer->address = $address;
        $customer->customer_type = 'I';
        $customer->full_name = $shippingAddress1->getFirstname().$shippingAddress1->getLastname();
        $customer->first_name = $shippingAddress1->getFirstname();
        $customer->last_name = $shippingAddress1->getLastname();
        $customer->email = $shippingAddress1->getEmail();
        $pay_request->customer = $customer;

        $products = [];
        foreach ($result->getAllVisibleItems() as $_item) {
            $_product = $this->productRepository->get($_item->getSku());
            $products[]=[
                'name'=>$_item->getName(),
                'category'=>'无',
                'price'=>str_replace(",", "", number_format($_item->getPrice(), 2)),
                'product_id'=>$_item->getProductId(),
                'quantity'=>$_item->getQtyOrdered(),
                'shipping_provider'=>'other',
                'sku'=>$_item->getSku(),
                'url'=>$_product->getProductUrl()
            ];
        }
        $shippingAddress = $this->getShippingAddress($result);
        $shipping['address'] = [
            'city' => $shippingAddress->getCity(),
            'country'=> $shippingAddress->getCountryId(),
            'line1' => $shippingAddress->getStreet()[0],
            'line2' => '无',
            'state'=> $shippingAddress->getRegion(),
            'postal_code'=> $shippingAddress->getPostcode()
        ];
        $shipping['name'] = $shippingAddress->getFirstname().' '.$shippingAddress->getLastname();
        $shipping['phone'] =  $shippingAddress->getTelephone();
        $shipping['cycle'] =  '48h';

        $merchant_order = new MerchantOrder();
        //此为商户系统的订单号，支付订单号和支付交易单号可以传一样
        $merchant_order->merchant_order_id = $merchant_transaction_id;
        $merchant_order->merchant_order_time = $time;
        $merchant_order->order_amount = str_replace(",", "", number_format($result->getGrandTotal(), 2));
        $merchant_order->order_currency_code = $result->getOrderCurrencyCode();
        $merchant_order->order_description = '无';

        $merchant_order->products = $products;
        $merchant_order->shipping = $shipping;

        $pay_request->merchant_order = $merchant_order;

        $payment_data = new RequestPaymentData();
        $billingAddress = $this->getBillingAddress($result);
        $card = new Card();
        $card->billing_address=[
            'line1'=>$billingAddress->getStreet()[0],
            'city'=>$billingAddress->getCity(),
            'country'=>$billingAddress->getCountryId(),
            'postal_code'=>$billingAddress->getPostcode()
        ];

        $card->holder_name=$shippingAddress1->getFirstname().$shippingAddress1->getLastname();
        $card->card_token=$additionalInformation['post_ll_data_value']['additional_data']['card_token'];
        $payment_data->card = $card;
        $pay_request->payment_data = $payment_data;

        $this->_data->_logger->log('INFO', 'magento_request', $this->_data->objectToArray($pay_request));

        //请求参数
        $payment = new Payment();
        $pay_response = $payment->pay($pay_request, $this->_data->getMerchantPriKey(), $this->_data->getLLPubKey());

        //响应结果
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/ll_pay.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info('响应信息：'.json_encode($pay_response, JSON_UNESCAPED_UNICODE));

        $this->_data->_logger->log('INFO', 'magento_response:'.json_encode($pay_response, JSON_UNESCAPED_UNICODE));

        $llOrderStatus = '';
        $llResponseMsg = $pay_response['return_message'];
        if ($pay_response['return_code'] === 'SUCCESS') {
            if ($pay_response['sign_verify']) {
                $responseOrder = $pay_response['order'];
                $llOrderStatus = $responseOrder['payment_data']['payment_status'];
                $llResponseMsg = $this->_data->getLLPayCodeMsg($llOrderStatus);
                $this->successDeal($responseOrder);
            }
        }
        $this->checkoutSession->setOrderId($result->getId());
        $this->checkoutSession->setIncrementId($result->getIncrementId());
        $this->checkoutSession->setLLPaymentStatus($pay_response['return_code']);
        $this->checkoutSession->setLLOrderStatus($llOrderStatus);
        $this->checkoutSession->setLLPaymentMessage($llResponseMsg);
    }

    public function successDeal($responseOrder)
    {
        if (isset($responseOrder['merchant_transaction_id'])
            && $responseOrder['payment_data']['payment_status'] == 'PS') {
            $this->_data->_logger->log('INFO', 'OrderObserver->execute->successDeal 支付成功');
            $increment_id = explode('-', $responseOrder['merchant_transaction_id'])[0];
            $orderDeal = $this->orderFactory->create();
            $this->orderResource->load($orderDeal, $increment_id, OrderInterface::INCREMENT_ID);
            $orderState = Order::STATE_PROCESSING;
            $orderDeal->setState($orderState)->setStatus($orderState);
            $orderDeal->save();

        }

        if (isset($responseOrder['3ds_status']) && $responseOrder['3ds_status'] === 'CHALLENGE') {
            $this->_data->_logger->log('INFO', 'OrderObserver->execute->successDeal 3DS验证');
            $this->checkoutSession->setTds($responseOrder['3ds_status']);
        } else {
            $this->checkoutSession->setTds('');
        }
        $this->_data->_logger->log('INFO', 'OrderObserver->execute->successDeal payment_url:'.$responseOrder['payment_url']);
        $this->checkoutSession->setPaymentUrl($responseOrder['payment_url']);
    }
}
