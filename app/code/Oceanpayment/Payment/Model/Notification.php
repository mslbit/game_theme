<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Oceanpayment\Payment\Api\NotificationInterface;
use Oceanpayment\Payment\Gateway\Callback\CallbackProcessor;
use Oceanpayment\Payment\Gateway\Helper\XmlHelper;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 异步通知 REST API 处理器
 *
 * 实现 NotificationInterface，处理 Oceanpayment 服务端推送的异步支付通知。
 * 端点路由：POST /rest/V1/oceanpayment/notice
 * 权限：anonymous（Oceanpayment 服务端无 Magento 认证）
 *
 * 核心业务逻辑委托给 CallbackProcessor 处理，本类只负责：
 * 1. 从请求体读取 XML 数据
 * 2. 解析 XML 为参数数组
 * 3. 调用 CallbackProcessor 验签 + 处理订单
 * 4. 返回 "receive-ok" 响应
 */
class Notification implements NotificationInterface
{
    /**
     * Oceanpayment 要求的成功响应文本
     */
    private const RESPONSE_OK = 'receive-ok';

    /**
     * Oceanpayment 异步通知 XML 中可能包含的所有字段
     */
    private const XML_FIELDS = [
        'response_type',
        'account',
        'terminal',
        'payment_id',
        'order_number',
        'order_currency',
        'order_amount',
        'payment_status',
        'payment_details',
        'signValue',
        'order_notes',
        'card_number',
        'payment_authType',
        'payment_risk',
        'methods',
        'payment_country',
        'payment_solutions',
    ];

    /**
     * 共享网关配置的 XML 路径前缀
     */
    private const SHARED_CONFIG_PREFIX = 'payment/oceanpayment_payment';

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var HttpRequest
     */
    private HttpRequest $request;

    /**
     * @var CallbackProcessor
     */
    private CallbackProcessor $callbackProcessor;

    /**
     * @var XmlHelper
     */
    private XmlHelper $xmlHelper;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var \Magento\Framework\App\Response\Http
     */
    protected $response;


    /**
     * @param LoggerInterface $logger
     * @param HttpRequest $request
     * @param CallbackProcessor $callbackProcessor
     * @param XmlHelper $xmlHelper
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        LoggerInterface $logger,
        HttpRequest $request,
        CallbackProcessor $callbackProcessor,
        XmlHelper $xmlHelper,
        \Magento\Framework\App\Response\Http $response,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->logger = $logger;
        $this->request = $request;
        $this->callbackProcessor = $callbackProcessor;
        $this->xmlHelper = $xmlHelper;
        $this->scopeConfig = $scopeConfig;
        $this->response    = $response;
    }

    /**
     * 处理 Oceanpayment 异步通知
     *
     * @return array $receive-ok 处理结果
     */
    public function handle(): void
    {
        try {
            
            /* IP 白名单校验 */
            if (!$this->validateIpWhitelist()) {
                $this->logger->error('[Oceanpayment] Notification: IP not in whitelist', [
                    'remote_ip' => $this->getRemoteIp(),
                ]);
                throw new \Exception('ip forbidden');
            }

            $xmlContent = $this->request->getContent();

            if (empty($xmlContent)) {
                $this->logger->error('[Oceanpayment] Notification: Empty request body');
                  throw new \Exception('empty body');
            }

            $rawData = $this->xmlHelper->parse($xmlContent);

            /* 只保留 XML_FIELDS 中定义的字段 */
            $params = [];
            foreach (self::XML_FIELDS as $field) {
                if (isset($rawData[$field])) {
                    $params[$field] = $rawData[$field];
                }
            }

            if (empty($params)) {
                $this->logger->error('[Oceanpayment] Notification: Failed to parse XML');
                throw new \Exception('parse error');
            }

            $this->logger->info('[Oceanpayment] Notification: Received', [
                'order_number'   => $params['order_number'] ?? '(missing)',
                'payment_status' => $params['payment_status'] ?? '(missing)',
                'payment_id'     => $params['payment_id'] ?? '(missing)',
                'terminal'       => $params['terminal'] ?? '(missing)',
            ]);

            if (!$this->callbackProcessor->verifySignature($params)) {
                $this->logger->error('[Oceanpayment] Notification: Signature verification failed');

                   throw new \Exception('sign error');
            }

            $orderNumber = $params['order_number'] ?? '';
            $order = $this->callbackProcessor->loadOrder($orderNumber);

            if (!$order || !$order->getEntityId()) {
                $this->logger->error('[Oceanpayment] Notification: Order not found for order_number: %1', [
                    $orderNumber ?: '(empty)',
                ]);
                 throw new \Exception('order not found');
               
            }

            if ($this->callbackProcessor->isOrderAlreadyProcessed($order)) {
                $this->logger->info('[Oceanpayment] Notification: Order #%1 already processed', [
                    $order->getIncrementId(),
                ]);
                throw new \Exception(self::RESPONSE_OK);
            }

            $this->callbackProcessor->processCallback($order, $params, 'Notice');

            $this->logger->info('[Oceanpayment] Notification: Order #%1 processed successfully', [
                $order->getIncrementId(),
            ]);

         
            $body = self::RESPONSE_OK;
          

        } catch (\Exception $e) {
            $this->logger->error('[Oceanpayment] Notification exception: %1', [$e->getMessage()]);
             $body = $e->getMessage();
        }
         // --- 核心修正：乾淨輸出純字串並強制中斷 Magento Webapi 渲染 ---
        $this->response->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $this->response->setBody($body);
        $this->response->sendResponse();
        exit;
    }

    /**
     * 校验请求 IP 是否在白名单中
     *
     * 当 notification_ip_allow_all=Yes 时跳过校验（测试模式）
     * 当 notification_ip_allow_all=No 时，请求 IP 必须在白名单中
     *
     * @return bool
     */
    private function validateIpWhitelist(): bool
    {
        $allowAll = $this->scopeConfig->isSetFlag(
            self::SHARED_CONFIG_PREFIX . '/notification_ip_allow_all',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        if ($allowAll) {
            return true;
        }

        $rawWhitelist = (string) $this->scopeConfig->getValue(
            self::SHARED_CONFIG_PREFIX . '/notification_ip_whitelist',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        if (trim($rawWhitelist) === '') {
            $this->logger->warning('[Oceanpayment] Notification: IP whitelist is empty and allow_all is disabled, rejecting all');
            return false;
        }

        $whitelist = array_map('trim', explode("\n", $rawWhitelist));
        $whitelist = array_filter($whitelist, static fn(string $ip): bool => $ip !== '');
        $whitelist = array_unique($whitelist);

        $remoteIp = $this->getRemoteIp();

        return in_array($remoteIp, $whitelist, true);
    }

    /**
     * 获取请求来源 IP
     *
     * @return string
     */
    private function getRemoteIp(): string
    {
        return (string) $this->request->getServer('REMOTE_ADDR', '');
    }
}
