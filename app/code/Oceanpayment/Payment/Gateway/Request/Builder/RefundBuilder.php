<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Request\Builder;

use Magento\Payment\Gateway\Request\BuilderInterface;
use Oceanpayment\Payment\Gateway\Config\Config;
use Oceanpayment\Payment\Gateway\Helper\SignatureHelper;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 退款请求构建器
 *
 * 构建 Oceanpayment Order Management API 的退款请求参数。
 * 签名算法由 SignatureHelper 统一管理，本类仅定义退款场景的签名字段顺序。
 *
 * 退款 API 端点：{gateway_base_url}/service/applyRefund
 *
 * 退款签名字段顺序：
 * SHA256(account + terminal + order_number + order_currency + refund_amount
 *        + refund_reason + payment_id + secureCode)
 */
class RefundBuilder implements BuilderInterface
{
    /**
     * 默认退款原因
     */
    private const DEFAULT_REFUND_REASON = 'Merchant initiated refund';

    /**
     * 退款签名字段顺序（严格按 Oceanpayment 退款 API 文档）
     */
    private const SIGN_FIELDS = [
        'account',
        'terminal',
        'order_number',
        'order_currency',
        'refund_amount',
        'refund_reason',
        'payment_id',
    ];

    /**
     * @var Config Oceanpayment 网关配置
     */
    private Config $config;

    /**
     * @var SignatureHelper 签名与 URL 集中管理
     */
    private SignatureHelper $signatureHelper;

    /**
     * @var LoggerInterface PSR-3 日志记录器
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param Config $config Oceanpayment 网关配置
     * @param SignatureHelper $signatureHelper 签名与 URL 集中管理
     * @param LoggerInterface $logger 日志记录器
     */
    public function __construct(
        Config $config,
        SignatureHelper $signatureHelper,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->signatureHelper = $signatureHelper;
        $this->logger = $logger;
    }

    /**
     * 构建退款请求参数
     *
     * 从 buildSubject 中提取退款相关信息，组装为 Oceanpayment 退款 API 所需的参数数组，
     * 包含退款签名（signValue）
     *
     * buildSubject 中应包含：
     * - payment: PaymentDataObjectInterface（包含订单和支付信息）
     * - amount: 退款金额
     *
     * @param array $buildSubject 构建参数
     * @return array 退款请求参数数组
     * @throws \InvalidArgumentException 当缺少必要参数时抛出
     */
    public function build(array $buildSubject): array
    {
        /* 从 buildSubject 中获取 PaymentDataObject */
        if (!isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof \Magento\Payment\Gateway\Data\PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        $paymentDO = $buildSubject['payment'];
        $payment = $paymentDO->getPayment();
        $order = $paymentDO->getOrder();

        /* 获取退款金额（格式化为两位小数） */
        $refundAmount = number_format((float) ($buildSubject['amount'] ?? 0), 2, '.', '');

        /* 获取退款原因（从 buildSubject 中读取，或使用默认值） */
        $refundReason = $buildSubject['refund_reason'] ?? self::DEFAULT_REFUND_REASON;

        /* 从 payment 的 additional_information 中获取原始 payment_id
         * 该值在支付成功回调时保存（oceanpayment_payment_id） */
        $paymentId = (string) ($payment->getAdditionalInformation('oceanpayment_payment_id') ?? '');

        /* 读取网关配置 */
        $account = $this->config->getAccount();
        $terminal = $this->config->getTerminal();

        /* 构建请求参数 */
        $request = [
            /* 商户账号 */
            'account'        => $account,

            /* 终端号 */
            'terminal'       => $terminal,

            /* 订单号（increment_id） */
            'order_number'   => $order->getOrderIncrementId(),

            /* 订单币种 */
            'order_currency' => $order->getCurrencyCode(),

            /* 退款金额（格式化为两位小数） */
            'refund_amount'  => $refundAmount,

            /* 退款原因 */
            'refund_reason'  => $refundReason,

            /* 原始支付交易的 payment_id */
            'payment_id'     => $paymentId,
        ];

        /* 计算退款签名（由 SignatureHelper 统一管理） */
        $signValue = $this->signatureHelper->calculateSignature(
            self::SIGN_FIELDS,
            $request,
            $this->config->getSecureCode()
        );
        $request['signValue'] = $signValue;

        $this->logger->info('[Oceanpayment] RefundBuilder: Built refund request', [
            'order_number'  => $request['order_number'],
            'refund_amount' => $request['refund_amount'],
            'payment_id'    => $paymentId ?: '(missing)',
        ]);

        return $request;
    }
}