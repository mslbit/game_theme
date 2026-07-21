<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Block;

use Magento\Payment\Block\ConfigurableInfo;

/**
 * Oceanpayment 支付信息 Block
 *
 * 在 Admin 订单详情页和前端订单详情页中显示 Oceanpayment 交易信息。
 * 继承 ConfigurableInfo，重写字段标签映射，将 additional_information 中的
 * 内部键名转换为用户友好的标签。
 *
 * 显示字段：
 * - oceanpayment_payment_id → Payment ID
 * - oceanpayment_card_number → Card Number
 * - oceanpayment_auth_type → Auth Type
 */
class Info extends ConfigurableInfo
{
    /**
     * 字段标签映射表
     *
     * 将 additional_information 中的内部键名映射为用户友好的标签
     *
     * @var array
     */
    private const LABEL_MAP = [
        'oceanpayment_payment_id' => 'Payment ID',
        'oceanpayment_card_number' => 'Card Number',
        'oceanpayment_auth_type' => 'Auth Type',
        'oceanpayment_high_risk' => 'High Risk',
    ];

    /**
     * 获取字段标签
     *
     * 重写父类方法，将内部键名转换为用户友好的标签
     * 未在映射表中的字段回退到父类的默认标签格式
     *
     * @param string $field 字段键名
     * @return string|\Magento\Framework\Phrase 字段标签
     */
    public function getLabel($field)
    {
        if (isset(self::LABEL_MAP[$field])) {
            return __(self::LABEL_MAP[$field]);
        }

        return parent::getLabel($field);
    }
}