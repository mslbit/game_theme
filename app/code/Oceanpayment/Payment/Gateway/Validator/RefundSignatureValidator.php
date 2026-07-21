<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Oceanpayment\Payment\Gateway\Config\Config;
use Oceanpayment\Payment\Gateway\Helper\SignatureHelper;
use Psr\Log\LoggerInterface;

/**
 * Oceanpayment 退款响应签名验证器
 *
 * 验证 Oceanpayment 退款 API 返回的签名是否合法。
 * 签名算法由 SignatureHelper 统一管理，本类仅定义退款响应场景的签名字段顺序。
 *
 * 退款签名字段顺序：
 * SHA256(account + terminal + order_number + order_currency + refund_amount
 *        + refund_reason + payment_id + secureCode)
 */
class RefundSignatureValidator extends AbstractValidator
{
    /**
     * 退款响应签名字段顺序（严格按 Oceanpayment 退款 API 文档）
     */
    private const SIGN_FIELDS = [
        'account',
        'terminal',
        'order_number',
        'order_currency',
        'refund_amount',
        'refund_reason',
        'payment_id',
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
     * @param ResultInterfaceFactory $resultFactory
     * @param Config $config
     * @param SignatureHelper $signatureHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        ResultInterfaceFactory $resultFactory,
        Config $config,
        SignatureHelper $signatureHelper,
        LoggerInterface $logger
    ) {
        parent::__construct($resultFactory);
        $this->config = $config;
        $this->signatureHelper = $signatureHelper;
        $this->logger = $logger;
    }

    /**
     * 验证退款响应签名
     *
     * @param array $validationSubject 验证参数，包含 response 数组
     * @return ResultInterface
     */
    public function validate(array $validationSubject): ResultInterface
    {
        $response = $validationSubject['response'] ?? [];

        $returnedSign = $response['signValue'] ?? '';
        $expectedSign = $this->signatureHelper->calculateSignature(
            self::SIGN_FIELDS,
            $response,
            $this->config->getSecureCode()
        );

        if (strcasecmp($returnedSign, $expectedSign) === 0) {
            return $this->createResult(true, []);
        }

        $this->logger->warning('[Oceanpayment] Refund signature validation failed', [
            'expected_sign' => $expectedSign,
            'returned_sign' => $returnedSign,
        ]);

        return $this->createResult(
            false,
            [__('Oceanpayment refund signature verification failed.')]
        );
    }
}
