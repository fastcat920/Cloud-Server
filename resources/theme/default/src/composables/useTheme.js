/**
 * 主题管理逻辑
 */
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { THEME_CONFIG } from '@/utils/baseConfig';

// 所有组件共享同一个主题状态，避免各组件分别读取系统主题后互相覆盖。
const sharedTheme = ref(THEME_CONFIG.defaultTheme);

export function useTheme() {
  const theme = sharedTheme;
  
  // 切换主题
  const toggleTheme = () => {
    theme.value = theme.value === 'light' ? 'dark' : 'light';
    localStorage.setItem('theme', theme.value);
    applyTheme(theme.value);
  };
  
  // 应用主题
  const applyTheme = (selectedTheme) => {
    const root = document.documentElement;
    const themeVars = THEME_CONFIG[selectedTheme];
    const isDark = selectedTheme === 'dark';

    // 网站主题是唯一颜色来源，避免设备深色模式继续影响原生控件和组件样式。
    root.dataset.theme = selectedTheme;
    root.style.colorScheme = selectedTheme;
    root.classList.toggle('dark-theme', isDark);
    root.classList.toggle('light-theme', !isDark);
    
    // 立即应用主题类名到body
    document.body.classList.toggle('dark-theme', isDark);
    document.body.classList.toggle('light-theme', !isDark);
    
    // 强制回流和重绘以确保样式正确应用
    // eslint-disable-next-line no-unused-expressions
    document.body.offsetHeight;
    
    // 设置CSS变量
    root.style.setProperty('--theme-color', themeVars.primaryColor);
    root.style.setProperty('--theme-color-rgb', themeVars.primaryColorRgb);
    root.style.setProperty('--theme-on-color', themeVars.primaryForegroundColor || '#FFFFFF');
    
    // 使用计算的hover变量
    root.style.setProperty('--theme-hover-color', themeVars.primaryColorHover);
    root.style.setProperty('--primary-color-hover', themeVars.primaryColorHover);
    
    // 添加延迟，确保背景颜色在DOM渲染后应用
    root.style.setProperty('--background-color', themeVars.backgroundColor);
    root.style.setProperty('--card-background', themeVars.cardBackground);
    root.style.setProperty('--card-background-rgb', themeVars.cardBackgroundRgb);
    root.style.setProperty('--text-color', themeVars.textColor);
    root.style.setProperty('--secondary-text-color', themeVars.secondaryTextColor);
    root.style.setProperty('--border-color', themeVars.borderColor);
    root.style.setProperty('--shadow-color', themeVars.shadowColor);
    
  };
  
  // 初始化主题
  const initTheme = () => {
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme) {
      theme.value = savedTheme;
    } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      theme.value = 'dark';
    }
    
    // 在DOM完全加载后应用主题
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => {
        applyTheme(theme.value);
      });
    } else {
      applyTheme(theme.value);
    }
  };
  
  // 监听主题变化
  watch(theme, (newTheme) => {
    applyTheme(newTheme);
  });
  
  // 监听系统主题变化
  const handleSystemThemeChange = (e) => {
    if (!localStorage.getItem('theme')) {
      theme.value = e.matches ? 'dark' : 'light';
    }
  };
  
  onMounted(() => {
    initTheme();
    
    if (window.matchMedia) {
      const colorSchemeQuery = window.matchMedia('(prefers-color-scheme: dark)');
      colorSchemeQuery.addEventListener('change', handleSystemThemeChange);
    }
  });
  
  onUnmounted(() => {
    if (window.matchMedia) {
      const colorSchemeQuery = window.matchMedia('(prefers-color-scheme: dark)');
      colorSchemeQuery.removeEventListener('change', handleSystemThemeChange);
    }
  });
  
  return {
    theme,
    toggleTheme,
    applyTheme
  };
} 
