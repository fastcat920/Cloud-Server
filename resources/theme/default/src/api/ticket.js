/**
 * 工单相关API
 */
import request from './request';

/**
 * 获取工单列表
 * @Board @url GET /user/ticket/fetch
 * @returns {Promise<Array>} - 工单列表
 * 返回数据：
 * - 工单ID、主题、状态
 * - 创建时间、最后回复时间
 * - 优先级
 * - 工单类型
 */
export function fetchTicketList() {
  return request({
    url: '/user/ticket/fetch',
    method: 'get'
  });
}

/**
 * 创建新工单
 * @Board @url POST /user/ticket/save
 * @param {object} data - 工单数据
 * @param {string} data.subject - 工单主题
 * @param {string} data.message - 工单内容
 * @param {number} data.level - 工单优先级 (0:低, 1:中, 2:高)
 * @returns {Promise<object>} - 创建结果
 * 返回数据：
 * - 工单ID
 * - 创建状态
 * - 工单详情
 */
export function createTicket(data) {
  return request({
    url: '/user/ticket/save',
    method: 'post',
    data
  });
}

/**
 * 获取工单详情和消息记录
 * @Board @url GET /user/ticket/fetch?id={id}
 * @param {number} id - 工单ID
 * @returns {Promise<object>} - 工单详情
 * 返回数据：
 * - 工单基本信息
 * - 消息记录列表
 * - 工单状态
 * - 可执行操作
 */
export function getTicketDetail(id) {
  return request({
    url: `/user/ticket/fetch?id=${id}`,
    method: 'get'
  });
}

/**
 * 回复工单
 * @Board @url POST /user/ticket/reply
 * @param {number} id - 工单ID
 * @param {string} message - 回复内容
 * @returns {Promise<object>} - 回复结果
 * 返回数据：
 * - 回复ID
 * - 回复状态
 * - 更新后的工单状态
 */
export function replyTicket(id, message) {
  return request({
    url: '/user/ticket/reply',
    method: 'post',
    data: {
      id,
      message
    }
  });
}

/**
 * 关闭工单
 * @Board @url POST /user/ticket/close
 * @param {number} id - 工单ID
 * @returns {Promise<object>} - 关闭结果
 * 功能：
 * - 将工单状态标记为已关闭
 * - 停止工单通知
 * - 在消息记录中添加关闭记录
 */
export function closeTicket(id) {
  return request({
    url: '/user/ticket/close',
    method: 'post',
    data: {
      id
    }
  });
} 