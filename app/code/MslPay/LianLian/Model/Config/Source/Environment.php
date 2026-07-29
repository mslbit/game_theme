<?php
declare(strict_types=1);

namespace MslPay\LianLian\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * 连连支付环境选项源
 */
class Environment implements OptionSourceInterface
{
    public const SANDBOX = 'sandbox';
    public const PRODUCTION = 'production';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::SANDBOX, 'label' => __('Sandbox')],
            ['value' => self::PRODUCTION, 'label' => __('Production')],
        ];
    }
}