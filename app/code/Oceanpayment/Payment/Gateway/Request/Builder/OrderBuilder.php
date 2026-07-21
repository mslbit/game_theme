<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Request\Builder;

use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Framework\App\RequestInterface;
use Oceanpayment\Payment\Gateway\Helper\SignatureHelper;
use Oceanpayment\Payment\Model\Ui\ConfigProvider;

/**
 * Oceanpayment 订单信息构建器
 *
 * 构建与订单相关的 API 请求参数：
 * - order_number: 订单增量 ID
 * - order_currency: 订单币种
 * - order_amount: 订单金额（保留 2 位小数）
 * - order_notes: 订单备注（默认为空）
 * - methods: Oceanpayment 交易方法标识（如 Credit Card、Alipay_Web）
 * - backUrl: 用户支付完成后返回商城的 URL
 * - noticeUrl: Oceanpayment 异步通知回调 URL
 * - pages: 页面类型标识（0=PC, 1=移动端）
 */
class OrderBuilder implements BuilderInterface
{
    /**
     * PC 端页面标识
     */
    private const PAGES_PC = 0;

    /**
     * 移动端页面标识
     */
    private const PAGES_MOBILE = 1;

    /**
     * Magento 支付方式 → Oceanpayment methods 映射
     *
     * 这是固定的 API 标识符对应关系，无需从数据库配置读取
     */
    private const METHOD_MAP = [
        ConfigProvider::CODE_CREDITCARD  => 'Credit Card',
        ConfigProvider::CODE_APPLEPAY    => 'ApplePay',
        ConfigProvider::CODE_GOOGLEPAY   => 'GooglePay',
        ConfigProvider::CODE_WECHATPAY   => 'WechatPay_Web',
        ConfigProvider::CODE_ALIPAY      => 'Alipay_Web',

    ];

    /**
     * @var RequestInterface HTTP 请求接口（用于检测设备类型）
     */
    private RequestInterface $httpRequest;

    /**
     * @var SignatureHelper 签名与 URL 集中管理
     */
    private SignatureHelper $signatureHelper;

    /**
     * Constructor
     *
     * @param RequestInterface $httpRequest HTTP 请求接口
     * @param SignatureHelper $signatureHelper 签名与 URL 集中管理
     */
    public function __construct(
        RequestInterface $httpRequest,
        SignatureHelper $signatureHelper
    ) {
        $this->httpRequest = $httpRequest;
        $this->signatureHelper = $signatureHelper;
    }

    /**
     * 构建订单相关请求参数
     *
     * @param array $buildSubject 构建参数，包含 payment 数据对象
     * @return array 订单相关 API 参数
     */
    public function build(array $buildSubject): array
    {
        /* 从构建参数中提取 PaymentDataObject */
        $paymentDO = $this->readPayment($buildSubject);
        $order = $paymentDO->getOrder();
        $payment = $paymentDO->getPayment();

        /* 从 map 获取 Oceanpayment methods 标识符 */
        $methodCode = $payment->getMethod();
        $methods = self::METHOD_MAP[$methodCode] ?? 'Credit Card';

        /* 微信/支付宝移动端场景切换：_Web → _Wap（信用卡/ApplePay/GooglePay 无后缀不受影响） */
        $pageType = $this->detectPageType();
        if ($pageType === self::PAGES_MOBILE && str_ends_with($methods, '_Web')) {
            $methods = substr($methods, 0, -4) . '_Wap';
        }

        return [
            /* 订单号：使用 increment_id 确保唯一性和可追溯性 */
            'order_number'   => $order->getOrderIncrementId(),

            /* 订单币种：ISO 4217 三字母代码（如 USD、EUR、CNY） */
            'order_currency' => $order->getCurrencyCode(),

            /* 订单金额：格式化为 2 位小数，符合 Oceanpayment 金额规范 */
            'order_amount'   => number_format((float) $order->getGrandTotalAmount(), 2, '.', ''),

            /* 订单备注：预留字段，当前为空 */
            'order_notes'    => '',

            /* 交易方法：从 map 查找，根据页面类型动态切换 _Web/_Wap */
            'methods'        => $methods,

            /* 支付完成返回 URL：由 SignatureHelper 统一管理 */
            'backUrl'        => $this->signatureHelper->buildBackUrl(),

            /* 异步通知 URL：由 SignatureHelper 统一管理 */
            'noticeUrl'      => $this->signatureHelper->buildNoticeUrl(),

            /* 页面类型：0=PC 端页面, 1=移动端页面，根据 User-Agent 自动判断 */
            'pages'          => $pageType,
        ];
    }

    /**
     * 检测当前访问的页面类型
     *
     * 通过 HTTP User-Agent 判断用户设备类型：
     * - 移动端浏览器返回 1
     * - PC 端浏览器返回 0
     *
     * @return int 页面类型标识
     */
    private function detectPageType(): int
    {
        $userAgent = $this->httpRequest->getServer('HTTP_USER_AGENT', '');
        $mobileKeywords = '/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i';

        if (preg_match($mobileKeywords, (string) $userAgent)) {
            return self::PAGES_MOBILE;
        }

        return self::PAGES_PC;
    }

    /**
     * 从构建参数中读取 PaymentDataObject
     *
     * @param array $buildSubject 构建参数
     * @return PaymentDataObjectInterface
     * @throws \InvalidArgumentException
     */
    private function readPayment(array $buildSubject): PaymentDataObjectInterface
    {
        if (!isset($buildSubject['payment']) || !$buildSubject['payment'] instanceof PaymentDataObjectInterface) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        return $buildSubject['payment'];
    }
}