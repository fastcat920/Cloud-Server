/**
 * API路径映射工具
 * 用于在中间件模式下将API路径转换为自定义路径
 */

// 路径映射配置（与中间件的配置相对应）
const pathMappings = {
  // 通用接口
  '/guest/comm/config': '/g/conf',
  '/user/comm/config': '/c/conf',

  // 认证相关
  '/passport/auth/login': '/auth/login',
  '/passport/auth/register': '/auth/reg',
  '/passport/auth/forget': '/auth/forget',
  '/passport/auth/token2Login': '/auth/token2Login',
  '/passport/comm/sendEmailVerify': '/mail/verify',
  '/user/checkLogin': '/auth/check',

  // 用户信息
  '/user/info': '/u/info',
  '/user/changePassword': '/u/pwd',
  '/user/sendChangeEmailVerify': '/u/changeEmailVerify',
  '/user/changeEmail': '/u/changeEmail',
  '/user/resetSecurity': '/u/reset',
  '/user/update': '/u/update',
  '/user/redeemgiftcard': '/u/gift',
  '/user/getActiveSession': '/u/session',

  // 订阅相关
  '/user/getSubscribe': '/sub/get',
  '/user/getStat': '/stat/get',
  '/user/stat/getTrafficLog': '/traffic/log',

  // 商店相关
  '/user/plan/fetch': '/plan/list',
  '/user/coupon/check': '/coup/check',
  '/user/order/save': '/order/new',
  '/user/order/fetch': '/order/list',
  '/user/order/detail': '/order/detail',
  '/user/order/cancel': '/order/cancel',
  '/user/order/checkout': '/order/pay',
  '/user/order/check': '/order/check',
  '/user/order/getPaymentMethod': '/pay/methods',

  // 服务器节点
  '/user/server/fetch': '/node/list',

  // 工单系统
  '/user/ticket/fetch': '/ticket/list',
  '/user/ticket/save': '/ticket/new',
  '/user/ticket/reply': '/ticket/reply',
  '/user/ticket/close': '/ticket/close',
  '/user/ticket/withdraw': '/withdraw',

  // 邀请系统
  '/user/invite/fetch': '/inv/info',
  '/user/invite/save': '/inv/new',
  '/user/invite/details': '/inv/detail',
  '/user/transfer': '/comm/transfer',

  // 公告系统
  '/user/notice/fetch': '/notice/list',
  
  // 知识库
  '/user/knowledge/fetch': '/knowledge/list'
};

/**
 * 将后端API路径转换为自定义前端路径
 * @param {string} originalPath - 原始API路径
 * @returns {string} - 映射后的路径
 */
export function mapApiPath(originalPath) {
  try {
    // 如果中间件未启用，直接返回原始路径
    if (!window.EZ_CONFIG || !window.EZ_CONFIG.API_MIDDLEWARE_ENABLED) {
      return originalPath;
    }
    
    // 处理带查询参数的路径
    const [path, query] = originalPath.split('?');
    
    // 查找精确匹配
    if (pathMappings[path]) {
      return query ? `${pathMappings[path]}?${query}` : pathMappings[path];
    }
    
    // 尝试查找最佳前缀匹配
    let matchedPrefix = '';
    let mappedPath = '';
    
    Object.keys(pathMappings).forEach(prefix => {
      if (path.startsWith(prefix) && prefix.length > matchedPrefix.length) {
        matchedPrefix = prefix;
        mappedPath = pathMappings[prefix];
      }
    });
    
    if (matchedPrefix) {
      // 替换前缀，保留路径其余部分
      const remainingPath = path.slice(matchedPrefix.length);
      const newPath = mappedPath + remainingPath;
      return query ? `${newPath}?${query}` : newPath;
    }
    
    // 无匹配，返回原始路径
    return originalPath;
  } catch (error) {
    console.error('路径映射错误:', error);
    return originalPath;
  }
}

/**
 * 将URL中的查询参数解析为对象
 * @param {string} url - 包含查询参数的URL
 * @returns {object} - 查询参数对象
 */
export function parseQueryParams(url) {
  try {
    const queryString = url.split('?')[1];
    if (!queryString) return {};
    
    const params = {};
    queryString.split('&').forEach(param => {
      const [key, value] = param.split('=');
      params[key] = decodeURIComponent(value || '');
    });
    
    return params;
  } catch (error) {
    console.error('查询参数解析错误:', error);
    return {};
  }
}

export default {
  mapApiPath,
  parseQueryParams
}; 
