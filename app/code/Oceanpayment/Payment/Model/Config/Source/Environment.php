<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Environment source model - 提供 sandbox/production 选项
 */
class Environment implements OptionSourceInterface
{
    /**
     * 沙箱环境标识
     */
    const SANDBOX = 'sandbox';

    /**
     * 生产环境标识
     */
    const PRODUCTION = 'production';

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::SANDBOX, 'label' => __('Sandbox')],
            ['value' => self::PRODUCTION, 'label' => __('Production')],
        ];
    }
}