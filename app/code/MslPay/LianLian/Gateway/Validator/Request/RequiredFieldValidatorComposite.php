<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Validator\Request;

use MslPay\LianLian\Model\Data\LianLianPayment;
use MslPay\LianLian\Model\Ui\ConfigProvider;

/**
 * 必填字段验证策略组合器
 *
 * 收银台模式：payment_method 为 'Checkout' → checkout 策略
 */
class RequiredFieldValidatorComposite implements RequiredFieldValidatorInterface
{
    /** @var RequiredFieldValidatorInterface[] */
    private array $validators;

    /**
     * @param RequiredFieldValidatorInterface[] $validators key 为支付方式代码
     */
    public function __construct(array $validators = [])
    {
        $this->validators = $validators;
    }

    public function validate(LianLianPayment $payment): void
    {
        $validator = $this->resolveValidator($payment);
        if ($validator !== null) {
            $validator->validate($payment);
        }
    }

    private function resolveValidator(LianLianPayment $payment): ?RequiredFieldValidatorInterface
    {
        $paymentMethod = $payment->getPaymentMethod();

        if ($paymentMethod === 'Checkout'
            && isset($this->validators[ConfigProvider::CODE_CHECKOUT])) {
            return $this->validators[ConfigProvider::CODE_CHECKOUT];
        }

        return null;
    }
}
