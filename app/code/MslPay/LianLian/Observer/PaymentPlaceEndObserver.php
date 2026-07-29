<?php
declare(strict_types=1);

namespace MslPay\LianLian\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order;
use MslPay\LianLian\Gateway\Service\PaymentSuccessService;
use MslPay\LianLian\Model\Ui\ConfigProvider;
use Psr\Log\LoggerInterface;

/**
 * 连连支付下单完成观察者
 *
 * 监听 sales_order_payment_place_end 事件：
 * authorize 命令已执行，lianlian_payment_status 已保存到 additionalInformation。
 *
 * 根据 payment_status 设置订单状态：
 * - PS（支付成功）：委托 PaymentSuccessService 统一处理（capture + 邮件 + 事件）
 * - 非 PS（PP/WP/IN/PC/PF）：设为 pending_payment，等异步通知确认
 *
 * 与 Notification/PaymentQuery 的成功路径统一走 PaymentSuccessService，
 * 保证开票、邮件、事件分发逻辑一致。
 */
class PaymentPlaceEndObserver implements ObserverInterface
{
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

        if ($payment->getMethod() !== ConfigProvider::CODE_CHECKOUT) {
            return;
        }

        $order = $payment->getOrder();

        /* 抑制下单邮件通知（PaymentSuccessService 内部会在 capture 后发送） */
        $order->setCanSendNewEmailFlag(false);

        $paymentStatus = (string) ($payment->getAdditionalInformation('lianlian_payment_status') ?? '');

        if ($paymentStatus === 'PS') {
            $this->handlePaymentSuccess($order);
        } else {
            $this->handlePaymentPending($order, $paymentStatus);
        }
    }

    /**
     * PS：委托 PaymentSuccessService 统一处理
     *
     * PaymentSuccessService 负责：capture + invoice + 邮件 + llpay_callback_after 事件
     */
    private function handlePaymentSuccess(Order $order): void
    {
        $this->paymentSuccessService->execute($order);

        $this->logger->info('[LianLian] PaymentPlaceEnd: PS → PaymentSuccessService', [
            'order' => $order->getIncrementId(),
        ]);
    }

    /**
     * 非 PS：设为 pending_payment，等异步通知确认
     */
    private function handlePaymentPending(Order $order, string $paymentStatus): void
    {
        $order->setState(Order::STATE_PENDING_PAYMENT);
        $order->setStatus(Order::STATE_PENDING_PAYMENT);

        $this->logger->info('[LianLian] PaymentPlaceEnd: non-PS → pending_payment', [
            'order' => $order->getIncrementId(),
            'payment_status' => $paymentStatus,
        ]);
    }
}
