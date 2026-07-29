<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Response\Handler;

use Magento\Framework\App\CacheInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * 登录状态缓存处理器
 *
 * 支付成功后保存客户登录状态到缓存，
 * 供 Back 控制器（同步跳转回来时）恢复登录态。
 *
 * 场景：用户在连连收银台/3DS 页面完成支付后跳转回商户网站，
 * 此时 session 可能已过期，需要从缓存恢复登录状态。
 *
 * 缓存 key 格式：lianlian_login_{order_increment_id}
 * 缓存值：customer_id
 * 缓存标签：LIANLIAN_LOGIN
 * 缓存时间：3600 秒
 */
class LoginStateHandler implements HandlerInterface
{
    private const CACHE_LOGIN_PREFIX = 'lianlian_login_';
    private const CACHE_LIFETIME = 3600;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * 保存登录状态到缓存
     *
     * @param array $commandSubject 命令参数
     * @param array $response 连连响应
     */
    public function handle(array $commandSubject, array $response): void
    {
        $paymentDO = SubjectReader::readPayment($commandSubject);
        $order = $paymentDO->getOrder();
        $customerId = $order->getCustomerId();

        /* 游客订单无需缓存登录状态 */
        if (!$customerId) {
            return;
        }

        /* 以 order_increment_id 为 key，Back 控制器可按订单号查找 */
        $orderNumber = $order->getOrderIncrementId();
        $this->cache->save(
            (string) $customerId,
            self::CACHE_LOGIN_PREFIX . $orderNumber,
            ['LIANLIAN_LOGIN'],
            self::CACHE_LIFETIME
        );

        $this->logger->info('[LianLian] LoginStateHandler: login state cached', [
            'order_number' => $orderNumber,
        ]);
    }
}