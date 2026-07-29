<?php
declare(strict_types=1);

namespace MslPay\LianLian\Gateway\Helper;

/**
 * 连连支付 RSA 签名与验签助手
 *
 * 签名算法：SHA1withRSA（PKCS8 标准）
 *
 * 签名串构建规则：
 * 1. 递归按参数名字母正序排序 JSON 对象的每一层
 * 2. 展平为 key=value&key=value 格式
 * 3. NULL 值不参与签名，空字符串参与签名
 * 4. 金额精度必须与上送一致（2位小数）
 */
class LianLianSignatureHelper
{
    /**
     * 生成 RSA 签名
     *
     * @param array $params 请求参数
     * @param string $privateKey 商户 RSA 私钥（PKCS8 格式）
     * @return string Base64 编码的签名
     */
    public function sign(array $params, string $privateKey): string
    {
        $signString = $this->buildSignString($params);
        $privateKey = $this->formatPrivateKey($privateKey);

        openssl_sign($signString, $signature, $privateKey, OPENSSL_ALGO_SHA1);

        return base64_encode($signature);
    }

    /**
     * 验证 RSA 签名
     *
     * @param array $params 响应参数
     * @param string $signature 待验证的签名（Base64 编码）
     * @param string $publicKey 连连公钥
     * @return bool
     */
    public function verify(array $params, string $signature, string $publicKey): bool
    {
        $signString = $this->buildSignString($params);

        $publicKey = $this->formatPublicKey($publicKey);

        return openssl_verify($signString, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA1) === 1;
    }

    /**
     * 构建签名串
     *
     * 递归按参数名字母正序排序，展平为 key=value&key=value 格式
     * 规则：
     * - 每层对象按 key 字母排序
     * - 数组内每个对象也按 key 排序
     * - NULL 值跳过，空字符串保留
     * - 嵌套对象展平为 parent_key=child_value（递归处理）
     *
     * @param array $params
     * @return string
     */
    public function buildSignString(array $params): string
    {
        $parts = $this->flattenParams($params);
        return implode('&', $parts);
    }

    /**
     * 递归展平参数为 key=value 对
     *
     * @param array $params 当前层参数
     * @return array 展平后的 key=value 字符串数组
     */
    private function flattenParams(array $params): array
    {
        /* 按参数名字母正序排序 */
        ksort($params);

        $parts = [];

        foreach ($params as $key => $value) {
            /* NULL 值不参与签名 */
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                if ($this->isIndexedArray($value)) {
                    /* 索引数组（如 products 列表）：递归处理每个元素 */
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $subParts = $this->flattenParams($item);
                            foreach ($subParts as $subPart) {
                                $parts[] = $subPart;
                            }
                        }
                    }
                } else {
                    /* 关联对象：递归展平 */
                    $subParts = $this->flattenParams($value);
                    foreach ($subParts as $subPart) {
                        $parts[] = $subPart;
                    }
                }
            } else {
                /* 标量值：直接拼接 key=value */
                $parts[] = $key . '=' . $this->formatValue($value);
            }
        }

        return $parts;
    }

    /**
     * 判断是否为索引数组（列表）
     */
    private function isIndexedArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * 格式化签名值
     *
     * 金额保持2位小数精度
     */
    private function formatValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }
        return (string) $value;
    }

    /**
     * 格式化商户私钥（PKCS8）
     */
    private function formatPrivateKey(string $privateKey): \OpenSSLAsymmetricKey
    {
        if (strpos($privateKey, '-----BEGIN PRIVATE KEY-----') === false) {
            $privateKey = "-----BEGIN RSA PRIVATE KEY-----\n" .
                wordwrap($privateKey, 64, "\n", true) .
                "\n-----END RSA PRIVATE KEY-----";
        }

        return openssl_get_privatekey($privateKey);
    }

    /**
     * 格式化连连公钥
     */
    private function formatPublicKey(string $key): \OpenSSLAsymmetricKey
    {
        if (strpos($key, '-----BEGIN PUBLIC KEY-----') === false) {
            $key = "-----BEGIN PUBLIC KEY-----\n" .
                wordwrap($key, 64, "\n", true) .
                "\n-----END PUBLIC KEY-----";
        }
        return openssl_pkey_get_public($key);
    }

    /**
     * 生成请求 Header
     *
     * @param array $params 请求参数
     * @param string $privateKey 商户私钥
     * @param string $timezone 时区（从 Config.getTimezone() 获取）
     * @return array Header 数组
     */
    public function buildRequestHeaders(array $params, string $privateKey, string $timezone = 'Asia/Hong_Kong'): array
    {
        $dt = new \DateTime('now', new \DateTimeZone($timezone));
        $timestamp = $dt->format('YmdHis');

        return [
            'sign-type'    => 'RSA',
            'signature'    => $this->sign($params, $privateKey),
            'timezone'     => $timezone,
            'timestamp'    => $timestamp,
            'Content-Type' => 'application/json',
        ];
    }
}