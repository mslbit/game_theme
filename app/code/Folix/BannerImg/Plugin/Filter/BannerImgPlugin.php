<?php
/**
 * Copyright © Folix. All rights reserved.
 */
declare(strict_types=1);

namespace Folix\BannerImg\Plugin\Filter;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Magento\Framework\Filter\Template;
use Psr\Log\LoggerInterface;

/**
 * 服务端把 PageBuilder 背景图渲染为 <img> 标签
 *
 * 挂在 Magento\Framework\Filter\Template::filter() 之后（PageBuilder 官方
 * TemplatePlugin 生成背景图 <style> 的同一条链），输出到浏览器的 HTML
 * 直接携带真实 <img>：加载时机最早、原生网络行为、可被 FPC 缓存。
 *
 * 转换规则：
 * - 目标节点：所有 data-background-images 非 "{}" 的元素（slide wrapper、
 *   hero poster 等；row 的空占位跳过）
 * - data-background-images 属性保留不动（PageBuilder 前台 JS 兼容 +
 *   背景图样式作为 <img> 失败时的兜底层）
 * - desktop_image → <img class="folix-banner-img folix-banner-desktop">
 *   mobile_image → <img class="folix-banner-img folix-banner-mobile">
 *   （显隐 media query 由主题 CSS 控制，不依赖 vendor 编译完整性）
 * - 第一个 slide 的 desktop 图加 fetchpriority="high"（LCP 优化）
 */
class BannerImgPlugin
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * 渲染 <img> 后返回
     *
     * @param Template $subject
     * @param string $result
     * @return string
     */
    public function afterFilter(Template $subject, string $result): string
    {
        if ($result === '' || strpos($result, 'data-background-images') === false) {
            return $result;
        }

        try {
            return $this->renderImgTags($result);
        } catch (\Exception $e) {
            $this->logger->error('Folix_BannerImg: ' . $e->getMessage(), ['exception' => $e]);
            return $result;
        }
    }

    /**
     * DOM 层转换：向背景图容器插入 <img>
     *
     * @param string $html
     * @return string
     */
    private function renderImgTags(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $converted = mb_encode_numericentity(
            $html,
            [0x80, 0x10FFFF, 0, 0x1FFFFF],
            'UTF-8'
        );
        $document->loadHTML(
            '<html><body>' . $converted . '</body></html>',
            LIBXML_SCHEMA_CREATE
        );
        libxml_clear_errors();

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*[@data-background-images and normalize-space(@data-background-images) != "{}"]');

        $isFirstSlide = true;
        foreach ($nodes as $node) {
            /* @var DOMElement $node */
            $images = $this->decodeImages($node->getAttribute('data-background-images'));
            if (empty($images['desktop_image']) && empty($images['mobile_image'])) {
                continue;
            }
            $this->insertImg($node, $images, $isFirstSlide);
            $isFirstSlide = false;
        }

        preg_match('/<body>(.+)<\/body><\/html>$/si', $document->saveHTML(), $matches);
        return !empty($matches) ? $matches[1] : $html;
    }

    /**
     * 解析 data-background-images JSON（PageBuilder 存储值可能带反斜杠转义）
     *
     * @param string $value
     * @return array
     */
    private function decodeImages(string $value): array
    {
        $images = json_decode(stripslashes($value), true);
        return is_array($images) ? $images : [];
    }

    /**
     * 创建并插入 <img>（保持 wrapper 内部原有结构不变，仅插到最前）
     *
     * @param DOMElement $wrapper
     * @param array $images
     * @param bool $isFirstSlide
     * @return void
     */
    private function insertImg(DOMElement $wrapper, array $images, bool $isFirstSlide): void
    {
        if (!empty($images['desktop_image'])) {
            $desktop = $this->createImg(
                $wrapper->ownerDocument,
                (string) $images['desktop_image'],
                'folix-banner-img folix-banner-desktop',
                !$isFirstSlide
            );
            if ($isFirstSlide) {
                $desktop->setAttribute('fetchpriority', 'high');
            }
            $wrapper->insertBefore($desktop, $wrapper->firstChild);
        }
        if (!empty($images['mobile_image'])) {
            $mobile = $this->createImg(
                $wrapper->ownerDocument,
                (string) $images['mobile_image'],
                'folix-banner-img folix-banner-mobile',
                !$isFirstSlide
            );
            $wrapper->insertBefore($mobile, $wrapper->firstChild);
        }
    }

    /**
     * 创建 <img> 元素
     *
     * 非首 slide 加 loading="lazy"：slick fade 下可见端图几何均在视口内
     * （lazy 对其无作用、立即加载，轮播切换无等待），而 display:none 的
     * 另一分辨率图（桌面端 mobile 图 / 移动端 desktop 图）因 lazy 不发起
     * 请求——首访图片流量近乎减半。首 slide 保持 eager：LCP 图禁用 lazy。
     * lazy 判定正确性依赖显隐规则在内联 critical CSS 中（HTML 解析时已生效）。
     *
     * @param DOMDocument $document
     * @param string $src
     * @param string $class
     * @param bool $lazy
     * @return DOMElement
     */
    private function createImg(DOMDocument $document, string $src, string $class, bool $lazy): DOMElement
    {
        $img = $document->createElement('img');
        $img->setAttribute('src', $src);
        $img->setAttribute('class', $class);
        $img->setAttribute('decoding', 'async');
        $img->setAttribute('alt', '');
        if ($lazy) {
            $img->setAttribute('loading', 'lazy');
        }

        return $img;
    }
}