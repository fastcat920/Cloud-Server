/**
 * 组件生命周期管理工具
 * 提供在组件激活和停用时处理资源的辅助函数
 */

/**
 * 清理组件中的定时器和事件监听器
 * @param {Object} timers 定时器对象集合
 * @param {Object} listeners 事件监听器集合
 */
export function cleanupResources(timers = {}, listeners = {}) {
  // 清理所有定时器
  Object.keys(timers).forEach(key => {
    if (timers[key]) {
      if (timers[key]._repeat) { // setInterval
        clearInterval(timers[key]);
      } else { // setTimeout
        clearTimeout(timers[key]);
      }
      timers[key] = null;
    }
  });
  
  // 清理所有事件监听器
  Object.keys(listeners).forEach(key => {
    const listener = listeners[key];
    if (listener && listener.element && listener.event && listener.callback) {
      listener.element.removeEventListener(listener.event, listener.callback, listener.options);
      listeners[key] = null;
    }
  });
}

/**
 * 为组件创建防抖更新函数
 * 防抖函数在一段时间内多次调用，只执行最后一次
 * @param {Function} updateFn 更新函数
 * @param {Number} delay 延迟时间（毫秒）
 * @returns {Function} 防抖后的函数
 */
export function createDebouncedUpdate(updateFn, delay = 300) {
  let timer = null;
  
  return function(...args) {
    const context = this;
    
    if (timer) {
      clearTimeout(timer);
    }
    
    timer = setTimeout(() => {
      updateFn.apply(context, args);
      timer = null;
    }, delay);
    
    return timer;
  };
}

/**
 * 为组件创建节流更新函数
 * 节流函数在一段时间内只执行一次，无论调用多少次
 * @param {Function} updateFn 更新函数
 * @param {Number} delay 延迟时间（毫秒）
 * @returns {Function} 节流后的函数
 */
export function createThrottledUpdate(updateFn, delay = 300) {
  let lastExecTime = 0;
  let timer = null;
  
  return function(...args) {
    const context = this;
    const now = Date.now();
    
    // 清除之前可能存在的定时器
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
    
    // 计算距离上次执行的时间
    const elapsed = now - lastExecTime;
    
    if (elapsed >= delay) {
      // 如果已经超过了延迟时间，立即执行
      lastExecTime = now;
      updateFn.apply(context, args);
    } else {
      // 否则设置一个定时器，在延迟结束后执行
      timer = setTimeout(() => {
        lastExecTime = Date.now();
        updateFn.apply(context, args);
        timer = null;
      }, delay - elapsed);
    }
  };
}

/**
 * 组件注册事件监听并保存引用
 * @param {Object} listeners 保存监听器引用的对象
 * @param {String} name 监听器名称
 * @param {Element} element DOM元素
 * @param {String} event 事件名称
 * @param {Function} callback 回调函数
 * @param {Object} options 事件选项
 */
export function registerEventListener(listeners, name, element, event, callback, options = false) {
  // 如果已存在，先移除
  if (listeners[name]) {
    const existing = listeners[name];
    existing.element.removeEventListener(existing.event, existing.callback, existing.options);
  }
  
  // 添加新的事件监听
  element.addEventListener(event, callback, options);
  
  // 保存引用
  listeners[name] = {
    element,
    event,
    callback,
    options
  };
}

/**
 * 安全地创建定时器并保存引用
 * @param {Object} timers 保存定时器引用的对象
 * @param {String} name 定时器名称
 * @param {Function} callback 回调函数
 * @param {Number} delay 延迟时间（毫秒）
 * @param {Boolean} isInterval 是否为setInterval
 * @returns {Number} 定时器ID
 */
export function createTimer(timers, name, callback, delay, isInterval = false) {
  // 如果已存在，先清除
  if (timers[name]) {
    if (timers[name]._repeat) { // setInterval
      clearInterval(timers[name]);
    } else { // setTimeout
      clearTimeout(timers[name]);
    }
    timers[name] = null;
  }
  
  // 创建新的定时器
  const timer = isInterval 
    ? setInterval(callback, delay)
    : setTimeout(callback, delay);
  
  // 保存引用
  timers[name] = timer;
  
  return timer;
}

export default {
  cleanupResources,
  createDebouncedUpdate,
  createThrottledUpdate,
  registerEventListener,
  createTimer
}; 