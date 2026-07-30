<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Validator\ValidatorInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Gateway\Command\CommandException;
use MslPay\LianLian\Gateway\Validator\Request\RequiredFieldValidatorInterface;
use MslPay\LianLian\Model\Data\LianLianPayment;
use Psr\Log\LoggerInterface;

/**
 * 通用管道-过滤器网关命令
 *
 * 参考 Braintree GatewayCommand 架构：
 * Builder → TransferFactory → Client → Validator → Handler
 *
 * 职责分离：
 * - Builder: 只管构建请求数据，无副作用
 * - TransferFactory: 负责签名 + headers + uri，包装为 Transfer 对象
 * - Client: 只管发送 HTTP 请求，返回原始响应
 * - Validator: 只管验证响应，返回 ResultInterface
 * - Handler: 只管处理响应数据，更新 Payment 状态
 *
 * 业务逻辑（缓存、登录状态）通过 Handler 实现，不在 Command 中
 * 签名在 TransferFactory 中完成，不在 Command 中
 */
class GatewayCommand implements CommandInterface
{
    /**
     * @param BuilderInterface $requestBuilder 构建请求数据
     * @param TransferFactoryInterface $transferFactory 签名+headers+uri，包装为 Transfer
     * @param ClientInterface $client 发送 HTTP 请求
     * @param ValidatorInterface|null $validator 验证响应（签名+返回码）
     * @param HandlerInterface|null $handler 处理响应数据，更新 Payment 状态
     * @param RequiredFieldValidatorInterface|null $requestValidator 请求必填字段验证（发送前校验）
     * @param LoggerInterface|null $logger 日志记录
     */
    public function __construct(
        private readonly BuilderInterface $requestBuilder,
        private readonly TransferFactoryInterface $transferFactory,
        private readonly ClientInterface $client,
        private readonly ?ValidatorInterface $validator = null,
        private readonly ?HandlerInterface $handler = null,
        private readonly ?RequiredFieldValidatorInterface $requestValidator = null,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * 执行管道：Builder → RequestValidator → TransferFactory → Client → Validator → Handler
     *
     * @param array $commandSubject 命令参数，包含 'payment' 和 'amount'
     * @throws CommandException 验证失败时抛出
     */
    public function execute(array $commandSubject): void
    {
        /* 1. Builder 构建请求数据 */
        $request = $this->requestBuilder->build($commandSubject);

        /* 2. RequestValidator 验证必填字段（发送前校验，避免因缺字段被 API 拒绝） */
        if ($this->requestValidator !== null) {
            $payment = new LianLianPayment();
            $payment->addData($request);
            $this->requestValidator->validate($payment);
        }
       
        /* 3. TransferFactory 签名 + headers + uri → Transfer 对象 */
        $transfer = $this->transferFactory->create($request);

        /* 4. Client 发送 HTTP 请求，返回原始响应 */
        $response = $this->client->placeRequest($transfer);

        /* 5. Validator 验证响应（签名 + return_code） */
        if ($this->validator !== null) {
            $result = $this->validateResponse($commandSubject, $response);
            if (!$result->isValid()) {
                throw new CommandException(
                    __(implode('; ', $result->getFailsDescription()))
                );
            }
        }


        /* 6. Handler 处理响应数据，更新 Payment 状态 */
        if ($this->handler !== null) {
            $this->handler->handle($commandSubject, $response);
        }
    }

    /**
     * 验证响应，合并 commandSubject 和 response 传入 Validator
     */
    private function validateResponse(array $commandSubject, array $response)
    {
        return $this->validator->validate(
            array_merge($commandSubject, ['response' => $response])
        );
    }
}