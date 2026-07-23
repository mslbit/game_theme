<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Oceanpayment 支付模式选项源
 *
 * merchant_controlled: 商家控制重定向（后端调 sendTrade → 前端控制跳转）
 * auto_redirect: Oceanpayment 自动重定向（落单后调 /pay → 整页跳转）
 * embedded: 嵌入式支付（前端直接调用 Oceanpayment Direct API，不跳转）
 */
class Mode implements OptionSourceInterface
{
    public const MODE_MERCHANT_CONTROLLED = 'merchant_controlled';
    public const MODE_AUTO_REDIRECT = 'auto_redirect';
    public const MODE_EMBEDDED = 'embedded';

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
           // ['value' => self::MODE_MERCHANT_CONTROLLED, 'label' => __('Merchant Controlled Redirect')],
         //   ['value' => self::MODE_AUTO_REDIRECT, 'label' => __('Auto Redirect')],
            ['value' => self::MODE_EMBEDDED, 'label' => __('Embedded Payment')],
        ];
    }
}