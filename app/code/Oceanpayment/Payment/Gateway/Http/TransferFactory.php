<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Oceanpayment\Payment\Gateway\Config\Config;

/**
 * Oceanpayment HTTP 传输工厂
 *
 * 将请求参数数组封装为 Transfer 对象，定义：
 * - 请求方法：POST
 * - 请求 URI：由调用方传入，未传时使用 Config 中的网关 URL
 * - 请求体：由 Builder 组装完成的参数数组
 * - 请求头：Content-Type 为 application/x-www-form-urlencoded
 *
 * 设计说明：
 * - URI 参数化，不同场景（sendTrade / applyRefund / 其他）可灵活指定端点
 * - RefundTransferFactory 已废弃，统一使用此类 + 不同 URI
 */
class TransferFactory implements TransferFactoryInterface
{
    /**
     * 默认 sendTrade 端点路径
     */
    private const DEFAULT_URI = '/gateway/service/sendTrade';

    /**
     * @var TransferBuilder Magento 传输对象构建器
     */
    private TransferBuilder $transferBuilder;

    /**
     * @var Config Oceanpayment 网关配置
     */
    private Config $config;

    /**
     * Constructor
     *
     * @param TransferBuilder $transferBuilder 传输对象构建器
     * @param Config $config Oceanpayment 网关配置
     */
    public function __construct(
        TransferBuilder $transferBuilder,
        Config $config
    ) {
        $this->transferBuilder = $transferBuilder;
        $this->config = $config;
    }

    /**
     * 创建 HTTP 传输对象
     *
     * @param array      $request 由 Builder 组装的完整请求参数
     * @param string|null $uri    可选，API 端点路径（如 /gateway/service/sendTrade）
     *                            未传时使用 Config 中的 gatewayUrl + 默认 sendTrade 路径
     * @return TransferInterface 封装好的传输对象
     */
    public function create(array $request, ?string $uri = null): TransferInterface
    {
        $fullUri = $uri !== null
            ? rtrim($this->config->getGatewayUrl(), '/') . $uri
            : $this->config->getGatewayUrl() . self::DEFAULT_URI;

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
