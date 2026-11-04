<template>
  <div class="main-board">
    <!-- 内容区域 - 路由视图 -->
    <div class="content-area">
      <div v-if="showBackButton" class="route-page-header">
        <PageBackButton @click="handlePageBack" />
        <h1 class="route-page-title">{{ currentPageTitle }}</h1>
      </div>
      <router-view v-slot="{ Component }">
        <transition name="content-transition" mode="out-in" appear>
          <div class="view-wrapper" :key="$route.path">
            <component :is="Component" />
          </div>
        </transition>
      </router-view>
    </div>
    
    <!-- 背景装饰 -->
    <div class="background-decoration">
      <div class="floating-ball ball-1"></div>
      <div class="floating-ball ball-2"></div>
      <div class="floating-ball ball-3"></div>
    </div>
  </div>
</template>

<script>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import PageBackButton from '@/components/common/PageBackButton.vue';

export default {
  name: 'MainBoard',
  components: {
    PageBackButton
  },
  setup() {
    const route = useRoute();
    const router = useRouter();
    const { t } = useI18n();

    const showBackButton = computed(() => route.meta.isRootPage !== true);
    const currentPageTitle = computed(() => {
      const titleKey = route.meta.titleKey;
      return titleKey ? t(titleKey) : '';
    });

    const getFallbackRoute = () => {
      if (route.name === 'Plan') return '/shop';
      if (route.name === 'DocDetail') return '/docs';
      if (route.name === 'InviteUsers') return '/invite';
      if (route.name === 'Payment') {
        return route.query.from === 'orders' ? '/orders' : '/shop';
      }
      return '/mine';
    };

    const handlePageBack = () => {
      if (window.history.state?.back) {
        router.back();
        return;
      }
      router.push(getFallbackRoute());
    };

    return {
      showBackButton,
      currentPageTitle,
      handlePageBack
    };
  }
};
</script>

<style lang="scss" scoped>
.main-board {
  min-height: 100vh;
  position: relative;
  overflow-x: hidden;
  z-index: 1;
}

.content-area {
  position: relative;
  padding: 2rem 6px;
  padding-top: 80px; /* 为导航栏留出空间 */
  
  @media (min-width: 768px) {
    padding: 2rem;
    padding-top: 90px;
  }
  
  @media (min-width: 1200px) {
    padding: 2rem 4rem;
    padding-top: 90px;
  }
}

.route-page-header {
  position: relative;
  z-index: 3;
  width: 100%;
  max-width: var(--page-max-width);
  min-height: 40px;
  margin: 0 auto;
  padding: 0;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  gap: 12px;

  @media (max-width: 767px) {
    padding-inline: 16px;
  }
}

.route-page-title {
  min-width: 0;
  margin: 0;
  color: var(--text-color);
  font-size: 19px;
  font-weight: 700;
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

// 背景装饰
.background-decoration {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: -1;
  overflow: hidden;
  pointer-events: none;
  
  // iOS设备上完全隐藏背景装饰
  @supports (-webkit-touch-callout: none) {
    display: none;
  }
}

.floating-ball {
  position: absolute;
  border-radius: 50%;
  filter: blur(60px);
  opacity: 0.3;
  mix-blend-mode: lighten;
}

.ball-1 {
  width: 600px;
  height: 600px;
  background: radial-gradient(circle at 30% 30%, 
    rgba(var(--theme-color-rgb), 0.4),
    rgba(var(--theme-color-rgb), 0.1) 70%,
    transparent
  );
  top: -10%;
  left: -10%;
  animation: floatingBall1 25s infinite ease-in-out;
}

.ball-2 {
  width: 500px;
  height: 500px;
  background: radial-gradient(circle at 70% 70%, 
    rgba(167, 71, 254, 0.35),
    rgba(167, 71, 254, 0.08) 70%,
    transparent
  );
  top: 40%;
  right: -5%;
  animation: floatingBall2 30s infinite ease-in-out;
}

.ball-3 {
  width: 450px;
  height: 450px;
  background: radial-gradient(circle at 50% 50%, 
    rgba(55, 222, 201, 0.3),
    rgba(55, 222, 201, 0.05) 70%,
    transparent
  );
  bottom: -10%;
  left: 20%;
  animation: floatingBall3 35s infinite ease-in-out;
}

// 内容区域过渡动画
.content-transition-enter-active,
.content-transition-leave-active {
  transition: opacity 0.3s ease, transform 0.3s ease;
}

.content-transition-enter-from,
.content-transition-leave-to {
  opacity: 0;
  transform: translateY(3px); //页面上浮效果
}

// 动画关键帧
@keyframes floatingBall1 {
  0%, 100% { transform: translate(0, 0) rotate(0deg); }
  25% { transform: translate(5%, 10%) rotate(90deg); }
  50% { transform: translate(2%, 5%) rotate(180deg); }
  75% { transform: translate(-3%, 8%) rotate(270deg); }
}

@keyframes floatingBall2 {
  0%, 100% { transform: translate(0, 0) rotate(0deg); }
  25% { transform: translate(-8%, -5%) rotate(-90deg); }
  50% { transform: translate(-4%, -10%) rotate(-180deg); }
  75% { transform: translate(-6%, -2%) rotate(-270deg); }
}

@keyframes floatingBall3 {
  0%, 100% { transform: translate(0, 0) rotate(0deg); }
  33% { transform: translate(6%, -8%) rotate(120deg); }
  66% { transform: translate(-4%, -4%) rotate(240deg); }
  100% { transform: translate(0, 0) rotate(360deg); }
}

.view-wrapper {
  width: 100%;
  height: 100%;
}
</style> 
