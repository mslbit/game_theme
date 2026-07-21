<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Service;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Oceanpayment\Payment\Gateway\Config\Config;
use Oceanpayment\Payment\Gateway\Helper\SignatureHelper;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 自动重定向服务
 *
 * 托管结账自动重定向模式：服务器 POST 到 /gateway/service/pay，
 * Oceanpayment 返回 302 重定向到支付页（或 XML 响应含 pay_url）。
 *
 * 数据收集与签名计算与 SendTradeService 完全一致，
 * 仅端点不同（/pay vs /sendTrade），响应处理不同。
 */
class AutoRedirectService
{
    private const URI_PAY = '/gateway/service/pay';
    private const PAY_RESULTS_SUCCESS = 1;

    private const SIGN_FIELDS = [
        'account',
        'terminal',
        'backUrl',
        'order_number',
        'order_currency',
        'order_amount',
        'billing_firstName',
        'billing_lastName',
        'billing_email',
    ];

    private BuilderInterface $orderBuilder;
    private BuilderInterface $customerBuilder;
    private Config $config;
    private SignatureHelper $signatureHelper;
    private TransferFactoryInterface $transferFactory;
    private ClientInterface $client;
    private LoggerInterface $logger;

    public function __construct(
        BuilderInterface $orderBuilder,
        BuilderInterface $customerBuilder,
        Config $config,
        SignatureHelper $signatureHelper,
        TransferFactoryInterface $transferFactory,
        ClientInterface $client,
        LoggerInterface $logger
    ) {
        $this->orderBuilder = $orderBuilder;
        $this->customerBuilder = $customerBuilder;
        $this->config = $config;
        $this->signatureHelper = $signatureHelper;
        $this->transferFactory = $transferFactory;
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * POST 到 /gateway/service/pay，处理 302 重定向或 XML 响应
     *
     * @param PaymentDataObjectInterface $paymentDO
     * @return array 包含 redirect_url
     * @throws \RuntimeException
     */
    public function execute(PaymentDataObjectInterface $paymentDO): array
    {
        $buildSubject = ['payment' => $paymentDO];

        $request = array_merge(
            $this->orderBuilder->build($buildSubject),
            $this->customerBuilder->build($buildSubject)
        );

        $request['signValue'] = $this->signatureHelper->calculateSignature(
            self::SIGN_FIELDS,
            $request,
            $this->config->getSecureCode()
        );

        $transfer = $this->transferFactory->create($request, self::URI_PAY);
        $this->client->placeRequest($transfer);

        return $this->parseResponse($response, $paymentDO);
    }

    private function parseResponse(array $response, PaymentDataObjectInterface $paymentDO): array
    {
        /* 302 重定向：Client 返回 redirect_url */
        $redirectUrl = $response['redirect_url'] ?? '';
        if (!empty($redirectUrl)) {
            return ['redirect_url' => $redirectUrl];
        }

        /* XML 响应：含 pay_url */
        $payUrl = $response['pay_url'] ?? '';
        if (!empty($payUrl)) {
            return ['redirect_url' => $payUrl];
        }

        $order = $paymentDO->getOrder();
        $this->logger->error('[Oceanpayment] AutoRedirect failed', [
            'order_id' => $order->getOrderIncrementId(),
            'response' => $response,
        ]);

        throw new \RuntimeException('Oceanpayment auto redirect failed: no redirect URL returned');
    }
}
