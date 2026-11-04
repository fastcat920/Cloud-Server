<template>
  <div class="more-container">
    <!-- 域名授权验证提示 -->
    <DomainAuthAlert
      :is-authorized="authStatus.isAuthorized"
      :api-domain="authStatus.apiDomain"
    />

    <div class="more-inner">
      <!-- 欢迎卡片 -->
      <button type="button" class="dashboard-card welcome-card" :class="{ 'card-animate': !loading.userInfo }" @click="goToProfile">
        <div class="account-icon">
          <IconUser :size="20" />
        </div>
        <div class="account-copy">
          <h2 class="card-title">{{ $t('dashboard.welcome') }}</h2>
          <p v-if="userStats.userEmail && DASHBOARD_CONFIG.showUserEmail" class="user-email">
            <span>{{ userStats.userEmail }}</span>
          </p>
        </div>
        <div class="card-arrow">
          <IconChevronRight :size="20" />
        </div>
      </button>

      <!-- 有套餐时显示套餐信息卡片 -->
      <SubscriptionUsageCard
        v-if="hasPlan"
        :plan-name="userPlan.name"
        :expiry-date="userPlan.expireDate"
        :is-permanent="userPlan.isExpireDatePermanent"
        :remaining-days="userStats.remainingDays"
        :is-remaining-days-permanent="userStats.isRemainingDaysPermanent"
        :reset-day="userPlan.resetDay"
        :used-percent="usedPercentage"
        :used-traffic="usedTrafficDisplay"
        :total-traffic="userPlan.totalTraffic"
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

      <!-- 没有套餐时的提示卡片 -->
      <div v-else class="dashboard-card stats-card no-plan-card">
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

      <!-- 原有菜单项 -->
      <h2 class="section-heading">{{ $t('mine.myServices') }}</h2>
      <div class="unified-card">
        <button type="button" class="menu-item" @click="$router.push('/orders')">
          <div class="menu-icon"><IconReceipt :size="24" /></div>
          <div class="menu-info"><div class="menu-title">{{ $t('orders.title') }}</div></div>
          <div class="menu-arrow"><IconChevronRight :size="18" /></div>
        </button>

        <button v-if="showTrafficLog" type="button" class="menu-item" @click="$router.push('/traffic')">
          <div class="menu-icon"><IconChartDonut :size="24" /></div>
          <div class="menu-info"><div class="menu-title">{{ $t('trafficLog.title') }}</div></div>
          <div class="menu-arrow"><IconChevronRight :size="18" /></div>
        </button>

        <button type="button" class="menu-item" @click="$router.push('/wallet/deposit')">
          <div class="menu-icon"><IconWallet :size="24" /></div>
          <div class="menu-info"><div class="menu-title">{{ $t('common.myWallet') }}</div></div>
          <div class="menu-right"><span class="balance-text">{{ currencySymbol }}{{ userBalance }}</span></div>
          <div class="menu-arrow"><IconChevronRight :size="18" /></div>
        </button>

        <button type="button" class="menu-item" @click="$router.push('/docs')">
          <div class="menu-icon"><IconBook :size="24" /></div>
          <div class="menu-info"><div class="menu-title">{{ $t('docs.title') }}</div></div>
          <div class="menu-arrow"><IconChevronRight :size="18" /></div>
        </button>

        <template v-if="morePageConfig.enableCustomCards">
          <button v-for="card in morePageConfig.customCards" :key="card.id" type="button" class="menu-item" @click="handleCustomCardClick(card)">
            <div class="menu-icon">
              <div v-if="card.svgIcon" class="custom-svg-icon" v-html="card.svgIcon"></div>
              <component v-else-if="card.icon" :is="getIconComponent(card.icon)" :size="24" />
            </div>
            <div class="menu-info"><div class="menu-title">{{ card.title }}</div></div>
            <div class="menu-arrow"><IconChevronRight :size="18" /></div>
          </button>
        </template>

        <button type="button" class="menu-item" @click="$router.push('/gift')">
          <div class="menu-icon"><IconGift :size="24" /></div>
          <div class="menu-info"><div class="menu-title">{{ $t('profile.giftCardTitle') }}</div></div>
          <div class="menu-arrow"><IconChevronRight :size="18" /></div>
        </button>

      </div>

    </div>

    <!-- 重置流量确认弹窗 -->
    <transition name="modal-fade">
      <div class="modal-overlay" v-if="showResetTrafficModal">
        <div class="modal-container">
          <div v-accessible-dialog="closeResetTrafficModal" class="modal-card reset-traffic-modal" :aria-label="trafficRecoveryCopy.title">
            <div class="modal-header">
              <h3>{{ trafficRecoveryCopy.title }}</h3>
              <button class="close-button" type="button" :aria-label="$t('common.close')" @click="closeResetTrafficModal">×</button>
            </div>
            <div class="modal-body">
              <div class="warning-icon"><IconAlertTriangle :size="48" /></div>
              <p class="warning-text">{{ trafficRecoveryCopy.description }}</p>
              <p class="note-text">{{ trafficRecoveryCopy.warning }}</p>
            </div>
            <div class="modal-footer">
              <button class="cancel-btn" @click="closeResetTrafficModal">{{ $t('common.cancel') }}</button>
              <button class="confirm-btn" :disabled="resetConfirmCooldown > 0 || isCreatingResetOrder" @click="confirmTrafficRecovery">
                <template v-if="isCreatingResetOrder">
                  <span class="loading-container"><div class="loader-small"></div><span>{{ $t('common.loading') }}</span></span>
                </template>
                <template v-else>{{ resetConfirmCooldown > 0 ? `${$t('common.confirm')} (${resetConfirmCooldown})` : $t('common.confirm') }}</template>
              </button>
            </div>
          </div>
        </div>
      </div>
    </transition>

    <!-- 流量耗尽时续费确认弹窗 -->
    <transition name="modal-fade">
      <div class="modal-overlay" v-if="showRenewPlanModal">
        <div class="modal-container">
          <div v-accessible-dialog="closeRenewPlanModal" class="modal-card reset-traffic-modal renew-plan-modal" :aria-label="$t('dashboard.confirmRenewPlan')">
            <div class="modal-header">
              <h3>{{ $t('dashboard.confirmRenewPlan') }}</h3>
              <button class="close-button" type="button" :aria-label="$t('common.close')" @click="closeRenewPlanModal">×</button>
            </div>
            <div class="modal-body">
              <div class="warning-icon"><IconAlertTriangle :size="48" /></div>
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
  </div>
</template>

<script setup name="MoreOptions">
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  IconFileText,
  IconShoppingCart,
  IconChevronRight,
  IconServer,
  IconChartBar,
  IconWallet,
  IconAlertTriangle,
  IconUser,
  IconShoppingBag,
  IconGift,
  IconReceipt,
  IconChartDonut,
  IconBook
} from '@tabler/icons-vue';
import { useToast } from '@/composables/useToast';
import { getUserInfo, getSubscribe, getUserConfig, startNewTrafficPeriod } from '@/api/dashboard';
import { createOrderAfterUnpaidCleanup } from '@/utils/orderCleanup';
import { TRAFFICLOG_CONFIG, MORE_PAGE_CONFIG, DASHBOARD_CONFIG } from '@/utils/baseConfig';
import DomainAuthAlert from '@/components/common/DomainAuthAlert.vue';
import SubscriptionUsageCard from '@/components/subscription/SubscriptionUsageCard.vue';
import { applyDomainAuth } from '@/utils/licenseAuth';
import { calculateRemainingDays, formatExpiryDate, isExpiryDateExpired } from '@/utils/subscriptionExpiry';

const { t, locale } = useI18n();
const router = useRouter();
const { showToast } = useToast();

const isSmallScreen = ref(false);
const authStatus = ref({ isAuthorized: true, apiDomain: '' });
const showTrafficLog = ref(false);
const morePageConfig = MORE_PAGE_CONFIG;

const loading = reactive({ userInfo: true, subscribe: true });
const userStats = reactive({ remainingTraffic: '', remainingDays: '', userEmail: '', isRemainingDaysPermanent: false, usedTrafficBytes: 0 });
const userPlan = ref({ name: '', expireDate: null, expiredAt: null, isExpireDatePermanent: false, totalTraffic: '', totalTrafficBytes: 0, resetDay: null, deviceLimit: null, aliveIp: 0, subscribeUrl: '' });
const userPlanId = ref(null);
const allowNewPeriod = ref(false);
const userBalance = ref('0.00');
const currencySymbol = ref('¥');
const hasPlan = ref(true);

const showResetTrafficModal = ref(false);
const showRenewPlanModal = ref(false);
const resetConfirmCooldown = ref(0);
let resetConfirmTimer = null;
const isCreatingResetOrder = ref(false);

const fetchUserInfo = async () => {
  loading.userInfo = true;
  try {
    const response = await getUserInfo();
    if (response.data) {
      const info = response.data;
      userPlanId.value = info.plan_id;
      hasPlan.value = info.plan_id !== null && info.plan_id !== undefined;
      if (info.email) userStats.userEmail = info.email;
      if (info.balance !== undefined) userBalance.value = (info.balance / 100).toFixed(2);

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
  loading.subscribe = true;
  try {
    const response = await getSubscribe({ force });
    if (response.data) {
      const subscribe = response.data;
      allowNewPeriod.value = subscribe.allow_new_period === true || Number(subscribe.allow_new_period) === 1;
      if (subscribe.plan?.name) userPlan.value.name = subscribe.plan.name;
      if (subscribe.plan?.id) userPlanId.value = subscribe.plan.id;
      if (subscribe.transfer_enable) {
        userPlan.value.totalTrafficBytes = Number(subscribe.transfer_enable) || 0;
        userPlan.value.totalTraffic = formatTraffic(userPlan.value.totalTrafficBytes, 0);
      }
      if (subscribe.transfer_enable && subscribe.u !== undefined && subscribe.d !== undefined) {
        const usedTraffic = Math.max(0, (Number(subscribe.u) || 0) + (Number(subscribe.d) || 0));
        userStats.usedTrafficBytes = usedTraffic;
        const remainingTraffic = Math.max(0, userPlan.value.totalTrafficBytes - usedTraffic);
        userStats.remainingTraffic = formatTraffic(remainingTraffic);
      }
      if (subscribe.reset_day !== undefined && subscribe.reset_day !== null) userPlan.value.resetDay = subscribe.reset_day;
      if (subscribe.subscribe_url) userPlan.value.subscribeUrl = subscribe.subscribe_url;
      if (subscribe.device_limit !== undefined) userPlan.value.deviceLimit = subscribe.device_limit;
      if (subscribe.alive_ip !== undefined) userPlan.value.aliveIp = subscribe.alive_ip;

      if (subscribe.expired_at) {
        userPlan.value.expireDate = formatExpiryDate(subscribe.expired_at);
        userPlan.value.expiredAt = subscribe.expired_at;
        userPlan.value.isExpireDatePermanent = false;
        userStats.remainingDays = `${calculateRemainingDays(subscribe.expired_at)}`;
        userStats.isRemainingDaysPermanent = false;
      } else {
        userPlan.value.expiredAt = null;
        userStats.remainingDays = null;
        userStats.isRemainingDaysPermanent = true;
      }
    }
  } catch (error) {
    console.error('获取订阅信息失败:', error);
  } finally {
    loading.subscribe = false;
  }
};

const fetchUserConfig = async () => {
  try {
    const response = await getUserConfig();
    if (response?.data?.currency_symbol) currencySymbol.value = response.data.currency_symbol;
  } catch (error) {
    console.error('获取用户配置失败:', error);
  }
};

watch(locale, () => {
  fetchSubscribe(true);
});

const formatTraffic = (bytes, decimals = 2) => {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(k)), sizes.length - 1);
  return parseFloat((bytes / Math.pow(k, i)).toFixed(decimals)) + ' ' + sizes[i];
};

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

const isLowTraffic = computed(() => {
  const remainingMatch = userStats.remainingTraffic.match(/(\d+(\.\d+)?)\s*([KMGT]?B)/i);
  if (!userPlan.value.totalTraffic || !remainingMatch) return false;
  const totalMatch = userPlan.value.totalTraffic.match(/(\d+(\.\d+)?)\s*([KMGT]?B)/i);
  if (!totalMatch) return false;
  const remainingValue = parseFloat(remainingMatch[1]);
  const remainingUnit = remainingMatch[3].toUpperCase();
  const totalValue = parseFloat(totalMatch[1]);
  const totalUnit = totalMatch[3].toUpperCase();
  const unitToBytes = { B: 1, KB: 1024, MB: 1048576, GB: 1073741824, TB: 1099511627776 };
  const remainingBytes = remainingValue * unitToBytes[remainingUnit];
  const totalBytes = totalValue * unitToBytes[totalUnit];
  if (totalBytes === 0) return false;
  const percentage = (remainingBytes / totalBytes) * 100;
  return percentage > 0 && percentage <= DASHBOARD_CONFIG.lowTrafficThreshold;
});

const isTrafficDepleted = computed(() => {
  const remainingMatch = userStats.remainingTraffic.match(/(\d+(\.\d+)?)\s*([KMGT]?B)/i);
  if (!remainingMatch) return false;
  const value = parseFloat(remainingMatch[1]);
  const unit = remainingMatch[3].toUpperCase();
  if (value === 0) return true;
  if (unit === 'B' && value < 10) return true;
  if (unit === 'KB' && value < 0.01) return true;
  return false;
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

const usedTrafficDisplay = computed(() => {
  return formatBytes(userStats.usedTrafficBytes, 2);
});

const hasValidTrafficData = computed(() => userPlan.value.totalTrafficBytes > 0);

const usedPercentage = computed(() => {
  if (!hasValidTrafficData.value) return 0;
  const totalBytes = userPlan.value.totalTrafficBytes;
  if (totalBytes === 0) return 0;
  const percent = (userStats.usedTrafficBytes / totalBytes) * 100;
  return Math.min(Math.max(percent, 0), 100);
});

function formatBytes(bytes, decimals = 2) {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(k)), sizes.length - 1);
  return parseFloat((bytes / Math.pow(k, i)).toFixed(decimals)) + ' ' + sizes[i];
}

const goToRenewPlan = () => {
  if (!userPlanId.value) {
    showToast(t('dashboard.noPlanToRenew'), 'error');
    return;
  }
  router.push(`/plan/${userPlanId.value}`);
};

const renewPlan = () => {
  if (!userPlanId.value) {
    showToast(t('dashboard.noPlanToRenew'), 'error');
    return;
  }
  if (isTrafficDepleted.value) {
    showRenewPlanModal.value = true;
    return;
  }
  goToRenewPlan();
};

const closeRenewPlanModal = () => {
  showRenewPlanModal.value = false;
};

const confirmRenewPlan = () => {
  closeRenewPlanModal();
  goToRenewPlan();
};

const openResetTrafficModal = () => {
  showResetTrafficModal.value = true;
  resetConfirmCooldown.value = 3;
  resetConfirmTimer = setInterval(() => {
    if (resetConfirmCooldown.value > 0) resetConfirmCooldown.value--;
    else clearInterval(resetConfirmTimer);
  }, 1000);
};

const closeResetTrafficModal = () => {
  showResetTrafficModal.value = false;
  if (resetConfirmTimer) clearInterval(resetConfirmTimer);
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

const goToShop = () => router.push('/shop');
const goToProfile = () => router.push('/profile');
const checkScreenSize = () => { isSmallScreen.value = window.innerWidth < 905; };
const handleCustomCardClick = (card) => {
  if (card.url) {
    if (card.openInNewTab) window.open(card.url, '_blank');
    else window.location.href = card.url;
  }
};
const getIconComponent = (iconName) => {
  const iconMap = { IconFileText, IconShoppingCart, IconChevronRight, IconServer, IconChartBar, IconWallet };
  return iconMap[iconName] || IconChevronRight;
};

onMounted(() => {
  showTrafficLog.value = TRAFFICLOG_CONFIG.enableTrafficLog;
  checkScreenSize();
  window.addEventListener('resize', checkScreenSize);
  applyDomainAuth().then(status => { authStatus.value = status; });
  Promise.allSettled([
    fetchUserConfig(),
    fetchUserInfo(),
    fetchSubscribe()
  ]);
});

onUnmounted(() => {
  window.removeEventListener('resize', checkScreenSize);
  if (resetConfirmTimer) clearInterval(resetConfirmTimer);
});
</script>

<style lang="scss" scoped>
.expired-message {
  color: #f44336;
  font-weight: 500;
  font-size: 16px;
  margin: 0;
}

.more-container {
  padding: 20px;
  display: flex;
  justify-content: center;

  .more-inner {
    width: 100%;
    max-width: 900px;
  }

  // 欢迎卡片和套餐卡片通用样式
  .dashboard-card {
    background-color: #ffffff;
    border-radius: 20px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    padding: 20px;
    margin-bottom: 24px;
    border: 1px solid var(--card-border);
    transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;

    &:hover {
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      border-color: rgba(var(--theme-color-rgb), 0.3);
    }

    .card-header {
      margin-bottom: 15px;

      .card-title {
        font-size: 18px;
        font-weight: 600;
        margin: 0;
      }
    }

    .user-email {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 12px;
      padding-top: 8px;
      border-top: 1px solid rgba(var(--theme-color-rgb), 0.1);
      color: var(--supporting-text-color);
      font-size: 14px;
    }
  }

  .dark & .dashboard-card,
  .dark-theme & .dashboard-card {
    background-color: #1e293b !important;
  }

  .welcome-card {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 68px;
    padding: 10px 16px;
    cursor: pointer;

    .account-icon {
      width: 36px;
      height: 36px;
      flex: 0 0 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--theme-color);
      background: rgba(var(--theme-color-rgb), 0.1);
      border-radius: var(--radius-chip);
    }

    .account-copy {
      min-width: 0;
      flex: 1;

      .card-title {
        margin: 0;
        font-size: 14px;
        line-height: 1.4;
      }
    }

    .card-arrow {
      display: flex;
      align-items: center;
      color: var(--chevron-color);
      transition: transform 0.2s ease, color 0.2s ease;
    }

    .user-email {
      display: block;
      min-width: 0;
      margin: 2px 0 0;
      padding: 0;
      overflow: hidden;
      color: var(--supporting-text-color);
      border: 0;
      font-size: 13px;
      line-height: 1.4;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    &:hover .card-arrow {
      transform: translateX(3px);
      color: var(--theme-color);
    }
  }

  .subscription-card {
    padding: 18px 20px;

    .subscription-info {
      min-width: 0;
      margin-bottom: 0;

      .plan-info {
        margin-bottom: 8px;
        .info-value {
          font-size: 16px;
          font-weight: 600;
          color: var(--text-color);
        }
      }

      .info-item .info-row {
        display: grid;
        gap: 4px;
        .info-label {
          display: block;
          font-size: 14px;
          color: var(--secondary-text-color);
          line-height: 1.55;
          overflow-wrap: anywhere;
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
        button { width: 100%; }
      }
      .btn-outline {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        background-color: transparent;
        color: var(--text-color);
        border: 1px solid var(--card-border);
        transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
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
        &.renew-warning { color: #ff9800; border-color: #ff9800; background-color: rgba(255,152,0,0.1); }
        &.renew-danger { color: #f44336; border-color: #f44336; background-color: rgba(244,67,54,0.1); }
      }
      .reset-traffic-btn {
        &.reset-warning { color: #ff9800; border-color: #ff9800; background-color: rgba(255,152,0,0.1); }
        &.reset-danger { color: #f44336; border-color: #f44336; background-color: rgba(244,67,54,0.1); }
      }
    }

    .skeleton-card {
      width: 100%;
      border-radius: 20px;
      overflow: hidden;
      position: relative;
      &::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        transform: translateX(-100%);
        background-image: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
        animation: shimmer 2s infinite;
      }
      .skeleton-header { height: 24px; margin: 16px 20px; background-color: var(--skeleton-bg, rgba(0,0,0,0.05)); border-radius: 6px; width: 30%; }
      .skeleton-body { padding: 0 20px 20px; .skeleton-row { height: 16px; margin-bottom: 16px; background-color: var(--skeleton-bg, rgba(0,0,0,0.05)); border-radius: 4px; width: 100%; &:last-child { width: 75%; } } }
    }
  }

  .no-plan-card {
    background: linear-gradient(145deg, rgba(255,152,0,0.05), rgba(255,152,0,0.1));
    border-color: #ff9800;
    position: relative;
    overflow: hidden;
    &::before, &::after {
      content: '';
      position: absolute;
      border-radius: 50%;
      background: rgba(255,152,0,0.08);
      z-index: 0;
    }
    &::before { top: -20px; right: -20px; width: 120px; height: 120px; }
    &::after { bottom: -30px; left: -30px; width: 160px; height: 160px; }
    .no-plan-content {
      display: flex;
      align-items: center;
      gap: 24px;
      position: relative;
      z-index: 1;
      @media (max-width: 768px) {
        flex-direction: column;
        text-align: center;
      }
    }
    .no-plan-icon {
      background-color: rgba(255,152,0,0.15);
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
    &:hover .no-plan-icon { transform: rotate(0deg) scale(1.05); }
    .no-plan-message { flex: 1; }
    .no-plan-title {
      color: #ff9800;
      font-size: 1.2rem;
      font-weight: 600;
      margin-bottom: 16px;
    }
    .no-plan-actions {
      display: flex;
      gap: 12px;
      .action-button {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 18px;
        border-radius: 10px;
        font-size: 15px;
        font-weight: 500;
        cursor: pointer;
        transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
        &.primary {
          background-color: var(--theme-color);
          color: white;
          border: none;
          box-shadow: 0 4px 10px rgba(var(--theme-color-rgb),0.3);
          &:hover {
            background-color: var(--theme-hover-color);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(var(--theme-color-rgb),0.4);
          }
        }
      }
    }
  }

  .unified-card {
    background-color: #ffffff;
    border-radius: 20px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    overflow: hidden;
    border: 1px solid var(--card-border);
    transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
    margin-top: 0;
    &:hover {
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      border-color: rgba(var(--theme-color-rgb), 0.3);
    }
  }
  .dark & .unified-card, .dark-theme & .unified-card {
    background-color: #1e293b !important;
  }

  .menu-item {
    display: flex;
    align-items: center;
    min-height: 56px;
    padding: 8px 16px;
    position: relative;
    cursor: pointer;
    transition: background-color 0.2s ease;
    &:not(:last-child)::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 65px;
      right: 16px;
      height: 1px;
      background-color: var(--border-color);
    }
    &:hover {
      background-color: rgba(var(--theme-color-rgb), 0.05);
      .menu-arrow { transform: translateX(3px); opacity: 1; }
    }
    .menu-icon {
      width: 36px;
      height: 36px;
      background-color: rgba(var(--theme-color-rgb), 0.1);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 15px;
      color: var(--theme-color);
      flex-shrink: 0;
      :deep(svg) { width: 20px; height: 20px; }
    }
    .menu-info {
      flex: 1;
      .menu-title { font-size: 15px; font-weight: 500; color: var(--text-color); }
    }
    .menu-arrow {
      color: var(--chevron-color);
      opacity: 1;
      transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
      margin-left: 8px;
    }
  }

  .section-heading {
    margin: 4px 0 10px;
    padding-left: 2px;
    color: var(--secondary-text-color);
    font-size: 14px;
    font-weight: 600;
    line-height: 1.4;
  }

  // 重置流量弹窗样式
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
  }
  .modal-container {
    width: 90%;
    max-width: 400px;
    border-radius: 20px;
    overflow: hidden;
  }
  .reset-traffic-modal {
    background-color: #ffffff;
    .modal-header {
      padding: 16px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid var(--border-color);
      h3 { margin: 0; font-size: 18px; font-weight: 600; color: var(--text-color); }
      .close-button { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--secondary-text-color); }
    }
    .modal-body {
      padding: 20px;
      text-align: center;
      .warning-icon { color: #ff9800; margin-bottom: 16px; }
      .warning-text { font-size: 16px; margin-bottom: 12px; color: var(--text-color); }
      .note-text { font-size: 14px; color: var(--secondary-text-color); background: rgba(var(--theme-color-rgb),0.05); padding: 8px 12px; border-radius: 6px; }
    }
    .modal-footer {
      padding: 16px 20px;
      display: flex;
      justify-content: flex-end;
      gap: 12px;
      border-top: 1px solid var(--border-color);
      button { padding: 8px 16px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease; }
      .cancel-btn { background: transparent; border: 1px solid var(--border-color); color: var(--text-color); }
      .confirm-btn { background-color: #f44336; color: white; border: none; &:hover { background-color: #e53935; transform: translateY(-2px); } }
    }
  }
  .dark & .reset-traffic-modal, .dark-theme & .reset-traffic-modal {
    background-color: #1e293b !important;
  }
  .renew-plan-modal {
    .modal-body .warning-text {
      line-height: 1.6;
    }
    .modal-footer .confirm-btn {
      background-color: var(--theme-color);
      &:hover {
        background-color: var(--primary-color-hover);
      }
    }
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
  .loading-container { display: flex; align-items: center; justify-content: center; }
  @keyframes spin { to { transform: rotate(360deg); } }
  @keyframes shimmer { 100% { transform: translateX(100%); } }
  .modal-fade-enter-active, .modal-fade-leave-active { transition: opacity 0.3s ease; }
  .modal-fade-enter-from, .modal-fade-leave-to { opacity: 0; }
}

@media (max-width: 768px) {
  .more-container {
    padding: 12px 16px 80px;
  }

  .more-container .dashboard-card {
    margin-bottom: 16px;
  }

}
</style>
