define(function() {

    'use strict';

    function isXMLDocument(obj) {
       return obj instanceof Document && obj.documentElement.nodeName === 'parsererror';
    }
    return function(rawData) {

        if (typeof rawData === 'object') return rawData;
       // 1. 数据清洗：移除 xml 声明，去掉 RESPONSE 关键字和尾部的 RESPONSEEND
            let cleanXml = rawData
                .replace(/<\?xml[^>]*\?>/i, '') // 移除可能存在的 XML 声明头
                .replace('RESPONSE', '')
                .replace('RESPONSEEND', '')
                .trim();
            const parser = new DOMParser();
            const xmlDoc = parser.parseFromString(cleanXml, "application/xml");

            const resultObject = {};
            const responseNode = xmlDoc.querySelector('response');
            if (responseNode) {
                Array.from(responseNode.children).forEach(child => {
                    resultObject[child.tagName] = child.textContent;
                });
        }

            console.log("转换后的对象：", resultObject);
            return resultObject;
    }

    
});