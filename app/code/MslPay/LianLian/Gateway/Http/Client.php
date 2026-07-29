<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Http;

use GuzzleHttp\Client as GuzzleClient;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Psr\Log\LoggerInterface;

/**
 * 连连支付 HTTP 客户端
 *
 * 职责：只管发送 HTTP 请求，返回原始响应
 *
 * 参考 Braintree Client 模式：
 * - 从 Transfer 对象读取 uri/headers/body
 * - 发送 JSON POST 请求
 * - 解析 JSON 响应，提取签名 Header
 * - 返回 ['body' => ..., 'signature' => ...] 格式
 *
 * 不负责：
 * - 签名（由 TransferFactory 处理）
 * - 验签（由 Validator 处理）
 * - 业务逻辑（由 Handler 处理）
 */
class Client implements ClientInterface
{
    public function __construct(
        private readonly GuzzleClient $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * 发送 HTTP 请求到连连 API
     *
     * @param TransferInterface $transferObject 由 TransferFactory 创建的 Transfer 对象
     * @return array ['body' => 解析后的 JSON, 'signature' => 响应签名 Header]
     * @throws \Magento\Payment\Gateway\Http\ClientException 请求失败时抛出
     */
    public function placeRequest(TransferInterface $transferObject): array
    {
        $uri = $transferObject->getUri();
        $headers = $transferObject->getHeaders();
        $body = $transferObject->getBody();

        $this->logger->info('[LianLian] Client sending request', [
            'uri' => $uri,
            'merchant_transaction_id' => $body['merchant_transaction_id'] ?? '',
        ]);

        try {
            $response = $this->httpClient->post($uri, [
                'headers' => $headers,
                'json' => $body,
                'timeout' => 30,
            ]);

            $result = json_decode((string) $response->getBody(), true);
            $responseSignature = $response->getHeaderLine('signature');

            if (!is_array($result)) {
                throw new \RuntimeException('Invalid response from LianLian');
            }

            return [
                'body' => $result,
                'signature' => $responseSignature,
            ];
        } catch (\Magento\Payment\Gateway\Http\ClientException $e) {
            /* 保留 ClientException 原样抛出 */
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('[LianLian] Client request failed', [
                'error' => $e->getMessage(),
            ]);
            throw new \Magento\Payment\Gateway\Http\ClientException(
                __('LianLian payment request failed: %1', $e->getMessage())
            );
        }
    }
}
