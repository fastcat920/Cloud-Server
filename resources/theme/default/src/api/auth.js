/**
 * 认证相关API接口
 */
import request, { requestMessage } from './request';
import store from '@/store';
import { SITE_CONFIG } from '@/utils/baseConfig';

/**
 * 设置cookie
 * @param {string} name cookie名称
 * @param {string} value cookie值
 * @param {number} days cookie过期天数
 */
const setCookie = (name, value, days) => {
  const siteName = SITE_CONFIG.siteName;
  
  // 将网站名称和值组合成一个对象，并转换为 JSON 字符串
  const cookieValue = JSON.stringify({
    site: siteName,
    value: value
  });
  
  // 检测当前环境
  const isSecure = window.location.protocol === 'https:';
  const isLocalhost = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
  
  // 创建Cookie
  const date = new Date();
  date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
  const expires = `expires=${date.toUTCString()}`;
  const domain = isLocalhost ? '' : `domain=${window.location.hostname};`;
  let cookieString = `${name}=${encodeURIComponent(cookieValue)}; ${expires}; ${domain} path=/`;
  
  // 如果是HTTPS连接，添加secure属性
  if (isSecure) {
    cookieString += '; secure';
  }
  
  // 添加SameSite属性
  cookieString += '; SameSite=Lax';
  
  // 设置Cookie
  document.cookie = cookieString;
  
  // 备份Cookie到localStorage作为后备
  try {
    localStorage.setItem(`cookie_${name}`, cookieValue);
  } catch (err) {
    // 安静地处理错误
  }
  
  // 验证Cookie是否设置成功
  setTimeout(() => {
    const checkCookie = getCookie(name);
    const success = !!checkCookie;
    
    // 如果Cookie设置失败，尝试备用方法
    if (!success) {
      // 安静地处理
      // 再次尝试设置Cookie，去掉domain
      document.cookie = `${name}=${encodeURIComponent(cookieValue)}; ${expires}; path=/`;
      // 设置后备标记
      localStorage.setItem(`cookie_${name}_failure`, 'true');
      // 设置全局标记以便其他地方知道Cookie可能有问题
      window.authCookieFailure = true;
    }
  }, 300);
};

/**
 * 获取cookie
 * @param {string} name cookie名称
 * @returns {string|null} cookie值或null
 */
const getCookie = (name) => {
  const siteName = SITE_CONFIG.siteName;
  
  const nameEQ = name + "=";
  const ca = document.cookie.split(';');
  let cookieValue = null;
  
  // 从 document.cookie 中获取
  for (let i = 0; i < ca.length; i++) {
    let c = ca[i];
    while (c.charAt(0) === ' ') c = c.substring(1, c.length);
    if (c.indexOf(nameEQ) === 0) {
      try {
        // 获取 cookie 值并解码
        const rawValue = c.substring(nameEQ.length, c.length);
        const decodedValue = decodeURIComponent(rawValue);
        const parsedValue = JSON.parse(decodedValue);
        
        // 验证网站名称
        if (parsedValue && parsedValue.site === siteName) {
          cookieValue = parsedValue.value;
          break;
        }
      } catch (err) {
        // 如果解析失败，可能是旧格式的 cookie，不处理
        console.error('Cookie 解析失败:', err);
      }
    }
  }
  
  // 如果 cookie 不存在或验证失败，尝试从 localStorage 获取后备值
  if (!cookieValue) {
    try {
      const localValue = localStorage.getItem(`cookie_${name}`);
      if (localValue) {
        try {
          const parsedValue = JSON.parse(localValue);
          // 验证网站名称
          if (parsedValue && parsedValue.site === siteName) {
            cookieValue = parsedValue.value;
          }
        } catch (err) {
          // 如果解析失败，可能是旧格式的值，不处理
          console.error('LocalStorage cookie 解析失败:', err);
        }
      }
    } catch (err) {
      // 安静地处理错误
    }
  }
  
  // 对于 auth_data，还有一个特殊的全局变量后备
  if (!cookieValue && name === 'auth_data' && window.authDataInStorage) {
    // 这是原始值，不需要验证网站名称
    cookieValue = window.authDataInStorage;
  }
  
  return cookieValue;
};

/**
 * 删除cookie
 * @param {string} name cookie名称
 */
const deleteCookie = (name) => {
  // 过期时间设为过去，导致Cookie失效
  document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
  
  // 清除备份
  try {
    localStorage.removeItem(`cookie_${name}`);
    localStorage.removeItem(`cookie_${name}_failure`);
  } catch (err) {
    // 安静地处理错误
  }
  
  // 验证是否删除成功
  setTimeout(() => {
    const checkCookie = getCookie(name);
    if (checkCookie) {
      // 安静地处理
      // 尝试添加不同的路径和域
      document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/`;
      
      // 为了确保删除掉旧格式的 cookie，也尝试直接设置空值
      const isLocalhost = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
      const domain = isLocalhost ? '' : `domain=${window.location.hostname};`;
      document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; ${domain} path=/`;
    }
  }, 100);
};

/**
 * 处理登录成功后的认证存储操作
 * @param {Object} responseData - 登录响应数据，包含token和auth_data
 * @param {boolean} rememberMe - 是否记住登录状态（影响cookie过期时间）
 * @returns {Object} 处理结果
 */
export const handleLoginSuccess = (responseData, rememberMe) => {
  try {
    // 清除任何标记
    window.isUserLoggedIn = undefined;
    window.authCookieFailure = false;
    window.authDataInStorage = null;
    
    // 设置Vuex状态
    store.dispatch('login', responseData.token);
    
    // 存储在localStorage
    localStorage.setItem('token', responseData.token);
    if (responseData.is_admin === 1) {
      localStorage.setItem('is_admin', '1');
    }
    
    // 重要：将 auth_data 保存到 localStorage - 确保所有 API 请求能使用
    if (responseData.auth_data) {
      // 保存原始值，用于 API 请求
      localStorage.setItem('auth_data', responseData.auth_data);
    }
    
    // 设置Cookie
    const days = rememberMe ? 30 : 1; // 记住我30天，否则1天
    if (responseData.auth_data) {
      setCookie('auth_data', responseData.auth_data, days);
    }
    
    // 延迟检查确认登录状态
    setTimeout(() => {
      const loginCheck = checkLoginStatus();
      
      // 如果检测失败，使用全局变量强制设置登录状态
      if (!loginCheck) {
        // 安静地处理
        window.isUserLoggedIn = true;
        
        if (responseData.auth_data) {
          // 保存原始值到全局变量，因为这是一个后备机制
          window.authDataInStorage = responseData.auth_data;
          localStorage.setItem('cookie_auth_data', responseData.auth_data);
        }
      }
      
      // 登录后重新加载语言文件
      Promise.resolve().then(function() { return import('@/i18n'); })
        .then(({ reloadMessages }) => {
          reloadMessages().catch(() => {
            // 安静地处理错误
          });
        }).catch(() => {
          // 安静地处理错误
        });
    }, 500);
    
    return { success: true };
  } catch (error) {
    // 安静地处理错误
    return { success: false, error: error.message };
  }
};

/**
 * login - 用户登录接口
 * @Board @url POST /passport/auth/login
 * @param {object} loginData - 登录参数，包含username/email、password及rememberMe
 * @returns {Promise<object>} - 登录结果，成功时包含token、auth_data等
 * 特点：
 * 1. 自动处理多种响应格式
 * 2. 集成handleLoginSuccess统一处理认证流程
 * 3. 返回标准化的响应对象
 */
export const login = async (loginData) => {
  // 从登录数据中提取rememberMe字段，其余字段作为API请求数据
  const { rememberMe, ...requestData } = loginData;
  
  // 发送登录请求
  const response = await request({
    url: '/passport/auth/login',
    method: 'post',
    data: requestData
  });
  
  // 从响应中提取数据
  let responseData = response;
  if ((response && response.data) || (response && typeof response === 'object' && Object.prototype.hasOwnProperty.call(response, 'data'))) {
    responseData = response.data;
  }
  
  // 验证响应数据是否包含必要的字段
  if (!responseData || !(responseData.token || responseData.auth_data)) {
    throw new Error(requestMessage('responseError'));
  }
  
  // 处理登录成功
  const handledResponse = handleLoginSuccess(responseData, rememberMe);
  
  // 返回完整响应供组件使用
  if (handledResponse.success) {
    return {
      success: true,
      token: responseData.token,
      auth_data: responseData.auth_data,
      is_admin: responseData.is_admin
    };
  } else {
    throw new Error(handledResponse.error);
  }
};

/**
 * register - 用户注册接口
 * @Board @url POST /passport/auth/register
 * @param {object} data - 注册参数
 * @param {string} data.email - 邮箱
 * @param {string} data.password - 密码
 * @param {string} [data.email_code] - 邮箱验证码（可选）
 * @param {string} [data.invite_code] - 邀请码（可选）
 * @param {string} [data.recaptcha_data] - 人机验证数据（可选）
 * @returns {Promise<object>} - 注册结果
 * 特点：
 * 1. 支持邀请码和邮箱验证码
 * 2. 注册成功后自动设置认证状态
 */
export function register(data) {
  return request({
    url: '/passport/auth/register',
    method: 'post',
    data
  }).then(response => {
    let responseData = response.data || response;
    
    // 处理token
    if (responseData.token) {
      store.dispatch('login', responseData.token);
      
      // 强制设置全局登录状态标记，确保checkLoginStatus能立即识别登录状态
      window.isUserLoggedIn = true;
    }
    
    // 处理auth_data
    if (responseData.auth_data) {
      // 设置cookie，24小时有效期
      setCookie('auth_data', responseData.auth_data, 1); // 1天 = 24小时
      
      // 保存到localStorage
      localStorage.setItem('auth_data', responseData.auth_data);
      
      // 设置全局备份，解决cookie可能无法正确设置的问题
      window.authDataInStorage = responseData.auth_data;
    }
    
    // 保存is_admin信息
    if (typeof responseData.is_admin !== 'undefined') {
      localStorage.setItem('is_admin', responseData.is_admin);
    }
    
    // 重要：在注册成功后重新加载语言文件
    // 延迟确保登录状态先被正确设置
    console.log('注册成功，准备重新加载语言文件');
    setTimeout(async () => {
      try {
        const i18nModule = await import('@/i18n');
        const result = await i18nModule.reloadMessages();
        console.log('注册后重新加载语言包结果:', result);
        
        // 触发语言变更事件，确保组件更新
        window.dispatchEvent(new CustomEvent('languageChanged'));
      } catch (error) {
        console.error('注册后重载语言包失败:', error);
      }
    }, 100);
    
    return response;
  });
}

/**
 * resetPassword - 重置密码接口
 * @Board @url POST /passport/auth/forget
 * @param {object} data - 重置密码参数
 * @param {string} data.email - 邮箱
 * @param {string} data.password - 新密码
 * @param {string} data.email_code - 6 位邮箱验证码
 * @returns {Promise<boolean>} - 是否重置成功
 * 特点：
 * 1. 通过邮箱验证码验证身份
 * 2. 支持忘记密码流程
 */
export function resetPassword(data) {
  return request({
    url: '/passport/auth/forget',
    method: 'post',
    data
  });
}

/**
 * getUserInfo - 获取当前用户信息
 * @Board @url GET /user/info
 * @returns {Promise<object>} - 用户信息
 * 特点：
 * 1. 获取用户详细资料
 * 2. 用于验证用户登录状态
 */
export function getUserInfo() {
  return request({
    url: '/user/info',
    method: 'get'
  });
}

/**
 * logout - 退出登录
 * @Board @url 不涉及实际API请求，只清除本地认证数据
 * @returns {Promise<object>} - 退出结果 {success: boolean, redirectToLogin: boolean, redirectUrl: string}
 * 特点：
 * 1. 清除所有认证数据
 * 2. 重载语言文件
 * 3. 提供重定向信息
 */
export const logout = async () => {
  try {
    // 使用内部函数清除所有认证数据
    _clearAllAuthData();
    
    // 延迟以确保所有清除操作完成
    return new Promise(resolve => {
      setTimeout(() => {
        // 重新加载i18n消息，移除授权相关内容
        Promise.resolve().then(function() { return import('@/i18n'); })
          .then(({ reloadMessages }) => {
            reloadMessages().then(() => {
              resolve({
                success: true,
                redirectToLogin: true,
                redirectUrl: '/login?logout=true'
              });
            }).catch(() => {
              // 安静地处理错误
              resolve({
                success: true, 
                redirectToLogin: true,
                redirectUrl: '/login?logout=true'
              });
            });
          }).catch(() => {
            // 安静地处理错误
            resolve({
              success: true,
              redirectToLogin: true,
              redirectUrl: '/login?logout=true'
            });
          });
      }, 200);
    });
  } catch (error) {
    // 安静地处理错误
    return {
      success: false,
      error: error.message,
      redirectToLogin: true,
      redirectUrl: '/login?logout=true'
    };
  }
};

/**
 * getWebsiteConfig - 获取网站配置
 * @Board @url GET /guest/comm/config
 * @returns {Promise<object>} - 网站配置信息
 * 特点：
 * 1. 无需登录即可访问
 * 2. 返回网站基础配置信息
 */
export function getWebsiteConfig() {
  return request({
    url: '/guest/comm/config',
    method: 'get'
  });
}

/**
 * sendEmailVerify - 发送邮箱验证码
 * @Board @url POST /passport/comm/sendEmailVerify
 * @param {object} data - 参数
 * @param {string} data.email - 邮箱
 * @param {boolean} [data.isForgetPassword=false] - 是否为忘记密码场景（仅Xiao-V2board面板有效）
 * @returns {Promise<boolean>} - 是否发送成功
 * 特点：
 * 1. 用于注册和重置密码等场景
 * 2. 返回发送状态
 */
export function sendEmailVerify(data) {
  // 检查是否为Xiao-V2board面板，如果是，需要添加isforget参数
  const sendData = { ...data };
  
  // Xiao-V2board面板类型下，添加isforget参数
  // 默认为0（注册），忘记密码场景为1
  if (window.EZ_CONFIG && window.EZ_CONFIG.PANEL_TYPE === 'Xiao-V2board' && 
      typeof sendData.isForgetPassword !== 'undefined') {
    sendData.isforget = sendData.isForgetPassword ? 1 : 0;
    // 删除临时字段，避免发送到API
    delete sendData.isForgetPassword;
  }
  
  return request({
    url: '/passport/comm/sendEmailVerify',
    method: 'post',
    data: sendData
  });
}

/**
 * checkLoginStatus - 检查用户登录状态
 * @Board @url 不涉及实际API请求，只检查本地认证数据
 * @returns {boolean} - 用户是否已登录
 * 特点：
 * 1. 多重检查确保状态准确
 * 2. 检查URL、token、localStorage等多个位置
 * 3. 使用缓存避免短时间内重复检测
 */
export const checkLoginStatus = () => {
  // 使用缓存避免短时间内重复检测
  // 如果在过去1秒内检测过，直接返回上次结果
  const now = Date.now();
  if (window._lastLoginCheck && (now - window._lastLoginCheckTime < 1000)) {
    // console.log('使用缓存的登录状态检测结果:', window._lastLoginCheck);
    return window._lastLoginCheck;
  }
  
  // 最高优先级：检查是否正在进行登出操作
  if (window._isLoggingOut === true) {
    // console.log('检测到正在退出登录，返回未登录状态');
    _cacheLoginStatus(false);
    return false;
  }
  
  // 检查URL是否包含logout参数
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('logout') === 'true') {
    // 确保所有认证状态被清除
    // console.log('URL包含logout=true参数，返回未登录状态');
    _clearAllAuthData();
    _cacheLoginStatus(false);
    return false;
  }
  
  // 检查全局登出标记
  if (window.isUserLoggedIn === false) {
    _cacheLoginStatus(false);
    return false;
  }
  
  // 核心检查：必须同时满足token存在且有效
  const token = localStorage.getItem('token') || sessionStorage.getItem('token');
  if (!token || token === 'undefined' || token === 'null' || token === '') {
    _clearAllAuthData(); // 使用内部函数，避免循环引用
    _cacheLoginStatus(false);
    return false;
  }
  
  // 检查登录成功必要条件：auth_data
  const authData = localStorage.getItem('auth_data') || 
                  sessionStorage.getItem('auth_data') || 
                  window.authDataInStorage;
                  
  if (!authData || authData === 'undefined' || authData === 'null' || authData === '') {
    // 如果没有auth_data但有token，可能是登录流程中的临时状态
    // 但不应该将这种状态视为完全登录状态
    if (window.isUserLoggedIn === true) {
      // 只有当全局标记明确设置为true时，才认可这种状态
      _cacheLoginStatus(true);
      return true;
    }
    
    // 否则还是视为未登录状态
    _clearAllAuthData();
    _cacheLoginStatus(false);
    return false;
  }
  
  // Vuex状态检查（作为辅助验证）
  try {
    const vuexAuth = store.getters.isLoggedIn;
    if (!vuexAuth) {
      // Vuex状态不一致，但不立即清除数据
      // 可能是Vuex状态尚未同步
    }
  } catch (e) {
    // 忽略Vuex检查错误
  }
  
  // 用户数据检查
  const userInfoStr = localStorage.getItem('userInfo');
  let userInfo = null;
  
  try {
    if (userInfoStr) {
      userInfo = JSON.parse(userInfoStr);
      // 如果有用户信息，确认是有效的对象
      if (!userInfo || typeof userInfo !== 'object') {
        userInfo = null;
      }
    }
  } catch (e) {
    // JSON解析失败，用户信息无效
    userInfo = null;
    localStorage.removeItem('userInfo');
  }
  
  // 最终判断：
  // 1. 有token
  // 2. 有auth_data
  // 3. 用户信息可选（不作为决定性因素）
  const isLoggedIn = !!token && !!authData;
  
  // 更新全局状态，保持一致性
  if (isLoggedIn) {
    window.isUserLoggedIn = true;
  }
  
  // 缓存结果
  _cacheLoginStatus(isLoggedIn);
  return isLoggedIn;
};

/**
 * 缓存登录状态检测结果，避免短时间内重复检测
 * @param {boolean} status - 登录状态
 */
const _cacheLoginStatus = (status) => {
  window._lastLoginCheck = status;
  window._lastLoginCheckTime = Date.now();
};

/**
 * 内部函数：清除所有认证数据（避免循环引用）
 * 不导出，只在当前文件内使用
 */
const _clearAllAuthData = () => {
  // 1. 设置全局标记
  window.isUserLoggedIn = false;
  window.authDataInStorage = null;
  window.authCookieFailure = false;
  
  // 2. 清除localStorage
  const authKeys = [
    'token', 
    'auth_data', 
    'cookie_auth_data', 
    'userInfo', 
    'is_admin',
    'vuex',
    'user',
    'auth'
  ];
  
  authKeys.forEach(key => {
    localStorage.removeItem(key);
  });
  
  // 3. 清除sessionStorage
  const sessionKeys = [
    'token', 
    'auth_data',
    'vuex',
    'user',
    'auth'
  ];
  
  sessionKeys.forEach(key => {
    sessionStorage.removeItem(key);
  });
  
  // 4. 清除cookie
  const cookiePaths = ['/', '/dashboard', '/user', '/admin'];
  const cookieNames = ['auth_data', 'XSRF-TOKEN', 'laravel_session', 'token'];
  
  cookieNames.forEach(name => {
    cookiePaths.forEach(path => {
      document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=${path};`;
    });
    
    document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;`;
    deleteCookie(name);
  });
  
  // 5. 清除Vuex状态（只对store进行基本操作）
  try {
    if (store && typeof store.commit === 'function') {
      store.commit('CLEAR_USER');
    }
  } catch (e) {
    // 忽略错误
    console.error('Vuex状态清除失败', e);
  }
};

/**
 * forceLogout - 强制登出
 * @Board @url 不涉及实际API请求，只清除本地认证数据
 * 特点：
 * 1. 彻底清除所有认证数据
 * 2. 清除localStorage、sessionStorage、cookie中所有认证相关内容
 * 3. 重置Vuex状态
 * 4. 用于处理异常情况或安全退出
 */
export const forceLogout = () => {
  // 1. 设置全局标记
  window.isUserLoggedIn = false;
  window.authDataInStorage = null;
  window.authCookieFailure = false;
  
  // 2. 清除localStorage中所有可能的认证数据
  const authKeys = [
    'token', 
    'auth_data', 
    'cookie_auth_data', 
    'userInfo', 
    'is_admin',
    'vuex',
    'user',
    'auth'
  ];
  
  authKeys.forEach(key => {
    localStorage.removeItem(key);
  });
  
  // 3. 清除sessionStorage中所有可能的认证数据
  const sessionKeys = [
    'token', 
    'auth_data',
    'vuex',
    'user',
    'auth'
  ];
  
  sessionKeys.forEach(key => {
    sessionStorage.removeItem(key);
  });
  
  // 4. 清除cookie (包括不同路径下的cookie)
  const cookiePaths = ['/', '/dashboard', '/user', '/admin'];
  const cookieNames = ['auth_data', 'XSRF-TOKEN', 'laravel_session', 'token'];
  
  cookieNames.forEach(name => {
    cookiePaths.forEach(path => {
      document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=${path};`;
    });
    
    // 针对根域名的cookie
    document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;`;
    
    // 安全起见，使用第三方函数删除一次
    deleteCookie(name);
  });
  
  // 5. 清除Vuex状态 - 避免循环依赖问题，只执行基本操作
  try {
    // 避免直接调用store.dispatch('logout')，以防止循环调用
    // 而是检查store上是否有commit方法，直接提交状态更改
    if (store && typeof store.commit === 'function') {
      store.commit('CLEAR_USER');
    }
  } catch (e) {
    // 忽略可能的错误
    console.error('Vuex状态清除失败', e);
  }
};

/**
 * 使用验证令牌登录
 * @param {string} verifyToken 验证令牌
 * @param {string} redirect 登录成功后重定向的路径
 * @returns {Promise} 验证令牌登录的请求Promise
 */
export const tokenLogin = (verifyToken, redirect) => {
  return request({
    url: `/passport/auth/token2Login`,
    method: 'get',
    skipAuth: true,
    params: { 
      verify: verifyToken,
      redirect: redirect || '' 
    }
  });
};

/**
 * 检查用户登录状态是否过期
 * 通过请求接口检查token是否有效并更新本地登录状态
 * @returns {Promise} 检查结果的Promise对象
 */
export const checkUserLoginStatus = async () => {
  // 检查本地是否有认证信息
  const authData = localStorage.getItem('auth_data') || sessionStorage.getItem('auth_data');
  const token = localStorage.getItem('token') || sessionStorage.getItem('token');
  
  // 如果本地没有认证信息，已经是未登录状态，无需请求接口
  if (!token || !authData) {
    forceLogout(); // 确保清除可能存在的部分认证信息
    return { isLoggedIn: false };
  }
  
  try {
    // 请求接口检查登录状态
    const response = await request({
      url: '/user/checkLogin',
      method: 'GET',
      headers: {
        'Authorization': authData
      }
    });
    
    // 判断返回结果
    if (response && response.data && response.data.is_login === true) {
      // 登录有效，更新全局状态
      window.isUserLoggedIn = true;
      return { isLoggedIn: true };
    } else {
      // 登录已过期或失效
      console.log('登录已过期或失效，清除登录状态');
      forceLogout();
      
      // 获取当前路由信息
      const currentRoute = window.location.pathname;
      const isAuthPage = /\/(login|register|forgot-password)/.test(currentRoute);
      
      // 如果不在登录相关页面，跳转到登录页
      if (!isAuthPage) {
        window.location.href = '/#/login';
      }
      
      return { isLoggedIn: false, message: '登录已过期，请重新登录' };
    }
  } catch (error) {
    // 请求失败处理
    console.error('检查登录状态失败:', error);
    
    // 判断是否是登录失效的特定错误信息
    if (error.response && error.response.data && error.response.data.message === '未登录或登陆已过期') {
      // 登录已过期，清除登录状态
      forceLogout();
      
      // 获取当前路由信息
      const currentRoute = window.location.pathname;
      const isAuthPage = /\/(login|register|forgot-password)/.test(currentRoute);
      
      // 如果不在登录相关页面，跳转到登录页
      if (!isAuthPage) {
        window.location.href = '/#/login';
      }
      
      return { isLoggedIn: false, message: '登录已过期，请重新登录' };
    }
    
    // 其他错误，如网络问题等，不清除登录状态
    return { isLoggedIn: null, error: error.message || '网络错误' };
  }
}; 
