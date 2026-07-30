<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Config;

use Magento\Payment\Gateway\ConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * 连连支付网关配置类
 *
 * 管理连连支付的商户配置：商户号、站点号、RSA密钥、环境切换
 * 实现 ConfigInterface 以兼容 ConfigValueHandler 的类型约束
 */
class Config implements ConfigInterface
{
    private const KEY_ACTIVE = 'active';
    private const KEY_TITLE = 'title';
    private const KEY_SORT_ORDER = 'sort_order';
    private const KEY_ENVIRONMENT = 'environment';
    private const KEY_COUNTRY = 'country';

    /**
     * 按环境区分的共享配置字段映射
     * 键为业务语义名，值为 [sandbox_path, production_path]
     */
    private const ENVIRONMENT_FIELDS = [
        'merchant_id'     => ['sandbox_merchant_id',     'production_merchant_id'],
        'sub_merchant_id' => ['sandbox_sub_merchant_id', 'production_sub_merchant_id'],
        'private_key'     => ['sandbox_private_key',     'production_private_key'],
        'public_key'      => ['sandbox_public_key',      'production_public_key'],
    ];

    private const DEFAULT_PATH_PATTERN = 'payment/%s/%s';
    private const SHARED_CONFIG_PREFIX = 'payment/mslpay_lianlian';

    private ScopeConfigInterface $scopeConfig;
    private string $methodCode;
    private string $pathPattern;

    public function __construct(
        ScopeConfigInterface $scopeConfig,

        string $methodCode = 'mslpay_lianlian_checkout',
        string $pathPattern = self::DEFAULT_PATH_PATTERN
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->methodCode = $methodCode;
        $this->pathPattern = $pathPattern;
    }

    public function getValue($field, $storeId = null)
    {
        return $this->scopeConfig->getValue(
            sprintf($this->pathPattern, $this->methodCode, $field),
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function setMethodCode($methodCode)
    {
        $this->methodCode = $methodCode;
    }

    public function setPathPattern($pathPattern)
    {
        $this->pathPattern = $pathPattern;
    }

    public function getSharedValue(string $field, ?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::SHARED_CONFIG_PREFIX . '/' . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isActive(): bool
    {
        return (bool) $this->getValue(self::KEY_ACTIVE);
    }

    public function getTitle(): string
    {
        return $this->getValue(self::KEY_TITLE);
    }

    public function getSortOrder(): string
    {
        return $this->getValue(self::KEY_SORT_ORDER);
    }

    public function getEnvironment(): string
    {
        return $this->getSharedValue(self::KEY_ENVIRONMENT);
    }

    public function isSandbox(): bool
    {
        return $this->getEnvironment() !== 'production';
    }

    public function getMerchantId(): string
    {
        return $this->getEnvironmentBasedValue('merchant_id');
    }

    public function getSubMerchantId(): string
    {
        return $this->getEnvironmentBasedValue('sub_merchant_id');
    }

    public function getPrivateKey(): string
    {
        return $this->getEnvironmentBasedValue('private_key');
    }

    public function getLianLianPublicKey(): string
    {
        return $this->getEnvironmentBasedValue('public_key');
    }

    public function getCountry(): string
    {
        return $this->getSharedValue(self::KEY_COUNTRY);
    }

    /**
     * 根据当前环境自动获取对应的共享配置值
     *
     * 从 mslpay_lianlian 配置段中，根据 environment 字段
     * 自动选择 sandbox_* 或 production_* 前缀的配置项
     *
     * @param string $field 字段名（如 merchant_id, private_key）
     * @return string 配置值
     */
    private function getEnvironmentBasedValue(string $field): string
    {
        if (!isset(self::ENVIRONMENT_FIELDS[$field])) {
            return '';
        }

        $isSandbox = $this->isSandbox();
        $pathKey = $isSandbox
            ? self::ENVIRONMENT_FIELDS[$field][0]
            : self::ENVIRONMENT_FIELDS[$field][1];

        return (string) $this->getSharedValue($pathKey);
    }

    private const COUNTRY_TIMEZONE_MAP = [
        'US' => 'America/New_York',
        'HK' => 'Asia/Hong_Kong',
        'CN' => 'Asia/Shanghai',
        'SG' => 'Asia/Singapore',
        'GB' => 'Europe/London',
        'AU' => 'Australia/Sydney',
        'JP' => 'Asia/Tokyo',
        'KR' => 'Asia/Seoul',
        'DE' => 'Europe/Berlin',
        'FR' => 'Europe/Paris',
    ];

    public function getTimezone(): string
    {
        $country = $this->getCountry();
    
        return self::COUNTRY_TIMEZONE_MAP[$country] ?? 'Asia/Hong_Kong';
    }


    public function getApiBaseUrl(): string
    {
        if ($this->isSandbox()) {
            return 'https://celer-api.LianLianpay-inc.com';
        }
        return 'https://gpapi.lianlianpay.com';
    }

    public function getPaymentApiUrl(): string
    {
        return $this->getApiBaseUrl() . '/v3/merchants/' . $this->getMerchantId() . '/payments';
    }

    public function getRefundApiUrl(string $originalTransactionId): string
    {
        return $this->getApiBaseUrl() . '/v3/merchants/' . $this->getMerchantId()
            . '/payments/' . $originalTransactionId . '/refunds';
    }


    public function getMethodCode(): string
    {
        return $this->methodCode;
    }
}