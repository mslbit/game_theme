<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use MslPay\LianLian\Gateway\Config\Config;
use MslPay\LianLian\Gateway\Helper\LianLianSignatureHelper;

class ResponseValidator extends AbstractValidator
{
    private Config $config;
    private LianLianSignatureHelper $signatureHelper;

    public function __construct(
        ResultInterfaceFactory $resultFactory,
        Config $config,
        LianLianSignatureHelper $signatureHelper
    ) {
        parent::__construct($resultFactory);
        $this->config = $config;
        $this->signatureHelper = $signatureHelper;
    }

    /**
     * 验证连连支付响应
     *
     * @param array $validationSubject 包含 'response' => ['body' => ..., 'signature' => ...]
     * @return ResultInterface
     */
    public function validate(array $validationSubject): ResultInterface
    {
        $response = $validationSubject['response'] ?? [];
        $responseBody = $response['body'] ?? [];
        $responseSignature = $response['signature'] ?? '';

        /* 第一层：验证响应签名（连连私钥签名，用连连公钥验签） */
        if (!empty($responseSignature) && !empty($responseBody)) {
            if (!$this->signatureHelper->verify(
                $responseBody,
                $responseSignature,
                $this->config->getLianLianPublicKey()
            )) {
                return $this->createResult(
                    false,
                    [__('LianLian response signature verification failed.')]
                );
            }
        }

        /* 第二层：检查返回码 */
        $returnCode = $responseBody['return_code'] ?? '';
        if ($returnCode !== 'SUCCESS') {
            $returnMessage = $responseBody['return_message'] ?? 'Unknown error';
            $declineCode = $responseBody['decline_code'] ?? '';
            return $this->createResult(
                false,
                [__('LianLian payment failed: %1 %2', $declineCode, $returnMessage)]
            );
        }

        return $this->createResult(true, []);
    }
}