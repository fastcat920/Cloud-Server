import request from './request';

/**
 * fetchKnowledgeList - 获取知识库文档列表
 * @Board @url GET /user/knowledge/fetch?keyword={keyword}
 * @param {string} keyword - 可选搜索关键词，后端会同时匹配中英文字段
 * @returns {Promise<object>} - 文档列表
 * 返回数据：
 * - 文档分类
 * - 每个分类下的文档列表
 * - 文档ID、标题和描述
 * 特点：
 * - 多语言由请求头 X-Locale / Accept-Language 决定
 * - 包含错误处理和格式验证
 */
export function fetchKnowledgeList(keyword = '') {
  const normalizedKeyword = String(keyword || '').trim();

  return request({
    url: '/user/knowledge/fetch',
    method: 'get',
    params: normalizedKeyword ? { keyword: normalizedKeyword } : undefined
  }).then(response => {
    // 检查响应格式是否正确
    if (typeof response === 'object') {
      return response;
    }
    
    // 如果响应不是对象，则抛出错误
    throw new Error('Invalid response format');
  }).catch(error => {
    console.error('Error fetching knowledge list:', error);
    throw error;
  });
}

/**
 * fetchKnowledgeDetail - 获取知识库文档详情
 * @Board @url GET /user/knowledge/fetch?id={id}
 * @param {number|string} id - 文档ID
 * @returns {Promise<object>} - 文档详情
 * 返回数据：
 * - 文档标题
 * - 文档内容 (HTML格式)
 * - 文档更新时间
 * - 相关文档推荐
 * 特点：
 * - 多语言由请求头 X-Locale / Accept-Language 决定
 * - 包含错误处理和格式验证
 */
export function fetchKnowledgeDetail(id) {
  return request({
    url: '/user/knowledge/fetch',
    method: 'get',
    params: {
      id,
      // 文档可能刚在后台更新，使用唯一 URL 避免浏览器或 CDN 返回旧响应。
      _t: Date.now()
    }
  }).then(response => {
    // 检查响应格式是否正确
    if (typeof response === 'object') {
      return response;
    }
    
    // 如果响应不是对象，则抛出错误
    throw new Error('Invalid response format');
  }).catch(error => {
    console.error('Error fetching knowledge detail:', error);
    throw error;
  });
}
