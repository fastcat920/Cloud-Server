/**
 * 预加载管理器
 * 用于管理应用中资源的预加载状态
 */

// 预加载状态记录
const preloadStatus = {
  // 组件预加载状态
  components: {},
  
  // 静态资源预加载状态
  resources: {},
  
  // 记录预加载的开始时间
  startTime: null
};

/**
 * 标记组件已预加载
 * @param {string} componentName - 组件名称
 */
const markComponentLoaded = (componentName) => {
  if (!componentName) return;
  preloadStatus.components[componentName] = true;
};

/**
 * 检查组件是否已预加载
 * @param {string} componentName - 组件名称
 * @returns {boolean} - 是否已预加载
 */
const isComponentLoaded = (componentName) => {
  return !!preloadStatus.components[componentName];
};

/**
 * 标记资源已预加载
 * @param {string} resourceUrl - 资源URL
 */
const markResourceLoaded = (resourceUrl) => {
  if (!resourceUrl) return;
  preloadStatus.resources[resourceUrl] = true;
};

/**
 * 检查资源是否已预加载
 * @param {string} resourceUrl - 资源URL
 * @returns {boolean} - 是否已预加载
 */
const isResourceLoaded = (resourceUrl) => {
  return !!preloadStatus.resources[resourceUrl];
};

/**
 * 开始预加载计时
 */
const startPreloadTimer = () => {
  preloadStatus.startTime = Date.now();
};

/**
 * 获取预加载耗时
 * @returns {number} - 预加载耗时(ms)
 */
const getPreloadTime = () => {
  if (!preloadStatus.startTime) return 0;
  return Date.now() - preloadStatus.startTime;
};

/**
 * 重置预加载状态
 */
const resetPreloadStatus = () => {
  preloadStatus.components = {};
  preloadStatus.resources = {};
  preloadStatus.startTime = null;
};

/**
 * 获取预加载统计信息
 * @returns {Object} - 预加载统计信息
 */
const getPreloadStats = () => {
  return {
    componentsCount: Object.keys(preloadStatus.components).length,
    resourcesCount: Object.keys(preloadStatus.resources).length,
    time: getPreloadTime()
  };
};

/**
 * 创建预加载资源的link元素
 * @param {string} href - 资源URL
 * @param {string} as - 资源类型 (script, style, font, image等)
 * @param {string} type - 资源MIME类型
 * @returns {HTMLElement} - 创建的link元素
 */
const createPreloadLink = (href, as, type) => {
  if (!href || isResourceLoaded(href)) return null;
  
  const link = document.createElement('link');
  link.rel = 'preload';
  link.href = href;
  link.as = as || 'script';
  if (type) link.type = type;
  
  // 标记资源已预加载
  markResourceLoaded(href);
  
  return link;
};

// 导出工具函数
export default {
  markComponentLoaded,
  isComponentLoaded,
  markResourceLoaded,
  isResourceLoaded,
  startPreloadTimer,
  getPreloadTime,
  resetPreloadStatus,
  getPreloadStats,
  createPreloadLink
}; 