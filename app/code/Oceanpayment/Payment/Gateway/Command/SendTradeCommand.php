<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Framework\App\CacheInterface;
use Oceanpayment\Payment\Gateway\Config\Config;
use Oceanpayment\Payment\Gateway\Helper\SignatureHelper;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 托管支付 authorize 命令
 *
 * 用于 WeChat Pay / Alipay / UnionPay 的 /gateway/service/sendTrade 端点。
 * 在 Oceanpayment/Payment 模块中保留此命令，主要为了 cachePayUrl() 和 saveLoginState() 能力，
 * 供 PlacePay API 和 Back 控制器恢复登录态使用。
 *
 * 流程：
 * 1. 调用 BuilderComposite 构建请求参数（OrderBuilder + CustomerBuilder + ProductBuilder）
 * 2. 计算签名（签名字段含 backUrl）
 * 3. 通过 TransferFactory + Client 发送 HTTP 请求
 * 4. 检查响应，设置交易信息到 payment
 * 5. 缓存 pay_url 供 PlacePay API 读取
 * 6. 保存登录状态到 cache
 */
class SendTradeCommand implements CommandInterface
{
    /**
     * /gateway/service/sendTrade 签名字段顺序（含 backUrl）
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

    private BuilderInterface $requestBuilder;
    private TransferFactoryInterface $transferFactory;
    private ClientInterface $client;
    private Config $config;
    private SignatureHelper $signatureHelper;
    private CacheInterface $cache;
    private LoggerInterface $logger;

    private const CACHE_LOGIN_PREFIX = 'oceanpayment_login_';
    private const CACHE_LOGIN_LIFETIME = 3600;

    public function __construct(
        BuilderInterface $requestBuilder,
        TransferFactoryInterface $transferFactory,
        ClientInterface $client,
        Config $config,
        SignatureHelper $signatureHelper,
        CacheInterface $cache,
        LoggerInterface $logger
    ) {
        $this->requestBuilder = $requestBuilder;
        $this->transferFactory = $transferFactory;
        $this->client = $client;
        $this->config = $config;
        $this->signatureHelper = $signatureHelper;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * 执行托管支付命令
     *
     * @param array $commandSubject 包含 payment 数据对象
     * @throws \Magento\Payment\Gateway\Command\CommandException 支付请求失败时
     */
    public function execute(array $commandSubject): void
    {
        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $commandSubject['payment'];
        $payment = $paymentDO->getPayment();

        /* 1. 调用 BuilderComposite 构建请求参数 */
        $request = $this->requestBuilder->build($commandSubject);

        /* 2. 计算签名（含 backUrl） */
        $request['signValue'] = $this->signatureHelper->calculateSignature(
            self::SIGN_FIELDS,
            $request,
            $this->config->getSecureCode()
        );

        $this->logger->info('[Oceanpayment] SendTradeCommand sending request', [
            'order_number' => $request['order_number'] ?? '',
            'order_amount' => $request['order_amount'] ?? '',
            'methods'      => $request['methods'] ?? '',
        ]);

        /* 3. 通过 TransferFactory + Client 发送 HTTP 请求 */
        $transfer = $this->transferFactory->create($request);
        $response = $this->client->placeRequest($transfer);

        /* 4. 检查支付结果 */
        $payResults = (int) ($response['payment_status'] ?? 0);
        $payUrl = $response['pay_url'] ?? '';

        if ($payResults === 0 || empty($payUrl)) {
            $payDetails = $response['pay_details'] ?? 'Unknown error';
            $this->logger->error('[Oceanpayment] SendTradeCommand failed', [
                'order_number' => $request['order_number'] ?? '',
                'payment_status' => $payResults,
                'pay_details'  => $payDetails,
            ]);
            throw new \Magento\Payment\Gateway\Command\CommandException(
                __('Oceanpayment payment failed: %1', $payDetails)
            );
        }

        /* 5. 设置交易信息到 payment */
        $paymentId = $response['payment_id'] ?? '';
        if (!empty($paymentId)) {
            $payment->setTransactionId($paymentId);
            $payment->setLastTransId($paymentId);
        }

        $payment->setIsTransactionClosed(false);
        $payment->setAdditionalInformation('oceanpayment_payment_id', $paymentId);
        $payment->setAdditionalInformation('oceanpayment_pay_url', $payUrl);
        $payment->setAdditionalInformation('oceanpayment_payment_status', $response['payment_status'] ?? '');

        /* 6. 缓存 pay_url 供 PlacePay API 读取 */
        $this->cachePayUrl($paymentDO, $payUrl);

        /* 7. 保存登录状态到 cache（回调回来时恢复） */
        $this->saveLoginState($paymentDO);

        $this->logger->info('[Oceanpayment] SendTradeCommand success', [
            'payment_id'   => $paymentId,
            'order_number' => $request['order_number'] ?? '',
            'pay_url'      => '(hosted checkout)',
        ]);
    }

    /**
     * 缓存 pay_url 供 PlacePay API 读取
     *
     * 托管式支付（WeChatPay/Alipay/UnionPay）前端通过 PlacePay API 获取 pay_url 跳转。
     * 此处将 pay_url 写入缓存，避免 Observer 延迟或竞态导致前端取不到数据。
     */
    private function cachePayUrl(PaymentDataObjectInterface $paymentDO, string $payUrl): void
    {
        if (empty($payUrl)) {
            return;
        }

        $order = $paymentDO->getOrder();
        $quoteId = $order->getQuoteId();

        $this->cache->save(
            $payUrl,
            sprintf('oceanpayment_pay_url_%s', $quoteId),
            [],
            3600
        );

        $this->logger->info('[Oceanpayment] SendTradeCommand: pay_url cached', [
            'quote_id' => $quoteId,
        ]);
    }

    /**
     * 保存登录状态到 cache
     *
     * 3D 验证跨站 POST 回来时 session 完全重建，登录态丢失。
     * 将 customer_id 缓存，Back 控制器的 restoreLoginState() 据此恢复登录态。
     */
    private function saveLoginState(PaymentDataObjectInterface $paymentDO): void
    {
        $order = $paymentDO->getOrder();
        $customerId = $order->getCustomerId();
        if (!$customerId) {
            return;
        }

        $orderNumber = $order->getOrderIncrementId();
        $cacheKey = self::CACHE_LOGIN_PREFIX . $orderNumber;

        $this->cache->save(
            (string) $customerId,
            $cacheKey,
            ['OCEANPAYMENT_LOGIN'],
            self::CACHE_LOGIN_LIFETIME
        );

        $this->logger->info('[Oceanpayment] Login state saved to cache', [
            'order_number' => $orderNumber,
            'customer_id'  => $customerId,
        ]);
    }
}