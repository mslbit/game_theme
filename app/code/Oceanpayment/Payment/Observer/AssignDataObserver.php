<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Oceanpayment 数据分配观察者
 *
 * 将前端传递的 additional_data 逐字段转存到 additional_information。
 *
 * 为什么用 setAdditionalInformation 而非 setAdditionalData：
 * - additional_data 是临时数据，Quote→Order Payment 转换时不复制，数据丢失
 * - additional_information 会自动复制到 Order Payment，Gateway Command 可正常读取
 * - additional_information 由 Magento 自动 JSON 序列化，无需手动 json_encode
 *
 * 当前嵌入式支付（CC/ApplePay/GooglePay）前端 additional_data 为空对象，
 * 敏感数据通过独立 CheckoutData REST API 在后端组装。
 * 此 Observer 为托管支付（收银台跳转）等场景预留，确保前端传来的字段能传递到 Order Payment。
 */
class AssignDataObserver implements ObserverInterface
{
    /**
     * 需要从 additional_data 转存到 additional_information 的字段
     */
    private const TRANSFER_FIELDS = [
        'card_data',
        'payment_id',
        'card_number',
        'auth_type',
        'pay_url',
        'payment_status',
    ];

    public function execute(Observer $observer): void
    {
        $payment = $observer->getEvent()->getPayment();

        if (!$payment) {
            return;
        }

        $input = $observer->getEvent()->getInput();
        $additionalData = $input && isset($input['additional_data']) ? $input['additional_data'] : [];

        if (empty($additionalData)) {
            return;
        }

        /* 兼容字符串格式（Magento 有时将 additional_data 序列化为 JSON 字符串） */
        if (is_string($additionalData)) {
            $additionalData = json_decode($additionalData, true) ?: [];
        }

        /* 逐字段转存到 additional_information，只转存白名单字段 */
        foreach (self::TRANSFER_FIELDS as $field) {
            if (isset($additionalData[$field])) {
                $value = $additionalData[$field];
                /* 确保只存标量值，数组/对象序列化为 JSON 字符串 */
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                }
                $payment->setAdditionalInformation($field, $value);
            }
        }
    }
}
