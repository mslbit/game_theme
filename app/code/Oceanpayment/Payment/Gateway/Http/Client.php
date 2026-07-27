<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Store\Model\ScopeInterface;
use Oceanpayment\Payment\Gateway\Helper\XmlHelper;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment HTTP 客户端
 *
 * 使用 Guzzle HTTP 客户端向 Oceanpayment 网关发送 POST 请求，
 * 支持 XML 响应解析，并在调试模式下记录请求/响应日志
 */
class Client implements ClientInterface
{
    /**
     * 调试模式配置路径
     * 注意：Oceanpayment_Payment 模块使用 oceanpayment_payment 配置段
     */
    private const PATH_DEBUG = 'payment/oceanpayment_payment/debug';

    /**
     * @var GuzzleClient
     */
    private GuzzleClient $httpClient;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var XmlHelper
     */
    private XmlHelper $xmlHelper;

    /**
     * @param GuzzleClient $httpClient
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param XmlHelper $xmlHelper
     */
    public function __construct(
        GuzzleClient $httpClient,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        XmlHelper $xmlHelper
    ) {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->xmlHelper = $xmlHelper;
    }

    /**
     * 发送 HTTP 请求并解析 XML 响应
     *
     * @inheritDoc
     */
    public function placeRequest(TransferInterface $transferObject): array
    {
        $uri = $transferObject->getUri();
        $body = $transferObject->getBody();
        $headers = $transferObject->getHeaders();

        /* 调试模式下记录请求日志 */
        if ($this->isDebugMode()) {
            $this->logger->debug('[Oceanpayment] HTTP Request', [
                'uri'     => $uri,
                'body'    => $this->maskSensitiveData($body),
                'headers' => $headers,
            ]);
        }

        try {
            $options = [
                'timeout'     => 30,
                'verify'      => true,
                'form_params' => $body,
                'headers'     => array_merge(
                    ['Content-Type' => 'application/x-www-form-urlencoded'],
                    is_array($headers) ? $headers : []
                ),
            ];

            $response = $this->httpClient->request('POST', $uri, $options);
            $responseBody = (string) $response->getBody();

            /* 调试模式下记录响应日志 */
            if ($this->isDebugMode()) {
                $this->logger->debug('[Oceanpayment] HTTP Response', [
                    'response' => $responseBody,
                ]);
            }

            return $this->xmlHelper->parse($responseBody);

        } catch (GuzzleException $e) {
            $this->logger->error('[Oceanpayment] HTTP request failed', [
                'error' => $e->getMessage(),
                'uri'   => $uri,
            ]);
            throw new \RuntimeException(
                'Oceanpayment API request failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * 脱敏敏感数据（用于日志）
     *
     * @param array $body 请求体
     * @return array 脱敏后的请求体
     */
    private function maskSensitiveData(array $body): array
    {
        $sensitiveFields = ['secureCode', 'signValue'];

        foreach ($sensitiveFields as $field) {
            if (isset($body[$field])) {
                $body[$field] = '******';
            }
        }

        return $body;
    }

    /**
     * 检查是否开启调试模式
     *
     * @return bool
     */
    private function isDebugMode(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::PATH_DEBUG,
            ScopeInterface::SCOPE_STORE
        );
    }
}
