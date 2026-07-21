<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Request\Builder;

use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Framework\App\RequestInterface;
use Oceanpayment\Payment\Gateway\Config\Config;
use Oceanpayment\Payment\Service\GeoIpService;

/**
 * Oceanpayment 客户信息构建器
 *
 * 构建与客户/账单地址相关的 API 请求参数：
 * - account / terminal: 从共享网关配置读取
 * - billing_*: 账单地址相关字段（姓名、邮箱、电话、国家、省、市、地址、邮编）
 * - billing_ip: 客户 IP 地址（用于风控）
 *
 * 姓名字段提供默认值（Guest / User），防止空值导致 API 报错
 */
class CustomerBuilder implements BuilderInterface
{
    /**
     * 姓名默认值：当账单地址中无 first_name 时使用
     */
    private const DEFAULT_FIRST_NAME = 'Guest';

    /**
     * 姓名默认值：当账单地址中无 last_name 时使用
     */
    private const DEFAULT_LAST_NAME = 'User';

    /**
     * GeoIP 查询失败时的默认国家代码
     */
    private const DEFAULT_COUNTRY = 'HK';

    /**
     * GeoIP 查询失败时的默认省州代码
     */
    private const DEFAULT_STATE = 'HCW';

    /**
     * GeoIP 查询失败时的默认城市
     */
    private const DEFAULT_CITY = 'Hong Kong';

    /**
     * @var Config Oceanpayment 网关配置
     */
    private Config $config;

    /**
     * @var RequestInterface HTTP 请求接口（用于获取客户 IP）
     */
    private RequestInterface $httpRequest;

    /**
     * @var GeoIpService GeoIP 查询服务（虚拟产品根据 IP 获取国家/城市）
     */
    private GeoIpService $geoIpService;

    /**
     * Constructor
     *
     * @param Config $config Oceanpayment 网关配置
     * @param RequestInterface $httpRequest HTTP 请求接口
     * @param GeoIpService $geoIpService GeoIP 查询服务
     */
    public function __construct(
        Config $config,
        RequestInterface $httpRequest,
        GeoIpService $geoIpService
    ) {
        $this->config = $config;
        $this->httpRequest = $httpRequest;
        $this->geoIpService = $geoIpService;
    }

    /**
     * 构建客户/账单信息请求参数
     *
     * @param array $buildSubject 构建参数，包含 payment 数据对象
     * @return array 客户相关 API 参数
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = $this->readPayment($buildSubject);
        $payment = $paymentDO->getPayment();
        
        /* 从 payment 获取原始 order 对象（避免 Braintree OrderAdapter 缺少方法） */
        $order = $payment->getOrder();
        $billingAddress = $order->getBillingAddress();

        /* 虚拟订单（如游戏充值）可能无账单地址，或 SimpleCheckout 注入假 billing */
        $isVirtual = (bool) $order->getIsVirtual();
        $customerEmail = $order->getCustomerEmail() ?? '';
        $customerFirstname = trim((string) ($order->getCustomerFirstname() ?? ''));
        $customerLastname = trim((string) ($order->getCustomerLastname() ?? ''));

        /* 安全获取账单地址姓名，空值时回退到客户姓名或默认值 */
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

        /*
         * 虚拟产品：优先使用订单客户信息（SimpleCheckout 会注入假 billing）
         * 实体产品：优先使用账单地址
         */
        if ($isVirtual) {
            $firstName = $customerFirstname ?: self::DEFAULT_FIRST_NAME;
            $lastName = $customerLastname ?: self::DEFAULT_LAST_NAME;

            /* 虚拟产品通过 GeoIP 根据客户端 IP 获取国家、省州、城市 */
            $clientIp = $this->getRemoteAddress();
            $billingCountry = $this->geoIpService->getCountryCode($clientIp) ?: self::DEFAULT_COUNTRY;
            $billingState = $this->geoIpService->getRegionCode($clientIp) ?: self::DEFAULT_STATE;
            $billingCity = $this->geoIpService->getCity($clientIp) ?: self::DEFAULT_CITY;
            $billingAddress = '';
            $billingZip = '';
        } 

        return [
            'account'           => $this->config->getAccount(),
            'terminal'          => $this->config->getTerminal(),
            'billing_firstName' => $firstName,
            'billing_lastName'  => $lastName,
            'billing_email'     => $customerEmail,
            'billing_phone'     => $billingPhone ?? $this->generateMaskedMobileNumber(),
            'billing_country'   => $billingCountry,
            'billing_state'     => $billingState,
            'billing_city'      => $billingCity,
            'billing_address'   => $billingStreet,
            'billing_zip'       => $billingZip,
            'billing_ip'        => $this->getRemoteAddress(),
        ];
    }

   private function generateMaskedMobileNumber() {
        $prefix = '1' . mt_rand(3, 9) . mt_rand(0, 9);
        $suffix = sprintf('%04d', mt_rand(0, 9999));
        return $prefix . '****' . $suffix;
  }
    /**
     * 获取账单地址的街道首行
     *
     * Magento 的街道字段为多行数组结构，Oceanpayment 只需要单行地址
     *
     * @param \Magento\Payment\Gateway\Data\AddressAdapterInterface $address 地址适配器
     * @return string 街道地址首行
     */
    private function getStreetLine($address): string
    {
        $street = $address->getStreetLine1();
        return is_string($street) ? $street : '';
    }

    /**
     * 获取客户远程 IP 地址
     *
     * 优先从 HTTP_X_FORWARDED_FOR 获取（代理/CDN 场景），
     * 回退到 REMOTE_ADDR
     *
     * @return string 客户 IP 地址
     */
    private function getRemoteAddress(): string
    {
        /* 优先检查代理转发的真实 IP */
        $forwardedFor = $this->httpRequest->getServer('HTTP_X_FORWARDED_FOR');
        if (!empty($forwardedFor)) {
            /* X-Forwarded-For 可能包含多个 IP，取第一个（最原始的客户端 IP） */
            $ips = explode(',', (string) $forwardedFor);
            return trim($ips[0]);
        }

        /* 回退到直连 IP */
        return (string) $this->httpRequest->getServer('REMOTE_ADDR', '');
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