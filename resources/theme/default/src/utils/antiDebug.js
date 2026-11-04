/**
 * 反调试工具
 * 提供额外的调试防护机制，配合webpack-obfuscator使用
 * 检测并阻止各种调试工具的使用尝试
 * 
 * 如果不需要反调试功能，可以在 baseConfig.js 中将 SECURITY_CONFIG.enableAntiDebugging 设置为 false
 */
import { SECURITY_CONFIG } from './baseConfig';

/**
 * 初始化反调试功能
 * 当检测到调试工具打开时，会阻止页面正常工作
 */
export const initAntiDebug = () => {
  // 如果通过配置禁用了反调试功能，直接返回
  if (!SECURITY_CONFIG.enableAntiDebugging) {
    return;
  }
  
  // 仅在生产环境启用，避免干扰开发
  if (process.env.NODE_ENV !== 'production') {
    // 开发环境下不启用反调试
    return;
  }
  
  /**
   * 防止调试快捷键使用
   * 监听并阻止各种开发者工具快捷键
   */
  document.addEventListener('keydown', (e) => {
    // 阻止F12键 - 开发者工具
    if (e.key === 'F12' || e.keyCode === 123) {
      e.preventDefault();
      return false;
    }
    
    // 阻止常见开发者工具快捷键
    // Ctrl+Shift+I (开发者工具), Ctrl+Shift+J (控制台), Ctrl+Shift+C (元素检查)
    if (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'i' || e.key === 'J' || e.key === 'j' || e.key === 'C' || e.key === 'c')) {
      e.preventDefault();
      return false;
    }
    
    // 阻止查看源代码 Ctrl+U
    if (e.ctrlKey && (e.key === 'U' || e.key === 'u')) {
      e.preventDefault();
      return false;
    }
  }, { capture: true });
  
  /**
   * 定期检查控制台是否打开
   * 使用多种方法检测调试工具并触发防御机制
   */
  // const devtoolsDetector = () => {
  //   const threshold = 160; // 执行时间阈值(毫秒)
    
  //   /**
  //    * 方法1: 使用console.clear执行时间检测
  //    * 当控制台打开时，console操作会变慢
  //    */
  //   const checkConsole = () => {
  //     const startTime = performance.now();
  //     console.clear();
  //     const endTime = performance.now();
      
  //     // 如果执行时间过长，可能是控制台已打开
  //     if (endTime - startTime > threshold) {
  //       // 控制台可能已打开，触发防御措施
  //       triggerDefense();
  //     }
  //   };
    
  //   /**
  //    * 方法2: 使用console.log记录计数检测
  //    * 利用打开的开发者工具对console.log的处理特性
  //    */
  //   const checkDebugger = () => {
  //     const div = document.createElement('div');
  //     let counter = 0;
      
  //     // 临时替换console.log函数
  //     const consoleLog = console.log;
  //     console.log = () => {
  //       counter++;
  //       return undefined;
  //     };
      
  //     // 尝试触发console.log
  //     console.log('%c', div);
      
  //     // 恢复console.log原始函数
  //     console.log = consoleLog;
      
  //     // 打开的控制台会多次处理log命令
  //     return counter > 1;
  //   };
    
  //   /**
  //    * 触发防御措施
  //    * 清空页面内容并强制刷新
  //    */
  //   const triggerDefense = () => {
  //     // 清除所有页面内容
  //     document.body.innerHTML = '';
  //     document.head.innerHTML = '';
      
  //     // 强制页面刷新
  //     window.location.href = window.location.href;
  //   };
    
  //   // 定期执行检查
  //   setInterval(() => {
  //     try {
  //       checkConsole();
  //       if (checkDebugger()) {
  //         triggerDefense();
  //       }
  //     } catch (e) {
  //       // 忽略检查过程中的错误
  //     }
  //   }, 2000);
  // };
  
  // // 启动检测器
  // devtoolsDetector();
}; 