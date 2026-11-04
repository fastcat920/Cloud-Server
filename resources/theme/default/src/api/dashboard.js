/**
 * Dashboard相关API接口
 */
import request from './request';
import { getRequestLocale } from '@/utils/locale';

// 首页与“我的”页面会读取同一批数据。这里做短时内存缓存并复用正在进行的
// 请求，避免页面切换时重复访问相同接口，同时保证切换账号后不会串数据。
const responseCache = new Map();
const pendingRequests = new Map();

function getAuthScope() {
  const authData = localStorage.getItem('auth_data') || window.authDataInStorage || 'guest';
  let hash = 0;
  for (let i = 0; i < authData.length; i += 1) {
    hash = ((hash << 5) - hash + authData.charCodeAt(i)) | 0;
  }
  return String(hash);
}

function cachedGet(url, ttl, options = {}) {
  const { force = false } = options;
  const key = `${getAuthScope()}:${getRequestLocale()}:${url}`;
  const now = Date.now();
  const cached = responseCache.get(key);

  if (!force && cached && now - cached.timestamp < ttl) {
    return Promise.resolve(cached.response);
  }

  if (!force && pendingRequests.has(key)) {
    return pendingRequests.get(key);
  }

  const promise = request({ url, method: 'get' })
    .then(response => {
      responseCache.set(key, { response, timestamp: Date.now() });
      return response;
    })
    .finally(() => pendingRequests.delete(key));

  pendingRequests.set(key, promise);
  return promise;
}

/**
 * getUserInfo - 获取用户信息
 * @Board @url GET /user/info
 * @returns {Promise<object>} - 用户信息
 * 返回数据：
 * - 用户基本资料
 * - 账户余额
 * - 套餐信息
 * - 使用统计
 */
export function getUserInfo(options) {
  return cachedGet('/user/info', 15 * 1000, options);
}

/**
 * getSubscribe - 获取用户订阅信息
 * @Board @url GET /user/getSubscribe
 * @returns {Promise<object>} - 订阅信息
 * 返回数据：
 * - 订阅链接
 * - 二维码
 * - 套餐详情
 * - 到期时间
 * - 流量使用情况
 */
export function getSubscribe(options) {
  return cachedGet('/user/getSubscribe', 15 * 1000, options);
}

/**
 * 提前开启下一个流量周期
 * @Board @url POST /user/newPeriod
 * @returns {Promise<object>}
 */
export function startNewTrafficPeriod() {
  return request({
    url: '/user/newPeriod',
    method: 'post',
    data: {}
  });
}

/**
 * getNotices - 获取用户通知
 * @Board @url GET /user/notice/fetch
 * @returns {Promise<Array>} - 通知列表
 * 返回数据：
 * - 通知ID、标题、内容
 * - 发布时间
 * - 已读状态
 * - 通知类型（系统通知、个人通知等）
 */
export function getNotices(options) {
  return cachedGet('/user/notice/fetch', 2 * 60 * 1000, options);
}

/**
 * getUserStats - 获取用户统计数据
 * @Board @url GET /user/getStat
 * @returns {Promise<object>} - 用户统计
 * 返回数据：
 * - 流量使用统计（今日、本月、总计）
 * - 在线设备数
 * - 最近登录记录
 * - 账户活跃度
 */
export function getUserStats() {
  return request({
    url: '/user/getStat',
    method: 'get'
  });
}

/**
 * getUserConfig - 获取用户通用配置
 * @Board @url GET /user/comm/config
 * @returns {Promise<object>} - 通用配置
 * 返回数据：
 * - 货币符号
 * - 货币代码
 * - 时区设置
 * - 语言偏好
 * - 系统功能开关状态
 */
export function getUserConfig(options) {
  return cachedGet('/user/comm/config', 5 * 60 * 1000, options);
}
