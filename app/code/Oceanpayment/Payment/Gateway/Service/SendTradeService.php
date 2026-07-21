<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Service;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Oceanpayment\Payment\Gateway\Config\Config;
use Oceanpayment\Payment\Gateway\Helper\SignatureHelper;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 托管收银 sendTrade 服务
 *
 * 编排层：调用 Builder 构建参数 → 合并 → 签名 → 发送 → 解析响应
 *
 * 职责划分：
 * - OrderBuilder:    订单信息 + methods + backUrl/noticeUrl + pages
 * - CustomerBuilder: account/terminal + billing 字段（含虚拟产品降级）
 * - 本类:            合并 Builder 输出 → 计算签名 → HTTP 通信 → 解析响应
 *
 * 签名在此处计算的原因：签名依赖完整的请求数据（由各 Builder 合并而来），
 * 不应由任何 Builder 单独承担数据组装职责。
 */
class SendTradeService
{
    /**
     * sendTrade 响应成功状态码
     */
    private const PAY_RESULTS_SUCCESS = 1;

    /**
     * 默认 sendTrade 端点路径
     */
    private const URI_SEND_TRADE = '/gateway/service/sendTrade';

    /**
     * 自动重定向端点路径
     */
    private const URI_PAY = '/gateway/service/pay';

    /**
     * sendTrade 签名字段顺序（严格按 Oceanpayment 文档）
     */
    private const SIGN_FIELDS = [
        'account',
        'terminal',
        'backUrl',
        'order_number',
        'order_currency',
        'order_amount',
        'billing_firstName',
        'billing_lastName',
        'billing_email',
    ];

    /**
     * @var BuilderInterface 订单参数构建
     */
    private BuilderInterface $orderBuilder;

    /**
     * @var BuilderInterface 客户参数构建
     */
    private BuilderInterface $customerBuilder;

    /**
     * @var Config 网关配置（读取 secureCode）
     */
    private Config $config;

    /**
     * @var SignatureHelper 签名计算
     */
    private SignatureHelper $signatureHelper;

    /**
     * @var TransferFactoryInterface HTTP 传输工厂
     */
    private TransferFactoryInterface $transferFactory;

    /**
     * @var ClientInterface HTTP 客户端
     */
    private ClientInterface $client;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param BuilderInterface         $orderBuilder    OrderBuilder 实例
     * @param BuilderInterface         $customerBuilder CustomerBuilder 实例
     * @param Config                   $config          网关配置
     * @param SignatureHelper          $signatureHelper 签名计算
     * @param TransferFactoryInterface $transferFactory HTTP 传输工厂
     * @param ClientInterface          $client          HTTP 客户端
     * @param LoggerInterface          $logger          日志
     */
    public function __construct(
        BuilderInterface $orderBuilder,
        BuilderInterface $customerBuilder,
        Config $config,
        SignatureHelper $signatureHelper,
        TransferFactoryInterface $transferFactory,
        ClientInterface $client,
        LoggerInterface $logger
    ) {
        $this->orderBuilder    = $orderBuilder;
        $this->customerBuilder = $customerBuilder;
        $this->config          = $config;
        $this->signatureHelper = $signatureHelper;
        $this->transferFactory = $transferFactory;
        $this->client          = $client;
        $this->logger          = $logger;
    }

    /**
     * 执行 sendTrade 请求，获取 pay_url
     *
     * @param PaymentDataObjectInterface $paymentDO 支付数据对象
     * @param string $uri API 端点路径，默认为 sendTrade，auto_redirect 模式使用 /pay
     * @return array 包含 pay_url / payment_id 的响应数组
     * @throws \RuntimeException 当请求失败时
     */
    public function execute(PaymentDataObjectInterface $paymentDO, string $uri = self::URI_SEND_TRADE): array
    {
        $buildSubject = ['payment' => $paymentDO];

        /* 1. 调用 Builder 构建参数片段，合并为完整请求 */
        $request = array_merge(
            $this->orderBuilder->build($buildSubject),
            $this->customerBuilder->build($buildSubject)
        );

        /* 2. 从已合并的请求数据计算签名（数据来自 Builder，不重复组装） */
        $request['signValue'] = $this->signatureHelper->calculateSignature(
            self::SIGN_FIELDS,
            $request,
            $this->config->getSecureCode()
        );

        /* 3. TransferFactory 创建传输对象（URI 参数化，支持 sendTrade / pay 端点） */
        $transfer = $this->transferFactory->create($request, $uri);

        /* 4. Client 发送 HTTP 请求 */
        $response = $this->client->placeRequest($transfer);

        /* 5. 解析响应 */
        return $this->parseResponse($response, $paymentDO);
    }

    /**
     * 解析 sendTrade 响应
     *
     * @param array $response
     * @param PaymentDataObjectInterface $paymentDO
     * @return array 包含 pay_url / payment_id
     * @throws \RuntimeException 当请求失败时
     */
    private function parseResponse(array $response, PaymentDataObjectInterface $paymentDO): array
    {
        $payResults = (int) ($response['pay_results'] ?? 0);
        $payUrl     = $response['pay_url'] ?? '';
        $payDetails = $response['pay_details'] ?? '';

        if ($payResults !== self::PAY_RESULTS_SUCCESS || empty($payUrl)) {
            $order = $paymentDO->getOrder();
            $this->logger->error('[Oceanpayment] sendTrade failed', [
                'order_id'    => $order->getOrderIncrementId(),
                'pay_results' => $payResults,
                'pay_details' => $payDetails,
            ]);

            throw new \RuntimeException(
                sprintf('Oceanpayment request failed: %s (results: %d)', $payDetails, $payResults)
            );
        }

        return [
            'pay_url'      => $payUrl,
            'payment_id'   => $response['payment_id'] ?? '',
        ];
    }
}
