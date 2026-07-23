<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;

/**
 * Oceanpayment 数据分配观察者
 *
 * 将前端传递的 additional_data 转存到 additional_information，
 * 因为 Quote Payment → Order Payment 转换时只复制 additional_information，
 * additional_data 不会传递到 Order Payment。
 *
 * SaleCommand 通过 $payment->getAdditionalInformation() 读取交易数据。
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
        $data = $observer->getData(AbstractDataAssignObserver::DATA_CODE);
        if (!$data) {
            return;
        }

        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_array($additionalData) || empty($additionalData)) {
            return;
        }

        $paymentInfo = $observer->getData(AbstractDataAssignObserver::MODEL_CODE);
        if (!$paymentInfo) {
            return;
        }

        // 将 additional_data 中的交易字段转存到 additional_information
        foreach (self::TRANSFER_FIELDS as $field) {
            if (isset($additionalData[$field])) {
                $paymentInfo->setAdditionalInformation(
                    'oceanpayment_' . $field,
                    $additionalData[$field]
                );
            }
        }
    }
}