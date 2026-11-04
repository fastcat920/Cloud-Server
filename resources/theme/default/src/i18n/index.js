/**
 * 国际化配置
 */
import { createI18n } from 'vue-i18n';
import { SITE_CONFIG, DEFAULT_CONFIG } from '@/utils/baseConfig';
import { checkLoginStatus } from '@/api/auth';
import { SUPPORTED_LOCALES, normalizeLocale } from '@/utils/locale';

// 仅开放简体中文和英文
const supportedLocales = SUPPORTED_LOCALES;

// 注入应用名称到语言包
const injectSiteName = (messages) => {
  Object.keys(messages).forEach(locale => {
    if (messages[locale]?.common) {
      messages[locale].common.appName = SITE_CONFIG.siteName;
      if (messages[locale].common.welcome && messages[locale].common.welcome.includes('V2Board Admin')) {
        messages[locale].common.welcome = messages[locale].common.welcome.replace('V2Board Admin', SITE_CONFIG.siteName);
      }
    }
  });
  return messages;
};

// 获取浏览器语言
const getBrowserLanguage = () => {
  const browserLang = navigator.language || navigator.userLanguage;
  // 仅英语浏览器默认英文，其余环境统一使用简体中文
  if (browserLang.toLowerCase().startsWith('en')) return 'en-US';
  return 'zh-CN';
};

// 获取存储的语言或默认语言
const getStoredLanguage = () => {
  // 1. 首先检查是否有手动选择的语言（存储在localStorage中）
  const storedLanguage = localStorage.getItem('language');
  if (supportedLocales.includes(storedLanguage)) {
    return storedLanguage;
  }
  if (storedLanguage) {
    localStorage.setItem('language', 'zh-CN');
    return 'zh-CN';
  }
  
  // 2. 如果没有手动选择的语言，使用设备语言
  const browserLanguage = normalizeLocale(getBrowserLanguage());
  if (browserLanguage) {
    return browserLanguage;
  }
  
  // 3. 最后使用配置文件中的默认语言
  return supportedLocales.includes(DEFAULT_CONFIG.defaultLanguage)
    ? DEFAULT_CONFIG.defaultLanguage
    : 'zh-CN';
};

// 动态加载语言文件
const loadLocaleMessages = async (isLoggedIn) => {
  const messages = {};
  
  try {
    // 无论登录状态如何，首先尝试从index文件加载所有语言
    let indexModule = null;
    
    // 根据登录状态从主目录或auth目录加载语言文件
    if (isLoggedIn) {
      // 已登录：尝试从主目录index加载
      try {
        indexModule = await import(/* webpackChunkName: "locale-index" */ './locales/index.js');
      } catch (e) {
        // 安静地处理错误
      }
    } else {
      // 未登录：尝试从auth目录index加载
      try {
        indexModule = await import(/* webpackChunkName: "locale-auth-index" */ './locales/auth/index.js');
      } catch (e) {
        // 安静地处理错误
      }
    }
    
    // 如果成功加载了索引文件，先从中获取语言
    if (indexModule && indexModule.default) {
      for (const locale of supportedLocales) {
        if (indexModule.default[locale]) {
          messages[locale] = indexModule.default[locale];
        }
      }
    }
    
    // 检查是否所有语言都已加载，如果没有，单独加载缺失的语言
    for (const locale of supportedLocales) {
      if (!messages[locale]) {
        try {
          let module = null;
          
          // 使用更明确的导入路径
          if (locale === 'zh-CN') {
            module = await import(/* webpackChunkName: "locale-zh-CN" */ './locales/zh-CN.js');
          } else if (locale === 'en-US') {
            module = await import(/* webpackChunkName: "locale-en-US" */ './locales/en-US.js');
          } 
          
          if (module && module.default) {
            messages[locale] = module.default;
          }
        } catch (e) {
          // 安静地处理错误
          
          // 如果失败且不是英文，尝试加载英文作为后备
          if (locale !== 'en-US') {
            try {
              const fallbackModule = await import(/* webpackChunkName: "locale-en-US-fallback" */ './locales/en-US.js');
              if (fallbackModule && fallbackModule.default) {
                messages[locale] = fallbackModule.default;
              }
            } catch (fallbackError) {
              // 安静地处理错误
            }
          }
        }
      }
    }
  } catch (e) {
    // 安静地处理错误
  }
  
  // 确保所有语言都有内容，如果某个语言为空，使用英文作为后备
  for (const locale of supportedLocales) {
    if (!messages[locale] || Object.keys(messages[locale]).length === 0) {
      try {
        const fallbackModule = await import(/* webpackChunkName: "locale-en-US-fallback" */ './locales/en-US.js');
        if (fallbackModule && fallbackModule.default) {
          messages[locale] = fallbackModule.default;
        }
      } catch (fallbackError) {
        messages[locale] = {};
      }
    }
  }
  
  return injectSiteName(messages);
};

// 创建i18n实例
const i18n = createI18n({
  legacy: false, // 使用组合式API
  locale: getStoredLanguage(),
  fallbackLocale: 'en-US',
  messages: {}, // 初始为空，稍后动态加载
  silentTranslationWarn: true, 
  missingWarn: false, // 禁用缺失警告
  fallbackWarn: false // 禁用回退警告
});

// 切换语言
export const setLanguage = async (lang) => {
  // 检查语言是否支持
  if (!supportedLocales.includes(lang)) {
    lang = 'zh-CN';
  }
  
  // 添加过渡类，防止字体突变
  document.body.classList.add('language-transitioning');
  
  const isLoggedIn = checkLoginStatus();
  
  // 清除现有的语言消息
  for (const locale of supportedLocales) {
    i18n.global.setLocaleMessage(locale, {});
  }
  
  const messages = await loadLocaleMessages(isLoggedIn);
  
  // 设置所有可用的语言消息
  for (const locale in messages) {
    if (messages[locale]) {
      i18n.global.setLocaleMessage(locale, messages[locale]);
    }
  }
  
  // 设置当前语言
  i18n.global.locale.value = lang;
  localStorage.setItem('language', lang);
  document.querySelector('html').setAttribute('lang', lang);
  
  // 立即更新页面标题
  updatePageTitle();
  
  // 延迟移除过渡类
  setTimeout(() => {
    document.body.classList.remove('language-transitioning');
  }, 300);
  
  // 延迟再次更新标题
  setTimeout(() => {
    updatePageTitle();
  }, 300);
  
  return {
    success: true,
    availableLocales: Object.keys(messages)
  };
};

// 更新页面标题的函数
export const updatePageTitle = () => {
  // 检查是否有router和当前路由
  if (window.router?.currentRoute?.value?.meta?.titleKey) {
    const titleKey = window.router.currentRoute.value.meta.titleKey;
    try {
      const translatedTitle = i18n.global.t(titleKey);
      document.title = `${translatedTitle} - ${SITE_CONFIG.siteName}`;
    } catch (error) {
      // 安静地处理错误
      // 回退到网站名称
      document.title = SITE_CONFIG.siteName;
    }
  } else if (window.router?.currentRoute?.value) {
    // 如果当前路由没有titleKey，至少设置网站名称
    document.title = SITE_CONFIG.siteName;
  }
};

// 重新加载语言包（登录/登出时调用）
export const reloadMessages = async () => {
  // 保存当前语言
  const currentLang = i18n.global.locale.value;
  
  // 添加过渡类，防止闪烁
  document.body.classList.add('language-transitioning');
  
  // 检查当前语言包是否已经存在且完整
  const currentMessages = i18n.global.getLocaleMessage(currentLang);
  const hasValidMessages = currentMessages && Object.keys(currentMessages).length > 50; // 简单判断是否有内容
  
  // 如果已经有完整的语言包，跳过重新加载，直接返回
  if (hasValidMessages) {
    // 延迟移除过渡类
    setTimeout(() => {
      document.body.classList.remove('language-transitioning');
    }, 300);
    return { success: true, cached: true };
  }
  
  const isLoggedIn = checkLoginStatus();
  
  // 加载新语言包
  const messages = await loadLocaleMessages(isLoggedIn);
  
  // 先设置新语言包
  for (const locale in messages) {
    if (messages[locale]) {
      i18n.global.setLocaleMessage(locale, messages[locale]);
    }
  }
  
  // 恢复当前语言（确保语言不变）
  if (i18n.global.locale.value !== currentLang && supportedLocales.includes(currentLang)) {
    i18n.global.locale.value = currentLang;
  }
  
  // 更新页面标题
  updatePageTitle();
  
  // 延迟移除过渡类
  setTimeout(() => {
    document.body.classList.remove('language-transitioning');
  }, 300);
  
  return {
    success: true,
    availableLocales: Object.keys(messages)
  };
};

// 初始化标志
let isInitialized = false;
let currentLoadingPromise = null;

// 防止重复加载
const ensureMessagesLoaded = async () => {
  if (currentLoadingPromise) {
    return currentLoadingPromise;
  }
  
  currentLoadingPromise = (async () => {
    const isLoggedIn = checkLoginStatus();
    const messages = await loadLocaleMessages(isLoggedIn);
    
    for (const locale in messages) {
      if (messages[locale]) {
        i18n.global.setLocaleMessage(locale, messages[locale]);
      }
    }
    return messages;
  })();
  
  return currentLoadingPromise;
};

// 初始化加载语言
(async () => {
  if (isInitialized) return;
  isInitialized = true;
  
  try {
    const initialLang = getStoredLanguage();
    
    // 先设置 HTML 语言属性
    document.querySelector('html').setAttribute('lang', initialLang);
    
    // 确保语言包加载完成
    await ensureMessagesLoaded();
    
    // 设置当前语言（如果已经是目标语言，不会触发变化）
    if (i18n.global.locale.value !== initialLang) {
      i18n.global.locale.value = initialLang;
    }
    
    // 页面加载时更新标题
    updatePageTitle();
  } catch (error) {
    console.error('初始化语言失败:', error);
  }
})();

export default i18n;
