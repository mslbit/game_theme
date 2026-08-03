<?php
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Helper;

use Magento\Store\Model\StoreManagerInterface;

/**
 * Oceanpayment 签名与 URL 集中管理
 *
 * 所有签名计算、字符过滤、回调 URL 构建的唯一入口：
 * - filterSpecialChars(): 特殊字符过滤（Oceanpayment 全局统一规则）
 * - calculateSignature():  通用 SHA256 签名计算（支持不同字段顺序）
 * - buildBackUrl():        支付完成返回 URL
 * - buildCheckoutBackUrl(): 嵌入式支付返回 URL（结账页）
 * - buildNoticeUrl():      异步通知回调 URL
 *
 * 使用场景：
 * - SendTradeCommand:       托管支付签名（含 backUrl）
 * - OrderQueryBuilder:      订单查询签名
 * - CheckoutData:           嵌入式支付前端表单签名
 * - CallbackProcessor:      回调/通知签名验证
 */
class SignatureHelper
{
    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(StoreManagerInterface $storeManager)
    {
        $this->storeManager = $storeManager;
    }

    /**
     * 过滤特殊字符
     *
     * Oceanpayment 全局统一规则：
     * - 去除首尾空白
     * - 将 < > ' " 替换为空格
     *
     * @param string $value 原始值
     * @return string 过滤后的值
     */
    public function filterSpecialChars(string $value): string
    {
        $value = trim($value);
        $value = str_replace(['<', '>', "'", '"'], ' ', $value);

        return $value;
    }

    /**
     * 计算 SHA256 签名
     *
     * 按指定字段顺序从数据中提取值，拼接后追加 secureCode，计算 SHA256 哈希。
     * 不同场景（sendTrade / 回调）使用不同的字段顺序，
     * 但签名算法统一：SHA256(filter(field1) + filter(field2) + ... + filter(secureCode))
     *
     * @param array  $fields     签名字段名列表（按顺序）
     * @param array  $data       数据源（键值对）
     * @param string $secureCode 安全校验码
     * @return string SHA256 哈希值
     */
    public function calculateSignature(array $fields, array $data, string $secureCode): string
    {
        $parts = [];

        foreach ($fields as $field) {
            $parts[] = $this->filterSpecialChars((string) ($data[$field] ?? ''));
        }

        /* 追加 secureCode（不参与请求参数，仅用于签名） */
        $parts[] = $this->filterSpecialChars($secureCode);
        file_put_contents(BP.'/var/signature.log', implode('', $parts).PHP_EOL, FILE_APPEND);
        return hash('sha256', implode('', $parts));
    }

    /**
     * 构建支付完成返回 URL
     *
     * 用户在 Oceanpayment 页面完成支付后跳回商城的 URL
     * iframe=1 标识前端以 iframe 弹窗模式发起支付，Back 控制器据此返回 postMessage 页面
     * 格式：https://www.example.com/oceanpayment/payment/back
     *
     * @return string
     */
    public function buildBackUrl(): string
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        return rtrim($baseUrl, '/') . '/oceanpayment/payment/back';
    }

    /**
     * 构建嵌入式支付返回 URL（结账页）
     *
     * 3D 验证完成后跳回结账页，前端据此判断支付结果
     * 格式：https://www.example.com/checkout/index/index
     *
     * @return string
     */
    public function buildCheckoutBackUrl(): string
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        return rtrim($baseUrl, '/') . '/checkout/index/index';
    }

    /**
     * 构建异步通知 URL
     *
     * Oceanpayment 服务端推送支付结果的 REST API 回调地址
     * 格式：https://www.example.com/rest/V1/oceanpayment/notice
     *
     * @return string
     */
    public function buildNoticeUrl(): string
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        return rtrim($baseUrl, '/') . '/rest/V1/oceanpayment/notice';
    }
}
