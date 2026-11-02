/**
 * Folix Game Theme - Banner Placeholder
 *
 * PageBuilder 背景图（slider/hero）无 <img> 标签，浏览器无法预留空间，
 * 慢网络下长时间空白。此模块预载背景图，加载完成后给容器加 bg-loaded
 * class，配合 _slider-skeleton.less 的骨架屏实现占位与淡入。
 *
 * 状态仅以 bg-loaded class 表示（不用 data-* 标记）：
 * slick 无限轮播会 cloneNode(true) 复制幻灯片，克隆节点若发生在图片
 * 加载完成前，会复制不到加载状态——由 MutationObserver 对新增节点
 * 重新预载补齐（同 URL 预载走缓存，无额外开销）。
 */
define([], function () {
    'use strict';

    var MOBILE_BREAKPOINT = '(max-width: 767px)',
        ATTR_IMAGES = 'data-background-images';

    function isMobile() {
        return window.matchMedia(MOBILE_BREAKPOINT).matches;
    }

    function pickUrl(wrapper) {
        var raw = wrapper.getAttribute(ATTR_IMAGES),
            data;

        if (!raw) {
            return '';
        }
        try {
            data = JSON.parse(raw);
        } catch (e) {
            return '';
        }
        if (isMobile() && data.mobile_image) {
            return data.mobile_image;
        }
        return data.desktop_image || data.mobile_image || '';
    }

    function track(wrapper) {
        var url, img;

        if (wrapper.classList.contains('bg-loaded')) {
            return;
        }
        url = pickUrl(wrapper);
        if (!url) {
            wrapper.classList.add('bg-loaded');
            return;
        }
        img = new Image();
        img.onload = function () {
            wrapper.classList.add('bg-loaded');
        };
        img.onerror = function () {
            wrapper.classList.add('bg-loaded');
        };
        img.src = url;
    }

    function trackIn(node) {
        var i;

        if (!node || node.nodeType !== 1) {
            return;
        }
        if (node.hasAttribute(ATTR_IMAGES)) {
            track(node);
        }
        if (node.querySelectorAll) {
            node.querySelectorAll('[' + ATTR_IMAGES + ']').forEach(track);
        }
    }

    function trackAll() {
        document.querySelectorAll('[' + ATTR_IMAGES + ']').forEach(track);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', trackAll);
    } else {
        trackAll();
    }

    if ('MutationObserver' in window && document.body) {
        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                var i;

                for (i = 0; i < m.addedNodes.length; i++) {
                    trackIn(m.addedNodes[i]);
                }
            });
        }).observe(document.body, {childList: true, subtree: true});
    }

    var wasMobile = isMobile(),
        timer = null;

    window.addEventListener('resize', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            if (isMobile() !== wasMobile) {
                wasMobile = isMobile();
                document.querySelectorAll('[' + ATTR_IMAGES + ']').forEach(function (wrapper) {
                    wrapper.classList.remove('bg-loaded');
                    track(wrapper);
                });
            }
        }, 200);
    });

    return function () {};
});