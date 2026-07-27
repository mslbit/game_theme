<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Oceanpayment\Payment\Gateway\Config\Config;

/**
 * Oceanpayment 订单查询 HTTP 传输工厂
 *
 * 订单查询接口走 query 域名（test-query.oceanpayment.com / query.oceanpayment.com），
 * 端点为 /service/check/normal
 */
class OrderQueryTransferFactory implements TransferFactoryInterface
{
    /**
     * 订单查询 API 端点路径
     */
    private const QUERY_PATH = '/service/check/normal';

    /**
     * @var TransferBuilder
     */
    private TransferBuilder $transferBuilder;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @param TransferBuilder $transferBuilder
     * @param Config $config
     */
    public function __construct(TransferBuilder $transferBuilder, Config $config)
    {
        $this->transferBuilder = $transferBuilder;
        $this->config = $config;
    }

    /**
     * 创建订单查询传输对象
     *
     * @param array $request 请求数据
     * @return TransferInterface
     */
    public function create(array $request): TransferInterface
    {
        $fullUri = rtrim($this->config->getQueryBaseUrl(), '/') . self::QUERY_PATH;

        return $this->transferBuilder
            ->setMethod('POST')
            ->setUri($fullUri)
            ->setBody($request)
            ->setHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
            ])
            ->build();
    }
}