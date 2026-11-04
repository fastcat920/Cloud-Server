/**
 * 页面缓存管理工具
 * 用于控制页面组件是否应该被缓存，以及如何处理缓存
 */

// 默认需要缓存的路由名称列表
const DEFAULT_CACHED_ROUTES = [
  'Dashboard' // 将仪表板页面默认加入缓存
];

// 当前缓存的路由，使用Set提高查找性能
let cachedRoutes = new Set(DEFAULT_CACHED_ROUTES);

// 路由访问时间记录，用于LRU缓存策略
let routeAccessTimes = new Map();

// 缓存最大数量，防止内存泄漏
const MAX_CACHE_COUNT = 5;

/**
 * 获取当前缓存的路由列表
 * @returns {Array} 路由名称数组
 */
export function getCachedRoutes() {
  return Array.from(cachedRoutes);
}

/**
 * 添加路由到缓存列表
 * 使用LRU（最近最少使用）策略管理缓存
 * @param {String} routeName 路由名称
 */
export function addRouteToCache(routeName) {
  if (!routeName) return;
  
  // 更新访问时间
  routeAccessTimes.set(routeName, Date.now());
  
  // 添加到缓存集合
  cachedRoutes.add(routeName);
  
  // 如果超过最大缓存数量，删除最久未使用的路由
  if (cachedRoutes.size > MAX_CACHE_COUNT) {
    let oldestRoute = null;
    let oldestTime = Infinity;
    
    // 找出最久未访问的路由
    for (const [route, time] of routeAccessTimes.entries()) {
      // 跳过默认缓存的路由
      if (DEFAULT_CACHED_ROUTES.includes(route)) continue;
      
      if (time < oldestTime) {
        oldestTime = time;
        oldestRoute = route;
      }
    }
    
    // 如果找到了最久未访问的路由，从缓存中移除
    if (oldestRoute) {
      cachedRoutes.delete(oldestRoute);
      routeAccessTimes.delete(oldestRoute);
    }
  }
}

/**
 * 从缓存列表中移除路由
 * @param {String} routeName 路由名称
 */
export function removeRouteFromCache(routeName) {
  if (!routeName) return;
  
  cachedRoutes.delete(routeName);
  routeAccessTimes.delete(routeName);
}

/**
 * 清空缓存列表，仅保留默认缓存
 */
export function clearCache() {
  cachedRoutes = new Set(DEFAULT_CACHED_ROUTES);
  
  // 重置访问时间记录，仅保留默认路由
  routeAccessTimes = new Map();
  DEFAULT_CACHED_ROUTES.forEach(route => {
    routeAccessTimes.set(route, Date.now());
  });
}

/**
 * 重置所有缓存到初始状态
 */
export function resetCache() {
  clearCache();
}

/**
 * 设置需要缓存的路由列表
 * @param {Array} routes 路由名称数组
 */
export function setCachedRoutes(routes) {
  if (Array.isArray(routes)) {
    cachedRoutes = new Set(routes);
    
    // 更新访问时间记录
    routeAccessTimes = new Map();
    routes.forEach(route => {
      routeAccessTimes.set(route, Date.now());
    });
  }
}

/**
 * 检查路由是否在缓存列表中
 * @param {String} routeName 路由名称
 * @returns {Boolean} 是否被缓存
 */
export function isRouteCached(routeName) {
  return cachedRoutes.has(routeName);
}

/**
 * 更新路由的访问时间，但不添加到缓存
 * @param {String} routeName 路由名称
 */
export function touchRoute(routeName) {
  if (cachedRoutes.has(routeName)) {
    routeAccessTimes.set(routeName, Date.now());
  }
}

export default {
  getCachedRoutes,
  addRouteToCache,
  removeRouteFromCache,
  clearCache,
  resetCache,
  setCachedRoutes,
  isRouteCached,
  touchRoute
}; 