<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Model\Order\Payment\State;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\State\AuthorizeCommand as MagentoAuthorizeCommand;
use Magento\Sales\Model\Order\StatusResolver;

/**
 * Oceanpayment 自定义授权状态命令
 *
 * 重写 Magento 原生 AuthorizeCommand，实现两个功能：
 * 1. 下单时不发邮件：setCustomerNoteNotify(false)
 *    嵌入式支付在下单时支付尚未经后端确认，不应发确认邮件，
 *    等异步通知确认支付成功后再发。
 * 2. 3D验证场景下状态改为 PENDING_PAYMENT：
 *    原生 AuthorizeCommand 在 isTransactionPending=true 时设 STATE_PAYMENT_REVIEW，
 *    但 3D 验证场景下订单实际是"待支付"而非"支付审查"，
 *    改为 STATE_PENDING_PAYMENT 更准确，且不会触发自动 invoice。
 *
 * 非 Oceanpayment 支付方式走原生逻辑，不受影响。
 */
class AuthorizeCommand extends MagentoAuthorizeCommand
{
    /**
     * Oceanpayment 嵌入式支付方式代码列表（仅3种嵌入式）
     */
    private const OCEANPAYMENT_METHODS = [
        'oceanpayment_creditcard',
        'oceanpayment_applepay',
        'oceanpayment_googlepay',
    ];

    /**
     * @var StatusResolver
     */
    private $statusResolver;

    /**
     * @param StatusResolver|null $statusResolver
     */
    public function __construct(?StatusResolver $statusResolver = null)
    {
        parent::__construct($statusResolver);
        $this->statusResolver = $statusResolver
            ?: \Magento\Framework\App\ObjectManager::getInstance()->get(StatusResolver::class);
    }

    /**
     * 执行授权状态命令
     *
     * Oceanpayment 支付方式：
     * - 抑制邮件通知（等异步通知确认后再发）
     * - isTransactionPending=true（3D验证）→ STATE_PENDING_PAYMENT
     * - isFraudDetected=true → STATE_PAYMENT_REVIEW + STATUS_FRAUD（保持原生逻辑）
     * - 其他 → STATE_PROCESSING（保持原生逻辑）
     *
     * 非 Oceanpayment 支付方式：完全走原生逻辑
     */
    public function execute(OrderPaymentInterface $payment, $amount, OrderInterface $order)
    {

        if (!$this->isOceanpaymentMethod($payment)) {
            return parent::execute($payment, $amount, $order);
        }

        /* Oceanpayment 支付：抑制下单邮件通知 */
        $order->setCanSendNewEmailFlag(false);

        /* 欺诈检测：保持原生 PAYMENT_REVIEW + FRAUD 逻辑 */
        if ($payment->getIsFraudDetected()) {
            $state = Order::STATE_PAYMENT_REVIEW;
            $status = Order::STATUS_FRAUD;
            $message = 'Order is suspended as its authorizing amount %1 is suspected to be fraudulent.';
        } elseif ($payment->getIsTransactionPending()) {
            /*
             * 3D验证场景：PENDING_PAYMENT 代替 PAYMENT_REVIEW
             *
             * 原生逻辑在此处设 STATE_PAYMENT_REVIEW，但：
             * - 3D验证是正常支付流程，不是风控审查
             * - PENDING_PAYMENT 更准确表达"等待支付确认"语义
             * - 避免 PAYMENT_REVIEW 状态下自动创建 invoice
             */
            $state = Order::STATE_PENDING_PAYMENT;
            $status = null;
            $message = 'Order is pending payment verification at the payment gateway.';
        } else {
            /* 授权成功：PROCESSING */
            $state = Order::STATE_PROCESSING;
            $status = null;
            $message = 'Authorized amount of %1.';
        }

        if (!isset($status)) {
            $status = $this->statusResolver->getOrderStatusByState($order, $state);
        }

        $order->setState($state);
        $order->setStatus($status);

        return __($message, $order->getBaseCurrency()->formatTxt($amount));
    }

    /**
     * 检查是否为 Oceanpayment 支付方式
     */
    private function isOceanpaymentMethod(OrderPaymentInterface $payment): bool
    {
        return in_array($payment->getMethod(), self::OCEANPAYMENT_METHODS, true);
    }
}