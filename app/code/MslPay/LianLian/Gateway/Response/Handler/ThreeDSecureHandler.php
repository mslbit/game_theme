<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Response\Handler;

use Magento\Framework\App\CacheInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * 3DS 跳转处理器
 *
 * iframe 模式专用：
 * 当连连返回 payment_url（3DS 验证跳转地址）时，
 * 将其缓存供前端 get-pay-url API 读取。
 *
 * 流程：
 * 1. IframeCommand 获取 payment_url 后缓存
 * 2. 前端 placeOrder 完成后调 /V1/lianlian/get-pay-url API
 * 3. API 从缓存读取 payment_url 返回前端
 * 4. 前端跳转到 payment_url 完成 3DS 验证
 *
 * 缓存 key 格式：lianlian_pay_url_{quote_id}
 * 缓存标签：LIANLIAN_PAY_URL
 * 缓存时间：3600 秒
 */
class ThreeDSecureHandler implements HandlerInterface
{
    private const CACHE_PAY_URL_PREFIX = 'lianlian_pay_url_';
    private const CACHE_LIFETIME = 3600;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * 缓存 payment_url 供前端 get-pay-url API 读取
     *
     * @param array $commandSubject 命令参数
     * @param array $response 连连响应 ['body' => ..., 'signature' => ...]
     */
    public function handle(array $commandSubject, array $response): void
    {
        $paymentDO = SubjectReader::readPayment($commandSubject);
        $responseBody = $response['body'] ?? [];
        $orderData = $responseBody['order'] ?? [];
        $paymentData = $orderData['payment_data'] ?? [];

        $paymentStatus = $paymentData['payment_status'] ?? '';
        $threeDsStatus = $orderData['3ds_status'] ?? '';
        $paymentUrl = $orderData['payment_url'] ?? '';

        /*
         * 只有 3ds_status=CHALLENGE 时才缓存 payment_url 供前端跳转
         *
         * 连连支付状态：
         * - PS（支付成功）：终态，不需要跳转，哪怕有 payment_url
         * - PP + 3ds_status=CHALLENGE：需要 3DS 验证，跳转 payment_url
         * - PP（无 CHALLENGE）：等待银行结果，等异步通知
         * - WP/IN：中间状态，等异步通知
         * - PC/PF：终态，失败或过期
         */
        if ($paymentStatus === 'PS') {
            $this->logger->info('[LianLian] ThreeDSecureHandler: no redirect needed', [
                'payment_status' => $paymentStatus,
                '3ds_status' => $threeDsStatus,
            ]);
            return;
        }
        if (empty($paymentUrl)) {
            return;
        }

        /* 以 quote_id 为 key 缓存，前端 placeOrder 后可按 quote 读取 */
        $quoteId = $paymentDO->getOrder()->getQuoteId();
        $this->cache->save(
            $paymentUrl,
            self::CACHE_PAY_URL_PREFIX . $quoteId,
            ['LIANLIAN_PAY_URL'],
            self::CACHE_LIFETIME
        );

        $this->logger->info('[LianLian] ThreeDSecureHandler: payment_url cached for 3DS CHALLENGE', [
            'quote_id' => $quoteId,
        ]);
    }
}