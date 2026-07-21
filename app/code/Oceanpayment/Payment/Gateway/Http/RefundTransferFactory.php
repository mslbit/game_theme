<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\TransferInterface;

/**
 * Oceanpayment 退款 HTTP 传输工厂
 *
 * 轻量代理：将退款 API 端点路径转发给通用 TransferFactory，
 * 不重复实现 URL 拼接、Header 设置等逻辑。
 *
 * 退款端点：/service/applyRefund
 */
class RefundTransferFactory implements TransferFactoryInterface
{
    /**
     * 退款 API 路径
     */
    private const REFUND_API_PATH = '/service/applyRefund';

    /**
     * @var TransferFactory
     */
    private TransferFactory $transferFactory;

    /**
     * @param TransferFactory $transferFactory 通用传输工厂
     */
    public function __construct(TransferFactory $transferFactory)
    {
        $this->transferFactory = $transferFactory;
    }

    /**
     * 创建退款 HTTP 传输对象
     *
     * @param array $request 退款请求参数
     * @return TransferInterface
     */
    public function create(array $request): TransferInterface
    {
        return $this->transferFactory->create($request, self::REFUND_API_PATH);
    }
}
