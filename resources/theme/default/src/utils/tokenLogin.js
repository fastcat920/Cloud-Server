/**
 * 令牌验证登录功能
 * 用于处理通过URL参数verify进行的自动登录
 */

import { useToast } from '@/composables/useToast';
import { useI18n } from 'vue-i18n';
import NProgress from 'nprogress';
import { tokenLogin, handleLoginSuccess, forceLogout } from '@/api/auth';
import { handleRedirectPath } from '@/utils/redirectHandler';
import pageCache from '@/utils/pageCache';

// 存储URL参数的全局变量，在页面加载时立即捕获
const initialUrlParams = {
  verifyToken: null,
  redirectPath: null,
  originalUrl: ''
};
let activeTokenLoginPromise = null;

const getCurrentTokenParams = () => {
  const hashPart = window.location.hash || '';
  const queryIndex = hashPart.indexOf('?');
  const hashParams = new URLSearchParams(queryIndex >= 0 ? hashPart.slice(queryIndex + 1) : '');
  const pageParams = new URLSearchParams(window.location.search);

  return {
    verifyToken: hashParams.get('verify') || pageParams.get('verify'),
    redirectPath: hashParams.get('redirect') || pageParams.get('redirect')
  };
};

// 在模块加载时立即执行，捕获原始URL参数
(function captureInitialUrlParams() {
  try {
    // 保存原始URL
    initialUrlParams.originalUrl = window.location.href;
    
    const tokenParams = getCurrentTokenParams();
    initialUrlParams.verifyToken = tokenParams.verifyToken;
    initialUrlParams.redirectPath = tokenParams.redirectPath;
    
    // console.log('页面加载时捕获的URL参数:', initialUrlParams);
  } catch (error) {
    // console.error('捕获初始URL参数时出错:', error);
  }
})();

/**
 * 处理令牌验证登录
 * @param {Object} options - 配置选项
 * @param {Function} options.onLoginSuccess - 登录成功后的回调函数
 * @returns {Promise<Object>} 登录结果对象
 */
const performTokenLogin = async (options = {}) => {
  const { showToast } = useToast();
  const { t } = useI18n();
  
  // 当前 URL 必须优先，首次捕获值仅用于兜底，避免同一 SPA 会话复用已消费的旧令牌。
  const currentTokenParams = getCurrentTokenParams();
  let verifyToken = currentTokenParams.verifyToken || initialUrlParams.verifyToken;
  let redirectPath = currentTokenParams.redirectPath || initialUrlParams.redirectPath;

  // 令牌已经复制到本次调用的局部变量，立即清除模块级兜底，防止后续账号切换误用。
  initialUrlParams.verifyToken = null;
  initialUrlParams.redirectPath = null;
  
  // console.log('处理令牌登录使用的参数:', { verifyToken, redirectPath, initialParams: initialUrlParams });
  
  // 设置默认的重定向路径
  redirectPath = redirectPath || '/dashboard';
  
  // 如果没有验证令牌，直接返回
  if (!verifyToken) {
    return { success: false, verified: false };
  }
  
  // 显示加载进度条
  NProgress.start();
  
  try {
    // 调用验证令牌登录API
    const response = await tokenLogin(verifyToken, redirectPath);
    
    // 验证成功，返回格式为 { data: { token, auth_data }, message: "..." }
    if (response.data && (response.data.token || response.data.auth_data)) {
      // 到这里才清理旧账号：验证失败时仍保留原账号，验证成功时执行原子切换。
      forceLogout();
      pageCache.resetCache();
      window._lastLoginCheck = false;
      window._lastLoginCheckTime = 0;

      // 显示成功消息，优先使用API返回的message
      showToast(response.message || t('auth.verifyTokenSuccess'), 'success');
      
      // 处理登录成功后的认证数据存储 (类似于普通登录)
      // 将token和auth_data存入cookie和localStorage，24小时有效期
      const loginResult = handleLoginSuccess(response.data, false); // false表示不是"记住我"登录，使用24小时有效期
      
      if (!loginResult.success) {
        // console.error('令牌登录认证数据处理失败', loginResult.error);
      }
      
      // 使用重定向处理工具函数处理路径
      const targetPath = handleRedirectPath(redirectPath);
      
      // 登录成功回调
      if (options.onLoginSuccess && typeof options.onLoginSuccess === 'function') {
        options.onLoginSuccess();
      }
      
      // 使用干净URL重新加载，清除旧账号的KeepAlive、接口缓存和verify参数。
      setTimeout(() => {
        const targetUrl = new URL(window.location.href);
        targetUrl.searchParams.delete('verify');
        targetUrl.searchParams.delete('redirect');
        targetUrl.hash = `#${targetPath.startsWith('/') ? targetPath : `/${targetPath}`}`;
        window.location.replace(targetUrl.toString());
      }, 500);
      
      return { success: true, verified: true, redirectPath: targetPath };
    } else {
      // 验证失败但API正常响应
      showToast(t('auth.verifyTokenFailed'), 'error');
      return { success: false, verified: true, error: t('auth.verifyTokenFailed') };
    }
  } catch (error) {
    // API请求错误
    // console.error('Token verification failed', error);
    // 优先使用国际化翻译，而不是API返回的错误消息
    showToast(t('auth.verifyTokenFailed'), 'error');
    return { 
      success: false, 
      verified: true, 
      error: t('auth.verifyTokenFailed')
    };
  } finally {
    // 完成加载进度条
    NProgress.done();
  }
};

export const handleTokenLogin = (options = {}) => {
  if (activeTokenLoginPromise) return activeTokenLoginPromise;

  window.__tokenLoginInProgress = true;
  const loginPromise = performTokenLogin(options);
  activeTokenLoginPromise = loginPromise;

  const clearActiveLogin = () => {
    if (activeTokenLoginPromise === loginPromise) {
      activeTokenLoginPromise = null;
      window.__tokenLoginInProgress = false;
    }
  };
  loginPromise.then(clearActiveLogin, clearActiveLogin);

  return loginPromise;
};

/**
 * 检查URL中是否包含验证令牌
 * @returns {Boolean} 是否包含验证令牌
 */
export const hasVerifyToken = () => {
  const currentTokenParams = getCurrentTokenParams();
  return Boolean(currentTokenParams.verifyToken || initialUrlParams.verifyToken);
}; 
