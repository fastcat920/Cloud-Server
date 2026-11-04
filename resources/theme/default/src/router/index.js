/**
 * 路由配置
 */
import { createRouter, createWebHashHistory } from 'vue-router';
import { SITE_CONFIG, DEFAULT_CONFIG, isBrowserRestricted, TRAFFICLOG_CONFIG, AUTH_LAYOUT_CONFIG } from '@/utils/baseConfig';
import i18n from '@/i18n';
import pageCache from '@/utils/pageCache';

// 路由懒加载
const LandingPage = () => import('@/views/landing/LandingPage.vue');
const CustomLandingPage = () => import('@/views/landing/CustomLandingPage.vue');
const ApiValidation = () => import('@/views/errors/ApiValidation.vue');

// 动态根据配置选择登录页面布局
const getAuthComponent = (componentName) => {
  const layoutType = AUTH_LAYOUT_CONFIG?.layoutType || 'center';
  return () => import(`@/views/auth/${layoutType}/${componentName}.vue`);
};

const Login = getAuthComponent('Login');
const Register = getAuthComponent('Register');
const ForgotPassword = getAuthComponent('ForgotPassword');
const Dashboard = () => import('@/views/dashboard/Dashboard.vue');
const MainBoard = () => import('@/views/layout/MainBoard.vue');
const Profile = () => import('@/views/profile/UserProfile.vue');
const BrowserRestricted = () => import('@/views/errors/BrowserRestricted.vue');
const NotFound = () => import('@/views/errors/NotFound.vue');
const routes = [
  {
    path: '/',
    redirect: DEFAULT_CONFIG.enableLandingPage ? '/landing' : '/login'
  },
  {
    path: '/api-validation',
    name: 'ApiValidation',
    component: ApiValidation,
    meta: {
      titleKey: 'common.apiChecking',
      requiresAuth: false
    }
  },
  {
    path: '/landing',
    name: 'Landing',
    component: getCustomOrDefaultLandingPage(),
    meta: {
      titleKey: 'landing.mainText',
      requiresAuth: false
    },
    beforeEnter: (to, from, next) => {
      // 如果禁用了落地页，则重定向到登录页
      if (!DEFAULT_CONFIG.enableLandingPage) {
        next('/login');
      } else {
        next();
      }
    }
  },
  {
    path: '/login',
    name: 'Login',
    component: Login,
    meta: {
      titleKey: 'common.login',
      requiresAuth: false
    }
  },
  {
    path: '/register',
    name: 'Register',
    component: Register,
    meta: {
      titleKey: 'common.register',
      requiresAuth: false,
      keepAlive: true
    }
  },
  {
    path: '/forgot-password',
    name: 'ForgotPassword',
    component: ForgotPassword,
    meta: {
      titleKey: 'common.forgotPassword',
      requiresAuth: false,
      keepAlive: true
    }
  },
  {
    path: '/browser-restricted',
    name: 'BrowserRestricted',
    component: BrowserRestricted,
    meta: {
      titleKey: 'errors.browserRestricted',
      requiresAuth: false
    }
  },
  {
    path: '/',
    component: MainBoard,
    meta: { 
      requiresAuth: true 
    },
    children: [
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: Dashboard,
        meta: {
          titleKey: 'menu.dashboard',
          requiresAuth: true,
          keepAlive: true,
          isRootPage: true
        }
      },
      {
        path: 'shop',
        name: 'Shop',
        component: () => import('@/views/shop/Shop.vue'),
        meta: {
          titleKey: 'menu.shop',
          requiresAuth: true,
          keepAlive: true,
          isRootPage: true
        }
      },
      {
        path: 'plan/:id',
        name: 'Plan',
        component: () => import('@/views/shop/OrderConfirm.vue'),
        meta: {
          titleKey: 'orders.confirmOrder',
          requiresAuth: true,
          activeNav: 'Shop' // 激活顶部菜单的"商店"标签
        }
      },
      {
        path: 'payment',
        name: 'Payment',
        component: () => import('@/views/shop/Payment.vue'),
        meta: {
          titleKey: 'orders.payment',
          requiresAuth: true,
          activeNav: 'Shop' // 激活顶部菜单的"商店"标签
        }
      },
      {
        path: 'invite',
        name: 'Invite',
        component: () => import('@/views/invite/Invite.vue'),
        meta: {
          titleKey: 'menu.invite',
          requiresAuth: true,
          keepAlive: true,
          isRootPage: true
        }
      },
      {
        path: 'invite/users',
        name: 'InviteUsers',
        component: () => import('@/views/invite/InviteUsers.vue'),
        meta: { titleKey: 'invite.users.title', requiresAuth: true, activeNav: 'Invite' }
      },
      {
        path: 'coupons',
        alias: 'coupon',
        name: 'Coupons',
        component: () => import('@/views/coupon/CouponWallet.vue'),
        meta: { titleKey: 'couponCenter.title', requiresAuth: true, activeNav: 'Mine' }
      },
      {
        path: 'membership',
        redirect: '/invite'
      },
      {
        path: 'mine',
        name: 'Mine',
        component: () => import('@/views/more/MoreOptions.vue'),
        meta: {
          titleKey: 'mine.pageTitle',
          requiresAuth: true,
          isRootPage: true
        }
      },
      {
        path: 'docs',
        name: 'Docs',
        component: () => import('@/views/docs/DocsPage.vue'),
        meta: {
          titleKey: 'docs.title',
          requiresAuth: true,
          activeNav: 'Mine' // 激活"我的"菜单
        }
      },
      {
        path: 'gift',
        name: 'Gift',
        component: () => import('@/views/gift/Gift.vue'),
        meta: {
          titleKey: 'profile.giftCard',
          requiresAuth: true,
          activeNav: 'Mine' // 激活"我的"菜单
        }
      },      
      {
        path: 'docs/:id',
        name: 'DocDetail',
        component: () => import('@/views/docs/DocDetail.vue'),
        meta: {
          titleKey: 'docs.title',
          requiresAuth: true,
          activeNav: 'Mine' // 激活"我的"菜单
        }
      },
      {
        path: 'nodes',
        name: 'NodeList',
        component: () => import('@/views/servers/NodeList.vue'),
        meta: {
          titleKey: 'nodes.title',
          requiresAuth: true,
          activeNav: 'Mine' // 激活"我的"菜单
        }
      },
      {
        path: 'orders',
        name: 'OrderList',
        component: () => import('@/views/orders/OrderList.vue'),
        meta: {
          titleKey: 'orders.title',
          requiresAuth: true,
          activeNav: 'Mine' // 激活"我的"菜单
        }
      },
      {
        path: 'tickets',
        name: 'TicketList',
        component: () => import('@/views/ticket/TicketList.vue'),
        meta: {
          titleKey: 'tickets.title',
          requiresAuth: true,
          activeNav: 'Mine' // 激活"我的"菜单
        }
      },
      // 手机工单页面
      {
        path: 'mobile/tickets',
        name: 'MobileTickets',
        component: () => import('@/views/ticket/MobileTicketList.vue'),
        meta: {
          titleKey: 'tickets.title',
          requiresAuth: true,
          activeNav: 'Mine' // 激活"我的"菜单
        }
      },
      {
        path: 'profile',
        name: 'Profile',
        component: Profile,
        meta: {
          titleKey: 'profile.title',
          requiresAuth: true,
          activeNav: 'Mine' // 激活"我的"菜单
        }
      },
      {
        path: 'traffic',
        name: 'Traffic',
        component: () => import('@/views/trafficLog/TrafficLog.vue'),
        meta: {
          titleKey: 'trafficLog.title',
          requiresAuth: true,
          activeNav: 'Mine' // 激活"我的"菜单
        },
        beforeEnter: (to, from, next) => {
          // 如果禁用了流量明细页面，则重定向到首页
          if (!TRAFFICLOG_CONFIG.enableTrafficLog) {
            next('/dashboard');
          } else {
            next();
          }
        }
      },
      {
        path: 'wallet/deposit',
        name: 'Deposit',
        component: () => import('@/views/wallet/WalletDeposit.vue'),
        meta: {
          titleKey: 'wallet.title',
          requiresAuth: true,
          activeNav: 'Mine' // 激活"我的"菜单
        }
      }
    ]
  },
  // 404 路由，需放在路由列表最后
  {
    path: '/:pathMatch(.*)*',
    name: 'NotFound',
    component: NotFound,
    meta: {
      titleKey: 'errors.notFound',
      requiresAuth: false
    }
  }
];

const router = createRouter({
  history: createWebHashHistory(),
  routes,
  // 滚动行为 - 手机端强制滚动到顶部
  scrollBehavior(to, from, savedPosition) {
    return new Promise((resolve) => {
      // 延迟执行，确保页面完全渲染
      setTimeout(() => {
        if (savedPosition) {
          resolve(savedPosition);
        } else {
          // 手机端强制滚动到顶部
          const forceScrollToTop = () => {
            // 滚动 window
            window.scrollTo({ top: 0, behavior: 'instant' });
            // 滚动 document
            document.documentElement.scrollTop = 0;
            document.body.scrollTop = 0;
            document.body.scrollTo({ top: 0, behavior: 'instant' });
            
            // 滚动所有可能的内容容器
            const containers = [
              '.main-board',
              '.content-area', 
              '.view-wrapper',
              '.dashboard-container',
              '.account-container',
              '.shop-container'
            ];
            
            containers.forEach(selector => {
              const element = document.querySelector(selector);
              if (element) {
                element.scrollTop = 0;
                element.scrollTo({ top: 0, behavior: 'instant' });
              }
            });
          };
          
          // 立即执行一次
          forceScrollToTop();
          
          // 延迟多次执行，确保在各种情况下都能生效
          setTimeout(forceScrollToTop, 50);
          setTimeout(forceScrollToTop, 150);
          setTimeout(forceScrollToTop, 300);
          
          resolve({ top: 0 });
        }
      }, 100);
    });
  }
});

// 全局前置守卫
router.beforeEach(async (to, from, next) => {
  // 浏览器限制检查
  if (to.name !== 'BrowserRestricted' && isBrowserRestricted()) {
    next({ name: 'BrowserRestricted' });
    return;
  }
  
  // API可用性检查 - 在任何路由跳转前先检查API可用性
  // 如果需要API检测且不是前往API验证页面，则先重定向到API验证页面
  const { shouldCheckApiAvailability } = await import('@/utils/apiAvailabilityChecker');
  if (shouldCheckApiAvailability() && to.name !== 'ApiValidation') {
    // 检查是否已有可用的API URL
    const availableUrl = sessionStorage.getItem('ez_api_available_url');
    if (!availableUrl) {
      // 没有可用URL，需要进行检测
      // Preserve all current query params when jumping to ApiValidation so they can be passed back later
      const apiRedirectQuery = {
        redirect: to.path,
        ...to.query
      };
      next({ 
        name: 'ApiValidation',
        query: apiRedirectQuery
      });
      return;
    }
    // 有可用URL，继续正常路由
  }
  
  // 设置页面标题 - 使用i18n翻译标题
  const getTitle = () => {
    if (to.meta.titleKey) {
      try {
        const title = i18n.global.t(to.meta.titleKey);
        return `${title} - ${SITE_CONFIG.siteName}`;
      } catch (error) {
        // 安静地处理错误
        return SITE_CONFIG.siteName;
      }
    }
    return SITE_CONFIG.siteName;
  };
  
  document.title = getTitle();
  
  // 检查是否需要登录
  const token = localStorage.getItem('token');
  const hashQuery = window.location.hash.includes('?')
    ? new URLSearchParams(window.location.hash.split('?')[1] || '')
    : new URLSearchParams();
  const pageQuery = new URLSearchParams(window.location.search);
  const verifyToken = to.query?.verify
    || hashQuery.get('verify')
    || pageQuery.get('verify');
  const verifyRedirect = to.query?.redirect
    || hashQuery.get('redirect')
    || pageQuery.get('redirect');
  const hasVerifyToken = Boolean(verifyToken);
  
  // 检查是否在登录状态发生变化的路由间跳转
  // 例如：从需要登录的页面到不需要登录的页面，或相反
  const loginStatusChanged = 
    (from.meta.requiresAuth && !to.meta.requiresAuth) || 
    (!from.meta.requiresAuth && to.meta.requiresAuth);
  
  if (loginStatusChanged) {
    // 如果登录状态发生变化，确保i18n消息被重新加载
    try {
      const { reloadMessages } = await import('@/i18n');
      await reloadMessages();
    } catch (error) {
      // 安静地处理错误
    }
  }
  
  // 快速登录令牌必须先进入登录页处理，不能被现有账号或目标页直接放行。
  // 这也覆盖客户端在已打开的官网标签页中触发 Hash 路由切换的情况。
  if (hasVerifyToken && to.path !== '/login') {
    next({
      name: 'Login',
      query: {
        verify: verifyToken,
        redirect: verifyRedirect || to.path || '/dashboard'
      },
      replace: true
    });
  } else if (to.meta.requiresAuth && !token) {
    next({ name: 'Login' });
  } else if (to.path === '/login' && token && !hasVerifyToken) {
    next({ path: '/dashboard' });
  } else {
    // 确保路由切换平滑
    document.body.classList.add('page-transitioning');
    
    // 处理页面缓存
    if (to.meta.keepAlive && to.name) {
      pageCache.addRouteToCache(to.name);
    } else if (to.name && to.meta.keepAlive === false) {
      pageCache.removeRouteFromCache(to.name);
    }
    
    next();
  }
});

// 全局后置钩子 - 确保每次路由切换后滚动到顶部
router.afterEach(() => {
  // 短暂延迟，确保过渡动画完成
  setTimeout(() => {
    document.body.classList.remove('page-transitioning');
    
    // 手机端强制滚动到顶部（后置钩子中再次确保）
    const forceScrollToTop = () => {
      window.scrollTo(0, 0);
      document.documentElement.scrollTop = 0;
      document.body.scrollTop = 0;
      document.body.scrollTo(0, 0);
      
      // 滚动所有可能的内容容器
      const containers = ['.main-board', '.content-area', '.view-wrapper'];
      containers.forEach(selector => {
        const element = document.querySelector(selector);
        if (element) element.scrollTop = 0;
      });
    };
    
    forceScrollToTop();
    setTimeout(forceScrollToTop, 50);
    setTimeout(forceScrollToTop, 150);
  }, 100);
});

// 根据配置决定使用自定义landing page还是默认landing page
function getCustomOrDefaultLandingPage() {
  // 如果没有设置自定义landing page，直接返回默认landing page
  if (!SITE_CONFIG.customLandingPage) {
    return LandingPage;
  }
  // 无需在路由阶段验证授权，组件内部已处理授权逻辑
  return CustomLandingPage;
}

export default router;
