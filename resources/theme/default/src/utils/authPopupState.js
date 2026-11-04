/**
 * Auth弹窗状态管理
 * 用于跟踪弹窗在当前会话中是否已显示，避免重复显示
 */

// 当前会话中弹窗是否已显示
let popupShownInSession = false;

/**
 * 检查弹窗是否应该显示
 * @param {Object} config 弹窗配置
 * @returns {Boolean} 是否应该显示弹窗
 */
export function shouldShowAuthPopup(config) {
  // 如果弹窗未启用，不显示
  if (!config || !config.enabled) {
    return false;
  }
  
  // 如果当前会话已显示过，不再显示
  if (popupShownInSession) {
    return false;
  }
  
  // 检查冷却时间
  if (config.cooldownHours > 0) {
    const closeTime = localStorage.getItem('auth_popup_close_time');
    if (closeTime) {
      const now = new Date().getTime();
      const elapsed = now - parseInt(closeTime);
      const cooldownMs = config.cooldownHours * 60 * 60 * 1000;
      
      // 如果未过冷却期，不显示
      if (elapsed < cooldownMs) {
        return false;
      }
    }
  }
  
  // 标记当前会话已显示
  popupShownInSession = true;
  return true;
}

/**
 * 重置会话显示状态
 * 用于测试或特殊情况下强制显示弹窗
 */
export function resetPopupSessionState() {
  popupShownInSession = false;
} 