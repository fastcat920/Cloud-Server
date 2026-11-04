import { AUTHORIZED_DOMAINS, SECURITY_CONFIG } from './baseConfig';

/**
 * 检查当前域名是否在授权列表中
 * 获取当前网页域名并与授权列表比对
 * @returns {boolean} 是否授权
 */
export const isDomainAuthorized = () => {
  // 如果禁用了域名检查功能，始终返回已授权
  if (!SECURITY_CONFIG.enableFrontendDomainCheck) {
    return true;
  }
  
  const currentDomain = window.location.hostname; // 获取当前域名
  return AUTHORIZED_DOMAINS.includes(currentDomain); // 检查是否在授权列表中
};

/**
 * 处理非授权域名访问
 * 如果域名未授权，会导致页面卡死
 * @returns {boolean} 域名授权状态
 */
export const handleUnauthorizedDomain = () => {
  // 如果禁用了域名检查功能，直接返回true（授权状态）
  if (!SECURITY_CONFIG.enableFrontendDomainCheck) {
    return true;
  }
  
  if (!isDomainAuthorized()) {
    console.clear(); // 清除控制台内容
    
    /**
     * 阻止页面继续加载和交互，实现"卡死"效果
     * 创建白屏遮罩并禁用所有用户交互
     */
    const blockUI = () => {
      // 创建一个覆盖整个页面的白色元素
      const blocker = document.createElement('div');
      blocker.style.position = 'fixed';
      blocker.style.top = '0';
      blocker.style.left = '0';
      blocker.style.width = '100%';
      blocker.style.height = '100%';
      blocker.style.backgroundColor = '#ffffff';
      blocker.style.zIndex = '999999';
      document.body.appendChild(blocker);
      
      // 禁止页面滚动
      document.body.style.overflow = 'hidden';
      
      /**
       * 禁用所有事件处理函数
       * 阻止用户通过任何方式与页面交互
       */
      const disableEvents = (e) => {
        e.stopPropagation();
        e.preventDefault();
        return false;
      };
      
      // 捕获各种用户交互事件并禁用
      ['click', 'contextmenu', 'mousedown', 'mouseup', 'touchstart', 
       'touchend', 'keydown', 'keyup', 'keypress', 'scroll', 'wheel'].forEach(eventType => {
        document.addEventListener(eventType, disableEvents, { capture: true });
      });
      
      /**
       * 无限循环消耗资源，造成"卡死"效果
       * 通过大量内存分配和操作使浏览器变得极度缓慢
       */
      const startLoop = () => {
        // 禁用无限循环消耗资源的代码
        // 原代码已被注释掉：
        // let arr = [];
        // while (true) {
        //   arr.push(new Array(10000).fill('x').join(''));
        //   if (arr.length > 1000) arr = [];
        // }
      };
      
      // 延迟启动资源消耗循环
      setTimeout(startLoop, 500);
    };
    
    // 执行阻塞功能
    blockUI();
    
    return false;
  }
  
  return true;
}; 