/**
 * API请求工具
 */
import axios from 'axios';
import { API_BASE_URL, getApiBaseUrl, isXiaoV2board, isXboard, CUSTOM_HEADERS_CONFIG } from '@/utils/baseConfig';
import { mapApiPath } from './utils/pathMapper';
import { getAvailableApiUrl } from '@/utils/apiAvailabilityChecker';
import { getRequestLocale } from '@/utils/locale';

// 创建axios实例
const request = axios.create({
  // 初始配置使用静态API_BASE_URL
  baseURL: API_BASE_URL,
  timeout: 30000, // 请求超时时间
  headers: {
    'Content-Type': 'application/json'
  }
});

let isRedirectingToLogin = false;
const requestMessages = {
  'zh-CN': {
    configError: '请求配置错误',
    responseError: '响应数据处理错误',
    sessionExpired: '登录已过期，请重新登录',
    badRequest: '请求参数错误',
    unauthorized: '未授权，请重新登录',
    forbidden: '拒绝访问',
    notFound: '请求的资源不存在',
    failed: '请求失败',
    timeout: '请求超时',
    network: '网络错误，请检查您的网络连接'
  },
  'en-US': {
    configError: 'Request configuration error',
    responseError: 'Failed to process response data',
    sessionExpired: 'Your session has expired. Please sign in again',
    badRequest: 'Invalid request parameters',
    unauthorized: 'Unauthorized. Please sign in again',
    forbidden: 'Access denied',
    notFound: 'The requested resource was not found',
    failed: 'Request failed',
    timeout: 'Request timed out',
    network: 'Network error. Please check your connection'
  }
};
export const requestMessage = key => (requestMessages[getRequestLocale()] || requestMessages['zh-CN'])[key];
const isAuthExpiredMessage = message => typeof message === 'string' && /未登录|未登陆|登录.*过期|登陆.*过期|unauthenticated|token.*(expired|invalid)/i.test(message);
const handleSessionExpired = message => {
  if (isRedirectingToLogin) return;
  isRedirectingToLogin = true;
  try {
    const { forceLogout } = require('./auth');
    forceLogout();
  } finally {
    if (!window.location.hash.startsWith('#/login')) window.location.replace('/#/login');
  }
  return message;
};

// 请求拦截器
request.interceptors.request.use(
  config => {
    const requestLocale = getRequestLocale();
    config.headers['X-Locale'] = requestLocale;
    config.headers['Accept-Language'] = requestLocale;

    // 设置基础URL
      config.baseURL = getApiBaseUrl();
    
    // 如果启用了中间件，则映射API路径（中间件和多静态地址二选一）
    if (window.EZ_CONFIG && window.EZ_CONFIG.API_MIDDLEWARE_ENABLED) {
      // 保存原始URL，用于日志和调试
      const originalUrl = config.url;
      
      // 映射URL路径
      config.url = mapApiPath(config.url);
      
      // 开发环境下记录路径映射日志
      if (process.env.NODE_ENV === 'development') {
        console.log(`API路径映射: ${originalUrl} -> ${config.url}`);
      }
    } 
    // 如果未启用中间件，且有多个静态地址时，才检查可用的API地址
    else if (window.EZ_CONFIG && window.EZ_CONFIG.API_BASE_URLS && 
             Array.isArray(window.EZ_CONFIG.API_BASE_URLS) && 
             window.EZ_CONFIG.API_BASE_URLS.length > 1) {
      const availableApiUrl = getAvailableApiUrl();
      if (availableApiUrl) {
        config.baseURL = availableApiUrl;
      }
    }
    
    // 检查是否是Xiao-V2board面板或Xboard面板，如果是则修改请求格式
    if ((isXiaoV2board() || isXboard()) && config.method === 'post' && config.data) {
      // 将JSON请求转换为表单URL编码格式
      const formData = new URLSearchParams();
      for (const key in config.data) {
        if (Object.prototype.hasOwnProperty.call(config.data, key)) {
          formData.append(key, config.data[key]);
        }
      }
      
      // 替换原始数据并设置正确的Content-Type
      config.data = formData;
      config.headers['Content-Type'] = 'application/x-www-form-urlencoded';
    }
    
    // token2Login 等账号切换请求必须脱离当前账号上下文，避免携带旧账号凭证。
    const skipAuth = config.skipAuth === true;
    if (skipAuth) delete config.headers.Authorization;

    // 优先从 localStorage 获取 auth_data（原始值）
    let authData = skipAuth ? null : localStorage.getItem('auth_data');
    
    // 如果 localStorage 中没有，尝试从 cookie 获取
    if (!skipAuth && !authData) {
      try {
        // 使用 auth.js 中的 getCookie 函数获取 cookie
        const { getCookie } = require('./auth');
        authData = getCookie('auth_data');
      } catch (err) {
        // 如果导入失败或者获取失败，使用备用方式继续尝试
        // 尝试从 cookie 获取
        const cookieAuthData = document.cookie
          .split('; ')
          .find(row => row.startsWith('auth_data='));
        
        if (cookieAuthData) {
          try {
            // 尝试解析新格式的 cookie
            const encodedValue = cookieAuthData.split('=')[1];
            const decodedValue = decodeURIComponent(encodedValue);
            const parsedValue = JSON.parse(decodedValue);
            
            // 验证网站名称 (需要导入配置)
            const { SITE_CONFIG } = require('../utils/baseConfig');
            if (parsedValue && parsedValue.site === SITE_CONFIG.siteName) {
              authData = parsedValue.value;
            }
          } catch (e) {
            // 如果解析失败，可能是旧格式，直接使用值
            authData = cookieAuthData.split('=')[1];
          }
        }
      }
    }
    
    // 如果 cookie 中没有，尝试从全局变量获取
    if (!skipAuth && !authData && window.authDataInStorage) {
      authData = window.authDataInStorage;
    }
    
    // 后备方案：尝试从 localStorage 的备份获取
    if (!skipAuth && !authData) {
      const backupData = localStorage.getItem('cookie_auth_data');
      if (backupData) {
        try {
          // 尝试解析 JSON 格式
          const parsedValue = JSON.parse(backupData);
          
          // 验证网站名称
          const { SITE_CONFIG } = require('../utils/baseConfig');
          if (parsedValue && parsedValue.site === SITE_CONFIG.siteName) {
            authData = parsedValue.value;
          } else {
            // 可能是旧格式，直接使用
            authData = backupData;
          }
        } catch (e) {
          // 如果解析失败，可能是旧格式，直接使用
          authData = backupData;
        }
      }
    }
    
    // 设置 Authorization 头
    if (authData) {
      config.headers['Authorization'] = authData;
    }
    
    // 应用自定义标头
    try {
      // 检查是否启用了自定义标头
      if (CUSTOM_HEADERS_CONFIG && CUSTOM_HEADERS_CONFIG.enabled && CUSTOM_HEADERS_CONFIG.headers) {
        // 遍历所有自定义标头并添加到请求中
        const customHeaders = CUSTOM_HEADERS_CONFIG.headers;
        for (const headerName in customHeaders) {
          if (Object.prototype.hasOwnProperty.call(customHeaders, headerName)) {
            const headerValue = customHeaders[headerName];
            config.headers[headerName] = headerValue;
          }
        }
      }
    } catch (error) {
      console.error('应用自定义标头失败:', error);
    }
    
    return config;
  },
  error => {
    console.error('请求拦截器错误:', error);
    return Promise.reject(new Error(requestMessage('configError')));
  }
);

// 响应拦截器
request.interceptors.response.use(
  response => {
    try {
      // 获取响应数据
      const res = response.data;
      
      // 检查响应是否包含登录过期信息
      if (res && isAuthExpiredMessage(res.message)) {
        handleSessionExpired(res.message);
        return Promise.reject(new Error(res.message));
      }
      
      // 直接返回原始响应数据，让具体的业务代码处理
      return res;
    } catch (err) {
      console.error('响应数据处理错误:', err);
      return Promise.reject(new Error(requestMessage('responseError')));
    }
  },
  error => {
    // 增强错误处理，转换各种类型的错误为标准格式
    console.error('请求错误:', error);
    
    const statusCode = error.response?.status;
    const responseMessage = error.response?.data?.message;
    const requestHadAuth = Boolean(error.config?.headers?.Authorization);
    if ((statusCode === 401 || isAuthExpiredMessage(responseMessage)) && requestHadAuth && error.config?.skipAuth !== true) {
      handleSessionExpired(responseMessage || requestMessage('sessionExpired'));
    }

    // 确保error.response.message存在，便于页面使用
    if (error.response && error.response.data && error.response.data.message) {
      // 将API返回的错误消息添加到error.response.message
      error.response.message = error.response.data.message;
    } else if (error.response) {
      // 根据HTTP状态码设置通用错误信息
      switch (error.response.status) {
        case 400: error.response.message = requestMessage('badRequest'); break;
        case 401: error.response.message = requestMessage('unauthorized'); break;
        case 403: error.response.message = requestMessage('forbidden'); break;
        case 404: error.response.message = requestMessage('notFound'); break;
        default: error.response.message = `${requestMessage('failed')} (${statusCode})`;
      }
    } else if (error.message) {
      if (error.message.includes('timeout')) {
        error.message = requestMessage('timeout');
      } else if (error.message.includes('Network Error')) {
        error.message = requestMessage('network');
      }
    }
    
    // 返回处理后的原始错误对象
    return Promise.reject(error);
  }
);

export default request; 
