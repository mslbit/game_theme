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
 * 将 Oceanpayment 各支付方式的配置信息注入到 Magento Checkout JS 组件中，
 * 使前端可以动态获取支付方式的标题、Logo、启用状态、模式等信息
 *
 * 支持的支付方式：
 * - Credit Card（信用卡）
 * - Apple Pay
 * - Google Pay
 * - WeChat Pay（微信支付）
 * - Alipay（支付宝）
 *
 * 两种托管结账模式（merchant_controlled / auto_redirect）共享同一套支付方式，
 * mode 仅影响跳转方式，不影响支付方式选择。
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
     * WeChat Pay 支付方式代码
     */
    public const CODE_WECHATPAY = 'oceanpayment_wechatpay';

    /**
     * Alipay 支付方式代码
     */
    public const CODE_ALIPAY = 'oceanpayment_alipay';

    /**
     * Logo 图片文件名映射
     * 键为支付方式代码，值为 Logo 文件名
     */
    private const LOGO_MAP = [
        self::CODE_CREDITCARD => 'creditcard.svg',
        self::CODE_APPLEPAY   => 'applepay.svg',
        self::CODE_GOOGLEPAY  => 'googlepay.svg',
        self::CODE_WECHATPAY  => 'wechatpay.svg',
        self::CODE_ALIPAY     => 'alipay.svg',
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
     * @var Config WeChat Pay 配置实例
     */
    private Config $wechatPayConfig;

    /**
     * @var Config Alipay 配置实例
     */
    private Config $alipayConfig;

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
     * @param Config $creditCardConfig Credit Card 配置（OPCreditCardConfig 虚拟类型）
     * @param Config $applePayConfig Apple Pay 配置（OPApplePayConfig 虚拟类型）
     * @param Config $googlePayConfig Google Pay 配置（OPGooglePayConfig 虚拟类型）
     * @param Config $wechatPayConfig WeChat Pay 配置（OPWechatPayConfig 虚拟类型）
     * @param Config $alipayConfig Alipay 配置（OPAlipayConfig 虚拟类型）
     * @param AssetRepository $assetRepository 静态资源仓库
     * @param SignatureHelper $signatureHelper 签名与 URL 辅助
     */
    public function __construct(
        Config $creditCardConfig,
        Config $applePayConfig,
        Config $googlePayConfig,
        Config $wechatPayConfig,
        Config $alipayConfig,
        AssetRepository $assetRepository,
        SignatureHelper $signatureHelper
    ) {
        $this->creditCardConfig = $creditCardConfig;
        $this->applePayConfig = $applePayConfig;
        $this->googlePayConfig = $googlePayConfig;
        $this->wechatPayConfig = $wechatPayConfig;
        $this->alipayConfig = $alipayConfig;
        $this->assetRepository = $assetRepository;
        $this->signatureHelper = $signatureHelper;
    }

    /**
     * 获取结账配置
     *
     * 将所有已启用的 Oceanpayment 支付方式配置注入到 Checkout JS 中，
     * 每种支付方式包含：code, title, is_active, logo_url
     * 同时暴露 mode 配置供前端判断跳转方式
     *
     * @return array 结账配置数组
     */
    public function getConfig(): array
    {
        $mode = $this->creditCardConfig->getMode();
        $config = [
            'payment' => [
                self::CODE => [
                    'mode' => $mode,
                    'is_production'   => 
                    $this->creditCardConfig->getEnvironment() 
                    === \Oceanpayment\Payment\Model\Config\Source\Environment::PRODUCTION ,
                    'methods' => [],
                    'embedded' => $this->getEmbeddedConfig(),
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

            $config['payment'][self::CODE]['methods'][$code] = $methodData;
        }

        return $config;
    }



    /**
     * 获取所有支付方式的配置实例映射
     *
     * @return array<string, Config> 支付方式代码 => 配置实例
     */
    private function getMethodConfigs(): array
    {
        return [
            self::CODE_CREDITCARD => $this->creditCardConfig,
            self::CODE_APPLEPAY   => $this->applePayConfig,
            self::CODE_GOOGLEPAY  => $this->googlePayConfig,
            self::CODE_WECHATPAY  => $this->wechatPayConfig,
            self::CODE_ALIPAY     => $this->alipayConfig,
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
     * 供前端 Oceanpayment.init() 初始化 iframe 时使用
     *
     * @return array
     */
    private function getEmbeddedConfig(): array
    {
        return [
            'is_sandbox' => $this->creditCardConfig->getEnvironment()
                === \Oceanpayment\Payment\Model\Config\Source\Environment::SANDBOX,
            'language' => 'en',
            'public_key' => (string) $this->creditCardConfig->getValue('public_key'),
            'back_url' => $this->signatureHelper->buildBackUrl(),
        ];
    }

    /**
     * 获取支付方式 Logo URL     *
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
