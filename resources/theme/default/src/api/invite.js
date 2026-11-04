/**
 * 邀请相关API接口
 */
import request from './request';

/**
 * getInviteData - 获取邀请码和统计数据
 * @Board @url GET /user/invite/fetch
 * @returns {Promise<object>} - 邀请数据
 * 返回数据：
 * - 邀请码
 * - 已邀请用户数
 * - 累计佣金
 * - 可用佣金
 * - 已提现佣金
 */
export function getInviteData() {
  return request({
    url: '/user/invite/fetch',
    method: 'get'
  });
}

/**
 * getReferralProgram - 获取推广等级和里程碑数据
 */
export function getReferralProgram() {
  return request({
    url: '/user/invite/program',
    method: 'get'
  });
}

/**
 * getInviteDetails - 获取邀请明细
 * @Board @url GET /user/invite/details
 * @param {number} current - 当前页码（从1开始）
 * @param {number} pageSize - 每页显示数量
 * @returns {Promise<Array>} - 邀请明细列表
 * 返回数据：
 * - 被邀请用户信息
 * - 注册时间
 * - 消费金额
 * - 获得佣金
 * - 状态
 */
export function getInviteDetails(current, pageSize) {
  return request({
    url: '/user/invite/ledger',
    method: 'get',
    params: {
      current,
      page_size: pageSize
    }
  });
}

export function getInvitedUsers(current = 1, pageSize = 10) {
  return request({
    url: '/user/invite/users',
    method: 'get',
    params: { current, page_size: pageSize }
  });
}

/**
 * getCommissionConfig - 获取佣金配置
 * @Board @url GET /user/comm/config
 * @returns {Promise<object>} - 佣金配置
 * 返回数据：
 * - 佣金比例
 * - 最低提现金额
 * - 提现方式
 * - 结算规则
 */
export function getCommissionConfig() {
  return request({
    url: '/user/comm/config',
    method: 'get'
  });
}

/**
 * generateInviteCode - 生成邀请码
 * @Board @url GET /user/invite/save
 * @returns {Promise<object>} - 生成结果
 * 返回数据：
 * - 新邀请码
 * - 生成时间
 * - 有效期
 */
export function generateInviteCode() {
  return request({
    url: '/user/invite/save',
    method: 'get'
  });
}

/**
 * transferCommission - 佣金划转到余额
 * @Board @url POST /user/transfer
 * @param {number} amount - 划转金额(分)
 * @returns {Promise<object>} - 划转结果
 * 返回数据：
 * - 划转金额
 * - 划转后佣金余额
 * - 划转后账户余额
 * - 交易ID
 */
export function transferCommission(amount) {
  return request({
    url: '/user/transfer',
    method: 'post',
    data: {
      transfer_amount: amount
    }
  });
}

/**
 * withdrawCommission - 佣金提现
 * @Board @url POST /user/ticket/withdraw
 * @param {number} amount - 提现金额(分)
 * @param {string} account - 提现账号
 * @param {string} method - 提现方式
 * @returns {Promise<object>} - 提现结果
 * 功能：
 * - 创建提现工单
 * - 锁定提现金额
 * - 记录提现申请
 */
export function withdrawCommission(amount, account, method) {
  return request({
    url: '/user/ticket/withdraw',
    method: 'post',
    data: {
      withdraw_amount: amount,
      withdraw_account: account,
      withdraw_method: method
    }
  });
}
