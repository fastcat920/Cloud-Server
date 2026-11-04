/**
 * API可用性检测器
 * 检测主题内置配置中的多个 API 地址，使用第一个可用的地址
 */
// 注意：此模块在应用启动早期会被 baseConfig 引用，
// 如果在此处直接静态导入 router 会导致循环依赖，
// 进而出现 DEFAULT_CONFIG 未定义等问题。
// 因此改为按需异步加载 router，避免模块初始化顺序冲突。

let _router = null;

/**
 * 获取 router 实例（懒加载）
 * @returns {Promise<import('vue-router').Router>} router 实例
 */
const getRouterInstance = async () => {
  if (_router) return _router;
  // 动态导入 router 模块（默认导出为实例）
  const mod = await import('@/router');
  _router = mod.default || mod.router || mod;
  return _router;
};

/**
 * 检查是否需要进行API可用性检测
 * @returns {boolean} 是否需要检测
 */
function shouldCheckApiAvailability() {
  // 获取配置
  if (typeof window === 'undefined' || !window.EZ_CONFIG) return false;
  
  // 如果启用了中间件，不进行检测
  if (window.EZ_CONFIG.API_MIDDLEWARE_ENABLED === true) return false;
  
  // 仅当urlMode为static且staticBaseUrl是长度大于1的数组时才检测
  const apiConfig = window.EZ_CONFIG.API_CONFIG;
  if (!apiConfig || apiConfig.urlMode !== 'static') return false;
  
  // 检查staticBaseUrl是否为数组且长度大于1
  const staticBaseUrls = apiConfig.staticBaseUrl;
  return Array.isArray(staticBaseUrls) && staticBaseUrls.length > 1;
}

/**
 * 获取当前可用的API URL
 * 返回已检测可用的URL或配置的第一个URL
 * @returns {string} API URL
 */
function getAvailableApiUrl() {
  // 如果不需要检测，直接返回配置的URL
  if (!shouldCheckApiAvailability()) {
    if (window.EZ_CONFIG?.API_CONFIG?.staticBaseUrl) {
      const urls = window.EZ_CONFIG.API_CONFIG.staticBaseUrl;
      return Array.isArray(urls) ? urls[0] : urls;
    }
    return '';
  }
  
  // 获取已存储的可用URL
  const availableUrl = sessionStorage.getItem('ez_api_available_url');
  if (availableUrl) {
    return availableUrl;
  }
  
  // 如果没有存储的可用URL，返回配置的第一个URL
  return window.EZ_CONFIG.API_CONFIG.staticBaseUrl[0];
}

/**
 * 初始化API可用性检测
 * 此函数应在应用启动时调用
 * @param {boolean} redirect 是否重定向到API验证页面
 * @returns {Promise<string|null>} 可用的API URL或null
 */
async function initApiAvailabilityChecker(redirect = true) {
  // 检查是否需要进行API可用性检测
  if (!shouldCheckApiAvailability()) {
    return null;
  }
  
  try {
    // 获取已存储的可用URL
    const storedUrl = sessionStorage.getItem('ez_api_available_url');
    if (storedUrl) {
      console.log('使用已验证的API URL:', storedUrl);
      return storedUrl;
    }
    
    // 如果需要重定向且当前不在API验证页面
    if (redirect) {
      const router = await getRouterInstance();
      if (router.currentRoute.value.name !== 'ApiValidation') {
        // 保存当前路由信息以便验证后重定向回来，同时保留当前查询参数
        const { path: currentPath, query: currentQuery } = router.currentRoute.value;

        // 构造跳转到 API 验证页的查询对象，将原查询参数展开传递
        const apiValidationQuery = {
          redirect: currentPath !== '/api-validation' ? currentPath : '/',
          ...currentQuery
        };

        // 重定向到 API 验证页面
        router.push({
          path: '/api-validation',
          query: apiValidationQuery
        });
        return null;
      }
    }
  } catch (error) {
    console.error('API可用性检测初始化失败:', error);
  }
  
  return null;
}

export { 
  getAvailableApiUrl, 
  initApiAvailabilityChecker,
  shouldCheckApiAvailability
};
