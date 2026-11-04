/**
 * 用户钱包和充值相关API
 * @author EZ THEME
 */
import request from './request';

/**
 * getUserConfig - 获取用户通用配置
 * @Board @url GET /user/comm/config
 * @returns {Promise<object>} - 通用配置
 * 返回数据：
 * - 货币符号
 */
export function getUserConfig() {
  return request({
    url: '/user/comm/config',
    method: 'get'
  });
}

/**
 * 创建充值订单（新接口）
 * @param {number} amount - 充值金额(单位:分)
 * @returns {Promise<object>} - 充值订单信息，返回订单号
 */
export function createOrderDeposit(amount) {
  return request({
    url: '/user/order/save',
    method: 'post',
    data: {
      period: 'deposit',
      deposit_amount: amount,
      plan_id: 0 // 充值固定为0
    }
  });
}

/**
 * 获取余额流水，每页固定10条
 * @param {number} current - 当前页码
 */
export function getBalanceRecords(current = 1) {
  return request({
    url: '/user/balance/records',
    method: 'get',
    params: { current }
  });
}
