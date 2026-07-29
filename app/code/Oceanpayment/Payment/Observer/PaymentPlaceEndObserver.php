<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order;
use Oceanpayment\Payment\Gateway\Service\PaymentSuccessService;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 下单完成观察者
 *
 * 监听 sales_order_payment_place_end 事件：
 * authorize 命令已执行，支付状态已保存到 additionalInformation。
 *
 * 根据 payment_status 设置订单状态：
 * - 支付成功：委托 PaymentSuccessService 统一处理（capture + 邮件 + 事件）
 * - 非成功：设为 pending_payment，等异步通知确认
 *
 * 与 CallbackProcessor 的成功路径统一走 PaymentSuccessService，
 * 保证开票、邮件、事件分发逻辑一致。
 */
class PaymentPlaceEndObserver implements ObserverInterface
{
    private const OCEANPAYMENT_METHODS = [
        'oceanpayment_creditcard',
        'oceanpayment_applepay',
        'oceanpayment_googlepay',
    ];

    private PaymentSuccessService $paymentSuccessService;
    private LoggerInterface $logger;

    public function __construct(
        PaymentSuccessService $paymentSuccessService,
        LoggerInterface $logger
    ) {
        $this->paymentSuccessService = $paymentSuccessService;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        $payment = $observer->getEvent()->getPayment();
        if (!$payment instanceof OrderPaymentInterface) {
            return;
        }

        if (!in_array($payment->getMethod(), self::OCEANPAYMENT_METHODS, true)) {
            return;
        }

        $order = $payment->getOrder();

        /* 抑制下单邮件通知（PaymentSuccessService 内部会在 capture 后发送） */
        $order->setCanSendNewEmailFlag(false);

        if ($this->isPaymentSuccess($payment)) {
            $this->handlePaymentSuccess($order);
        } else {
            $this->handlePaymentPending($order);
        }
    }

    /**
     * 判断支付是否成功
     *
     * - SendTradeCommand：oceanpayment_payment_status = '1' 或 1
     * - EmbeddedCaptureCommand：isTransactionPending=false 表示成功
     */
    private function isPaymentSuccess(OrderPaymentInterface $payment): bool
    {
        $paymentStatus = $payment->getAdditionalInformation('oceanpayment_payment_status');
        if ($paymentStatus === '1' || $paymentStatus === 1) {
            return true;
        }

        if ($paymentStatus === null && !$payment->getIsTransactionPending()) {
            return true;
        }

        return false;
    }

    /**
     * 委托 PaymentSuccessService 统一处理
     *
     * PaymentSuccessService 负责：capture + invoice + 邮件 + oceanpayment_callback_after 事件
     */
    private function handlePaymentSuccess(Order $order): void
    {
        $this->paymentSuccessService->execute($order);

        $this->logger->info('[Oceanpayment] PaymentPlaceEnd: success → PaymentSuccessService', [
            'order' => $order->getIncrementId(),
        ]);
    }

    /**
     * 非成功：设为 pending_payment，等异步通知确认
     */
    private function handlePaymentPending(Order $order): void
    {
        $order->setState(Order::STATE_PENDING_PAYMENT);
        $order->setStatus(Order::STATE_PENDING_PAYMENT);

        $this->logger->info('[Oceanpayment] PaymentPlaceEnd: non-success → pending_payment', [
            'order' => $order->getIncrementId(),
        ]);
    }
}
