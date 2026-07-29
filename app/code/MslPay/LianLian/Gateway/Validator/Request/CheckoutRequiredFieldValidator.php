<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Validator\Request;

use MslPay\LianLian\Model\Data\LianLianPayment;

/**
 * 收银台模式必填字段验证策略
 *
 * 收银台模式必填字段较少：
 * - cancel_url: 收银台模式必传
 * - customer: 选传（建议填写，有助于提高交易成功率）
 * - payment_data: 不需要（用户在收银台页面完成支付）
 */
class CheckoutRequiredFieldValidator implements RequiredFieldValidatorInterface
{
    public function validate(LianLianPayment $payment): void
    {
        $errors = [];

        if (empty($payment->getCancelUrl())) {
            $errors[] = 'cancel_url is required for checkout mode';
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(
                '[LianLian] checkout mode required fields missing: ' . implode('; ', $errors)
            );
        }
    }
}