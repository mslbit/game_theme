<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Validator\Request;

use MslPay\LianLian\Model\Data\LianLianPayment;

/**
 * 连连支付请求必填字段验证策略接口
 *
 * 不同支付模式（iframe/checkout/direct_api）有不同的必填字段要求，
 * 通过策略模式在请求发送前验证，避免因缺少必填字段被连连 API 拒绝。
 *
 * @api
 */
interface RequiredFieldValidatorInterface
{
    /**
     * 验证请求数据的必填字段
     *
     * @param LianLianPayment $payment 请求数据对象
     * @throws \InvalidArgumentException 缺少必填字段时抛出异常
     */
    public function validate(LianLianPayment $payment): void;
}