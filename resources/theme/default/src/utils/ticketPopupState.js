/**
 * Ticket 弹窗状态管理
 * 用于跟踪工单弹窗在当前会话中是否已显示，避免重复显示
 */

let popupShownInSession = false;

/**
 * 检查弹窗是否应该显示
 * @param {Object} config 弹窗配置
 * @returns {Boolean}
 */
export function shouldShowTicketPopup(config) {
  if (!config || !config.enabled) return false;

  // Session 内已显示过
  if (popupShownInSession) return false;

  if (config.cooldownHours > 0) {
    const closeTime = localStorage.getItem('ticket_popup_close_time');
    if (closeTime) {
      const elapsed = Date.now() - parseInt(closeTime);
      if (elapsed < config.cooldownHours * 60 * 60 * 1000) {
        return false;
      }
    }
  }
  popupShownInSession = true;
  return true;
}

export function resetTicketPopupSession() {
  popupShownInSession = false;
} 