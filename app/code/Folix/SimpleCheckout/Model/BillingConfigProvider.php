<?php
namespace Folix\SimpleCheckout\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;

class BillingConfigProvider implements ConfigProviderInterface
{
    /**
     * 浏览器语言前缀 → 国家代码映射
     */
    private const LANG_COUNTRY_MAP = [
        'zh' => 'CN',
        'en' => 'US',
        'ja' => 'JP',
        'ko' => 'KR',
        'de' => 'DE',
        'fr' => 'FR',
        'es' => 'ES',
        'it' => 'IT',
        'pt' => 'PT',
        'ru' => 'RU',
        'ar' => 'SA',
        'th' => 'TH',
        'vi' => 'VN',
        'id' => 'ID',
        'ms' => 'MY',
        'hi' => 'IN',
        'nl' => 'NL',
        'pl' => 'PL',
        'tr' => 'TR',
        'sv' => 'SE',
        'da' => 'DK',
        'fi' => 'FI',
        'nb' => 'NO',
        'cs' => 'CZ',
        'el' => 'GR',
        'he' => 'IL',
        'uk' => 'UA',
        'ro' => 'RO',
        'hu' => 'HU',
        'bg' => 'BG',
    ];

    /**
     * 国家代码 → 默认 region 映射
     */
    private const COUNTRY_REGION_MAP = [
        'US' => 'CA',
        'CN' => 'CN-GD',
        'HK' => 'CN-HK',
        'JP' => 'JP-13',
        'GB' => 'GB-LND',
        'DE' => 'DE-BE',
        'FR' => 'FR-IDF',
    ];

    /**
     * 国家代码 → 默认 postcode 映射
     */
    private const COUNTRY_POSTCODE_MAP = [
        'US' => '90001',
        'CN' => '510000',
        'HK' => '999077',
        'TW' => '100',
        'JP' => '1000001',
        'KR' => '03000',
        'GB' => 'SW1A1AA',
        'DE' => '10115',
        'FR' => '75001',
        'IT' => '00100',
        'ES' => '28001',
        'NL' => '1012AA',
        'SE' => '11122',
        'AU' => '2000',
        'CA' => 'M5H2N2',
        'BR' => '01001000',
        'IN' => '110001',
        'RU' => '101000',
        'SA' => '11564',
        'SG' => '018956',
        'MY' => '50000',
        'TH' => '10100',
        'VN' => '100000',
        'ID' => '10110',
        'PH' => '1000',
        'AE' => '00000',
        'IL' => '6100000',
    ];

    private CheckoutSession $checkoutSession;
    private RequestInterface $request;

    public function __construct(
        CheckoutSession $checkoutSession,
        RequestInterface $request
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->request = $request;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig()
    {
        $quote = $this->checkoutSession->getQuote();
        $billingAddress = $quote->getBillingAddress();

        $firstname = $billingAddress ? $billingAddress->getFirstname() : '';
        $lastname = $billingAddress ? $billingAddress->getLastname() : '';

        if (empty($firstname) && empty($lastname)) {
            $email = $quote->getCustomerEmail() ?: ($billingAddress ? $billingAddress->getEmail() : '');
            if (!empty($email)) {
                $namePart = strstr($email, '@', true) ?: $email;
                $parts = preg_split('/[._-]/', $namePart, 2);
                $firstname = ucfirst($parts[0] ?? $namePart);
                $lastname = ucfirst($parts[1] ?? '');
            }
        }

        /* country 优先级：quote billing address > 浏览器语言映射 > 默认 US */
        $countryId = $billingAddress ? $billingAddress->getCountryId() : '';
        if (empty($countryId)) {
            $countryId = $this->resolveCountryByLocale();
        }

        $region = self::COUNTRY_REGION_MAP[$countryId] ?? '';
        $postcode = self::COUNTRY_POSTCODE_MAP[$countryId] ?? '90001';

        return [
            'defaultBillingAddress' => [
                'firstname' => $firstname ?: '',
                'lastname' => $lastname ?: '',
                'street' => [$lastname.' '.$countryId],
                'city' => $firstname,
                'postcode' => $postcode,
                'country_id' => $countryId ?: 'US',
                'telephone' => static::generateMaskedMobileNumber(),
                'region' => $region,
            ]
        ];
    }

    /**
     * 根据浏览器 Accept-Language Header 映射国家代码
     *
     * Accept-Language 格式如：zh-CN,zh;q=0.9,en-US;q=0.8,en;q=0.7
     * 取第一个语言标签（权重最高），提取语言前缀映射到国家代码
     */
    private function resolveCountryByLocale(): string
    {
        $acceptLanguage = $this->request->getServerValue('HTTP_ACCEPT_LANGUAGE') ?? '';
        if (empty($acceptLanguage)) {
            return '';
        }

        /* 取第一个语言标签（逗号前，分号前），如 zh-CN 或 en-US */
        $primary = strtolower(strtok($acceptLanguage, ','));
        $primary = strtok($primary, ';');

        /* zh-cn → zh, en-us → en */
        $langPrefix = strtok($primary, '-');

        return self::LANG_COUNTRY_MAP[$langPrefix] ?? '';
    }

     /**
     * 生成脱敏手机号（与 CustomerBuilder 一致）
     *
     * @return string
     */
    public static function generateMaskedMobileNumber(): string
    {
        $prefix = '1' . mt_rand(3, 9) . mt_rand(0, 9);
        $suffix = sprintf('%04d', mt_rand(0, 9999));
        return $prefix . '****' . $suffix;
    }
}