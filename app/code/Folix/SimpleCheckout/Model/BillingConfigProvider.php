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

        return [
            'defaultBillingAddress' => [
                'firstname' => $firstname ?: '',
                'lastname' => $lastname ?: '',
                'street' => [],
                'city' => '',
                'postcode' => '',
                'country_id' => $countryId ?: 'US',
                'telephone' => '',
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
}