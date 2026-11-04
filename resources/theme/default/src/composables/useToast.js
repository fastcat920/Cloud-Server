/**
 * Toast通知composable
 */
import { ref } from 'vue';

// 通知存储 - 使用全局状态，确保页面切换时不会丢失
const toasts = ref([]);
let toastId = 0;

// 存储计时器
const toastTimers = {};
const animationTimers = {};
// 存储开始时间和剩余时间
const toastTimes = {};

// 固定所有消息提示时间为3000毫秒（3秒）
const TOAST_DURATION = 3000;

export function useToast() {
  // 创建通知
  const showToast = (message, type = 'info') => {
    // 强制使用统一的时间设置
    const finalDuration = TOAST_DURATION;
    
    const id = toastId++;
    
    const toast = {
      id,
      message,
      type,
      show: false, // 初始状态为 false，不显示
      duration: finalDuration
    };
    
    // 记录初始时间信息
    toastTimes[id] = {
      startTime: Date.now(),
      remainingTime: finalDuration,
      isPaused: false
    };
    
    toasts.value.push(toast);
    
    // 使用 setTimeout 确保 DOM 更新后再添加 show 类
    setTimeout(() => {
      const index = toasts.value.findIndex(t => t.id === id);
      if (index !== -1) {
        toasts.value[index].show = true; // 触发滑入动画
      }
    }, 50);
    
    // 创建计时器
    startToastTimer(id, finalDuration);
    
    return id;
  };
  
  // 启动计时器
  const startToastTimer = (id, duration) => {
    if (toastTimers[id]) {
      clearTimeout(toastTimers[id]);
    }
    
    toastTimers[id] = setTimeout(() => {
      removeToast(id);
    }, duration);
  };
  
  // 暂停计时器
  const pauseToastTimer = (id) => {
    // 如果已经暂停，不做任何操作
    if (!toastTimes[id] || toastTimes[id].isPaused) return;
    
    // 清除当前计时器
    if (toastTimers[id]) {
      clearTimeout(toastTimers[id]);
      delete toastTimers[id];
    }
    
    // 计算已经过去的时间和剩余时间
    const elapsed = Date.now() - toastTimes[id].startTime;
    toastTimes[id].remainingTime = Math.max(0, toastTimes[id].remainingTime - elapsed);
    toastTimes[id].isPaused = true;
    
    // 暂停进度条动画
    const toastElement = document.querySelector(`.toast[data-id="${id}"] .toast-progress-bar`);
    if (toastElement) {
      const computedStyle = window.getComputedStyle(toastElement);
      const transform = computedStyle.getPropertyValue('transform');
      toastElement.style.transform = transform;
      toastElement.style.animationPlayState = 'paused';
    }
  };
  
  // 恢复计时器
  const resumeToastTimer = (id) => {
    // 如果没有暂停，不做任何操作
    if (!toastTimes[id] || !toastTimes[id].isPaused) return;
    
    // 更新开始时间
    toastTimes[id].startTime = Date.now();
    toastTimes[id].isPaused = false;
    
    // 重新启动计时器，使用剩余时间
    startToastTimer(id, toastTimes[id].remainingTime);
    
    // 恢复进度条动画
    const toastElement = document.querySelector(`.toast[data-id="${id}"] .toast-progress-bar`);
    if (toastElement) {
      toastElement.style.animationPlayState = 'running';
    }
  };
  
  // 移除通知
  const removeToast = (id) => {
    const index = toasts.value.findIndex(toast => toast.id === id);
    if (index !== -1) {
      // 清除计时器
      if (toastTimers[id]) {
        clearTimeout(toastTimers[id]);
        delete toastTimers[id];
      }
      
      // 设置隐藏动画
      toasts.value[index].show = false;
      
      // 给动画留出时间
      if (animationTimers[id]) {
        clearTimeout(animationTimers[id]);
      }
      
      animationTimers[id] = setTimeout(() => {
        toasts.value = toasts.value.filter(toast => toast.id !== id);
        delete animationTimers[id];
        delete toastTimes[id];
      }, 400);
    }
  };
  
  // 清除所有通知
  const clearToasts = () => {
    // 清除所有计时器
    Object.keys(toastTimers).forEach(id => {
      clearTimeout(toastTimers[id]);
      delete toastTimers[id];
    });
    
    Object.keys(animationTimers).forEach(id => {
      clearTimeout(animationTimers[id]);
      delete animationTimers[id];
    });
    
    // 清除所有时间记录
    Object.keys(toastTimes).forEach(id => {
      delete toastTimes[id];
    });
    
    toasts.value = [];
  };
  
  // 扩展封装便捷方法，使其不依赖hooks
  showToast.success = (message) => {
    return showToast(message, 'success');
  };
  
  showToast.error = (message) => {
    return showToast(message, 'error');
  };
  
  showToast.warning = (message) => {
    return showToast(message, 'warning');
  };
  
  showToast.info = (message) => {
    return showToast(message, 'info');
  };
  
  return {
    toasts,
    showToast,
    removeToast,
    pauseToastTimer,
    resumeToastTimer,
    clearToasts
  };
} 