<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Oceanpayment\Payment\Api\CheckoutDataInterface;
use Oceanpayment\Payment\Gateway\Config\Config;
use Oceanpayment\Payment\Gateway\Helper\SignatureHelper;
use Oceanpayment\Payment\Model\Ui\ConfigProvider;
use Oceanpayment\Payment\Service\GeoIpService;
use Psr\Log\LoggerInterface;
use Override;

/**
 * Oceanpayment 嵌入式支付 checkout 数据实现
 *
 * 从 quote + 前端传入的地址数据 组装 SDK.checkout() 所需的完整表单数据。
 * 敏感字段（account, terminal, signValue, key）由后端组装，前端无需接触。
 *
 * 地址数据来源：
 * - 前端传入 billingAddress / shippingAddress（用户可能在结账页修改过）
 * - 虚拟产品：无地址，通过 GeoIP 根据客户端 IP 获取国家/城市
 *
 * 参考 CustomerBuilder 的虚拟产品处理逻辑
 */
class CheckoutData implements CheckoutDataInterface
{
    /**
     * 嵌入式支付签名字段顺序
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

    /**
     * Magento 支付方式 → Oceanpayment methods 映射（仅嵌入式3种）
     */
    private const METHOD_MAP = [
        ConfigProvider::CODE_CREDITCARD => 'Credit Card',
        ConfigProvider::CODE_APPLEPAY   => 'ApplePay',
        ConfigProvider::CODE_GOOGLEPAY  => 'GooglePay',
    ];

    /* 虚拟产品默认值（与 CustomerBuilder 一致） */
    private const DEFAULT_FIRST_NAME = 'Guest';
    private const DEFAULT_LAST_NAME = 'User';
    private const DEFAULT_COUNTRY = 'HK';
    private const DEFAULT_STATE = 'HCW';
    private const DEFAULT_CITY = 'Hong Kong';

    /**
     * @var CartRepositoryInterface
     */
    private CartRepositoryInterface $quoteRepository;

    /**
     * @var QuoteIdMaskFactory
     */
    private QuoteIdMaskFactory $quoteIdMaskFactory;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var SignatureHelper
     */
    private SignatureHelper $signatureHelper;

    /**
     * @var RemoteAddress
     */
    private RemoteAddress $remoteAddress;

    /**
     * @var GeoIpService
     */
    private GeoIpService $geoIpService;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param CartRepositoryInterface $quoteRepository
     * @param QuoteIdMaskFactory $quoteIdMaskFactory
     * @param Config $config
     * @param SignatureHelper $signatureHelper
     * @param RemoteAddress $remoteAddress
     * @param GeoIpService $geoIpService
     * @param ResourceConnection $resource
     * @param LoggerInterface $logger
     */
    public function __construct(
        CartRepositoryInterface $quoteRepository,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        Config $config,
        SignatureHelper $signatureHelper,
        RemoteAddress $remoteAddress,
        GeoIpService $geoIpService,
        ResourceConnection $resource,
        LoggerInterface $logger
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->config = $config;
        $this->signatureHelper = $signatureHelper;
        $this->remoteAddress = $remoteAddress;
        $this->geoIpService = $geoIpService;
        $this->resource = $resource;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    #[Override]
    public function getCheckoutData(string $quoteId, string $methodCode, $addresses = null): string
    {
        $realQuoteId = $this->resolveQuoteId($quoteId);

        try {
            $quote = $this->quoteRepository->get((int) $realQuoteId);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('Quote not found.'));
        }

        if (!$quote->getIsActive()) {
            throw new LocalizedException(__('Quote is not active.'));
        }

        /* 确保 quote 有 reserved_order_id */
        if (!$quote->getReservedOrderId()) {
            $quote->reserveOrderId();
            $this->quoteRepository->save($quote);
        }

        $methods = self::METHOD_MAP[$methodCode] ?? '';
        if (empty($methods)) {
            throw new LocalizedException(__('Invalid payment method: %1', $methodCode));
        }

        /* 解析前端传入的地址数据 */
        $addressData = $this->parseAddresses($addresses);
        $isVirtual = $addressData['isVirtual'] ?? (bool) $quote->getIsVirtual();
        $billingAddr = $addressData['billingAddress'] ?? [];

        $orderAmount = number_format((float) $quote->getBaseGrandTotal(), 2, '.', '');
        $orderCurrency = (string) $quote->getBaseCurrencyCode();
        $clientIp = (string) $this->remoteAddress->getRemoteAddress();

        /* 读取该支付方式的 public_key */
        $connection = $this->resource->getConnection();
        $publicKey = (string) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('core_config_data'), ['value'])
                ->where('path = ?', 'payment/' . $methodCode . '/public_key')
                ->limit(1)
        );

        /* 组装 billing 字段（参考 CustomerBuilder 的虚拟产品处理） */
        $billingFields = $this->buildBillingFields($billingAddr, $isVirtual, $quote, $clientIp);

        /* 组装完整表单数据 */
        $data = [
            'account'           => $this->config->getAccount(),
            'terminal'          => $this->config->getTerminal(),
            'order_number'      => $quote->getReservedOrderId(),
            'order_currency'    => $orderCurrency,
            'order_amount'      => $orderAmount,
            'order_notes'       => '',
            'methods'           => $methods,
            'backUrl'           => $this->signatureHelper->buildBackUrl(),
            'noticeUrl'         => $this->signatureHelper->buildNoticeUrl(),
            'billing_firstName' => $billingFields['firstName'],
            'billing_lastName'  => $billingFields['lastName'],
            'billing_email'     => $billingFields['email'],
            'billing_phone'     => $billingFields['phone'],
            'billing_country'   => $billingFields['country'],
            'billing_state'     => $billingFields['state'],
            'billing_city'      => $billingFields['city'],
            'billing_address'   => $billingFields['street'],
            'billing_zip'       => $billingFields['zip'],
            'billing_ip'        => $clientIp,
            'productName'       => $this->buildProductName($quote),
            'productNum'        => $this->buildProductNum($quote),
            'productSku'        => $this->buildProductSku($quote),
            'productPrice'      => $this->buildProductPrice($quote),
            'pages'             => $this->detectPageType(),
            'key'               => $publicKey,
        ];

        /* 计算签名 */
        $data['signValue'] = $this->signatureHelper->calculateSignature(
            self::SIGN_FIELDS,
            $data,
            $this->config->getSecureCode()
        );

        $this->logger->info('[Oceanpayment] CheckoutData assembled', [
            'quote_id'     => $realQuoteId,
            'order_number' => $data['order_number'],
            'method'       => $methodCode,
            'is_virtual'   => $isVirtual,
        ]);

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 组装 billing 字段
     *
     * 参考 CustomerBuilder 的逻辑：
     * - 实体产品：使用前端传入的 billingAddress
     * - 虚拟产品：使用客户姓名 + GeoIP 根据 IP 获取国家/城市
     *
     * @param array $billingAddr 前端传入的 billingAddress 数据
     * @param bool $isVirtual 是否虚拟产品
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @param string $clientIp 客户端 IP
     * @return array billing 相关字段
     */
    private function buildBillingFields(array $billingAddr, bool $isVirtual, $quote, string $clientIp): array
    {
        $customerEmail = (string) ($quote->getCustomerEmail() ?? '');
        $customerFirstname = trim((string) ($quote->getCustomerFirstname() ?? ''));
        $customerLastname = trim((string) ($quote->getCustomerLastname() ?? ''));

        if ($isVirtual) {
            /* 虚拟产品：使用客户姓名 + GeoIP（与 CustomerBuilder 一致） */
            return [
                'firstName' => $customerFirstname ?: self::DEFAULT_FIRST_NAME,
                'lastName'  => $customerLastname ?: self::DEFAULT_LAST_NAME,
                'email'     => $customerEmail,
                'phone'     => $this->generateMaskedMobileNumber(),
                'country'   => $this->geoIpService->getCountryCode($clientIp) ?: self::DEFAULT_COUNTRY,
                'state'     => $this->geoIpService->getRegionCode($clientIp) ?: self::DEFAULT_STATE,
                'city'      => $this->geoIpService->getCity($clientIp) ?: self::DEFAULT_CITY,
                'street'    => '',
                'zip'       => '',
            ];
        }

        /* 实体产品：使用前端传入的 billingAddress，空值回退到客户信息或默认值 */
        $firstName = trim((string) ($billingAddr['firstname'] ?? ''));
        $firstName = $firstName ?: ($customerFirstname ?: self::DEFAULT_FIRST_NAME);

        $lastName = trim((string) ($billingAddr['lastname'] ?? ''));
        $lastName = $lastName ?: ($customerLastname ?: self::DEFAULT_LAST_NAME);

        $email = trim((string) ($billingAddr['email'] ?? ''));
        $email = $email ?: $customerEmail;

        $phone = trim((string) ($billingAddr['telephone'] ?? ''));
        $phone = $phone ?: $this->generateMaskedMobileNumber();

        $street = $billingAddr['street'] ?? [];
        if (is_array($street)) {
            $street = implode(' ', $street);
        }
        $street = trim((string) $street);

        return [
            'firstName' => $firstName,
            'lastName'  => $lastName,
            'email'     => $email,
            'phone'     => $phone,
            'country'   => trim((string) ($billingAddr['countryId'] ?? '')),
            'state'     => trim((string) ($billingAddr['regionCode'] ?? '')),
            'city'      => trim((string) ($billingAddr['city'] ?? '')),
            'street'    => $street,
            'zip'       => trim((string) ($billingAddr['postcode'] ?? '')),
        ];
    }

    /**
     * 解析前端传入的地址数据
     *
     * Magento REST API 的第三个参数如果是复杂类型会自动解析，
     * 如果是字符串则手动 JSON 解码
     *
     * @param mixed $addresses
     * @return array
     */
    private function parseAddresses($addresses): array
    {
        if ($addresses === null) {
            return [];
        }

        if (is_string($addresses)) {
            $decoded = json_decode($addresses, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            return [];
        }

        if (is_array($addresses)) {
            return $addresses;
        }

        return [];
    }

    /**
     * 解析 quoteId：masked ID → 真实 ID
     *
     * @param string $quoteId
     * @return string
     */
    private function resolveQuoteId(string $quoteId): string
    {
        if (ctype_digit($quoteId)) {
            return $quoteId;
        }

        try {
            $mask = $this->quoteIdMaskFactory->create();
            $mask->load($quoteId, 'masked_id');
            $realId = $mask->getQuoteId();
            if ($realId) {
                return (string) $realId;
            }
        } catch (\Exception $e) {
            $this->logger->warning('[Oceanpayment] CheckoutData: Failed to resolve masked quote ID', [
                'masked_id' => $quoteId,
                'error'     => $e->getMessage(),
            ]);
        }

        return $quoteId;
    }

    /**
     * 生成脱敏手机号（与 CustomerBuilder 一致）
     *
     * @return string
     */
    private function generateMaskedMobileNumber(): string
    {
        $prefix = '1' . mt_rand(3, 9) . mt_rand(0, 9);
        $suffix = sprintf('%04d', mt_rand(0, 9999));
        return $prefix . '****' . $suffix;
    }

    /**
     * 构建商品名称列表
     *
     * @param mixed $quote
     * @return string
     */
    private function buildProductName($quote): string
    {
        $names = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $names[] = str_replace([',', '<', '>', '"', "'"], ' ', (string) $item->getName());
        }
        return implode(',', $names);
    }

    /**
     * 构建商品数量列表
     *
     * @param mixed $quote
     * @return string
     */
    private function buildProductNum($quote): string
    {
        $nums = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $nums[] = (int) $item->getQty();
        }
        return implode(',', $nums);
    }

    /**
     * 构建商品 SKU 列表
     *
     * @param mixed $quote
     * @return string
     */
    private function buildProductSku($quote): string
    {
        $skus = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $skus[] = str_replace([',', '<', '>', '"', "'"], ' ', (string) $item->getSku());
        }
        return implode(',', $skus);
    }

    /**
     * 构建商品单价列表
     *
     * @param mixed $quote
     * @return string
     */
    private function buildProductPrice($quote): string
    {
        $prices = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $prices[] = number_format((float) $item->getPrice(), 2, '.', '');
        }
        return implode(',', $prices);
    }

    /**
     * 检测页面类型（PC/移动端）
     *
     * @return int 0=PC, 1=移动端
     */
    private function detectPageType(): int
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (preg_match('/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $userAgent)) {
            return 1;
        }
        return 0;
    }
}