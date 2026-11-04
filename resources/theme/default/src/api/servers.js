import request from './request';

/**
 * fetchServerNodes - 获取服务器节点列表
 * @Board @url GET /user/server/fetch
 * @returns {Promise<Array>} - 节点列表
 * 返回数据：
 * - 节点ID、名称、地址
 * - 节点类型、协议支持
 * - 带宽限制
 * - 节点状态（在线/离线）
 * - 节点分组和标签
 * - 地理位置信息
 * 特点：
 * - 包含错误处理和格式验证
 * - 节点可能按区域或类型分组
 */
export function fetchServerNodes() {
  return request({
    url: '/user/server/fetch',
    method: 'get'
  }).then(response => {
    // 检查响应格式是否正确
    if (typeof response === 'object') {
      return response;
    }
    
    // 如果响应不是对象，则抛出错误
    throw new Error('Invalid response format');
  }).catch(error => {
    console.error('Error fetching server nodes:', error);
    throw error;
  });
} 