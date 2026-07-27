<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Gateway\Config\Config as GatewayConfig;
use Magento\Store\Model\ScopeInterface;

/**
 * Oceanpayment 网关配置类
 *
 * 负责读取两套配置：
 * 1. 支付方式级别配置（active, title, sort_order, methods 等）—— 从 payment/<methodCode> 读取
 * 2. 共享网关配置（account, terminal, securecode, gateway_url, environment）—— 从 oceanpayment_payment 读取
 *
 * 共享网关配置根据 environment 字段自动切换 sandbox / production 值
 */
class Config extends GatewayConfig
{
    /**
     * 共享网关配置的 XML 路径前缀
     * 注意：Oceanpayment_Payment 模块使用 oceanpayment_payment 配置段
     */
    private const SHARED_CONFIG_PREFIX = 'payment/oceanpayment_payment';

    /**
     * 环境配置路径
     */
    private const PATH_ENVIRONMENT = self::SHARED_CONFIG_PREFIX . '/environment';

    /**
     * 调试模式配置路径
     */
    private const PATH_DEBUG = self::SHARED_CONFIG_PREFIX . '/debug';

    /**
     * 沙箱环境标识
     */
    private const ENV_SANDBOX = 'sandbox';

    /**
     * 生产环境标识
     */
    private const ENV_PRODUCTION = 'production';

    /**
     * 查询/退款域名（按环境区分）
     */
    public const ENDPOINT = [
        'sandbox'    => 'https://test-query.oceanpayment.com',
        'production' => 'https://query.oceanpayment.com',
    ];

    /**
     * 按环境区分的共享配置字段映射
     * 键为对外暴露的方法名后缀，值为 [sandbox_path, production_path]
     */
    private const ENVIRONMENT_FIELDS = [
        'account'     => ['sandbox_account',     'production_account'],
        'terminal'    => ['sandbox_terminal',    'production_terminal'],
        'securecode'  => ['sandbox_securecode',  'production_securecode'],
        'gateway_url' => ['sandbox_gateway_url', 'production_gateway_url'],
    ];

    /**
     * @var ScopeConfigInterface 用于读取共享网关配置
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * Constructor
     *
     * @param ScopeConfigInterface $scopeConfig 作用域配置接口（读取共享配置）
     * @param string|null $methodCode 支付方式代码，由 di.xml 虚拟类型注入
     * @param string $pathPattern 配置路径模式，默认为 Magento 标准路径
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ?string $methodCode = null,
        string $pathPattern = self::DEFAULT_PATH_PATTERN
    ) {
        parent::__construct($scopeConfig, $methodCode, $pathPattern);
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * 获取 Oceanpayment 商户账号
     *
     * 根据当前环境（sandbox/production）自动返回对应的账号配置
     *
     * @return string
     */
    public function getAccount(): string
    {
        return (string) $this->getEnvironmentBasedValue('account');
    }

    /**
     * 获取 Oceanpayment 终端号
     *
     * @return string
     */
    public function getTerminal(): string
    {
        return (string) $this->getEnvironmentBasedValue('terminal');
    }

    /**
     * 获取 Oceanpayment 安全校验码
     *
     * @return string
     */
    public function getSecureCode(): string
    {
        return (string) $this->getEnvironmentBasedValue('securecode');
    }

    /**
     * 获取 Oceanpayment 网关 URL
     *
     * @return string
     */
    public function getGatewayUrl(): string
    {
        return (string) $this->getEnvironmentBasedValue('gateway_url');
    }

    /**
     * 获取当前环境标识
     *
     * @return string sandbox 或 production
     */
    public function getEnvironment(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::PATH_ENVIRONMENT,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * 判断是否开启调试模式
     *
     * @return bool
     */
    public function isDebugMode(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::PATH_DEBUG,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * 获取支付方式对应的 Oceanpayment 交易方法标识
     *
     * 例如 Credit Card、ApplePay 等
     * 此值从支付方式级别配置（payment/<methodCode>/methods）读取
     *
     * @return string
     */
    public function getMethods(): string
    {
        return (string) $this->getValue('methods');
    }

    /**
     * 获取 Apple Pay Merchant Identifier
     *
     * 仅 Apple Pay 支付方式有效，从 payment/oceanpayment_applepay/merchant_identifier 读取
     *
     * @return string
     */
    public function getMerchantIdentifier(): string
    {
        return (string) $this->getValue('merchant_identifier');
    }

    /**
     * 获取 Google Pay Merchant ID
     *
     * 仅 Google Pay 支付方式有效，从 payment/oceanpayment_googlepay/merchant_id 读取
     *
     * @return string
     */
    public function getMerchantId(): string
    {
        return (string) $this->getValue('merchant_id');
    }

    /**
     * 获取 Google Pay Gateway Merchant ID
     *
     * 仅 Google Pay 支付方式有效，从 payment/oceanpayment_googlepay/gateway_merchant_id 读取
     *
     * @return string
     */
    public function getGatewayMerchantId(): string
    {
        return (string) $this->getValue('gateway_merchant_id');
    }

    /**
     * 获取异步通知 IP 白名单
     *
     * 从 payment/oceanpayment_payment/notification_ip_whitelist 读取，
     * 每行一个 IP，返回去重后的 IP 数组
     *
     * @return string[]
     */
    public function getNotificationIpWhitelist(): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::SHARED_CONFIG_PREFIX . '/notification_ip_whitelist',
            ScopeInterface::SCOPE_STORE
        );

        if (trim($raw) === '') {
            return [];
        }

        $ips = array_map('trim', explode("\n", $raw));
        $ips = array_filter($ips, static fn(string $ip): bool => $ip !== '');

        return array_unique($ips);
    }

    /**
     * 是否允许所有 IP 的异步通知（测试模式）
     *
     * @return bool
     */
    public function isNotificationIpAllowAll(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::SHARED_CONFIG_PREFIX . '/notification_ip_allow_all',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * 获取查询域名
     *
     * 根据当前环境（sandbox/production）返回对应的 query 域名
     *
     * @return string
     */
    public function getQueryBaseUrl(): string
    {
        $env = $this->getEnvironment();
        return self::ENDPOINT[$env] ?? self::ENDPOINT['sandbox'];
    }

    /**
     * 根据当前环境自动获取对应的共享配置值
     *
     * 从 oceanpayment_payment 配置段中，根据 environment 字段
     * 自动选择 sandbox_* 或 production_* 前缀的配置项。
     * 加密字段（securecode/key）由 Magento 框架在 ScopeConfig 层自动解密，
     * 业务代码无需手动处理。
     *
     * @param string $field 字段名（如 account, terminal, securecode, gateway_url）
     * @return string 配置值
     */
    private function getEnvironmentBasedValue(string $field): string
    {
        if (!isset(self::ENVIRONMENT_FIELDS[$field])) {
            return '';
        }

        $isSandbox = $this->getEnvironment() === self::ENV_SANDBOX;
        $pathKey = $isSandbox
            ? self::ENVIRONMENT_FIELDS[$field][0]
            : self::ENVIRONMENT_FIELDS[$field][1];

        $fullPath = self::SHARED_CONFIG_PREFIX . '/' . $pathKey;

        return (string) $this->scopeConfig->getValue(
            $fullPath,
            ScopeInterface::SCOPE_STORE
        );
    }
}
