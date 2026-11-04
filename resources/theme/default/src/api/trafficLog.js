/**
 * 流量明细相关API
 * 用于获取用户流量使用记录
 */
import request from './request';

/**
 * 获取用户流量使用记录
 * @Board @url GET /user/stat/getTrafficLog
 * @returns {Promise<object>} - 流量使用记录
 * 包含内容：
 * - u: 上行流量（字节）
 * - d: 下行流量（字节）
 * - record_at: 记录时间戳（秒）
 * - user_id: 用户ID
 * - server_rate: 节点倍率（如"1.50"表示1.5倍）
 */
export function getTrafficLog() {
  return request({
    url: '/user/stat/getTrafficLog',
    method: 'get'
  });
} 