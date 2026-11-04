<template>
  <div>
    <!-- 静态布局容器，包含不需要过渡效果的菜单和按钮 -->
    <div class="static-layout" v-if="$route.meta.requiresAuth">
      <!-- 网站名称 -->
      <div class="site-logo">
        <img v-if="siteConfig.showLogo" :src="siteConfig.logo || '/theme/default/images/logo.png'" alt="Logo" class="site-logo-img" @error="useFallbackLogo" />
        {{ siteConfig.siteName }}
      </div>
      
      <!-- 顶部导航栏 - 保持不变 -->
      <SlideTabsNav />
      
      <!-- 顶部工具栏：语言选择器、主题切换和用户头像 -->
      <div class="top-toolbar">
        <button
          class="coupon-shortcut"
          :class="{
            active: $route.path === '/coupons' || $route.path === '/coupon',
            'has-available-coupons': hasAvailableCoupons
          }"
          :title="couponShortcutLabel"
          :aria-label="couponShortcutLabel"
          @click="$router.push('/coupons')"
        >
          <IconTicket :size="21" :stroke-width="2.2" />
        </button>
        <ThemeToggle />
        <LanguageSelector />
        <button 
          v-if="PROFILE_CONFIG.showGiftCardRedeem" 
          class="gift-btn" 
          :title="$t('profile.giftCardTitle')"
          :aria-label="$t('profile.giftCardTitle')"
          @click="$router.push('/profile')"
        >
          <IconGift :size="20" />
        </button>
        <UserAvatar :username="username" :avatarUrl="avatarUrl" />
      </div>
    </div>

    <!-- 认证页面顶部工具栏，确保认证页面也有语言切换器 -->
    <div class="auth-toolbar" v-if="!$route.meta.requiresAuth && $route.path.includes('/auth')">
      <div class="top-toolbar">
        <ThemeToggle />
        <LanguageSelector />
      </div>
    </div>

    <!-- 路由视图只对内容部分应用过渡效果 -->
    <router-view v-slot="{ Component, route }">
      <transition 
        name="page-transition" 
        mode="out-in"
        appear
      >
        <keep-alive :include="cachedRoutes" :max="5">
          <component 
            :is="Component" 
            :key="route.path"
            :is-active="true"
          />
        </keep-alive>
      </transition>
    </router-view>

    <div class="visually-hidden" role="status" aria-live="polite" aria-atomic="true">
      {{ routeAnnouncement }}
    </div>
    
    <!-- 全局Toast通知 - 放在最外层，确保不受页面切换影响 -->
    <Toast />
    
    <!-- 返回顶部按钮 -->
    <BackToTop />
    
    <!-- 自定义鼠标右键菜单 -->
    <CustomContextMenu />
    
    <!-- Crisp嵌入组件 -->
    <CrispEmbed v-if="customerServiceConfig.embedMode === 'embed'" />
    
    <!-- 资源预加载组件 -->
    <ResourcePreloader />
    
    <!-- SVG图标定义 -->
    <IconDefinitions />
  </div>
</template>

<script>
import { onMounted, onUnmounted, ref, computed, provide, watch } from 'vue';
import { useStore } from 'vuex';
import { useTheme } from '@/composables/useTheme';
import { useRouter, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n'; // 添加这行
import { SITE_CONFIG, PROFILE_CONFIG, CUSTOMER_SERVICE_CONFIG } from '@/utils/baseConfig';
import { checkAuthAndReloadMessages } from '@/utils/authUtils';
import { checkUserLoginStatus } from '@/api/auth';
import { getWebsiteConfig } from '@/api/auth';
import { fetchCouponWallet } from '@/api/shop';
import { SUPPORTED_LOCALES } from '@/utils/locale';
import { updatePageTitle } from '@/i18n';
import { handleRedirectPath } from '@/utils/redirectHandler';
import Toast from '@/components/common/Toast.vue';
import IconDefinitions from '@/components/icons/IconDefinitions.vue';
import SlideTabsNav from '@/components/common/SlideTabsNav.vue';
import ThemeToggle from '@/components/common/ThemeToggle.vue';
import LanguageSelector from '@/components/common/LanguageSelector.vue';
import UserAvatar from '@/components/common/UserAvatar.vue';
import BackToTop from '@/components/common/BackToTop.vue';
import CustomContextMenu from '@/components/common/CustomContextMenu.vue';
import CrispEmbed from '@/components/common/CrispEmbed.vue';
import ResourcePreloader from '@/components/common/ResourcePreloader.vue';
import { IconGift, IconTicket } from '@tabler/icons-vue';
import NProgress from 'nprogress';
import 'nprogress/nprogress.css';
import pageCache from '@/utils/pageCache';

// 配置 NProgress
NProgress.configure({ 
  showSpinner: true,
  easing: 'ease',
  speed: 400,
  minimum: 0.2
});

export default {
  name: 'App',
  components: {
    Toast,
    IconDefinitions,
    SlideTabsNav,
    ThemeToggle,
    LanguageSelector,
    UserAvatar,
    BackToTop,
    CustomContextMenu,
    CrispEmbed,
    ResourcePreloader,
    IconGift,
    IconTicket
  },
  setup() {
    const router = useRouter();
    const route = useRoute();
    const store = useStore();
    useTheme();
    const { t, locale, mergeLocaleMessage } = useI18n();
    
    const siteConfig = ref(SITE_CONFIG);
    const cachedRoutes = computed(() => pageCache.getCachedRoutes());
    const customerServiceConfig = computed(() => CUSTOMER_SERVICE_CONFIG);
    const routeAnnouncement = computed(() => route.meta.titleKey ? t(route.meta.titleKey) : siteConfig.value.siteName);
    const availableCouponCount = ref(0);
    const hasAvailableCoupons = computed(() => availableCouponCount.value > 0);
    const couponShortcutLabel = computed(() => hasAvailableCoupons.value
      ? t('couponCenter.availableCount', { count: availableCouponCount.value })
      : t('couponCenter.title'));

    const refreshAvailableCoupons = async () => {
      if (!route.meta.requiresAuth) {
        availableCouponCount.value = 0;
        return;
      }

      try {
        const response = await fetchCouponWallet();
        const coupons = Array.isArray(response?.data) ? response.data : [];
        const now = Math.floor(Date.now() / 1000);
        availableCouponCount.value = coupons.filter(coupon => {
          const startsAt = Number(coupon.starts_at || 0);
          const expiresAt = Number(coupon.expires_at || 0);
          return coupon.status === 'available'
            && (!startsAt || startsAt <= now)
            && (!expiresAt || expiresAt >= now);
        }).length;
      } catch (_) {
        availableCouponCount.value = 0;
      }
    };
    
    // ========== 添加语言持久化逻辑 ==========
    // 初始化语言设置（在组件加载时立即执行）
    const initLanguage = () => {
      let savedLocale = localStorage.getItem('language');
      const browserLang = navigator.language || 'zh-CN';
      const defaultLang = browserLang.toLowerCase().startsWith('en') ? 'en-US' : 'zh-CN';
      
      // 旧版本保存的其他语言统一迁移到简体中文
      if (savedLocale && !SUPPORTED_LOCALES.includes(savedLocale)) {
        savedLocale = 'zh-CN';
        localStorage.setItem('language', savedLocale);
      } else if (!savedLocale) {
        savedLocale = defaultLang;
        localStorage.setItem('language', savedLocale);
      }
      
      // 强制设置语言，避免闪烁
      if (locale.value !== savedLocale) {
        locale.value = savedLocale;
      }
      
      // 设置 HTML lang 属性
      document.documentElement.lang = savedLocale === 'zh-CN' ? 'zh-CN' : 'en';
      
      console.log('语言初始化完成:', savedLocale);
      return savedLocale;
    };
    
    // 强制刷新语言设置（在路由跳转后调用）
    const enforceLanguage = () => {
      const savedLocale = localStorage.getItem('language');
      if (savedLocale && locale.value !== savedLocale) {
        locale.value = savedLocale;
        document.documentElement.lang = savedLocale === 'zh-CN' ? 'zh-CN' : 'en';
      }
    };

    const refreshSiteIdentity = async () => {
      try {
        const response = await getWebsiteConfig();
        const appName = response?.data?.app_name;
        const appDescription = response?.data?.app_description;
        if (appName) {
          SITE_CONFIG.siteName = appName;
          mergeLocaleMessage('zh-CN', { common: { appName, welcome: `欢迎使用 ${appName}` } });
          mergeLocaleMessage('en-US', { common: { appName, welcome: `Welcome to ${appName}` } });
        }
        if (appDescription !== undefined && appDescription !== null) SITE_CONFIG.siteDescription = appDescription;
        siteConfig.value = { ...SITE_CONFIG };
        updatePageTitle();
      } catch (error) {
        console.error('加载本地化站点信息失败:', error);
      }
    };
    
    // 监听语言变化并持久化
    watch(locale, (newLocale) => {
      if (newLocale) {
        localStorage.setItem('language', newLocale);
        document.documentElement.lang = newLocale === 'zh-CN' ? 'zh-CN' : 'en';
        refreshSiteIdentity();
      }
    });
    
    // 监听路由变化，在路由跳转后强制恢复语言
    router.afterEach(() => {
      // 延迟执行，确保组件已经渲染
      setTimeout(() => {
        enforceLanguage();
      }, 0);
      
      // 再次延迟执行，防止异步组件加载导致的语言重置
      setTimeout(() => {
        enforceLanguage();
      }, 50);
    });
    
    // 监听路由变化
    router.beforeEach((to, from, next) => {
      if (to.meta.keepAlive && to.name) {
        pageCache.addRouteToCache(to.name);
      }
      
      if (from.name && from.meta.keepAlive === false) {
        pageCache.removeRouteFromCache(from.name);
      }
      
      NProgress.start();
      next();
    });
    
    router.afterEach(() => {
      NProgress.done();
    });
    
    // 处理URL中的redirect参数
    const handleRedirectParam = () => {
      let redirectParam = null;
      let verifyParam = null;
      
      const hashParts = window.location.hash.split('?');
      if (hashParts.length > 1) {
        const hashParams = new URLSearchParams(hashParts[1]);
        redirectParam = hashParams.get('redirect');
        verifyParam = hashParams.get('verify');
      }

      const pageParams = new URLSearchParams(window.location.search);
      verifyParam = verifyParam || route.query.verify || pageParams.get('verify');

      // redirect 属于快速登录流程时，必须等 verify 验证成功后再处理。
      // 否则已挂载的 App 会抢先跳回 dashboard，导致登录组件没有机会验证新账号。
      if (verifyParam) return;
      
      if (!redirectParam) {
        redirectParam = route.query.redirect || pageParams.get('redirect');
      }
      
      if (redirectParam && typeof redirectParam === 'string') {
        const targetPath = handleRedirectPath(redirectParam);
        if (route.path !== targetPath) {
          console.log('重定向到:', targetPath);
          router.replace(targetPath);
        }
      }
    };
    
    watch(() => route.fullPath, () => {
      handleRedirectParam();
      refreshAvailableCoupons();
    });
    
    const username = computed(() => store.getters.username);
    const avatarUrl = computed(() => store.getters.avatarUrl || '');
    
    const languageChangedSignal = ref(0);
    
    const onLanguageChanged = () => {
      languageChangedSignal.value++;
      setTimeout(() => {
        document.body.classList.add('language-transitioning');
        setTimeout(() => {
          document.body.classList.remove('language-transitioning');
        }, 300);
      }, 0);
    };
    
    const handleVisibilityChange = () => {
      if (!document.hidden) {
        checkAuthAndReloadMessages();
        refreshAvailableCoupons();
        
        checkUserLoginStatus().then(result => {
          if (result.isLoggedIn === false && result.message) {
            const { showToast } = require('@/composables/useToast').useToast();
            if (showToast) {
              showToast(result.message, 'warning');
            }
          }
        }).catch(err => {
          console.error('检查登录状态出错:', err);
        });
      }
    };
    
    provide('languageChangedSignal', languageChangedSignal);
    
    const clearCache = () => {
      pageCache.clearCache();
    };
    
    const removeCachedRoute = (routeName) => {
      pageCache.removeRouteFromCache(routeName);
    };
    
    provide('clearCache', clearCache);
    provide('removeCachedRoute', removeCachedRoute);
    
    onMounted(() => {
      // 初始化语言（优先执行）
      initLanguage();
      refreshSiteIdentity();
      refreshAvailableCoupons();
      
      window.addEventListener('languageChanged', onLanguageChanged);
      checkAuthAndReloadMessages();
      document.addEventListener('visibilitychange', handleVisibilityChange);
      
      checkUserLoginStatus().then(result => {
        if (result.isLoggedIn === false && result.message) {
          const { showToast } = require('@/composables/useToast').useToast();
          if (showToast) {
            showToast(result.message, 'warning');
          }
        }
      }).catch(err => {
        console.error('检查登录状态出错:', err);
      });
      
      handleRedirectParam();
    });
    
    onUnmounted(() => {
      window.removeEventListener('languageChanged', onLanguageChanged);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    });

    const useFallbackLogo = event => {
      if (event.target.src.endsWith('/theme/default/images/logo.png')) return;
      event.target.src = '/theme/default/images/logo.png';
    };
    
    return {
      username,
      avatarUrl,
      siteConfig,
      PROFILE_CONFIG,
      cachedRoutes,
      customerServiceConfig,
      routeAnnouncement,
      hasAvailableCoupons,
      couponShortcutLabel,
      useFallbackLogo
    };
  }
};
</script>

<style lang="scss">
@use "sass:math";
@use "assets/styles/base/variables.scss" as *;
@use "assets/styles/base/reset.scss" as *;
@use "assets/styles/base/animations.scss" as *;
@use "assets/styles/base/scrollbar.scss" as *;

/* 强制隐藏所有球形 */
.background-decoration,
.floating-ball,
[class*="floating-ball"],
[class*="background-decoration"] {
  display: none !important;
  visibility: hidden !important;
  opacity: 0 !important;
  animation: none !important;
  pointer-events: none !important;
}


/* 防止语言切换时的内容闪烁 */
html, body, #app {
  transition: opacity 0.1s ease;
}

/* 确保页面加载时内容不闪烁 */
#app {
  opacity: 1;
  animation: none;
}

/* 防止文本在语言切换时闪烁 */
.language-transitioning {
  pointer-events: none;
}

.language-transitioning * {
  animation: none !important;
  transition: none !important;
}

/* 页面过渡相关的样式 */
.page-transitioning {
  overflow: hidden;
}

/* 静态布局部分 */
.static-layout {
  position: fixed;
  width: 100%;
  top: 0;
  left: 0;
  z-index: 100;
  pointer-events: none;

  > * {
    pointer-events: auto;
  }
}

/* 网站Logo样式 */
.site-logo {
  position: fixed;
  top: max(16px, env(safe-area-inset-top));
  left: max(20px, env(safe-area-inset-left));
  max-width: min(36vw, 320px);
  font-size: clamp(16px, 1.4vw, 20px);
  font-weight: 700;
  color: var(--theme-color);
  z-index: 110;
  letter-spacing: -0.02em;
  background-color: var(--card-background);
  border: 1px solid var(--card-border);
  padding: 7px 12px;
  border-radius: var(--radius-control);
  box-shadow: 0 4px 16px var(--card-shadow);
  transition: background-color 0.2s ease, border-color 0.2s ease;
  display: flex;
  align-items: center;
  gap: 10px;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  
  .site-logo-img {
    height: 24px;
    width: 24px;
    border-radius: 6px;
    object-fit: cover;
  }
}

/* 暗色模式下的Logo样式调整 */
.dark-theme .site-logo {
  background-color: var(--card-background);
}

/* 顶部工具栏 */
.top-toolbar {
  position: fixed;
  top: max(16px, env(safe-area-inset-top));
  right: max(20px, env(safe-area-inset-right));
  display: flex;
  gap: 8px;
  z-index: 110;

  > button,
  > :deep(button),
  > :deep(.avatar-wrapper) {
    flex: 0 0 auto;
  }

  .coupon-shortcut {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    padding: 0;
    overflow: hidden;
    color: var(--theme-color);
    background: rgba(var(--theme-color-rgb), .1);
    border: 1px solid rgba(var(--theme-color-rgb), .3);
    border-radius: 50%;
    box-shadow: none;
    cursor: pointer;
    transition: transform var(--motion-base) ease, box-shadow var(--motion-base) ease;

    svg { position: relative; z-index: 1; }
    &:hover { transform: translateY(-2px); background: rgba(var(--theme-color-rgb), .16); }
    &:active { transform: translateY(0) scale(.96); }
    &.active { box-shadow: 0 0 0 3px rgba(var(--theme-color-rgb), .16); }

    &.has-available-coupons {
      color: #fff;
      background: linear-gradient(135deg, #ff9f1c 0%, #ff4d8d 52%, #7c5cff 100%);
      border-color: rgba(255, 255, 255, .42);
      box-shadow: 0 5px 14px rgba(255, 77, 141, .28);

      &:hover {
        transform: translateY(-2px) rotate(-5deg);
        box-shadow: 0 8px 18px rgba(255, 77, 141, .38);
      }

      &.active {
        box-shadow: 0 0 0 3px rgba(255, 77, 141, .2), 0 7px 17px rgba(124, 92, 255, .3);
      }
    }
  }
  
  .gift-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 12px;
    background-color: rgba(var(--theme-color-rgb), 0.1);
    border: 1px solid rgba(var(--theme-color-rgb), 0.3);
    color: var(--theme-color);
    cursor: pointer;
    transition: color var(--motion-base) ease, background-color var(--motion-base) ease,
      border-color var(--motion-base) ease, box-shadow var(--motion-base) ease,
      transform var(--motion-fast) ease;
    
    &:hover {
      box-shadow: 0 0 0 3px rgba(var(--theme-color-rgb), 0.15);
      transform: translateY(-2px);
    }
  }
}

/* 移动端响应式布局调整 */
@media (max-width: 768px) {
  .site-logo {
    top: max(12px, env(safe-area-inset-top));
    left: max(16px, env(safe-area-inset-left));
    max-width: calc(100vw - 230px);
    padding: 6px 10px;
    border-radius: 12px;
  }
  
  .top-toolbar {
    top: max(12px, env(safe-area-inset-top));
    right: max(16px, env(safe-area-inset-right));
    gap: 8px;
  }
  
  /* 为底部导航栏预留空间 */
  main, .main-content, .content-container, .content-area {
    padding-bottom: calc(96px + env(safe-area-inset-bottom)) !important;
    margin-bottom: 0 !important;
  }
}


/* 页面过渡动画 */
.page-transition-enter-active,
.page-transition-leave-active {
  transition: opacity 0.3s ease;
}

.page-transition-enter-from {
  opacity: 0;
}

.page-transition-leave-to {
  opacity: 0;
}

/* 语言切换过渡效果 */
.language-transitioning .language-transition-item {
  animation: language-fade 0.3s ease-out;
}

@keyframes language-fade {
  0% {
    opacity: 0.2;
  }
  100% {
    opacity: 1;
  }
}

/* 保留淡入淡出效果以兼容现有代码 */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* 自定义滚动条样式 */
::-webkit-scrollbar {
  width: 0px;
  height: 0px;
  display: none;
}

::-webkit-scrollbar-track {
  background-color: rgba(var(--theme-color-rgb), 0.1); /* 主题色 30% 透明度 */
  border-radius: 0px;
}

::-webkit-scrollbar-thumb {
  background-color: rgba(var(--theme-color-rgb), 0.1); /* 主题色 30% 透明度 */
  border-radius: 1px;
  opacity: 0.7;
  transition: background-color 0.3s ease;
}

::-webkit-scrollbar-thumb:hover {
  background-color: rgba(var(--theme-color-rgb), 0.1);
}

::-webkit-scrollbar-corner {
  background-color: transparent;
}

/* Firefox 滚动条样式 */
* {
  scrollbar-width: thin;
  scrollbar-color: rgba(var(--theme-color-rgb), 0.3) var(--input-bg-color, rgba(0, 0, 0, 0.05));
  }

/* 确保滚动效果平滑 */
html {
  scroll-behavior: smooth;
}

/* 为认证页面添加工具栏样式 */
.auth-toolbar {
  position: fixed;
  top: 0;
  right: 0;
  z-index: 100;
  
  .top-toolbar {
    position: fixed;
    top: 20px;
    right: 25px;
    display: flex;
    gap: 12px;
    z-index: 110;
  }
}

/* 确保eztheme-btn样式不受任何Markdown链接样式的影响 */
.eztheme-btn {
  text-decoration: none !important;
  border-bottom: none !important;
  background-image: none !important;
  background-repeat: no-repeat !important;
  background-position: initial !important;
  background-size: initial !important;
  
  &:hover, &:active, &:focus, &:visited {
    text-decoration: none !important;
    border-bottom: none !important;
  }
  
  &::after, &::before {
    display: none !important;
    content: none !important;
  }
}

/* 自定义 NProgress 样式 */
#nprogress {
  pointer-events: none;
  
  .bar {
    background: var(--theme-color);
    position: fixed;
    z-index: 1031;
    top: 0;
    left: 0;
    width: 100%;
    height: 2px;
    box-shadow: 0 0 10px var(--theme-color), 0 0 5px var(--theme-color);
  }
  
  /* 自定义加载圆圈样式 */
  .spinner {
    display: block;
    position: fixed;
    z-index: 1031;
    top: 10px;  /* 距离顶部的距离 */
    left: 10px; /* 距离左侧的距离 */
    
    .spinner-icon {
      width: 18px;
      height: 18px;
      box-sizing: border-box;
      border: solid 2px transparent;
      border-top-color: var(--theme-color);
      border-left-color: var(--theme-color);
      border-radius: 50%;
      animation: nprogress-spinner 400ms linear infinite;
    }
  }
}

@keyframes nprogress-spinner {
  0% {
    transform: rotate(0deg);
  }
  100% {
    transform: rotate(360deg);
  }
}

/* 确保进度条在最顶层 */
.nprogress-custom-parent {
  overflow: hidden;
  position: relative;
}

.nprogress-custom-parent #nprogress .bar {
  position: absolute;
}
</style> 
