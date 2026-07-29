<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Oceanpayment\Payment\Gateway\Config\Config;
use Oceanpayment\Payment\Gateway\Helper\SignatureHelper;

/**
 * Oceanpayment 结账配置提供器
 *
 * 将 Oceanpayment 各嵌入式支付方式的配置信息注入到 Magento Checkout JS 组件中，
 * 使前端可以动态获取支付方式的标题、Logo、启用状态、模式等信息
 *
 * 支持的支付方式（仅嵌入式）：
 * - Credit Card（信用卡）
 * - Apple Pay
 * - Google Pay
 */
class ConfigProvider implements ConfigProviderInterface
{
    /**
     * 通用网关配置代码（对应 oceanpayment_payment 配置段）
     */
    public const CODE = 'oceanpayment_payment';

    /**
     * Credit Card 支付方式代码
     */
    public const CODE_CREDITCARD = 'oceanpayment_creditcard';

    /**
     * Apple Pay 支付方式代码
     */
    public const CODE_APPLEPAY = 'oceanpayment_applepay';

    /**
     * Google Pay 支付方式代码
     */
    public const CODE_GOOGLEPAY = 'oceanpayment_googlepay';

    /**
     * Logo 图片文件名映射
     */
    private const LOGO_MAP = [
        self::CODE_CREDITCARD => 'creditcard.svg',
        self::CODE_APPLEPAY   => 'applepay.svg',
        self::CODE_GOOGLEPAY  => 'googlepay.svg',
    ];

    /**
     * 嵌入式支付方式代码列表
     */
    private const EMBEDDED_METHODS = [
        self::CODE_CREDITCARD,
        self::CODE_APPLEPAY,
        self::CODE_GOOGLEPAY,
    ];

    /**
     * 嵌入式 SDK URL 映射
     */
    private const SDK_URL_MAP = [
        self::CODE_CREDITCARD => [
            'sandbox'    => 'https://test-secure.oceanpayment.com/pages/js/oceanpayment.js',
            'production' => 'https://secure.oceanpayment.com/pages/js/oceanpayment.js',
        ],
        self::CODE_APPLEPAY => [
            'sandbox'    => 'https://test-secure.oceanpayment.com/pages/js/oceanpayment-applepay.js',
            'production' => 'https://secure.oceanpayment.com/pages/js/oceanpayment-applepay.js',
        ],
        self::CODE_GOOGLEPAY => [
            'sandbox'    => 'https://test-secure.oceanpayment.com/pages/js/oceanpayment-googlepay.js',
            'production' => 'https://secure.oceanpayment.com/pages/js/oceanpayment-googlepay.js',
        ],
    ];

    /**
     * @var Config Credit Card 配置实例
     */
    private Config $creditCardConfig;

    /**
     * @var Config Apple Pay 配置实例
     */
    private Config $applePayConfig;

    /**
     * @var Config Google Pay 配置实例
     */
    private Config $googlePayConfig;

    /**
     * @var AssetRepository 静态资源仓库
     */
    private AssetRepository $assetRepository;

    /**
     * @var SignatureHelper
     */
    private SignatureHelper $signatureHelper;

    /**
     * Constructor
     *
     * @param Config $creditCardConfig Credit Card 配置
     * @param Config $applePayConfig Apple Pay 配置
     * @param Config $googlePayConfig Google Pay 配置
     * @param AssetRepository $assetRepository 静态资源仓库
     * @param SignatureHelper $signatureHelper 签名与 URL 辅助
     */
    public function __construct(
        Config $creditCardConfig,
        Config $applePayConfig,
        Config $googlePayConfig,
        AssetRepository $assetRepository,
        SignatureHelper $signatureHelper
    ) {
        $this->creditCardConfig = $creditCardConfig;
        $this->applePayConfig = $applePayConfig;
        $this->googlePayConfig = $googlePayConfig;
        $this->assetRepository = $assetRepository;
        $this->signatureHelper = $signatureHelper;
    }

    /**
     * 获取结账配置
     *
     * 将所有已启用的 Oceanpayment 支付方式配置注入到 Checkout JS 中，
     * 每种支付方式包含：code, title, is_active, logo_url
     *
     * @return array 结账配置数组
     */
    public function getConfig(): array
    {
        $config = [
            'payment' => [
                self::CODE => [
                    'is_production' =>
                    $this->creditCardConfig->getEnvironment()
                    === \Oceanpayment\Payment\Model\Config\Source\Environment::PRODUCTION,
                    'methods' => [],
                ],
            ],
        ];

        $methodConfigs = $this->getMethodConfigs();

        foreach ($methodConfigs as $code => $methodConfig) {
            if (!$this->isActive($methodConfig)) {
                continue;
            }

            $methodData = [
                'code'      => $code,
                'title'     => $this->getTitle($methodConfig),
                'is_active' => true,
                'logo_url'  => $this->getLogoUrl($code),
            ];

            /* 嵌入式支付方式：将 embedded 配置注入到对应 method 下，禁用的不暴露 */
            if (in_array($code, self::EMBEDDED_METHODS, true)) {
                $methodData['embedded'] = $this->getEmbeddedConfig($code);
            }

            $config['payment'][self::CODE]['methods'][$code] = $methodData;
        }

        return $config;
    }

    /**
     * 获取所有支付方式的配置实例映射（仅嵌入式3种）
     *
     * @return array<string, Config> 支付方式代码 => 配置实例
     */
    private function getMethodConfigs(): array
    {
        return [
            self::CODE_CREDITCARD => $this->creditCardConfig,
            self::CODE_APPLEPAY   => $this->applePayConfig,
            self::CODE_GOOGLEPAY  => $this->googlePayConfig,
        ];
    }

    /**
     * 检查支付方式是否启用
     *
     * @param Config $config 支付方式配置
     * @return bool
     */
    private function isActive(Config $config): bool
    {
        return (bool) $config->getValue('active');
    }

    /**
     * 获取支付方式标题
     *
     * @param Config $config 支付方式配置
     * @return string
     */
    private function getTitle(Config $config): string
    {
        return (string) $config->getValue('title');
    }

    /**
     * 获取嵌入式支付配置
     *
     * 供前端 SDK init() 使用，只包含当前支付方式的 SDK URL
     *
     * @param string $methodCode 支付方式代码
     * @return array
     */
    private function getEmbeddedConfig(string $methodCode): array
    {
        $isSandbox = $this->creditCardConfig->getEnvironment()
            === \Oceanpayment\Payment\Model\Config\Source\Environment::SANDBOX;
        $envKey = $isSandbox ? 'sandbox' : 'production';

        /* 使用对应支付方式的 Config 实例获取 terminal */
        $methodConfigs = $this->getMethodConfigs();
        $methodConfig = $methodConfigs[$methodCode] ?? $this->creditCardConfig;

        return [
            'is_sandbox' => $isSandbox,
            'language'   => 'en_US',
            'terminal'   => $methodConfig->getTerminal(),
            'back_url'   => $this->signatureHelper->buildBackUrl(),
            'sdk_url'    => self::SDK_URL_MAP[$methodCode][$envKey] ?? '',
        ];
    }

    /**
     * 获取支付方式 Logo URL
     *
     * 使用 Magento AssetRepository 生成包含正确主题/locale 的静态资源 URL
     *
     * @param string $code 支付方式代码
     * @return string Logo 图片的完整 URL
     */
    private function getLogoUrl(string $code): string
    {
        $logoFile = self::LOGO_MAP[$code] ?? 'default.svg';

        $asset = $this->assetRepository->createAsset(
            'Oceanpayment_Payment::images/' . $logoFile
        );

        return $asset->getUrl();
    }
}
