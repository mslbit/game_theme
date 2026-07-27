<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Request\Builder;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Oceanpayment\Payment\Gateway\Config\Config;
use Oceanpayment\Payment\Gateway\Helper\SignatureHelper;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 订单状态查询请求构建器
 *
 * 构建 /service/check/normal 接口的请求参数和签名。
 *
 * 请求参数：account, terminal, order_number, signValue
 * 签名字段顺序：SHA256(account + terminal + order_number + secureCode)
 */
class OrderQueryBuilder implements BuilderInterface
{
    /**
     * 订单查询签名字段顺序
     * 文档：account+terminal+order_number+secureCode
     */
    private const SIGN_FIELDS = [
        'account',
        'terminal',
        'order_number',
    ];

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var SignatureHelper
     */
    private SignatureHelper $signatureHelper;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param SignatureHelper $signatureHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        SignatureHelper $signatureHelper,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->signatureHelper = $signatureHelper;
        $this->logger = $logger;
    }

    /**
     * 构建订单查询请求参数
     *
     * @param array $buildSubject 构建参数
     * @return array 查询请求参数
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $order = $paymentDO->getOrder();

        $request = [
            'account'      => $this->config->getAccount(),
            'terminal'     => $this->config->getTerminal(),
            'order_number' => $order->getOrderIncrementId(),
        ];

        /* 计算签名 */
        $request['signValue'] = $this->signatureHelper->calculateSignature(
            self::SIGN_FIELDS,
            $request,
            $this->config->getSecureCode()
        );

        $this->logger->info('[Oceanpayment] OrderQueryBuilder: Built query request', [
            'order_number' => $request['order_number'],
        ]);

        return $request;
    }
}