<?php
/**
 * Created by
 * User：罗志禹
 * Date：2022/4/14
 * Time：23:07
 */

namespace LianLian\LLPay\Model;

use LianLian\LLPay\Api\NotificationInterface;
use LianLian\LLPay\Helper\Data;
use Magento\Framework\Webapi\Rest\Request;
use lianlianpay\v3sdk\utils\LianLianSign;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Spi\OrderResourceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderInterfaceFactory;
use Magento\Sales\Model\Order;

class Notification implements NotificationInterface
{

    protected $request;
    protected $sign_tools;
    protected $orderResource;
    protected $orderFactory;
    protected $_data;
    protected $orderSender;

    public function __construct(
        Request $request,
        LianLianSign $sign_tools,
        OrderResourceInterface $orderResource,
        OrderInterfaceFactory $orderFactory,
        OrderSender $orderSender,
        Data $data
    ) {
        $this->request = $request;
        $this->sign_tools = $sign_tools;
        $this->orderResource = $orderResource;
        $this->orderFactory = $orderFactory;
        $this->_data = $data;
        $this->orderSender = $orderSender;
    }

    /**
     * @return void
     */
    public function updateStatus()
    {
        $this->_data->_logger->log('INFO', 'Notification -> updateStatus');
        // TODO: Implement updateStatus() method.
        $data = $this->request->getBodyParams();
        if (isset($data['payment_data']) && isset($data['payment_data']['exchange_rate'])&& $data['payment_data']['exchange_rate']) {
            $data['payment_data']['exchange_rate'] = sprintf('%.8f', $data['payment_data']['exchange_rate']);
        }
        $data['payment_data']['payment_amount'] = sprintf('%.2f', $data['payment_data']['payment_amount']);
        if (isset($data['payment_data']) && isset($data['payment_data']['settlement_amount']) && $data['payment_data']['settlement_amount']) {
            $data['payment_data']['settlement_amount'] = sprintf('%.2f', $data['payment_data']['settlement_amount']);
        }
        $signature = $this->request->getHeaders()->toArray()['Signature']??'';
        $result = $this->sign_tools->verify($data, $signature, $this->_data->getLLPubKey());
        $this->_data->_logger->log('INFO', 'magento_api_update_order', [$data,$signature]);
        if ($result) {
            if (isset($data['merchant_transaction_id']) && $data['payment_data']['payment_status']=='PS') {
                $increment_id = explode('-', $data['merchant_transaction_id'])[0];
                $order = $this->orderFactory->create();
                $this->orderResource->load($order, $increment_id, OrderInterface::INCREMENT_ID);
                $orderState = Order::STATE_PROCESSING;
                if ($order->getStatus() == 'pending' || $order->getStatus() == 'processing') {
                    //TODO 支付成功发邮件 响应失败会连续发邮件
                    $this->_data->_logger->log('INFO', 'Notification->updateStatus 支付成功');
                    $this->orderSender->send($order);
                    $this->_data->_logger->log('INFO', 'Notification->updateStatus 发送成功邮件');

                    $order->setState($orderState)->setStatus($orderState);
                    $order->save();
                    // phpcs:ignore Magento2.Functions.DiscouragedFunction
                    header('content-type:application/json');
                    echo '{"code":"200","message":"success"}';
                    die;
                }
            }
        } else {
            header('content-type:application/json');
            echo '{"code":"401","message":"签名验证失败"}';
            die;
        }
    }
}
