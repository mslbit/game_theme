<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Http;

use Magento\Framework\DataObject;
use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use MslPay\LianLian\Gateway\Config\Config;
use MslPay\LianLian\Gateway\Helper\LianLianSignatureHelper;

/**
 * 连连支付传输工厂
 *
 * 职责：
 * 1. 计算 RSA 签名，构建请求 Header（签名从 Command 移入此处）
 * 2. 根据 Config 构建 API URI
 * 3. 将请求数据封装为 Transfer 对象
 *
 * 参考 Braintree TransferFactory 模式：
 * - 签名在 TransferFactory 中完成，不在 Command 中
 * - URI 由 Config + uriPath 参数决定
 * - headers 包含签名、时间戳、Content-Type
 *
 * uriPath 参数用于退款等不同端点：
 * - 空（默认）：使用 config->getPaymentApiUrl()（支付创单）
 * - 非空：使用 config->getApiBaseUrl() + uriPath（退款等）
 */
class TransferFactory implements TransferFactoryInterface
{
    /**
     * @param TransferBuilder $transferBuilder Transfer 构建器
     * @param Config $config 连连支付配置
     * @param LianLianSignatureHelper $signatureHelper RSA 签名助手
     * @param string $uriPath API 路径后缀（退款等端点通过 virtual type 传入）
     */
    public function __construct(
        private readonly TransferBuilder $transferBuilder,
        private readonly Config $config,
        private readonly LianLianSignatureHelper $signatureHelper,
        private readonly string $uriPath = ''
    ) {
    }

    /**
     * 将请求数据封装为 Transfer 对象
     *
     * 流程：
     * 1. 根据 uriPath / original_transaction_id 决定 API 端点
     * 2. 计算签名，构建请求 Header
     * 3. 封装为 Transfer（uri + headers + body + method）
     *
     * URI 决策优先级：
     * - uriPath 非空：使用 baseUrl + uriPath（自定义端点）
     * - 请求含 original_transaction_id：退款端点
     * - 默认：支付创单端点
     *
     * @param array $request Builder 构建的请求数据
     * @return TransferInterface
     */
    public function create(array $request): TransferInterface
    {
        /* 递归展平 DataObject 为纯数组（签名计算和 JSON 序列化都需要纯数组） */
        $flatRequest = $this->flattenDataObjects($request);

        /* 构建 API URI（按优先级决策） */
        if (!empty($this->uriPath)) {
            /* 自定义端点（通过 virtual type 传入 uriPath） */
            $uri = $this->config->getApiBaseUrl() . $this->uriPath;
        } elseif (isset($flatRequest['original_transaction_id']) && !empty($flatRequest['original_transaction_id'])) {
            /* 退款端点：URI 包含 original_transaction_id */
            $uri = $this->config->getRefundApiUrl($flatRequest['original_transaction_id']);
        } else {
            /* 支付创单端点（默认） */
            $uri = $this->config->getPaymentApiUrl();
        }

        /* 计算 RSA 签名，构建请求 Header（签名必须基于展平后的纯数组） */
        $headers = $this->signatureHelper->buildRequestHeaders(
            $flatRequest,
            $this->config->getPrivateKey(),
            $this->config->getTimezone()
        );

        return $this->transferBuilder
            ->setUri($uri)
            ->setHeaders($headers)
            ->setBody($flatRequest)
            ->setMethod('POST')
            ->build();
    }

    /**
     * 递归展平请求数组中的 DataObject 实例为纯数组
     *
     * BuilderComposite 合并各 Builder 输出后，请求数组中可能包含 DataObject 实例
     * （如 Customer、MerchantOrder、PaymentData 等）。
     * 签名计算和 JSON 序列化都需要纯数组，因此必须在设置 body 前展平。
     *
     * 展平逻辑与 LianLianPayment::toArray() 一致：
     * - DataObject → 递归调用 getData() 展平
     * - 数组中的 DataObject → 递归展平每个元素
     * - 标量值 → 保持不变
     * - null / 空字符串 → 跳过（避免传无效字段给连连 API）
     *
     * @param array $data BuilderComposite 合并后的请求数据
     * @return array 展平后的纯数组
     */
    private function flattenDataObjects(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if ($value instanceof DataObject) {
                /* DataObject 实例：递归展平其内部数据 */
                $flattened = $this->flattenDataObjects($value->getData());
                if (!empty($flattened)) {
                    $result[$key] = $flattened;
                }
            } elseif (is_array($value)) {
                /* 数组：递归处理（可能包含 DataObject 元素，如 Product[]） */
                $flattened = $this->flattenArray($value);
                if (!empty($flattened)) {
                    $result[$key] = $flattened;
                }
            } else {
                /* 标量值：直接保留 */
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * 展平数组（处理 Product[] 等 DataObject 数组）
     *
     * @param array $arr 可能包含 DataObject 元素的数组
     * @return array 展平后的纯数组
     */
    private function flattenArray(array $arr): array
    {
        $result = [];
        foreach ($arr as $key => $value) {
            if ($value instanceof DataObject) {
                /* DataObject 元素：递归展平 */
                $flattened = $this->flattenDataObjects($value->getData());
                if (!empty($flattened)) {
                    $result[$key] = $flattened;
                }
            } elseif (is_array($value)) {
                /* 嵌套数组：递归处理 */
                $result[$key] = $this->flattenArray($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
