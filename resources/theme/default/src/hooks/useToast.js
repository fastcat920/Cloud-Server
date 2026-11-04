import { useToast as useAppToast } from '@/composables/useToast';

/**
 * Toast提示钩子函数
 * @returns {Object} Toast相关的方法
 */
export default function useToast() {
  const { showToast } = useAppToast();

  /**
   * 显示成功提示
   * @param {string} message - 提示消息
   * @param {number} duration - 显示时长（毫秒）
   */
  const success = (message, duration = 3000) => {
    showToast(message, 'success', duration);
  };

  /**
   * 显示错误提示
   * @param {string} message - 提示消息
   * @param {number} duration - 显示时长（毫秒）
   */
  const error = (message, duration = 3000) => {
    showToast(message, 'error', duration);
  };

  /**
   * 显示警告提示
   * @param {string} message - 提示消息
   * @param {number} duration - 显示时长（毫秒）
   */
  const warning = (message, duration = 3000) => {
    showToast(message, 'warning', duration);
  };

  /**
   * 显示信息提示
   * @param {string} message - 提示消息
   * @param {number} duration - 显示时长（毫秒）
   */
  const info = (message, duration = 3000) => {
    showToast(message, 'info', duration);
  };

  return {
    success,
    error,
    warning,
    info
  };
} 