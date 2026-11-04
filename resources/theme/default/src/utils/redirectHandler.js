/**
 * 处理重定向路径，根据传入的路径确定最终应该跳转的目标路径
 * @param {string} redirectPath - 原始重定向路径
 * @returns {string} 处理后的目标路径
 */
export function handleRedirectPath(redirectPath) {
  let targetPath = '/dashboard'; // 默认重定向到首页

  if (redirectPath) {
    // 如果重定向路径是plan或shop，则重定向到shop页面
    if (redirectPath === '/plan' || redirectPath === '/shop' || redirectPath === 'plan' || redirectPath === 'shop') {
      targetPath = '/shop';
    } else if (redirectPath === '/knowledge' || redirectPath === '/docs' || redirectPath === 'knowledge' || redirectPath === 'docs') {
      targetPath = '/docs';  
    } else if (redirectPath === '/profile' || redirectPath === 'profile') {
      targetPath = '/profile'; 
    } else if (redirectPath === '/order' || redirectPath === '/orders' || redirectPath === 'order' || redirectPath === 'orders') {
      targetPath = '/orders'; 
    }
    // 其他有效路径，直接使用
    else if (redirectPath.startsWith('/')) {
      targetPath = redirectPath;
    }
    // 处理不带斜杠前缀的路径
    else {
      targetPath = `/${redirectPath}`;
    }
  }

  return targetPath;
}

/**
 * 从URL查询参数中获取重定向路径并处理
 * @param {Object} route - Vue Router路由对象
 * @returns {string} 处理后的目标路径
 */
export function getRedirectFromQuery(route) {
  const redirect = route.query.redirect;
  return handleRedirectPath(redirect);
}

/**
 * 执行重定向操作
 * @param {Object} router - Vue Router实例
 * @param {Object} route - 当前路由对象
 */
export function performRedirect(router, route) {
  const targetPath = getRedirectFromQuery(route);
  // 使用replace避免在历史记录中创建新条目
  router.replace(targetPath);
} 