<?php
declare(strict_types=1);

namespace MslPay\LianLian\Api;

/**
 * 连连支付查询接口
 *
 * 查询连连支付订单状态，供其他模块通过 DI 注入使用。
 *
 * 连连 API：
 * GET /v3/merchants/<merchant_id>/payments/<merchant_transaction_id>
 * 签名构建因子：merchant_id, merchant_transaction_id
 */
interface PaymentQueryInterface
{
    /**
     * 查询支付订单状态
     *
     * @param string $merchantTransactionId 商户支付交易ID（订单 increment_id）
     * @return array 连连响应，结构：
     *   [
     *     'return_code' => 'SUCCESS'|'FAIL',
     *     'return_message' => '...',
     *     'order' => [
     *       'll_transaction_id' => '...',
     *       'payment_data' => ['payment_status' => 'PS'|'PP'|'WP'|'IN'|'PC'|'PF', ...],
     *       ...
     *     ]
     *   ]
     * @throws \Magento\Framework\Exception\LocalizedException 查询失败时抛出
     */
    public function query(string $merchantTransactionId): array;
}