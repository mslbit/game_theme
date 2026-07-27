<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 下单完成观察者
 *
 * 监听 sales_order_payment_place_end 事件：
 * authorize 命令已执行，支付状态已保存到 additionalInformation。
 *
 * 根据 payment_status 设置订单状态：
 * - 支付成功（SendTradeCommand payment_status=1 或 EmbeddedCaptureCommand 非 pending）：
 *   调 registerCaptureNotification 完成支付，订单进入 PROCESSING
 * - 非 PS：设为 pending_payment，等异步通知确认
 *
 * 替代原自定义 AuthorizeCommand（全局替换 AuthorizeOperation 的 stateCommand），
 * 改用事件观察者方式，不影响其他支付方式。
 */
class PaymentPlaceEndObserver implements ObserverInterface
{
    /**
     * Oceanpayment 嵌入式支付方式代码列表
     */
    private const OCEANPAYMENT_METHODS = [
        'oceanpayment_creditcard',
        'oceanpayment_applepay',
        'oceanpayment_googlepay',
    ];

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        /** @var Payment $payment */
        $payment = $observer->getEvent()->getPayment();
        if (!$payment instanceof Payment) {
            return;
        }

        /* 只处理 Oceanpayment 支付方式 */
        if (!in_array($payment->getMethod(), self::OCEANPAYMENT_METHODS, true)) {
            return;
        }

        $order = $payment->getOrder();

        /* 抑制下单邮件通知（等异步通知确认后再发） */
        $order->setCanSendNewEmailFlag(false);

        if ($this->isPaymentSuccess($payment)) {
            $this->handlePaymentSuccess($payment, $order);
        } else {
            $this->handlePaymentPending($payment, $order);
        }
    }

    /**
     * 判断支付是否成功
     *
     * 两种数据来源：
     * - SendTradeCommand（收银台跳转）：oceanpayment_payment_status = '1' 或 1
     * - EmbeddedCaptureCommand（嵌入式查询）：isTransactionPending = false 表示成功
     */
    private function isPaymentSuccess(Payment $payment): bool
    {
        /* SendTradeCommand 路径：payment_status 为数字，1=成功 */
        $paymentStatus = $payment->getAdditionalInformation('oceanpayment_payment_status');
        if ($paymentStatus === '1' || $paymentStatus === 1) {
            return true;
        }

        /* EmbeddedCaptureCommand 路径：isTransactionPending=false 表示 capture 成功 */
        if ($paymentStatus === null && !$payment->getIsTransactionPending()) {
            return true;
        }

        return false;
    }

    /**
     * 支付成功：调 registerCaptureNotification 完成支付
     */
    private function handlePaymentSuccess(Payment $payment, Order $order): void
    {
        $amount = (float) $order->getBaseTotalDue();
        if ($amount <= 0) {
            $amount = (float) $order->getBaseGrandTotal();
        }

        $payment->registerCaptureNotification($amount);

        $this->logger->info('[Oceanpayment] PaymentPlaceEnd: success → registerCaptureNotification', [
            'order' => $order->getIncrementId(),
            'amount' => $amount,
        ]);
    }

    /**
     * 非 PS：设为 pending_payment，等异步通知确认
     */
    private function handlePaymentPending(Payment $payment, Order $order): void
    {
        $order->setState(Order::STATE_PENDING_PAYMENT);
        $order->setStatus(Order::STATE_PENDING_PAYMENT);

        $this->logger->info('[Oceanpayment] PaymentPlaceEnd: non-success → pending_payment', [
            'order' => $order->getIncrementId(),
        ]);
    }
}