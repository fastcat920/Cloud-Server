<template>
  <div class="dashboard-container">
    <div class="dashboard-inner">
      <!-- 待处理事项提示 -->
      <div v-if="hasPendingItems" class="dashboard-card pending-items-card" :class="{'card-animate': !loading.userStats}" style="animation-delay: 0.1s">
        <div class="card-header">
          <h2 class="card-title">{{ $t('dashboard.pendingItems') }}</h2>
        </div>
        <div class="card-body">
          <div class="pending-items-list">
            <button v-if="userStats.pendingOrders > 0" type="button" class="pending-item" @click="router.push('/orders')">
              <div class="pending-icon">
                <IconShoppingCart :size="20" />
              </div>
              <div class="pending-info">
                <span>{{ $t('dashboard.pendingOrders') }} ({{ userStats.pendingOrders }})</span>
              </div>
              <div class="pending-action">
                <IconChevronRight :size="16" />
              </div>
            </button>
            
            <button v-if="userStats.pendingTickets > 0" type="button" class="pending-item" @click="goToSupport">
              <div class="pending-icon">
                <IconMessage :size="20" />
              </div>
              <div class="pending-info">
                <span>{{ $t('dashboard.pendingTickets') }} ({{ userStats.pendingTickets }})</span>
              </div>
              <div class="pending-action">
                <IconChevronRight :size="16" />
              </div>
            </button>
          </div>
        </div>
      </div>
      
      <!-- 公告卡片 — 整卡轮播，触摸滑动切换 -->
      <div class="dashboard-card notice-card" :class="{'card-animate': !loading.notices}" v-if="loading.notices || (notices && notices.data && notices.data.length > 0)" style="animation-delay: 0.2s"
        @touchstart="onNoticeTouchStart"
        @touchend="onNoticeTouchEnd"
      >
        <div v-if="loading.notices" class="notice-body skeleton-loading">
          <div class="skeleton-row"></div>
          <div class="skeleton-row"></div>
          <div class="skeleton-row"></div>
        </div>
        <div v-else class="notice-body" role="button" tabindex="0" @click="showNoticeModal" @keydown.enter="showNoticeModal" @keydown.space.prevent="showNoticeModal">
          <transition name="fade-slide" mode="out-in">
            <div class="notice-item" v-if="notices.data[currentNoticeIndex]" :key="currentNoticeIndex">
              <div class="notice-heading">
                <span class="notice-icon"><IconSpeakerphone :size="15" /></span>
                <div class="notice-title">{{ notices.data[currentNoticeIndex].title }}</div>
                <div class="notice-date">{{ formatDate(notices.data[currentNoticeIndex].created_at) }}</div>
              </div>
              <div class="notice-content-preview">{{ announcementPreviewText }}</div>
              <div class="notice-footer">
                <div class="notice-dots" v-if="notices.data.length > 1">
                  <button
                    v-for="(_, idx) in notices.data" 
                    :key="idx"
                    type="button"
                    class="dot"
                    :class="{ active: idx === currentNoticeIndex }"
                    :aria-label="$t('common.noticeCount', { current: idx + 1, total: notices.data.length })"
                    @click.stop="setCurrentNotice(idx)"
                  ></button>
                </div>
              </div>
            </div>
          </transition>
        </div>
      </div>
      
      <!-- 公告弹窗 -->
      <transition name="fade">
        <div v-if="showNoticeDetails" class="notice-modal-overlay" @click="closeNoticeModal">
          <transition name="popup-slide">
            <div v-if="showNoticeDetails" v-accessible-dialog="closeNoticeModal" class="notice-modal" :style="noticeModalStyle" :aria-label="notices.data[currentNoticeIndex]?.title || $t('common.info')" @click.stop>
              <div class="notice-modal-header">
                <h2 class="popup-title">{{ notices.data[currentNoticeIndex]?.title || '' }}</h2>
                <button class="popup-close-btn" type="button" :aria-label="$t('common.close')" @click="closeNoticeModal">
                  <IconX :size="20" />
                </button>
              </div>
              <div class="notice-modal-content">
                <div v-html="processedNoticeContent" class="notice-content"></div>
              </div>
              <div class="notice-modal-footer">
                <button class="popup-action-btn adaptive-btn" @click="closeNoticeModal">
                  {{ $t('common.close') }}
                </button>
              </div>
            </div>
          </transition>
        </div>
      </transition>
      
      <!-- 套餐信息卡片 -->
      <SubscriptionUsageCard
        v-if="hasPlan"
        class="card-animate"
        :plan-name="userPlan.name"
        :expiry-date="userPlan.expireDate"
        :is-permanent="userPlan.isExpireDatePermanent"
        :remaining-days="userStats.remainingDays"
        :is-remaining-days-permanent="userStats.isRemainingDaysPermanent"
        :reset-day="userPlan.resetDay"
        :used-percent="trafficData.usedPercent"
        :used-traffic="trafficData.usedDisplay"
        :total-traffic="trafficData.totalDisplay"
        :is-expired="isExpired"
        :is-expiring-soon="isExpiringSoon"
        :is-low-traffic="isLowTraffic"
        :is-traffic-depleted="isTrafficDepleted"
        :show-renew="showRenewPlanButton"
        :show-reset="showResetTrafficButton"
        :traffic-action-mode="trafficActionMode"
        :loading="loading.userInfo || loading.subscribe"
        @renew="renewPlan"
        @reset="openResetTrafficModal"
      />
      
      <!-- 没有套餐时显示的提示卡片 -->
      <div v-if="!hasPlan" class="dashboard-card stats-card no-plan-card" :class="{'card-animate': !loading.userStats}">
        <div class="no-plan-content">
          <div class="no-plan-icon">
            <IconShoppingCart :size="45" class="icon-cart" />
          </div>
          <div class="no-plan-message">
            <div class="no-plan-title">{{ $t('dashboard.noPlanPrompt') }}</div>
            <div class="no-plan-actions">
              <button class="action-button primary" @click="goToShop">
                <IconShoppingBag :size="18" class="btn-icon" />
                <span>{{ $t('dashboard.purchasePlan') }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>
      
      <!-- 官方客户端下载区域 -->
      <div class="dashboard-card download-card" :class="{'card-animate': !loading.userInfo}" v-if="clientConfig.showDownloadCard" style="animation-delay: 0.9s">
        <div class="card-header">
          <h2 class="card-title">{{ $t('dashboard.officialClients') }}</h2>
        </div>
        <div class="card-body">
          <div class="download-options">
            <button type="button" class="download-option" v-if="clientConfig.showIOS" @click="downloadClient('ios')">
              <div class="option-icon ios">
                <IconBrandApple :size="32" />
              </div>
              <div class="option-info">
                <div class="option-name">iOS</div>
                <div class="option-version">{{ getClientVersion('ios') }}</div>
              </div>
            </button>
            <button type="button" class="download-option" v-if="clientConfig.showAndroid" @click="downloadClient('android')">
              <div class="option-icon android">
                <IconBrandAndroid :size="32" />
              </div>
              <div class="option-info">
                <div class="option-name">Android</div>
                <div class="option-version">{{ getClientVersion('android') }}</div>
              </div>
            </button>
            <button type="button" class="download-option" v-if="clientConfig.showMacOS" @click="downloadClient('macos')">
              <div class="option-icon macos">
                <IconBrandFinder :size="32" />
              </div>
              <div class="option-info">
                <div class="option-name">MacOS</div>
                <div class="option-version">{{ getClientVersion('macos') }}</div>
              </div>
            </button>
            <button type="button" class="download-option" v-if="clientConfig.showWindows" @click="downloadClient('windows')">
              <div class="option-icon windows">
                <IconBrandWindows :size="32" />
              </div>
              <div class="option-info">
                <div class="option-name">Windows</div>
                <div class="option-version">{{ getClientVersion('windows') }}</div>
              </div>
            </button>
            <button type="button" class="download-option" v-if="clientConfig.showLinux" @click="downloadClient('linux')">
              <div class="option-icon linux">
                <IconBrandDebian :size="32" />
              </div>
              <div class="option-info">
                <div class="option-name">Linux</div>
                <div class="option-version">{{ getClientVersion('linux') }}</div>
              </div>
            </button>
            <button type="button" class="download-option" v-if="clientConfig.showOpenWrt" @click="downloadClient('openwrt')">
              <div class="option-icon openwrt">
                <IconRouter :size="32" />
              </div>
              <div class="option-info">
                <div class="option-name">OpenWrt</div>
                <div class="option-version">{{ getClientVersion('openwrt') }}</div>
              </div>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <!-- 重置流量确认弹窗（优化后） -->
  <transition name="modal-fade">
    <div class="modal-overlay" v-if="showResetTrafficModal" @click.self="closeResetTrafficModal">
      <div class="reset-modal-container">
        <div v-accessible-dialog="closeResetTrafficModal" class="modal-card reset-traffic-modal" :aria-label="trafficRecoveryCopy.title">
          <div class="modal-header">
            <h3>{{ trafficRecoveryCopy.title }}</h3>
            <button class="close-btn-icon" type="button" :aria-label="$t('common.close')" @click="closeResetTrafficModal">
              <IconX :size="20" />
            </button>
          </div>
          <div class="modal-body">
            <div class="warning-icon">
              <IconAlertTriangle :size="48" />
            </div>
            <p class="warning-text">{{ trafficRecoveryCopy.description }}</p>
            <p class="note-text">{{ trafficRecoveryCopy.warning }}</p>
          </div>
          <div class="modal-footer">
            <button class="cancel-btn" @click="closeResetTrafficModal">
              {{ $t('common.cancel') }}
            </button>
            <button 
              class="confirm-btn" 
              :disabled="resetConfirmCooldown > 0 || isCreatingResetOrder"
              @click="confirmTrafficRecovery"
            >
              <template v-if="isCreatingResetOrder">
                <span class="loading-container">
                  <div class="loader-small"></div>
                  <span>{{ $t('common.loading') }}</span>
                </span>
              </template>
              <template v-else>
                {{ resetConfirmCooldown > 0 ? `${$t('common.confirm')} (${resetConfirmCooldown})` : $t('common.confirm') }}
              </template>
            </button>
          </div>
        </div>
      </div>
    </div>
  </transition>

  <!-- 流量耗尽时续费确认弹窗 -->
  <transition name="modal-fade">
    <div class="modal-overlay" v-if="showRenewPlanModal" @click.self="closeRenewPlanModal">
      <div class="reset-modal-container">
        <div v-accessible-dialog="closeRenewPlanModal" class="modal-card reset-traffic-modal renew-plan-modal" :aria-label="$t('dashboard.confirmRenewPlan')">
          <div class="modal-header">
            <h3>{{ $t('dashboard.confirmRenewPlan') }}</h3>
            <button class="close-btn-icon" type="button" :aria-label="$t('common.close')" @click="closeRenewPlanModal">
              <IconX :size="20" />
            </button>
          </div>
          <div class="modal-body">
            <div class="warning-icon">
              <IconAlertTriangle :size="48" />
            </div>
            <p class="warning-text">{{ $t(allowNewPeriod ? 'dashboard.trafficExhaustedRenewPeriod' : 'dashboard.trafficExhaustedRenewReset') }}</p>
          </div>
          <div class="modal-footer">
            <button class="cancel-btn" @click="closeRenewPlanModal">{{ $t('common.cancel') }}</button>
            <button class="confirm-btn" @click="confirmRenewPlan">{{ $t('common.confirm') }}</button>
          </div>
        </div>
      </div>
    </div>
  </transition>
</template>

<script>
import { ref, reactive, onMounted, computed, watch, onBeforeUnmount, nextTick, onActivated, onDeactivated } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { CLIENT_CONFIG, DASHBOARD_CONFIG, isXiaoV2board } from '@/utils/baseConfig';
import { 
  IconShoppingCart, IconBrandApple, IconBrandAndroid, IconBrandWindows,
  IconBrandDebian, IconRouter, IconBrandFinder, IconChevronRight,
  IconMessage, IconShoppingBag,
  IconAlertTriangle, IconX, IconSpeakerphone
} from '@tabler/icons-vue';
import { getUserInfo, getSubscribe, getNotices, getUserStats, getUserConfig, startNewTrafficPeriod } from '@/api/dashboard';
import { useToast } from '@/composables/useToast';
import { createOrderAfterUnpaidCleanup } from '@/utils/orderCleanup';
import MarkdownIt from 'markdown-it';
import SubscriptionUsageCard from '@/components/subscription/SubscriptionUsageCard.vue';
import { calculateRemainingDays, formatExpiryDate, isExpiryDateExpired } from '@/utils/subscriptionExpiry';
import { sanitizeHtml } from '@/utils/sanitizeHtml';

import { cleanupResources } from '@/utils/componentLifecycle';

const md = new MarkdownIt({ html: true, breaks: true, linkify: true, typographer: true });

md.renderer.rules.link_open = function(tokens, idx, options, env, self) {
  const token = tokens[idx];
  const hrefIndex = token.attrIndex('href');
  if (hrefIndex >= 0) {
    const href = token.attrs[hrefIndex][1];
    if (href.includes('#eztheme-btn') || href.includes('class=eztheme-btn') || href.includes('?eztheme-btn')) {
      token.attrs[hrefIndex][1] = href.replace('#eztheme-btn', '').replace('class=eztheme-btn', '').replace('?eztheme-btn', '');
      const classIndex = token.attrIndex('class');
      if (classIndex < 0) {
        token.attrPush(['class', 'eztheme-btn']);
      } else {
        const classes = token.attrs[classIndex][1];
        if (!classes.includes('eztheme-btn')) {
          token.attrs[classIndex][1] = classes + ' eztheme-btn';
        }
      }
    }
  }
  return self.renderToken(tokens, idx, options);
};

export default {
  name: 'UserDashboard',
  components: {
    SubscriptionUsageCard,
    IconShoppingCart, IconBrandApple, IconBrandAndroid, IconBrandWindows,
    IconBrandDebian, IconRouter, IconBrandFinder, IconChevronRight,
    IconMessage, IconShoppingBag,
    IconAlertTriangle, IconX, IconSpeakerphone
  },
  setup() {
    const { t, locale } = useI18n();
    const router = useRouter();
    const clientConfig = reactive(CLIENT_CONFIG);
    const { showToast } = useToast();
    
    // 数据状态
    const notices = ref([]);
    const userPlan = ref({ deviceLimit: null, aliveIp: 0, resetDay: null, name: '', totalTraffic: '', totalTrafficBytes: 0, expireDate: '', expiredAt: null, isExpireDatePermanent: false });
    const userStats = reactive({ remainingTraffic: '', usedTrafficBytes: 0, totalTrafficBytes: 0, remainingDays: '', accountBalance: '0.00', pendingOrders: 0, pendingTickets: 0, userEmail: '', isRemainingDaysPermanent: false });
    const userBalance = ref('0.00');
    const currencySymbol = ref('$');
    const hasPlan = ref(true);
    const userPlanId = ref(null);
    const allowNewPeriod = ref(false);
    const currentNoticeIndex = ref(0);
    const showNoticeDetails = ref(false);
    const loading = reactive({ userInfo: true, userStats: true, notices: true, userPlan: true, subscribe: true });
    const needRefreshData = ref(false);
    
    // 重置流量相关
    const showResetTrafficModal = ref(false);
    const showRenewPlanModal = ref(false);
    const resetConfirmCooldown = ref(0);
    const resetConfirmTimer = ref(null);
    const isCreatingResetOrder = ref(false);
    
    // 窗口尺寸
    const windowWidth = ref(window.innerWidth);
    const windowHeight = ref(window.innerHeight);
    const noticeModalStyle = ref({});
    
    // 自动轮播相关变量
    const carouselTimer = ref(null);
    const isCarouselActive = ref(true);
    
    // 定时器和监听器
    const timers = {};
    const listeners = {};
    
    // 格式化函数
    const formatTraffic = (bytes) => {
      if (bytes === 0) return '0 B';
      const k = 1024;
      const sizes = ['B', 'KB', 'MB', 'GB'];
      const i = Math.min(Math.floor(Math.log(bytes) / Math.log(k)), sizes.length - 1);
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    };
    
    const formatDate = (dateString) => {
      if (!dateString) return '';
      const d = new Date(dateString * 1000); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    };
    
    // 计算属性
    const isExpiringSoon = computed(() => {
      if (userStats.isRemainingDaysPermanent) return false;
      const days = parseInt(userStats.remainingDays, 10);
      return !isNaN(days)
        && days >= 0
        && days <= DASHBOARD_CONFIG.expiringThreshold
        && !isExpired.value;
    });
    
    const isExpired = computed(() => {
      if (userStats.isRemainingDaysPermanent) return false;
      return isExpiryDateExpired(userPlan.value.expiredAt);
    });
    
    // 套餐流量数据 — 内联格式化，不依赖外部函数
    const formatByteSize = (b, decimals = 2) => {
      if (!b || b <= 0) return '0 B';
      if (b < 1024) return parseFloat(Number(b).toFixed(decimals)) + ' B';
      if (b < 1048576) return parseFloat((b / 1024).toFixed(decimals)) + ' KB';
      if (b < 1073741824) return parseFloat((b / 1048576).toFixed(decimals)) + ' MB';
      return parseFloat((b / 1073741824).toFixed(decimals)) + ' GB';
    };
    
    const trafficData = computed(() => {
      const totalBytes = Number(userPlan.value.totalTrafficBytes) || 0;
      const usedBytes = Number(userStats.usedTrafficBytes) || 0;
      if (totalBytes <= 0) {
        return { totalBytes: 0, totalDisplay: '0 GB', usedDisplay: '0 GB', usedPercent: 0 };
      }
      const percent = Math.min(Math.max((usedBytes / totalBytes) * 100, 0), 100);
      return {
        totalBytes,
        totalDisplay: formatByteSize(totalBytes, 0),
        usedDisplay: formatByteSize(usedBytes, 2),
        usedPercent: percent,
      };
    });
    
    const trafficBarColor = computed(() => {
      const used = trafficData.value.usedPercent;
      if (used < 50) return 'var(--theme-color)';
      if (used < 90) return '#ff9800';
      return '#f44336';
    });
    
    const deviceLimitText = computed(() => {
      if (userPlan.value.deviceLimit === null) {
        return `${userPlan.value.aliveIp} / ${t('dashboard.unlimited')}`;
      }
      return `${userPlan.value.aliveIp} / ${userPlan.value.deviceLimit}`;
    });
    
    const hasPendingItems = computed(() => userStats.pendingOrders > 0 || userStats.pendingTickets > 0);
    
    const announcementPreviewText = computed(() => {
      const rawContent = notices.value?.data?.[currentNoticeIndex.value]?.content || '';
      const tempDiv = document.createElement('div');
      tempDiv.innerHTML = rawContent;
      let text = tempDiv.textContent || tempDiv.innerText || '';
      if (text.length > 120) text = text.slice(0, 120) + '...';
      return text;
    });
    
    const processedNoticeContent = computed(() => {
      if (!notices.value?.data?.[currentNoticeIndex.value]?.content) return '';
      const content = notices.value.data[currentNoticeIndex.value].content;
      const hasHtml = /<[a-z][\s\S]*>/i.test(content);
      if (hasHtml) {
        let processedContent = content.replace(/\n/g, '<br>');
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = processedContent;
        tempDiv.querySelectorAll('a').forEach(link => {
          const href = link.getAttribute('href');
          if (href && (href.includes('#eztheme-btn') || href.includes('?eztheme-btn') || href.includes('class=eztheme-btn'))) {
            link.href = href.replace('#eztheme-btn', '').replace('?eztheme-btn', '').replace('class=eztheme-btn', '');
            link.classList.add('eztheme-btn');
            link.style.cssText = 'text-decoration: none; border-bottom: none;';
          }
        });
        return sanitizeHtml(tempDiv.innerHTML);
      }
      return sanitizeHtml(md.render(content));
    });
    
    const isLowTraffic = computed(() => {
      if (trafficData.value.totalBytes <= 0) return false;
      const remainingPercent = 100 - trafficData.value.usedPercent;
      return remainingPercent > 0 && remainingPercent <= DASHBOARD_CONFIG.lowTrafficThreshold;
    });

    const isTrafficDepleted = computed(() => {
      if (trafficData.value.totalBytes <= 0) return false;
      return trafficData.value.usedPercent >= 100;
    });

    const showResetTrafficButton = computed(() => {
      if (allowNewPeriod.value) return !isExpired.value && isTrafficDepleted.value;
      if (!DASHBOARD_CONFIG.enableResetTraffic) return false;
      switch (DASHBOARD_CONFIG.resetTrafficDisplayMode) {
        case 'always': return true;
        case 'low': return isLowTraffic.value || isTrafficDepleted.value;
        case 'depleted': return isTrafficDepleted.value;
        default: return false;
      }
    });

    const trafficActionMode = computed(() => allowNewPeriod.value ? 'new-period' : 'reset');
    const trafficRecoveryCopy = computed(() => allowNewPeriod.value
      ? {
          title: t('dashboard.newPeriodConfirm'),
          description: t('dashboard.newPeriodDesc'),
          warning: t('dashboard.newPeriodWarning')
        }
      : {
          title: t('dashboard.resetTrafficConfirm'),
          description: t('dashboard.resetTrafficDesc'),
          warning: t('dashboard.resetTrafficWarning')
        });
    
    const showRenewPlanButton = computed(() => {
      if (!DASHBOARD_CONFIG.enableRenewPlan) return false;
      switch (DASHBOARD_CONFIG.renewPlanDisplayMode) {
        case 'always': return true;
        case 'expiring': return isExpiringSoon.value;
        case 'expired': return isExpired.value;
        default: return false;
      }
    });
    
    const showDeviceLimit = computed(() => isXiaoV2board() && DASHBOARD_CONFIG.showOnlineDevicesLimit);
    
    const waterAnimationState = reactive({ canAnimate: false, initialized: false });
    
    // 自动轮播控制函数
    const startCarousel = () => {
      if (carouselTimer.value) clearInterval(carouselTimer.value);
      if (!isCarouselActive.value) return;
      const noticesData = notices.value?.data;
      if (!noticesData || noticesData.length <= 1) return;
      
      carouselTimer.value = setInterval(() => {
        if (noticesData && noticesData.length > 0 && !showNoticeDetails.value) {
          const nextIndex = (currentNoticeIndex.value + 1) % noticesData.length;
          currentNoticeIndex.value = nextIndex;
        }
      }, 5000);
    };
    
    const stopCarousel = () => {
      if (carouselTimer.value) {
        clearInterval(carouselTimer.value);
        carouselTimer.value = null;
      }
    };
    
    const resetCarousel = () => {
      stopCarousel();
      startCarousel();
    };
    
    // 手动设置当前公告（通过指示点）
    const setCurrentNotice = (index) => {
      if (index >= 0 && notices.value?.data && index < notices.value.data.length) {
        currentNoticeIndex.value = index;
        resetCarousel();
      }
    };
    
    const prevNotice = () => {
      const len = notices.value?.data?.length || 0;
      if (len <= 1) return;
      currentNoticeIndex.value = (currentNoticeIndex.value - 1 + len) % len;
      resetCarousel();
    };
    
    const nextNotice = () => {
      const len = notices.value?.data?.length || 0;
      if (len <= 1) return;
      currentNoticeIndex.value = (currentNoticeIndex.value + 1) % len;
      resetCarousel();
    };
    
    // 触摸滑动
    let noticeTouchStartX = 0;
    const onNoticeTouchStart = (e) => {
      noticeTouchStartX = e.touches[0].clientX;
    };
    const onNoticeTouchEnd = (e) => {
      const diff = noticeTouchStartX - e.changedTouches[0].clientX;
      if (Math.abs(diff) > 50) {
        if (diff > 0) nextNotice();
        else prevNotice();
      }
    };
    
    // API 调用函数
    const updateAccountBalanceDisplay = () => {
      if (userBalance.value) {
        userStats.accountBalance = `${currencySymbol.value}${(parseFloat(userBalance.value) / 100).toFixed(2)}`;
      }
    };
    
    const fetchUserInfo = async (force = false) => {
      if (!force && loading.userInfo === false && Object.keys(userPlan.value).length > 0) return;
      loading.userInfo = true;
      try {
        const response = await getUserInfo({ force });
        if (response.data) {
          const info = response.data;
          userPlanId.value = info.plan_id;
          hasPlan.value = info.plan_id !== null && info.plan_id !== undefined;
          if (info.email) userStats.userEmail = info.email;
          if (info.balance !== undefined) {
            userBalance.value = info.balance;
            updateAccountBalanceDisplay();
          }
          if (info.expired_at) {
            userPlan.value.expireDate = formatExpiryDate(info.expired_at);
            userPlan.value.expiredAt = info.expired_at;
            userPlan.value.isExpireDatePermanent = false;
            userStats.remainingDays = `${calculateRemainingDays(info.expired_at)}`;
            userStats.isRemainingDaysPermanent = false;
          } else {
            userPlan.value.expireDate = null;
            userPlan.value.expiredAt = null;
            userPlan.value.isExpireDatePermanent = true;
            userStats.remainingDays = null;
            userStats.isRemainingDaysPermanent = true;
          }
        }
      } catch (error) {
        console.error('获取用户信息失败:', error);
      } finally {
        loading.userInfo = false;
      }
    };
    
    const fetchSubscribe = async (force = false) => {
      if (!force && loading.subscribe === false) return;
      loading.subscribe = true;
      try {
        const response = await getSubscribe({ force });
        if (response.data) {
          const subscribe = response.data;
          allowNewPeriod.value = subscribe.allow_new_period === true || Number(subscribe.allow_new_period) === 1;
          if (subscribe.plan?.name) userPlan.value.name = subscribe.plan.name;
          if (subscribe.plan?.id) userPlanId.value = subscribe.plan.id;
          if (subscribe.expired_at) {
            userPlan.value.expireDate = formatExpiryDate(subscribe.expired_at);
            userPlan.value.expiredAt = subscribe.expired_at;
            userPlan.value.isExpireDatePermanent = false;
            userStats.remainingDays = `${calculateRemainingDays(subscribe.expired_at)}`;
            userStats.isRemainingDaysPermanent = false;
          } else {
            userPlan.value.expireDate = null;
            userPlan.value.expiredAt = null;
            userPlan.value.isExpireDatePermanent = true;
            userStats.remainingDays = null;
            userStats.isRemainingDaysPermanent = true;
          }
          if (subscribe.transfer_enable) {
            userPlan.value.totalTrafficBytes = Number(subscribe.transfer_enable) || 0;
            userPlan.value.totalTraffic = formatTraffic(subscribe.transfer_enable);
            userStats.totalTrafficBytes = Number(subscribe.transfer_enable) || 0;
          }
          if (subscribe.transfer_enable && subscribe.u !== undefined && subscribe.d !== undefined) {
            const usedBytes = (Number(subscribe.u) || 0) + (Number(subscribe.d) || 0);
            userStats.usedTrafficBytes = usedBytes;
            userStats.remainingTraffic = formatTraffic(Math.max(0, subscribe.transfer_enable - usedBytes));
          }
          if (subscribe.reset_day !== undefined && subscribe.reset_day !== null) userPlan.value.resetDay = subscribe.reset_day;
          if (subscribe.device_limit !== undefined) userPlan.value.deviceLimit = subscribe.device_limit;
          if (subscribe.alive_ip !== undefined) userPlan.value.aliveIp = subscribe.alive_ip;
        }
      } catch (error) {
        console.error('获取订阅信息失败:', error);
      } finally {
        loading.subscribe = false;
      }
    };
    
    const fetchNotices = async (force = false) => {
      if (!force && loading.notices === false && notices.value.data?.length > 0) return;
      loading.notices = true;
      try {
        const response = await getNotices({ force });
        if (response?.data) {
          notices.value = response;
          checkForPopupNotices();
          if (notices.value.data && notices.value.data.length > 1) {
            startCarousel();
          } else {
            stopCarousel();
          }
        }
      } catch (error) {
        console.error('获取公告失败:', error);
      } finally {
        loading.notices = false;
      }
    };
    
    const checkForPopupNotices = () => {
      if (!notices.value?.data?.length) return;
      
      const popupNoticeIndex = notices.value.data.findIndex(notice => {
        if (!notice.tags) return false;
        if (typeof notice.tags === 'string') {
          return notice.tags.includes('弹窗') || notice.tags.includes('popup');
        }
        if (Array.isArray(notice.tags)) {
          return notice.tags.includes('弹窗') || notice.tags.includes('popup');
        }
        return false;
      });
      
      if (popupNoticeIndex !== -1) {
        const notice = notices.value.data[popupNoticeIndex];
        const noticeId = notice.id;
        const popupShownKey = `popup_notice_shown_${noticeId}`;
        
        if (!sessionStorage.getItem(popupShownKey)) {
          currentNoticeIndex.value = popupNoticeIndex;
          showNoticeDetails.value = true;
          sessionStorage.setItem(popupShownKey, 'true');
          nextTick(() => updateModalHeight());
        }
      }
    };
    
    const fetchUserStats = async () => {
      if (loading.userStats === false && userStats.remainingTraffic !== '0 GB') return;
      loading.userStats = true;
      try {
        const response = await getUserStats();
        if (response.data && Array.isArray(response.data) && response.data.length >= 2) {
          userStats.pendingOrders = response.data[0];
          userStats.pendingTickets = response.data[1];
        }
      } catch (error) {
        console.error('获取统计数据失败:', error);
      } finally {
        loading.userStats = false;
      }
    };
    
    const fetchUserConfig = async () => {
      try {
        const response = await getUserConfig();
        if (response.data?.currency_symbol) {
          currencySymbol.value = response.data.currency_symbol;
          updateAccountBalanceDisplay();
        }
      } catch (error) {
        console.error('获取用户配置失败:', error);
      }
    };
    
    // 重置流量相关
    const openResetTrafficModal = () => {
      showResetTrafficModal.value = true;
      resetConfirmCooldown.value = 3;
      if (resetConfirmTimer.value) clearInterval(resetConfirmTimer.value);
      resetConfirmTimer.value = setInterval(() => {
        if (resetConfirmCooldown.value > 0) resetConfirmCooldown.value--;
        else if (resetConfirmTimer.value) clearInterval(resetConfirmTimer.value);
      }, 1000);
    };
    
    const closeResetTrafficModal = () => {
      showResetTrafficModal.value = false;
      if (resetConfirmTimer.value) clearInterval(resetConfirmTimer.value);
      resetConfirmTimer.value = null;
    };
    
    const createResetTrafficOrder = async () => {
      if (resetConfirmCooldown.value > 0) return;
      isCreatingResetOrder.value = true;
      try {
        if (!userPlanId.value) {
          showToast(t('common.error_occurred'), 'error');
          return;
        }
        const response = await createOrderAfterUnpaidCleanup({
          plan_id: userPlanId.value,
          period: 'reset_price'
        });
        if (response?.data) {
          showToast(t('dashboard.resetTrafficSuccess'), 'success');
          closeResetTrafficModal();
          router.push({ path: '/payment', query: { trade_no: response.data } });
        }
      } catch (error) {
        console.error('创建重置流量订单失败:', error);
        showToast(error.message || t('common.error_occurred'), 'error');
      } finally {
        isCreatingResetOrder.value = false;
      }
    };

    const startNextTrafficPeriod = async () => {
      if (resetConfirmCooldown.value > 0) return;
      isCreatingResetOrder.value = true;
      try {
        await startNewTrafficPeriod();
        showToast(t('dashboard.newPeriodSuccess'), 'success');
        closeResetTrafficModal();
        loading.subscribe = true;
        await fetchSubscribe(true);
      } catch (error) {
        console.error('开启下一流量周期失败:', error);
        showToast(error.message || t('dashboard.newPeriodFailed'), 'error');
      } finally {
        isCreatingResetOrder.value = false;
      }
    };

    const confirmTrafficRecovery = () => {
      if (allowNewPeriod.value) return startNextTrafficPeriod();
      return createResetTrafficOrder();
    };
    
    // 公告弹窗控制
    const showNoticeModal = () => { 
      showNoticeDetails.value = true; 
      nextTick(updateModalHeight);
    };
    const closeNoticeModal = () => { showNoticeDetails.value = false; };
    
    // 其他函数
    const goToShop = () => router.push('/shop');
    const goToSupport = () => router.push(window.innerWidth < 905 ? '/mobile/tickets' : '/tickets');
    const getClientDownloadUrl = (platform) => clientConfig.clientLinks?.[platform] || '';
    const getClientVersion = (platform) => {
      const version = String(clientConfig.clientVersions?.[platform] || '').trim();
      return version && !/^v/i.test(version) ? `v${version}` : version;
    };
    const downloadClient = (platform) => {
      const url = getClientDownloadUrl(platform);
      if (url) window.open(url, '_blank', 'noopener,noreferrer');
    };
    const goToRenewPlan = () => {
      if (userPlanId.value) router.push(`/plan/${userPlanId.value}`);
      else showToast(t('dashboard.noPlanToRenew'), 'error', 3000);
    };
    const renewPlan = () => {
      if (!userPlanId.value) {
        showToast(t('dashboard.noPlanToRenew'), 'error', 3000);
        return;
      }
      if (isTrafficDepleted.value) {
        showRenewPlanModal.value = true;
        return;
      }
      goToRenewPlan();
    };
    const closeRenewPlanModal = () => { showRenewPlanModal.value = false; };
    const confirmRenewPlan = () => {
      closeRenewPlanModal();
      goToRenewPlan();
    };
    
    const updateModalHeight = () => {
      const isMobile = windowWidth.value <= 768;
      noticeModalStyle.value = { maxHeight: `${windowHeight.value * (isMobile ? 0.75 : 0.8)}px` };
    };
    
    const handleResize = () => {
      windowWidth.value = window.innerWidth;
      windowHeight.value = window.innerHeight;
      if (showNoticeDetails.value) updateModalHeight();
    };
    
    // 监听公告弹窗开关状态，控制轮播暂停/恢复
    watch(showNoticeDetails, (newVal) => {
      if (newVal) {
        stopCarousel();
      } else {
        if (notices.value?.data && notices.value.data.length > 1) {
          startCarousel();
        }
      }
    });
    
    // 监听公告数量变化，动态控制轮播
    watch(() => notices.value?.data?.length, (newLen) => {
      if (newLen && newLen > 1 && isCarouselActive.value && !showNoticeDetails.value) {
        startCarousel();
      } else if (!newLen || newLen <= 1) {
        stopCarousel();
      } else if (showNoticeDetails.value) {
        stopCarousel();
      }
    });
    
    watch(() => locale.value, () => {
      if (userPlan.value.isExpireDatePermanent) userPlan.value.expireDate = t('dashboard.permanent');
      fetchUserInfo(true);
      fetchSubscribe(true);
      fetchNotices(true);
    });
    
    watch(() => [loading.userStats, loading.userInfo], () => {
      if (!loading.userStats && !loading.userInfo) {
        setTimeout(() => { waterAnimationState.canAnimate = true; waterAnimationState.initialized = true; }, 500);
      }
    });
    
    onActivated(() => {
      if (needRefreshData.value) {
        fetchUserInfo();
        fetchUserStats();
        fetchNotices();
        needRefreshData.value = false;
      }
      isCarouselActive.value = true;
      if (notices.value?.data && notices.value.data.length > 1 && !showNoticeDetails.value) {
        startCarousel();
      }
    });
    
    onDeactivated(() => {
      needRefreshData.value = true;
      isCarouselActive.value = false;
      stopCarousel();
      cleanupResources(timers, listeners);
    });
    
    onMounted(() => {
      window.addEventListener('resize', handleResize);
      Promise.allSettled([
        fetchUserConfig(),
        fetchUserInfo(),
        fetchSubscribe(),
        fetchNotices(),
        fetchUserStats()
      ]);
    });
    
    onBeforeUnmount(() => {
      window.removeEventListener('resize', handleResize);
      stopCarousel();
      cleanupResources(timers, listeners);
      if (resetConfirmTimer.value) clearInterval(resetConfirmTimer.value);
    });
    
    return {
      userStats, userBalance, currencySymbol, userPlan, clientConfig, notices, loading,
      goToShop, downloadClient, getClientVersion, hasPendingItems, router, currentNoticeIndex,
      goToSupport, formatDate,
      showNoticeModal, closeNoticeModal, showNoticeDetails, noticeModalStyle,
      openResetTrafficModal, closeResetTrafficModal, confirmTrafficRecovery,
      showResetTrafficModal, showRenewPlanModal, resetConfirmCooldown, showResetTrafficButton,
      isCreatingResetOrder, isExpiringSoon, isExpired, isLowTraffic,
      isTrafficDepleted, hasPlan, processedNoticeContent, showRenewPlanButton,
      renewPlan, closeRenewPlanModal, confirmRenewPlan, showDeviceLimit, needRefreshData,
      waterAnimationState, DASHBOARD_CONFIG, announcementPreviewText, allowNewPeriod,
      trafficActionMode, trafficRecoveryCopy,
 trafficData, trafficBarColor,
      deviceLimitText, setCurrentNotice, onNoticeTouchStart, onNoticeTouchEnd
    };
  }
};
</script>

<style lang="scss" scoped>
// 卡片背景色：白天模式 #ffffff，暗黑模式 #1e293b
.dashboard-card {
  background-color: #ffffff;
  border-radius: 20px;
  box-shadow: 0 4px 16px var(--card-shadow);
  padding: 20px;
  margin-bottom: 24px;
  border: 1px solid var(--card-border);
  transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
  
  &:hover {
    box-shadow: 0 4px 16px var(--card-shadow-hover);
    border-color: rgba(var(--theme-color-rgb), 0.3);
  }
  
  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    
    .card-title {
      font-size: 18px;
      font-weight: 600;
      margin: 0;
    }
  }
}

// 暗黑模式卡片背景
.dark .dashboard-card,
.dark-theme .dashboard-card {
  background-color: #1e293b;
}

.expired-message {
  color: #f44336;
  font-weight: 500;
  font-size: 16px;
  margin: 0;
}

.dashboard-container {
  padding: 20px;
  display: flex;
  justify-content: center;
  
  .dashboard-inner {
    width: 100%;
    max-width: 900px;
  }
  
  // 套餐卡片内部样式
  .subscription-card {
    .subscription-info {
      margin-bottom: 15px;

      .plan-info {
        margin-bottom: 8px;

        .info-value {
          font-size: 16px;
          font-weight: 600;
          color: var(--text-color);
        }
      }

      .info-item {
        .info-row {
          display: flex;
          flex-wrap: wrap;
          gap: 2px;

          .info-label {
            flex: 0 0 auto;
            word-break: break-word;
            white-space: normal;
            font-size: 14px;
            color: var(--secondary-text-color);
          }
        }
      }
    }
    
    .traffic-usage-wrapper {
      display: flex;
      align-items: center;
      gap: 12px;
      margin: 16px 0 8px;
      
      .traffic-usage-bar-container {
        flex: 1;
        height: 6px;
        background-color: rgba(var(--theme-color-rgb), 0.15);
        border-radius: 3px;
        overflow: hidden;
        
        .traffic-usage-bar {
          height: 100%;
          border-radius: 3px;
          transition: width 0.3s ease;
        }
      }
      
      .traffic-usage-percent {
        font-size: 13px;
        font-weight: 500;
        min-width: 48px;
        text-align: right;
      }
    }
    
    .subscription-actions {
      display: flex;
      gap: 12px;
      margin-top: 15px;
      
      @media (max-width: 768px) {
        flex-direction: row;
        gap: 8px;
        button { flex: 1; }
      }
      
      .btn-outline {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: 12px;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
        background-color: transparent;
        color: var(--text-color);
        border: 1px solid var(--card-border);
        
        &:hover {
          border-color: var(--theme-color);
          color: var(--theme-color);
          background-color: rgba(var(--theme-color-rgb), 0.05);
          transform: translateY(-1px);
        }
      }
      
      .renew-plan-btn {
        border-color: var(--theme-color);
        color: var(--theme-color);
        &.renew-warning { color: #ff9800; border-color: #ff9800; background-color: rgba(255, 152, 0, 0.1); }
        &.renew-danger { color: #f44336; border-color: #f44336; background-color: rgba(244, 67, 54, 0.1); }
      }
      
      .reset-traffic-btn {
        &.reset-warning { color: #ff9800; border-color: #ff9800; background-color: rgba(255, 152, 0, 0.1); }
        &.reset-danger { color: #f44336; border-color: #f44336; background-color: rgba(244, 67, 54, 0.1); }
      }
    }
  }
  
  /* FastCatAPP 公告卡：固定高度、图标标题、日期与轮播指示器。 */
  .notice-card {
    min-height: 136px;
    padding: 0 !important;
    overflow: hidden;

    .notice-body {
      height: 100%;
      cursor: pointer;
    }
    
    .notice-item {
      display: flex;
      min-height: 134px;
      padding: 16px;
      flex-direction: column;
      justify-content: center;

      .notice-heading {
        display: flex;
        align-items: center;
        min-width: 0;
      }

      .notice-icon {
        display: inline-flex;
        width: 24px;
        height: 24px;
        margin-right: 8px;
        flex: 0 0 auto;
        align-items: center;
        justify-content: center;
        color: var(--theme-color);
        background: rgba(var(--theme-color-rgb), 0.12);
        border-radius: 50%;
      }

      .notice-title {
        min-width: 0;
        overflow: hidden;
        color: var(--heading-color);
        font-size: 14px;
        font-weight: 700;
        line-height: 1.3;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .notice-date {
        margin-left: auto;
        padding-left: 8px;
        flex: 0 0 auto;
        color: var(--secondary-text-color);
        font-size: 12px;
        font-weight: 600;
      }
      
      .notice-content-preview {
        display: -webkit-box;
        margin: 10px 0 0;
        overflow: hidden;
        color: var(--supporting-text-color);
        font-size: 12px;
        line-height: 1.5;
        word-break: break-word;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
      }
      
      .notice-footer {
        display: flex;
        min-height: 12px;
        margin-top: 8px;
        align-items: center;
        justify-content: center;
        
        .notice-dots {
          display: flex;
          gap: 6px;
          align-items: center;
          
          .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background-color: var(--secondary-text-color);
            opacity: 0.4;
            transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
            cursor: pointer;
            
            &.active {
              width: 18px;
              border-radius: 3px;
              opacity: 1;
              background-color: var(--theme-color);
            }
            
            &:hover {
              opacity: 0.8;
            }
          }
        }
      }
      
      @media (max-width: 576px) {
        min-height: 146px;
        padding: 12px 16px;
      }
    }
  }
  
  /* 修复过渡动画 - 避免高度跳动 */
  .fade-slide-enter-active,
  .fade-slide-leave-active {
    transition: opacity 0.3s ease, transform 0.3s ease;
  }
  
  .fade-slide-enter-from {
    opacity: 0;
    transform: translateX(20px);
  }
  
  .fade-slide-leave-to {
    opacity: 0;
    transform: translateX(-20px);
  }
  
  /* 待处理事项卡片 */
  .pending-items-card {
    .pending-item {
      display: flex;
      align-items: center;
      padding: 15px;
      border-radius: 10px;
      background-color: rgba(var(--theme-color-rgb), 0.05);
      cursor: pointer;
      transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
      
      &:hover {
        background-color: rgba(var(--theme-color-rgb), 0.1);
        transform: translateY(-2px);
      }
      
      .pending-icon {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        background-color: rgba(var(--theme-color-rgb), 0.15);
        color: var(--theme-color);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 15px;
      }
      
      .pending-info { flex: 1; font-weight: 500; }
      .pending-action { color: var(--secondary-text-color); transition: transform 0.3s ease; }
      &:hover .pending-action { transform: translateX(3px); color: var(--theme-color); }
    }
  }
  
  /* 没有套餐时的提示卡片 */
  .no-plan-card {
    border-color: #ff9800;
    background: linear-gradient(145deg, rgba(255, 152, 0, 0.05), rgba(255, 152, 0, 0.1));
    
    .no-plan-content {
      display: flex;
      align-items: center;
      gap: 24px;
      
      @media (max-width: 768px) {
        flex-direction: column;
        text-align: center;
      }
      
      .no-plan-icon {
        background-color: rgba(255, 152, 0, 0.15);
        color: #ff9800;
        width: 80px;
        height: 80px;
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        transform: rotate(-5deg);
        transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
      }
      
      .no-plan-title {
        color: #ff9800;
        font-size: 1.2rem;
        font-weight: 600;
        margin-bottom: 16px;
      }
      
      .action-button.primary {
        background-color: var(--theme-color);
        color: white;
        border: none;
        padding: 10px 18px;
        border-radius: 10px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        
        &:hover {
          transform: translateY(-2px);
          box-shadow: 0 6px 15px rgba(var(--theme-color-rgb), 0.4);
        }
      }
    }
  }
  
  /* 官方客户端下载 */
  .download-card .download-options {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 20px;
    
    .download-option {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 15px;
      border-radius: 10px;
      cursor: pointer;
      border: 1px solid var(--card-border);
      transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
      
      &:hover {
        background-color: rgba(var(--theme-color-rgb), 0.05);
        transform: translateY(-2px);
      }
      
      .option-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 12px;
        
        &.ios { background-color: rgba(0, 122, 255, 0.1); color: #007aff; }
        &.android { background-color: rgba(61, 178, 74, 0.1); color: #3db24a; }
        &.macos { background-color: rgba(90, 90, 90, 0.1); color: #5a5a5a; }
        &.windows { background-color: rgba(0, 120, 215, 0.1); color: #0078d7; }
      }
      
      .option-info {
        text-align: center;
        line-height: 1.25;
      }

      .option-name {
        font-size: 14px;
        font-weight: 600;
        color: var(--text-color);
      }

      .option-version {
        margin-top: 4px;
        font-size: 12px;
        font-weight: 500;
        color: var(--secondary-text-color);
      }
    }
  }
}

/* 重置流量弹窗 - 优化后样式 */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  backdrop-filter: blur(4px);
}

.reset-modal-container {
  width: 100%;
  max-width: 400px;
  margin: 20px;
  animation: modal-in 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.reset-traffic-modal {
  background-color: var(--card-background, #ffffff);
  border-radius: 20px;
  overflow: hidden;
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
  
  .modal-header {
    padding: 20px 20px 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    
    h3 {
      margin: 0;
      font-size: 18px;
      font-weight: 600;
      color: var(--text-color, #1f2937);
    }
    
    .close-btn-icon {
      background: transparent;
      border: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      color: var(--secondary-text-color, #6b7280);
      transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
      padding: 0;
      
      &:hover {
        background-color: rgba(var(--theme-color-rgb), 0.1);
        color: var(--theme-color, #3b82f6);
        transform: rotate(90deg);
      }
    }
  }
  
  .modal-body {
    padding: 24px 20px;
    text-align: center;
    
    .warning-icon {
      color: #ff9800;
      margin-bottom: 16px;
    }
    
    .warning-text {
      font-size: 16px;
      margin-bottom: 12px;
      color: var(--text-color, #374151);
      font-weight: 500;
    }
    
    .note-text {
      font-size: 14px;
      color: var(--secondary-text-color, #6b7280);
      padding: 12px 16px;
      background-color: rgba(var(--theme-color-rgb), 0.05);
      border-radius: 12px;
      line-height: 1.5;
    }
  }
  
  .modal-footer {
    padding: 16px 20px 20px;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    border-top: 1px solid var(--border-color, #e5e7eb);
    
    .cancel-btn {
      background-color: transparent;
      border: 1px solid var(--border-color, #d1d5db);
      padding: 10px 20px;
      border-radius: 12px;
      cursor: pointer;
      font-size: 14px;
      font-weight: 500;
      color: var(--text-color, #374151);
      transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
      
      &:hover {
        background-color: rgba(var(--theme-color-rgb), 0.05);
        border-color: var(--theme-color);
        color: var(--theme-color);
      }
    }
    
    .confirm-btn {
      background-color: #f44336;
      color: white;
      border: none;
      padding: 10px 24px;
      border-radius: 12px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
      
      &:hover:not(:disabled) {
        background-color: #d32f2f;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(244, 67, 54, 0.3);
      }
      
      &:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }
    }
  }
}

.renew-plan-modal {
  .modal-body .warning-text {
    line-height: 1.6;
  }

  .modal-footer .confirm-btn {
    background-color: var(--theme-color);

    &:hover:not(:disabled) {
      background-color: var(--primary-color-hover);
      box-shadow: 0 4px 12px rgba(var(--theme-color-rgb), 0.3);
    }
  }
}

/* 骨架屏 */
.skeleton-card {
  &::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    transform: translateX(-100%);
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    animation: shimmer 2s infinite;
  }
}

@keyframes shimmer {
  100% { transform: translateX(100%); }
}

/* 过渡动画 */
.modal-fade-enter-active, .modal-fade-leave-active { transition: opacity 0.3s ease; }
.modal-fade-enter-from, .modal-fade-leave-to { opacity: 0; }

@keyframes modal-in {
  from {
    opacity: 0;
    transform: translateY(20px) scale(0.98);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

.popup-slide-enter-active {
  transition: opacity var(--motion-slow) cubic-bezier(0.16, 1, 0.3, 1),
    transform var(--motion-slow) cubic-bezier(0.16, 1, 0.3, 1);
}

.popup-slide-leave-active {
  transition: opacity var(--motion-base) ease-out, transform var(--motion-base) ease-out;
}

.popup-slide-enter-from {
  opacity: 0;
  transform: translateY(20px) scale(0.98);
}

.popup-slide-leave-to {
  opacity: 0;
  transform: scale(0.95);
}

.loader-small {
  width: 16px;
  height: 16px;
  border: 2px solid rgba(255,255,255,0.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 1s linear infinite;
  display: inline-block;
  margin-right: 8px;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* 重置流量弹窗暗黑主题适配 */
.dark-theme .reset-traffic-modal,
.dark .reset-traffic-modal {
  background-color: #1a1a2e !important;
  
  .modal-header {
    border-bottom-color: #2d2d44 !important;
    
    h3 {
      color: #e0e0e0 !important;
    }
    
    .close-btn-icon {
      color: #8888aa !important;
      
      &:hover {
        background-color: rgba(59, 130, 246, 0.15) !important;
        color: var(--theme-color, #3b82f6) !important;
      }
    }
  }
  
  .modal-body {
    .warning-text {
      color: #e0e0e0 !important;
    }
    
    .note-text {
      background-color: rgba(255, 255, 255, 0.05) !important;
      color: #8888aa !important;
    }
  }
  
  .modal-footer {
    border-top-color: #2d2d44 !important;
    
    .cancel-btn {
      border-color: #2d2d44 !important;
      color: #c0c0d0 !important;
      
      &:hover {
        background-color: rgba(59, 130, 246, 0.1) !important;
        border-color: var(--theme-color) !important;
        color: var(--theme-color) !important;
      }
    }
  }
}
</style>

<!-- 全局弹窗样式（与主题同步） -->
<style lang="scss">
/* 公告弹窗全局样式 */
.notice-modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0, 0, 0, 0.7);
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  backdrop-filter: blur(4px);
}

.notice-modal {
  max-width: 500px;
  width: 100%;
  background-color: #ffffff;
  border-radius: 20px;
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
  border: 1px solid #e5e7eb;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  max-height: 80vh;
  animation: modal-in 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.notice-modal .notice-modal-header {
  padding: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid #e5e7eb;
  background-color: #ffffff;
}

.notice-modal .notice-modal-header .popup-title {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
  color: #1f2937;
}

.notice-modal .notice-modal-header .popup-close-btn {
  background: none;
  border: none;
  cursor: pointer;
  color: #6b7280;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 8px;
  margin: -8px;
  border-radius: 50%;
  transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
}

.notice-modal .notice-modal-header .popup-close-btn:hover {
  background-color: rgba(59, 130, 246, 0.1);
  color: #3b82f6;
  transform: rotate(90deg);
}

.notice-modal .notice-modal-content {
  padding: 20px;
  overflow-y: auto;
  flex: 1;
  background-color: #ffffff;
}

.notice-modal .notice-modal-content .notice-content {
  font-size: 14px;
  line-height: 1.6;
  color: #374151;
}

.notice-modal .notice-modal-footer {
  padding: 15px 20px;
  border-top: 1px solid #e5e7eb;
  display: flex;
  justify-content: flex-end;
  background-color: #f9fafb;
}

.notice-modal .notice-modal-footer .popup-action-btn {
  padding: 10px 24px;
  background-color: var(--theme-color, #3b82f6);
  color: white;
  border: none;
  border-radius: 12px;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
}

/* ========== 暗黑主题 - 使用和卡片相同的背景色 ========== */
.dark-theme .notice-modal,
.dark .notice-modal {
  max-width: 500px;
  background-color: #1a1a2e !important;
  border-color: #2d2d44 !important;
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4) !important;
}

.dark-theme .notice-modal .notice-modal-header,
.dark .notice-modal .notice-modal-header {
  background-color: #0f0f1a !important;
  border-bottom-color: #2d2d44 !important;
}

.dark-theme .notice-modal .notice-modal-header .popup-title,
.dark .notice-modal .notice-modal-header .popup-title {
  color: #e0e0e0 !important;
}

.dark-theme .notice-modal .notice-modal-header .popup-close-btn,
.dark .notice-modal .notice-modal-header .popup-close-btn {
  color: #8888aa !important;
}

.dark-theme .notice-modal .notice-modal-header .popup-close-btn:hover,
.dark .notice-modal .notice-modal-header .popup-close-btn:hover {
  background-color: rgba(59, 130, 246, 0.15) !important;
  color: var(--theme-color, #3b82f6) !important;
}

.dark-theme .notice-modal .notice-modal-content,
.dark .notice-modal .notice-modal-content {
  background-color: #1a1a2e !important;
}

.dark-theme .notice-modal .notice-modal-content .notice-content,
.dark .notice-modal .notice-modal-content .notice-content {
  color: #c0c0d0 !important;
}

.dark-theme .notice-modal .notice-modal-footer,
.dark .notice-modal .notice-modal-footer {
  background-color: #0f0f1a !important;
  border-top-color: #2d2d44 !important;
}

@keyframes modal-in {
  from {
    opacity: 0;
    transform: translateY(20px) scale(0.98);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}
</style>
