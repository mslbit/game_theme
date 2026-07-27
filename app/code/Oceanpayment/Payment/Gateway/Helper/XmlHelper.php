<?php
/**
 * Oceanpayment XML Parser Helper
 *
 * 提供统一的 XML 解析功能，将 XML 字符串转换为数组。
 * 使用 DOMDocument 替代 SimpleXML，兼容 PHP 8.4+。
 */
declare(strict_types=1);

namespace Oceanpayment\Payment\Gateway\Helper;

class XmlHelper
{
    /**
     * 解析 XML 字符串为关联数组
     *
     * @param string $xmlString XML 字符串
     * @return array 关联数组
     * @throws \RuntimeException
     */
    public function parse(string $xmlString): array
    {
        if (empty($xmlString)) {
            throw new \RuntimeException('XML content is empty');
        }

        $previousUseErrors = libxml_use_internal_errors(true);

        try {
            $dom = new \DOMDocument();
            $dom->substituteEntities = false;
            
            if (!$dom->loadXML($xmlString, LIBXML_NONET)) {
                $errors = libxml_get_errors();
                $errorMessages = array_map(static function (\LibXMLError $error) {
                    return trim($error->message);
                }, $errors);
                libxml_clear_errors();

                throw new \RuntimeException(
                    'Failed to parse XML: ' . implode('; ', $errorMessages)
                );
            }

            $parsed = $this->domToArray($dom->documentElement);
            return is_array($parsed) ? $parsed : [];

        } finally {
            libxml_use_internal_errors($previousUseErrors);
        }
    }

    /**
     * 将 DOMElement 转换为关联数组
     *
     * @param \DOMElement $element DOM 元素
     * @return array|string
     */
    private function domToArray(\DOMElement $element)
    {
        $result = [];
        
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $value = $this->domToArray($child);
                $key = $child->nodeName;
                
                if (isset($result[$key])) {
                    if (!is_array($result[$key]) || !isset($result[$key][0])) {
                        $result[$key] = [$result[$key]];
                    }
                    $result[$key][] = $value;
                } else {
                    $result[$key] = $value;
                }
            }
        }
        
        if (empty($result)) {
            return trim($element->nodeValue);
        }

        return $result;
    }
}
