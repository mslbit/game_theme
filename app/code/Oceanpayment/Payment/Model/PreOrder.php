<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Model;

use GuzzleHttp\Client as GuzzleClient;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Oceanpayment\Payment\Api\PreOrderInterface;
use Oceanpayment\Payment\Gateway\Config\Config;
use Oceanpayment\Payment\Gateway\Helper\SignatureHelper;
use Oceanpayment\Payment\Gateway\Helper\XmlHelper;
use Oceanpayment\Payment\Model\Ui\ConfigProvider;
use Oceanpayment\Payment\Service\GeoIpService;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 嵌入式支付预下单实现
 *
 * 流程：
 * 1. 加载购物车（quote），预生成 order_number
 * 2. 组装完整支付参数（参考 SendTradeService 的 OrderBuilder + CustomerBuilder）
 * 3. 合并 card_data，计算签名
 * 4. cURL POST 到 Oceanpayment /gateway/direct/pay
 * 5. 解析 XML 响应，保存 payment 信息到 quote payment
 * 6. 返回 pay_url（3D 验证）或支付结果给前端
 */
class PreOrder implements PreOrderInterface
{
    /**
     * /gateway/direct/pay 端点签名字段顺序（与 Creditcardonepage 一致，不含 backUrl）
     */
    private const SIGN_FIELDS = [
        'account',
        'terminal',
        'order_number',
        'order_currency',
        'order_amount',
        'billing_firstName',
        'billing_lastName',
        'billing_email',
    ];

    private const PAGES_PC = 0;
    private const PAGES_MOBILE = 1;

    private const METHOD_MAP = [
        ConfigProvider::CODE_CREDITCARD  => 'Credit Card',
        ConfigProvider::CODE_APPLEPAY    => 'ApplePay',
        ConfigProvider::CODE_GOOGLEPAY   => 'GooglePay',
        ConfigProvider::CODE_WECHATPAY   => 'WechatPay_Web',
        ConfigProvider::CODE_ALIPAY      => 'Alipay_Web',
    ];

    private const DEFAULT_FIRST_NAME = 'Guest';
    private const DEFAULT_LAST_NAME = 'User';
    private const DEFAULT_COUNTRY = 'HK';
    private const DEFAULT_STATE = 'HCW';
    private const DEFAULT_CITY = 'Hong Kong';

    private CartRepositoryInterface $quoteRepository;
    private RequestInterface $httpRequest;
    private Config $config;
    private SignatureHelper $signatureHelper;
    private GeoIpService $geoIpService;
    private XmlHelper $xmlHelper;
    private GuzzleClient $httpClient;
    private CacheInterface $cache;
    private LoggerInterface $logger;

    private const CACHE_LOGIN_PREFIX = 'oceanpayment_login_';
    private const CACHE_LOGIN_LIFETIME = 3600;

    public function __construct(
        CartRepositoryInterface $quoteRepository,
        RequestInterface $httpRequest,
        Config $config,
        SignatureHelper $signatureHelper,
        GeoIpService $geoIpService,
        XmlHelper $xmlHelper,
        GuzzleClient $httpClient,
        CacheInterface $cache,
        LoggerInterface $logger
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->httpRequest = $httpRequest;
        $this->config = $config;
        $this->signatureHelper = $signatureHelper;
        $this->geoIpService = $geoIpService;
        $this->xmlHelper = $xmlHelper;
        $this->httpClient = $httpClient;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function preOrder(int $cartId, string $cardData = ''): array
    {
        $quote = $this->quoteRepository->getActive($cartId);
        return $this->processPreOrder($quote, $cardData);
    }

    public function guestPreOrder(string $cartId, string $email, string $cardData = ''): array
    {
        $quote = $this->quoteRepository->getActive($cartId);
        /** @var Quote $quote */
        $quote->setCustomerEmail($email);
        return $this->processPreOrder($quote, $cardData);
    }

    private function processPreOrder(Quote $quote, string $cardData): array
    {
        // 先查 quote payment 是否已有支付结果，有则直接返回
        $existingResult = $this->getExistingPaymentInfo($quote);
        if ($existingResult) {
            $this->logger->info('[Oceanpayment] Pre-order returning existing payment info', [
                'payment_id' => $existingResult['payment_id'] ?? '',
                'payment_status' => $existingResult['payment_status'] ?? '',
            ]);
            return ['data' => $existingResult];
        }


        // 重新生成 order_number，避免 Duplicate order
        $quote->reserveOrderId();
        $this->quoteRepository->save($quote);

        $orderParams = $this->buildOrderParams($quote);
        $customerParams = $this->buildCustomerParams($quote);
        $params = array_merge($orderParams, $customerParams);

        // 合并 card_data
        $params['card_data'] = $cardData;

        // 计算签名
        $params['signValue'] = $this->signatureHelper->calculateSignature(
            self::SIGN_FIELDS,
            $params,
            $this->config->getSecureCode()
        );

        $this->logger->info('[Oceanpayment] Pre-order sending to gateway', [
            'order_number' => $params['order_number'],
            'order_amount' => $params['order_amount'],
            'order_currency' => $params['order_currency'],
        ]);

        // cURL POST 到 Oceanpayment /gateway/direct/pay
        $payModeUrl = $this->config->getGatewayUrl() . '/gateway/direct/pay';
        $response = $this->sendPaymentRequest($payModeUrl, $params);

        // 解析 XML 响应
        $result = $this->xmlHelper->parse($response);

        // payment_status 非 0 才保存（1=成功，-1=3D/待处理，0=失败/重复等）
        $paymentStatus = (string) ($result['payment_status'] ?? '0');
        if ($paymentStatus !== '0') {
            $this->savePaymentInfo($quote, $result);
            // 保存登录状态到 cache + cookie，3D 回来时 Back 控制器恢复
            $this->saveLoginState($quote);
        }

        // 返回前端需要的数据
        return [
            'data' => [
                'payment_id'      => $result['payment_id'] ?? '',
                'pay_url'         => $result['pay_url'] ?? '',
                'payment_status'  => $result['payment_status'] ?? '',
                'payment_details' => $result['payment_details'] ?? '',
                'card_number'     => $result['card_number'] ?? '',
                'payment_authType' => $result['payment_authType'] ?? '',
                'signValue'       => $result['signValue'] ?? '',
            ]
        ];
    }

    /**
     * cURL POST 到 Oceanpayment 网关
     */
    private function sendPaymentRequest(string $url, array $params): string
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'timeout'     => 30,
                'verify'      => true,
                'form_params' => $params,
                'headers'     => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);

            return (string) $response->getBody();

        } catch (\Exception $e) {
            $this->logger->error('[Oceanpayment] Gateway request failed', [
                'error' => $e->getMessage(),
                'url'   => $url,
            ]);
            throw new \RuntimeException('Oceanpayment gateway request failed: ' . $e->getMessage());
        }
    }

    /**
     * 查询 quote payment 是否已有支付结果
     *
     * 如果 payment_status 非 0（1=成功，-1=3D/待处理），说明已发起过支付
     *
     * @return array|null 已有的支付结果，无则返回 null
     */
    private function getExistingPaymentInfo(Quote $quote): ?array
    {
        $payment = $quote->getPayment();
        if (!$payment) {
            return null;
        }

        $paymentId = $payment->getAdditionalInformation('oceanpayment_payment_id');
        if (empty($paymentId)) {
            return null;
        }

        $paymentStatus = $payment->getAdditionalInformation('oceanpayment_payment_status');
        if ((string) $paymentStatus === '0') {
            return null;
        }

        return [
            'payment_id'       => $paymentId,
            'pay_url'          => $payment->getAdditionalInformation('oceanpayment_pay_url') ?? '',
            'payment_status'   => $paymentStatus ?? '',
            'payment_details'  => '',
            'card_number'      => $payment->getAdditionalInformation('oceanpayment_card_number') ?? '',
            'payment_authType' => $payment->getAdditionalInformation('oceanpayment_auth_type') ?? '',
            'signValue'        => '',
        ];
    }

    /**
     * 保存 payment 信息到 quote payment 的 additional_information
     */
    private function savePaymentInfo(Quote $quote, array $result): void
    {
        $payment = $quote->getPayment();
        if (!$payment) {
            return;
        }

        $payment->setAdditionalInformation('oceanpayment_payment_id', $result['payment_id'] ?? '');
        $payment->setAdditionalInformation('oceanpayment_card_number', $result['card_number'] ?? '');
        $payment->setAdditionalInformation('oceanpayment_auth_type', $result['payment_authType'] ?? '');
        $payment->setAdditionalInformation('oceanpayment_payment_status', $result['payment_status'] ?? '');
        $payment->setAdditionalInformation('oceanpayment_pay_url', $result['pay_url'] ?? '');
       // $payment->setAdditionalData(json_encode($result));

        $this->quoteRepository->save($quote);

        $this->logger->info('[Oceanpayment] Payment info saved to quote', [
            'payment_id' => $result['payment_id'] ?? '',
            'payment_status' => $result['payment_status'] ?? '',
        ]);
    }

    /**
     * 保存登录状态到 cache
     *
     * 3D 验证跨站 POST 回来时 session 完全重建，登录态丢失。
     * 用 cache 存储 customer_id，key = order_number，
     * Back 控制器从 POST 参数拿到 order_number 后查 cache 恢复登录。
     */
    private function saveLoginState(Quote $quote): void
    {
        $customerId = $quote->getCustomerId();
        if (!$customerId) {
            return;
        }

        $orderNumber = $quote->getReservedOrderId();
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

    private function buildOrderParams(Quote $quote): array
    {
        $paymentMethod = $quote->getPayment() ? $quote->getPayment()->getMethod() : '';
        $methods = self::METHOD_MAP[$paymentMethod] ?? 'Credit Card';

        $pageType = $this->detectPageType();
        if ($pageType === self::PAGES_MOBILE && str_ends_with($methods, '_Web')) {
            $methods = substr($methods, 0, -4) . '_Wap';
        }

        // backUrl 
        $backUrl = $this->signatureHelper->buildBackUrl();

        return [
            'order_number'   => $quote->getReservedOrderId(),
            'order_currency' => $quote->getQuoteCurrencyCode(),
            'order_amount'   => number_format((float) $quote->getGrandTotal(), 2, '.', ''),
            'order_notes'    => '',
            'methods'        => $methods,
            'backUrl'        => $backUrl,
            'noticeUrl'      => $this->signatureHelper->buildNoticeUrl(),
            'pages'          => $pageType,
        ];
    }

    private function buildCustomerParams(Quote $quote): array
    {
        $billingAddress = $quote->getBillingAddress();
        $isVirtual = $quote->getIsVirtual();
        $customerEmail = $quote->getCustomerEmail() ?? '';
        $customerFirstname = trim((string) ($quote->getCustomerFirstname() ?? ''));
        $customerLastname = trim((string) ($quote->getCustomerLastname() ?? ''));

        $firstName = $billingAddress ? trim((string) $billingAddress->getFirstname()) : '';
        $firstName = $firstName ?: ($customerFirstname ?: self::DEFAULT_FIRST_NAME);

        $lastName = $billingAddress ? trim((string) $billingAddress->getLastname()) : '';
        $lastName = $lastName ?: ($customerLastname ?: self::DEFAULT_LAST_NAME);

        $billingCountry = $billingAddress ? (string) $billingAddress->getCountryId() : '';
        $billingState = $billingAddress ? (string) $billingAddress->getRegionCode() : '';
        $billingCity = $billingAddress ? (string) $billingAddress->getCity() : '';
        $billingStreet = $billingAddress ? $this->getStreetLine($billingAddress) : '';
        $billingZip = $billingAddress ? (string) $billingAddress->getPostcode() : '';
        $billingPhone = $billingAddress ? (string) $billingAddress->getTelephone() : '';

        if ($isVirtual) {
            $firstName = $customerFirstname ?: self::DEFAULT_FIRST_NAME;
            $lastName = $customerLastname ?: self::DEFAULT_LAST_NAME;

            $clientIp = $this->getRemoteAddress();
            $billingCountry = $this->geoIpService->getCountryCode($clientIp) ?: self::DEFAULT_COUNTRY;
            $billingState = $this->geoIpService->getRegionCode($clientIp) ?: self::DEFAULT_STATE;
            $billingCity = $this->geoIpService->getCity($clientIp) ?: self::DEFAULT_CITY;
            $billingStreet = '';
            $billingZip = '';
        }

        return [
            'account'           => $this->config->getAccount(),
            'terminal'          => $this->config->getTerminal(),
            'billing_firstName' => $firstName,
            'billing_lastName'  => $lastName,
            'billing_email'     => $customerEmail,
            'billing_phone'     => $billingPhone ?: $this->generateMaskedMobileNumber(),
            'billing_country'   => $billingCountry,
            'billing_state'     => $billingState,
            'billing_city'      => $billingCity,
            'billing_address'   => $billingStreet,
            'billing_zip'       => $billingZip,
            'billing_ip'        => $this->getRemoteAddress(),
        ];
    }

    private function detectPageType(): int
    {
        $userAgent = $this->httpRequest->getServer('HTTP_USER_AGENT', '');
        $mobileKeywords = '/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i';

        if (preg_match($mobileKeywords, (string) $userAgent)) {
            return self::PAGES_MOBILE;
        }

        return self::PAGES_PC;
    }

    private function generateMaskedMobileNumber(): string
    {
        $prefix = '1' . mt_rand(3, 9) . mt_rand(0, 9);
        $suffix = sprintf('%04d', mt_rand(0, 9999));
        return $prefix . '****' . $suffix;
    }

    private function getStreetLine($address): string
    {
        $street = $address->getStreetLine1();
        return is_string($street) ? $street : '';
    }

    private function getRemoteAddress(): string
    {
        $forwardedFor = $this->httpRequest->getServer('HTTP_X_FORWARDED_FOR');
        if (!empty($forwardedFor)) {
            $ips = explode(',', (string) $forwardedFor);
            return trim($ips[0]);
        }

        return (string) $this->httpRequest->getServer('REMOTE_ADDR', '');
    }
}
