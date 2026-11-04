/**
 * 通知工具函数
 */
import { useToast } from '@/composables/useToast';

// 固定所有消息提示时间为3000毫秒（3秒）
const TOAST_DURATION = 3000;

/**
 * 显示通知消息
 * @param {object} options - 通知选项
 * @param {string} options.message - 通知消息
 * @param {string} options.type - 通知类型 (success, error, info, warning)
 * @param {number} options.duration - 通知显示时间（毫秒）
 * @returns {number} 通知ID
 */
export const showToast = (options, legacyType) => {
  let message, type, duration;
  
  // 支持旧式调用方式: showToast(message, type, duration)
  if (typeof options === 'string') {
    message = options;
    type = legacyType || 'info';
    duration = TOAST_DURATION; // 忽略传入的时间，统一使用3秒
  } 
  // 新的对象参数方式: showToast({ message, type, duration })
  else {
    message = options.message;
    type = options.type || 'info';
    duration = TOAST_DURATION; // 忽略传入的时间，统一使用3秒
  }
  
  const { showToast: createToast } = useToast();
  return createToast(message, type, duration);
}; 
