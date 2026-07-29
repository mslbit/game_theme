<?php
declare(strict_types=1);

namespace MslPay\LianLian\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use MslPay\LianLian\Gateway\Config\Config;

/**
 * 连连支付结账配置提供器
 *
 * 仅收银台模式（Checkout）：获取 payment_url，整页跳转
 */
class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'mslpay_lianlian';
    public const CODE_CHECKOUT = 'mslpay_lianlian_checkout';

    private const LOGO_MAP = [
        self::CODE_CHECKOUT => 'lianlianpay.svg',
    ];

    private Config $checkoutConfig;
    private StoreManagerInterface $storeManager;
    private AssetRepository $assetRepository;

    public function __construct(
        Config $checkoutConfig,
        StoreManagerInterface $storeManager,
        AssetRepository $assetRepository
    ) {
        $this->checkoutConfig = $checkoutConfig;
        $this->storeManager = $storeManager;
        $this->assetRepository = $assetRepository;
    }

    public function getConfig(): array
    {
        $config = [
            'payment' => [
                self::CODE => [
                    'is_sandbox' => $this->checkoutConfig->isSandbox(),
                    'back_url'   => $this->getBackUrl(),
                    'methods'    => [],
                ],
            ],
        ];

        if ($this->checkoutConfig->isActive()) {
            $config['payment'][self::CODE]['methods'][self::CODE_CHECKOUT] = [
                'code'      => self::CODE_CHECKOUT,
                'title'     => (string) $this->checkoutConfig->getTitle(),
                'is_active' => true,
                'logo_url'  => $this->getLogoUrl(self::CODE_CHECKOUT),
            ];
        }

        return $config;
    }

    private function getLogoUrl(string $code): string
    {
        $logoFile = self::LOGO_MAP[$code] ?? 'default.svg';
        $asset = $this->assetRepository->createAsset(
            'MslPay_LianLian::images/' . $logoFile
        );
        return $asset->getUrl();
    }

    private function getBackUrl(): string
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        return $baseUrl . 'lianlian/payment/back';
    }
}
