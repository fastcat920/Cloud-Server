/**
 * 认证工具函数
 */
import { checkLoginStatus } from '@/api/auth';

// 存储上次检查的登录状态，用于检测状态变化
let lastLoginState = null;

/**
 * 检查登录状态并确保i18n消息正确加载
 * @returns {boolean} 当前是否已登录
 */
export const checkAuthAndReloadMessages = () => {
  const isLoggedIn = checkLoginStatus();
  
  // 如果登录状态发生变化，或者首次检查，就重新加载语言
  if (lastLoginState === null || lastLoginState !== isLoggedIn) {
    // 更新状态缓存
    lastLoginState = isLoggedIn;
    
    // 确保i18n消息与当前登录状态一致
    try {
      // 使用异步方式调用，但不等待完成
      setTimeout(async () => {
        try {
          const { reloadMessages } = await import('@/i18n');
          await reloadMessages();
        } catch (asyncError) {
          // 安静地处理错误
        }
      }, 10);
    } catch (error) {
      // 安静地处理错误
    }
  }
  
  return isLoggedIn;
};

/**
 * 检查URL是否为认证相关页面
 * @param {string} path 路径
 * @returns {boolean} 是否为认证页面
 */
export const isAuthPage = (path) => {
  return /\/(login|register|forgot-password)/.test(path);
};

/**
 * 监听登录状态变化
 */
export const setupLoginStateWatcher = () => {
  // 跟踪上一次的登录状态
  let lastLoginState = null;
  
  // 每60秒检查登录状态
  setInterval(() => {
    const isLoggedIn = checkLoginStatus();
    
    // 如果登录状态发生变化
    if (lastLoginState !== null && lastLoginState !== isLoggedIn) {
      // 登录状态已变化，重新加载i18n文件
      Promise.resolve().then(function() { return import('@/i18n'); })
        .then(({ reloadMessages }) => {
          reloadMessages().catch(() => {
            // 安静地处理错误
          });
        }).catch(() => {
          // 安静地处理错误
        });
    }
    
    // 更新上一次状态
    lastLoginState = isLoggedIn;
  }, 60000); // 每60秒检查一次
}; 