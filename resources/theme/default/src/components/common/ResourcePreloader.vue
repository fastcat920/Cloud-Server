<template>
  <div class="resource-preloader" aria-hidden="true">
    <img v-if="shouldPreloadLogo" src="/theme/default/images/logo.png" alt="" width="1" height="1" />
  </div>
</template>

<script>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';

const routeRecommendations = {
  '/login': [
    { name: 'Dashboard', load: () => import('@/views/dashboard/Dashboard.vue') }
  ],
  '/dashboard': [
    { name: 'Shop', load: () => import('@/views/shop/Shop.vue') },
    { name: 'More', load: () => import('@/views/more/MoreOptions.vue') }
  ],
  '/shop': [
    { name: 'OrderConfirm', load: () => import('@/views/shop/OrderConfirm.vue') }
  ],
  '/orders': [
    { name: 'Payment', load: () => import('@/views/shop/Payment.vue') }
  ],
  '/mine': [
    { name: 'Profile', load: () => import('@/views/profile/UserProfile.vue') }
  ]
};

const loaded = new Set();

export default {
  name: 'ResourcePreloader',
  setup() {
    const route = useRoute();
    let idleHandle = null;
    let timeoutHandle = null;

    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    const constrainedConnection = () => Boolean(
      connection?.saveData || /(^|-)2g$/.test(connection?.effectiveType || '')
    );
    const shouldPreloadLogo = computed(() => !constrainedConnection());

    const cancelScheduled = () => {
      if (idleHandle !== null && window.cancelIdleCallback) window.cancelIdleCallback(idleHandle);
      if (timeoutHandle !== null) clearTimeout(timeoutHandle);
      idleHandle = null;
      timeoutHandle = null;
    };

    const preloadLikelyRoutes = () => {
      if (constrainedConnection() || document.hidden) return;
      const candidates = (routeRecommendations[route.path] || []).slice(0, 2);
      candidates.forEach(candidate => {
        if (loaded.has(candidate.name)) return;
        loaded.add(candidate.name);
        candidate.load().catch(() => loaded.delete(candidate.name));
      });
    };

    const schedule = () => {
      cancelScheduled();
      if (constrainedConnection()) return;
      if (window.requestIdleCallback) {
        idleHandle = window.requestIdleCallback(preloadLikelyRoutes, { timeout: 4000 });
      } else {
        timeoutHandle = setTimeout(preloadLikelyRoutes, 2500);
      }
    };

    onMounted(schedule);
    watch(() => route.path, schedule);
    onBeforeUnmount(cancelScheduled);

    return { shouldPreloadLogo };
  }
};
</script>

<style scoped>
.resource-preloader { display: none; }
</style>
