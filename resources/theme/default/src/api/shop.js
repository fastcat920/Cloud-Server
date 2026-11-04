import request from './request';

/**
 * fetchPlans - 获取套餐列表
 * @Board @url GET /user/plan/fetch
 * @returns {Promise<Array>} - 套餐列表
 * 返回数据：
 * - 套餐ID、名称、描述
 * - 各个周期的价格
 * - 流量、速度等限制信息
 * - 是否推荐、是否热门等标记
 */
export function fetchPlans() {
  return request({
    url: '/user/plan/fetch',
    method: 'get'
  });
}

/**
 * getCommConfig - 获取系统配置信息
 * @Board @url GET /user/comm/config
 * @returns {Promise<object>} - 系统配置
 * 返回数据：
 * - 货币符号
 * - 货币代码
 * - 系统设置
 * - 其他全局配置
 */
export function getCommConfig() {
  return request({
    url: '/user/comm/config',
    method: 'get'
  });
}

/**
 * fetchPlanById - 根据ID获取套餐详情
 * @Board @url GET /user/plan/fetch?id={id}
 * @param {number|string} id - 套餐ID
 * @returns {Promise<object>} - 套餐详情
 * 返回数据：
 * - 套餐完整信息
 * - 价格、流量、设备限制等详情
 * - 各周期价格
 */
export function fetchPlanById(id) {
  return request({
    url: `/user/plan/fetch?id=${id}`,
    method: 'get'
  });
}

export function fetchCouponWallet() {
  return request({ url: '/user/coupon/wallet', method: 'get' });
}

export function fetchAvailableCoupons(planId, period) {
  return request({
    url: '/user/coupon/available',
    method: 'get',
    params: { plan_id: planId, period }
  });
}

export function previewOrder(data) {
  return request({ url: '/user/order/preview', method: 'post', data });
}

/**
 * submitOrder - 提交订单
 * @Board @url POST /user/order/save
 * @param {object} data - 订单数据
 * @param {number|string} data.plan_id - 套餐ID
 * @param {string} data.period - 支付周期
 * @param {number|null} [data.user_coupon_id] - 用户选择的优惠券 ID
 * @returns {Promise<object>} - 订单创建结果
 * 返回数据：
 * - 订单号
 * - 支付金额
 * - 创建时间
 * - 订单状态
 */
export function submitOrder(data) {
  return request({
    url: '/user/order/save',
    method: 'post',
    data
  });
}

/**
 * getOrderDetail - 获取订单详情
 * @Board @url GET /user/order/detail?trade_no={tradeNo}
 * @param {string} tradeNo - 订单号
 * @returns {Promise<object>} - 订单详情
 * 返回数据：
 * - 订单完整信息
 * - 支付状态
 * - 套餐信息
 * - 优惠信息
 */
export function getOrderDetail(tradeNo) {
  return request({
    url: `/user/order/detail?trade_no=${tradeNo}`,
    method: 'get'
  });
}

/**
 * getPaymentMethods - 获取可用支付方式
 * @Board @url GET /user/order/getPaymentMethod
 * @returns {Promise<Array>} - 支付方式列表
 * 返回数据：
 * - 支付方式ID
 * - 支付方式名称
 * - 支付方式图标
 * - 是否启用
 */
export function getPaymentMethods() {
  return request({
    url: '/user/order/getPaymentMethod',
    method: 'get'
  });
}

/**
 * checkOrderStatus - 检查订单状态
 * @Board @url GET /user/order/check?trade_no={tradeNo}
 * @param {string} tradeNo - 订单号
 * @returns {Promise<object>} - 订单状态
 * 返回数据：
 * - 订单当前状态
 * - 支付状态
 * - 支付时间（如已支付）
 */
export function checkOrderStatus(tradeNo) {
  return request({
    url: `/user/order/check?trade_no=${tradeNo}`,
    method: 'get'
  });
}

/**
 * cancelOrder - 取消订单
 * @Board @url POST /user/order/cancel
 * @param {string} tradeNo - 订单号
 * @returns {Promise<object>} - 取消结果
 * 功能：
 * - 取消未支付的订单
 * - 释放相关资源
 */
export function cancelOrder(tradeNo) {
  return request({
    url: '/user/order/cancel',
    method: 'post',
    data: {
      trade_no: tradeNo
    }
  });
}

/**
 * checkoutOrder - 结算订单
 * @Board @url POST /user/order/checkout
 * @param {string} tradeNo - 订单号
 * @param {number|string} methodId - 支付方式ID
 * @returns {Promise<object>} - 结算结果
 * 返回数据：
 * - 支付URL或二维码
 * - 支付金额
 * - 支付说明
 * - 订单跟踪信息
 */
export function checkoutOrder(tradeNo, methodId) {
  return request({
    url: '/user/order/checkout',
    method: 'post',
    data: {
      trade_no: tradeNo,
      method: methodId
    }
  });
}
