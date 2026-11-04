/**
 * 应用初始化文件（在后台主题配置注入完成后被动态导入）
 * 原先 src/main.js 中的全部内容均迁移至此
 */

// 定义 Vue 功能标志
window.__VUE_OPTIONS_API__ = true;
window.__VUE_PROD_DEVTOOLS__ = false;
window.__VUE_PROD_HYDRATION_MISMATCH_DETAILS__ = false;

import { createApp } from 'vue';
import App from './App.vue';
import router from './router';
import store from './store';
import i18n from './i18n';
import { MotionPlugin } from '@vueuse/motion';
import { useToast } from './composables/useToast';
import accessibleDialog from './directives/accessibleDialog';
// 导入页面标题设置功能
import initPageTitle from './utils/exposeConfig';
// default 是项目内置主题，不执行第三方主题授权和反调试逻辑。

// 初始化异步流程
const initApp = async () => {
  try {
    // 设置页面标题
    initPageTitle();

    // 导入全局样式
    await import('./assets/styles/index.scss');

    // 创建应用实例
    const app = createApp(App);
    app.directive('accessible-dialog', accessibleDialog);

    // 创建全局Toast实例
    const toast = useToast();

    // 提供全局Toast服务
    app.provide('$toast', toast);

    // 使用插件
    app.use(router)
       .use(store)
       .use(i18n)
       .use(MotionPlugin);

    // 挂载应用
    app.mount('#app');

    // 初始化用户信息
    store.dispatch('initUserInfo');
  } catch (error) {
    console.error('应用初始化失败:', error);
  }
};

// 开始初始化应用
initApp();

// 导出router实例到window对象，以便在i18n中访问
window.router = router; 
