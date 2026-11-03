/**
 * Folix Game Theme - RequireJS Configuration
 * 
 * 配置说明：
 * 1. paths: 定义模块路径别名
 * 2. config: 模块配置（如 mixins）
 * 3. shim: 配置非 AMD 模块的依赖关系（一般不需要，jQuery 已内置）
 * 
 * 注意：
 * - Magento 2 主题的 requirejs-config.js 会与父主题和模块的配置合并
 * - 路径相对于 web 目录
 * - 组件名格式: "Magento_Theme/js/xxx" 对应 Magento_Theme/web/js/xxx.js
 */

var config = {

    paths: {
        // 自定义模块路径别名（可选）
        // 'folixSlider': 'Magento_Theme/js/slider'
    },

    shim: {
        // 第三方库 shim 配置（如有需要）
        // 'vendor/some-library': ['jquery']
    },

    map: {
        '*': {
            // 自定义组件别名，不与原生冲突
            'folixMenu': 'Magento_Theme/js/custom-menu',
            'navigationInit': 'Magento_Theme/js/navigation-init'
        }
    },

    
};
