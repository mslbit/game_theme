/**
 * Folix Game Theme - Banner Placeholder
 *
 * PageBuilder 背景图（slider/hero）无 <img> 标签，浏览器无法预留空间，
 * 慢网络下长时间空白。此模块预载背景图，加载完成后给容器加 bg-loaded
 * class，配合 _slider-skeleton.less 的骨架屏实现占位与淡入。
 */
define([], function () {
    'use strict';

    var MOBILE_BREAKPOINT = '(max-width: 767px)',
        ATTR_IMAGES = 'data-background-images',
        FLAG = 'folixBgTracked';

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

        if (wrapper.dataset[FLAG]) {
            return;
        }
        url = pickUrl(wrapper);
        wrapper.dataset[FLAG] = '1';
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

    function trackAll() {
        document.querySelectorAll('[' + ATTR_IMAGES + ']').forEach(track);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', trackAll);
    } else {
        trackAll();
    }

    var wasMobile = isMobile(),
        timer = null;

    window.addEventListener('resize', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            if (isMobile() !== wasMobile) {
                wasMobile = isMobile();
                document.querySelectorAll('[' + ATTR_IMAGES + ']').forEach(function (wrapper) {
                    delete wrapper.dataset[FLAG];
                    track(wrapper);
                });
            }
        }, 200);
    });

    return function () {};
});