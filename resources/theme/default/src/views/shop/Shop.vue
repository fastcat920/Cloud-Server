<template>
  <div class="shop-container">
    <div class="shop-inner">
      
      <!-- 套餐列表 -->
      <div class="plans-wrapper">
        <!-- 无结果提示 -->
        <div class="no-plans-message" v-if="!loading.plans && filteredPlans.length === 0">
          <IconInfoCircle :size="48" class="info-icon" />
          <h3>{{ $t('shop.no_plans_found') }}</h3>
          <p>{{ $t('shop.try_different_filter') }}</p>
          <button class="btn-reset-filter" @click="selectedFilter = 'all'">
            {{ $t('shop.reset_filter') }}
          </button>
        </div>
        
        <!-- 骨架屏加载动画 -->
        <div class="dashboard-card" v-else-if="loading.plans" v-for="i in 3" :key="'skeleton-'+i">
          <div class="skeleton-card">
            <div class="skeleton-header"></div>
            <div class="skeleton-body">
              <div class="skeleton-price"></div>
              <div class="skeleton-features">
                <div class="skeleton-feature" v-for="j in 5" :key="'feature-'+j"></div>
              </div>
              <div class="skeleton-button"></div>
            </div>
          </div>
        </div>
        
        <!-- 套餐卡片 -->
        <div class="plan-card" v-else v-for="plan in filteredPlans" :key="plan.id">
          <div class="card-header">
            <h2 class="card-title">{{ plan.name }}</h2>
            <span v-if="getLowestPriceInfo(plan).sale" class="sale-badge">{{ $t('shop.limited_offer') }}</span>
          </div>
          <div class="card-body">
            <div class="plan-content">
              <div class="price-display">
                <span class="currency">{{ currencySymbol }}</span>
                <span class="amount">{{ getLowestPriceInfo(plan).price }}</span>
                <del v-if="getLowestPriceInfo(plan).original">{{ currencySymbol }}{{ getLowestPriceInfo(plan).original }}</del>
                <span class="period">/ {{ $t(`shop.plan.price_options.${getPriceTypeKey(getLowestPriceInfo(plan).type)}`) }}</span>
              </div>
              <div v-if="getLowestPriceInfo(plan).sale" class="sale-countdown">{{ $t('shop.ends_in') }} {{ saleCountdown(getLowestPriceInfo(plan).sale.ends_at) }}</div>
              <div v-if="getLowestPriceInfo(plan).sale && getLowestPriceInfo(plan).sale.allow_coupon === false" class="sale-best-price-note">{{ $t('shop.checkout_best_price') }}</div>

              <div class="plan-metrics">
                <div class="plan-metric">
                  <IconCloud :size="20" />
                  <strong>{{ formatTraffic(plan.transfer_enable) }}</strong>
                </div>
                <div class="plan-metric">
                  <IconGauge :size="20" />
                  <strong>{{ formatSpeedLimit(plan.speed_limit) }}</strong>
                </div>
                <div class="plan-metric">
                  <IconDevices :size="20" />
                  <strong>{{ formatDeviceLimit(plan.device_limit) }}</strong>
                </div>
              </div>

              <div v-if="plan.content" class="plan-description">
                <div v-if="isJsonContent(plan.content)" class="feature-list">
                  <div v-for="(feature, index) in parseJsonContent(plan.content)" :key="index" class="feature-item">
                    <IconCheck v-if="feature.support" class="feature-icon enabled" />
                    <IconX v-else class="feature-icon disabled" />
                    <span :class="{ 'disabled-text': !feature.support }">{{ feature.feature }}</span>
                  </div>
                </div>
                <div v-else class="html-content" v-html="sanitizeHtml(plan.content)"></div>
              </div>
            </div>

            <!-- 购买按钮 -->
            <button 
              class="btn-purchase" 
              @click="purchasePlan(plan)"
            >
              <IconShoppingCart class="btn-icon" />
              <span class="btn-text">{{ $t('shop.plan.purchaseNow') }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 弹窗组件 -->
  <ShopPopup
    :show-popup="showPopup"
    :title="popupConfig.title"
    :content="popupConfig.content"
    :cooldown-hours="popupConfig.cooldownHours"
    :close-wait-seconds="popupConfig.closeWaitSeconds"
    @close="handlePopupClose"
  />
</template>

<script>
import { ref, reactive, onMounted, computed, watch, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import { useToast } from '@/composables/useToast';
import { fetchPlans, getCommConfig } from '@/api/shop';
import { SHOP_CONFIG } from '@/utils/baseConfig';
import ShopPopup from '@/components/shop/ShopPopup.vue';
import {
  IconCheck,
  IconX,
  IconShoppingCart,
  IconInfoCircle,
  IconCloud,
  IconGauge,
  IconDevices,
} from '@tabler/icons-vue';
import { useRouter } from 'vue-router';
import { sanitizeHtml } from '@/utils/sanitizeHtml';

export default {
  name: 'ShopView',
  components: {
    IconCheck,
    IconX,
    IconShoppingCart,
    IconInfoCircle,
    IconCloud,
    IconGauge,
    IconDevices,
    ShopPopup
  },
  setup() {
    const { t, locale } = useI18n();
    const { showToast } = useToast();
    const router = useRouter();
    
    // 加载状态
    const loading = reactive({
      plans: true,
      config: true
    });
    
    // 套餐数据
    const plans = ref([]);
    const currency = ref('CNY');
    const currencySymbol = ref('¥');
    
    // 选中的价格类型
    const selectedPriceType = reactive({});
    
    // 筛选选项
    const selectedFilter = ref('all');
    
    // 筛选选项列表
    const filters = [
      { label: '全部', value: 'all' },
      { label: '周期性', value: 'recurring' },
      { label: '一次性', value: 'onetime' }
    ];
    
    // 弹窗相关
    const showPopup = ref(false);
    const popupConfig = reactive({
      title: '',
      content: '',
      cooldownHours: 2,
      closeWaitSeconds: 0
    });
    
    // 处理弹窗关闭事件
    const handlePopupClose = () => {
      showPopup.value = false;
    };
    
    // 初始化弹窗配置
    const initPopup = () => {
      if (SHOP_CONFIG.popup && SHOP_CONFIG.popup.enabled) {
        popupConfig.title = SHOP_CONFIG.popup.title || '';
        popupConfig.content = SHOP_CONFIG.popup.content || '';
        popupConfig.cooldownHours = SHOP_CONFIG.popup.cooldownHours || 24;
        popupConfig.closeWaitSeconds = SHOP_CONFIG.popup.closeWaitSeconds || 0;
        
        if (popupConfig.cooldownHours === 0) {
          showPopup.value = true;
          return;
        }
        
        const closeTime = localStorage.getItem('shop_popup_close_time');
        if (!closeTime) {
          showPopup.value = true;
        } else {
          const now = new Date().getTime();
          const elapsed = now - parseInt(closeTime);
          const cooldownMs = popupConfig.cooldownHours * 60 * 60 * 1000;
          if (elapsed >= cooldownMs) {
            showPopup.value = true;
          }
        }
      }
    };
    
    // 当前语言
    const currentLanguage = computed(() => locale.value);
    
    // 设置筛选选项
    const setFilter = (filter) => {
      selectedFilter.value = filter;
    };
    
    // 获取套餐的主要价格类型（优先选择最大周期）
    const getPlanMainPriceType = (plan) => {
      const priceTypes = SHOP_CONFIG.periodOrder || ['three_year_price', 'two_year_price', 'year_price', 'half_year_price', 'quarter_price', 'month_price', 'onetime_price'];
      const recurringTypes = priceTypes.filter(type => type !== 'onetime_price');
      const defaultRecurring = recurringTypes.find(type => plan[type] !== null);
      if (defaultRecurring) return defaultRecurring;
      if (plan.onetime_price !== null) return 'onetime_price';
      return priceTypes.find(type => plan[type] !== null) || priceTypes[0];
    };
    
    // 获取套餐的主要价格
    const getPlanMainPrice = (plan) => {
      const priceType = getDisplayPriceType(plan);
      if (!priceType || plan[priceType] === null || plan[priceType] === undefined) {
        return '--';
      }
      return (plan[priceType] / 100).toFixed(2);
    };

    const getLowestPriceInfo = (plan) => {
      const entries = Object.entries(getPlanPrices(plan))
        .filter(([, price]) => price !== null && price !== undefined)
        .map(([type, price]) => {
          const sale = plan.active_flash_sales?.[type];
          return { type, raw: Number(sale?.final_amount ?? price), original: sale ? Number(price) : null, sale };
        });
      if (!entries.length) return { type: 'month_price', price: '--' };
      const lowest = entries.reduce((best, item) => item.raw < best.raw ? item : best);
      return { type: lowest.type, price: (lowest.raw / 100).toFixed(2), original: lowest.original === null ? null : (lowest.original / 100).toFixed(2), sale: lowest.sale };
    };

    const saleCountdown = endsAt => {
      const seconds = Math.max(0, Number(endsAt || 0) - Math.floor(Date.now() / 1000));
      const days = Math.floor(seconds / 86400); const hours = Math.floor(seconds % 86400 / 3600); const minutes = Math.floor(seconds % 3600 / 60);
      return t('shop.sale_countdown', { days, hours, minutes });
    };

    const formatSpeedLimit = (limit) => {
      const value = Number(limit);
      return value > 0 ? `${value} Mbps` : t('shop.plan.unlimitedSpeed', '不限速');
    };

    const formatDeviceLimit = (limit) => {
      const value = Number(limit);
      if (value <= 0) return t('shop.plan.unlimitedDevices');
      const unitKey = value === 1 ? 'shop.plan.deviceUnit' : 'shop.plan.deviceUnitPlural';
      return `${value} ${t(unitKey)}`;
    };
    
    // 监听筛选器变化
    watch(() => selectedFilter.value, () => {
      console.log('筛选条件变化为:', selectedFilter.value);
    });
    
    // 监听语言变化
    watch(() => currentLanguage.value, () => {
      fetchPlanData();
    });
    
    onMounted(() => {
      selectedFilter.value = 'all';
    });
    
    // 获取套餐数据
    const fetchPlanData = async () => {
      loading.plans = true;
      try {
        const response = await fetchPlans();
        if (response.data) {
          plans.value = response.data;
          Object.keys(selectedPriceType).forEach(key => delete selectedPriceType[key]);
          
          if (SHOP_CONFIG.autoSelectMaxPeriod) {
            nextTick(() => {
              plans.value.forEach(plan => {
                const priceTypes = SHOP_CONFIG.periodOrder || ['three_year_price', 'two_year_price', 'year_price', 'half_year_price', 'quarter_price', 'month_price', 'onetime_price'];
                const recurringTypes = priceTypes.filter(type => type !== 'onetime_price');
                const defaultRecurring = recurringTypes.find(type => plan[type] !== null);
                if (defaultRecurring) {
                  selectedPriceType[plan.id] = defaultRecurring;
                } else if (plan.onetime_price !== null) {
                  selectedPriceType[plan.id] = 'onetime_price';
                } else {
                  selectedPriceType[plan.id] = priceTypes.find(type => plan[type] !== null) || priceTypes[0];
                }
              });
            });
          }
        }
      } catch (error) {
        console.error('获取套餐数据失败:', error);
        showToast(t('shop.loadFailed'), 'error');
      } finally {
        loading.plans = false;
      }
    };
    
    // 获取系统配置
    const fetchConfig = async () => {
      loading.config = true;
      try {
        const response = await getCommConfig();
        if (response.data) {
          currency.value = response.data.currency || 'CNY';
          currencySymbol.value = response.data.currency_symbol || '¥';
        }
      } catch (error) {
        console.error('获取系统配置失败:', error);
      } finally {
        loading.config = false;
      }
    };
    
    // 获取套餐所有价格
    const getPlanPrices = (plan) => ({
      month_price: plan.month_price,
      quarter_price: plan.quarter_price,
      half_year_price: plan.half_year_price,
      year_price: plan.year_price,
      two_year_price: plan.two_year_price,
      three_year_price: plan.three_year_price,
      onetime_price: plan.onetime_price
    });
    
    // 获取价格类型key（用于i18n）
    const getPriceTypeKey = (type) => {
      const keyMap = {
        month_price: 'month',
        quarter_price: 'quarter',
        half_year_price: 'half_year',
        year_price: 'year',
        two_year_price: 'two_year',
        three_year_price: 'three_year',
        onetime_price: 'onetime'
      };
      return keyMap[type] || '';
    };
    
    // 选择价格类型
    const selectPlanPriceType = (planId, type) => {
      const plan = plans.value.find(p => p.id === planId);
      if (plan && plan[type] !== null) {
        selectedPriceType[planId] = type;
      }
    };
    
    // 获取显示的价格类型
    const getDisplayPriceType = (plan) => {
      if (selectedPriceType[plan.id]) return selectedPriceType[plan.id];
      if (SHOP_CONFIG.autoSelectMaxPeriod) return getPlanMainPriceType(plan);
      const availablePrices = Object.entries(getPlanPrices(plan))
        .filter(([, price]) => price !== null)
        .map(([type]) => type);
      return availablePrices.length > 0 ? availablePrices[0] : '';
    };
    
    // 判断内容是否为JSON格式
    const isJsonContent = (content) => {
      if (!content) return false;
      try {
        const parsed = JSON.parse(content);
        return Array.isArray(parsed) && parsed.length > 0 && Object.prototype.hasOwnProperty.call(parsed[0], 'feature');
      } catch (e) {
        return false;
      }
    };
    
    // 解析JSON格式的内容
    const parseJsonContent = (content) => {
      try {
        return JSON.parse(content);
      } catch (e) {
        return [];
      }
    };
    
    // 格式化流量
    const formatTraffic = (gb) => {
      if (!gb) return '-';
      return `${gb} GB`;
    };
    
    // 购买套餐
    const purchasePlan = (plan) => {
      const priceType = getLowestPriceInfo(plan).type;
      router.push({
        name: 'Plan',
        params: { id: plan.id },
        query: { period: priceType }
      });
    };
    
    // 筛选后的套餐列表
    const filteredPlans = computed(() => {
      if (selectedFilter.value === 'all') return plans.value;
      if (selectedFilter.value === 'recurring') {
        return plans.value.filter(plan => {
          const recurringTypes = ['month_price', 'quarter_price', 'half_year_price', 'year_price', 'two_year_price', 'three_year_price'];
          const hasRecurring = recurringTypes.some(type => plan[type] !== null);
          const isOnlyOnetime = plan.onetime_price !== null && !hasRecurring;
          return hasRecurring && !isOnlyOnetime;
        });
      }
      if (selectedFilter.value === 'onetime') {
        return plans.value.filter(plan => plan.onetime_price !== null);
      }
      return plans.value;
    });
    
    // 计算周期折扣
    const calculateDiscount = (plan) => {
      if (!plan.month_price) {
        return { showDiscount: false, periodName: '', discountPercentage: 0, savingsAmount: 0 };
      }
      const monthlyPrice = plan.month_price / 100;
      const availablePeriods = [
        { type: 'three_year_price', price: plan.three_year_price ? plan.three_year_price / 100 : null, months: 36, name: t('shop.plan.price_options.three_year') },
        { type: 'two_year_price', price: plan.two_year_price ? plan.two_year_price / 100 : null, months: 24, name: t('shop.plan.price_options.two_year') },
        { type: 'year_price', price: plan.year_price ? plan.year_price / 100 : null, months: 12, name: t('shop.plan.price_options.year') },
        { type: 'half_year_price', price: plan.half_year_price ? plan.half_year_price / 100 : null, months: 6, name: t('shop.plan.price_options.half_year') },
        { type: 'quarter_price', price: plan.quarter_price ? plan.quarter_price / 100 : null, months: 3, name: t('shop.plan.price_options.quarter') }
      ].filter(period => period.price !== null);
      
      if (availablePeriods.length === 0) {
        return { showDiscount: false, periodName: '', discountPercentage: 0, savingsAmount: 0 };
      }
      const selectedPeriod = availablePeriods[0];
      const totalMonthlyPrice = monthlyPrice * selectedPeriod.months;
      const discountPercentage = ((totalMonthlyPrice - selectedPeriod.price) / totalMonthlyPrice) * 100;
      const savingsAmount = (totalMonthlyPrice - selectedPeriod.price).toFixed(2);
      return {
        showDiscount: discountPercentage > 1,
        periodName: selectedPeriod.name,
        discountPercentage: discountPercentage.toFixed(0),
        savingsAmount: savingsAmount
      };
    };
    
    onMounted(async () => {
      try {
        loading.plans = true;
        await Promise.all([fetchPlanData(), fetchConfig()]);
        loading.plans = false;
        nextTick(() => {
          if (SHOP_CONFIG.popup && SHOP_CONFIG.popup.enabled && SHOP_CONFIG.popup.cooldownHours === 0) {
            localStorage.removeItem('shop_popup_close_time');
          }
          initPopup();
        });
      } catch (error) {
        console.error('加载数据失败:', error);
        loading.plans = false;
      }
    });
    
    return {
      plans,
      loading,
      currency,
      currencySymbol,
      selectedPriceType,
      selectedFilter,
      filteredPlans,
      getPlanPrices,
      getPriceTypeKey,
      selectPlanPriceType,
      getDisplayPriceType,
      isJsonContent,
      parseJsonContent,
      purchasePlan,
      setFilter,
      sanitizeHtml,
      getPlanMainPrice,
      getLowestPriceInfo,
      saleCountdown,
      formatSpeedLimit,
      formatDeviceLimit,
      SHOP_CONFIG,
      calculateDiscount,
      formatTraffic,
      showPopup,
      popupConfig,
      handlePopupClose,
      filters
    };
  }
};
</script>

<style lang="scss" scoped>
.shop-container {
  .sale-badge { padding: 5px 9px; border-radius: 999px; color: #fff; background: #ef6a55; font-size: 11px; font-weight: 700; }
  .price-display del { margin-left: 8px; color: var(--secondary-text-color); font-size: 13px; }
  .sale-countdown { margin: -2px 0 14px; color: #ef6a55; font-size: 12px; font-weight: 700; }
  .sale-best-price-note { margin: -8px 0 14px; color: var(--secondary-text-color); font-size: 11px; line-height: 1.45; }
  padding: 20px;
  display: flex;
  justify-content: center;
  
  .shop-inner {
    width: 100%;
    max-width: 900px;
  }
  
  // 卡片背景：白天模式白色，暗黑模式深色
  .dashboard-card,
  .plan-card,
  .stats-card,
  .no-plans-message {
    background-color: #ffffff;
    border-radius: 20px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    border: 1px solid var(--card-border);
    transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
  }
  
  // 暗黑模式卡片背景
  .dark & .dashboard-card,
  .dark-theme & .dashboard-card,
  .dark & .plan-card,
  .dark-theme & .plan-card,
  .dark & .stats-card,
  .dark-theme & .stats-card,
  .dark & .no-plans-message,
  .dark-theme & .no-plans-message {
    background-color: #1e293b !important;
  }
  
  .dashboard-card {
    padding: 20px;
    margin-bottom: 24px;
    
    &:hover {
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
      border-color: rgba(var(--theme-color-rgb), 0.3);
    }
  }
  
  /* 骨架屏样式 */
  .skeleton-card {
    width: 100%;
    height: 100%;
    
    .skeleton-header,
    .skeleton-price,
    .skeleton-feature,
    .skeleton-button {
      position: relative;
      overflow: hidden;
      
      &::after {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        bottom: 0;
        left: 0;
        background: linear-gradient(90deg, 
          rgba(255, 255, 255, 0) 0%, 
          rgba(255, 255, 255, 0.15) 50%, 
          rgba(255, 255, 255, 0) 100%);
        transform: translateX(-100%);
        animation: shimmer 2.5s infinite;
      }
    }
    
    .skeleton-header {
      height: 24px;
      background-color: rgba(0, 0, 0, 0.05);
      border-radius: 4px;
      margin-bottom: 20px;
      width: 60%;
    }
    
    .skeleton-body {
      .skeleton-price {
        height: 60px;
        background-color: rgba(0, 0, 0, 0.05);
        border-radius: 12px;
        margin-bottom: 24px;
      }
      
      .skeleton-features {
        margin-bottom: 24px;
        
        .skeleton-feature {
          height: 16px;
          background-color: rgba(0, 0, 0, 0.05);
          border-radius: 4px;
          margin-bottom: 12px;
          
          &:nth-child(1) { width: 90%; }
          &:nth-child(2) { width: 80%; }
          &:nth-child(3) { width: 85%; }
          &:nth-child(4) { width: 75%; }
          &:nth-child(5) { width: 70%; }
        }
      }
      
      .skeleton-button {
        height: 48px;
        background-color: rgba(0, 0, 0, 0.05);
        border-radius: 12px;
      }
    }
  }
  
  @keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
  }
  
  /* 套餐网格 */
  .plans-wrapper {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 24px;
    justify-content: center;
    margin-bottom: 24px;

    > .plan-card,
    > .dashboard-card {
      width: 100%;
      max-width: 450px;
      justify-self: center;
    }

    @media (min-width: 800px) {
      grid-template-columns: repeat(2, minmax(0, 1fr));

      > .plan-card,
      > .dashboard-card {
        max-width: none;
      }

      > .plan-card:only-child {
        max-width: 450px;
        grid-column: 1 / -1;
      }
    }
    
    .plan-card {
      padding: 24px;
      display: flex;
      flex-direction: column;
      height: auto;
      
      &:hover {
        transform: translateY(-5px);
      }
      
      .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        
        .title-with-traffic {
          display: flex;
          justify-content: space-between;
          align-items: center;
          width: 100%;
          
          .card-title {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
            flex: 1;
          }
          
          .traffic-badge {
            background-color: rgba(var(--theme-color-rgb), 0.12);
            color: var(--theme-color);
            padding: 4px 10px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            margin-left: 12px;
          }
        }
      }
      
      .card-body {
        flex: 1;
        display: flex;
        flex-direction: column;
      }

      > .card-header .card-title {
        margin: 0;
        color: var(--heading-color);
        font-size: 18px;
        font-weight: 600;
      }

      .price-display {
        display: flex;
        align-items: baseline;
        min-height: 34px;
        text-align: left;

        .currency { color: var(--theme-color); font-size: 16px; font-weight: 700; }
        .amount { color: var(--theme-color); font-size: 28px; font-weight: 700; line-height: 1; }
        .period { margin-left: 4px; color: var(--secondary-text-color); font-size: 14px; }
      }

      .plan-metrics {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
        margin-top: 16px;
      }

      .plan-metric {
        display: flex;
        min-width: 0;
        padding: 12px 6px;
        align-items: center;
        flex-direction: column;
        gap: 6px;
        color: var(--theme-color);
        background: rgba(var(--theme-color-rgb), 0.07);
        border-radius: 12px;

        strong {
          max-width: 100%;
          overflow: hidden;
          color: var(--text-color);
          font-size: 12px;
          font-weight: 600;
          text-overflow: ellipsis;
          white-space: nowrap;
        }
      }

      .plan-description {
        margin-top: 16px;
        color: var(--secondary-text-color);
        font-size: 14px;
        line-height: 1.6;
        text-align: left;

        .feature-list { display: flex; width: fit-content; max-width: 100%; margin: 0 auto; align-items: stretch; flex-direction: column; }
        .feature-item { display: flex; align-items: center; justify-content: flex-start; gap: 8px; margin-bottom: 8px; text-align: left; }
        .feature-icon { width: 18px; flex: 0 0 auto; color: var(--theme-color); }
        .feature-icon.disabled, .disabled-text { color: var(--secondary-text-color); opacity: .55; }
        .html-content { width: fit-content; max-width: 100%; margin: 0 auto; text-align: left; }
      }
    }
    
    .plan-price {
      margin: 0px 0;
      padding: 0;
      
      .price-display {
        text-align: center;
        margin-bottom: 0px;
        
        .currency {
          font-size: 24px;
          font-weight: 500;
          color: var(--text-color);
        }
        
        .amount {
          font-size: 48px;
          font-weight: 700;
          color: var(--text-color);
        }
        
        .period {
          font-size: 16px;
          color: var(--secondary-text-color);
        }
      }
      
      .supported-periods {
        margin-top: 6px;
        
        .period-labels {
          display: flex;
          justify-content: center;
          flex-wrap: wrap;
          gap: 6px;
          
          .period-tag {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            background-color: rgba(var(--border-color-rgb), 0.1);
            color: var(--secondary-text-color);
            cursor: pointer;
            transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
            border: 1px solid transparent;
            display: flex;
            align-items: center;
            
            .tag-icon {
              margin-right: 4px;
              width: 14px;
              height: 14px;
              
              &.check { color: #4caf50; }
              &.error { color: #f44336; }
            }
            
            &:hover:not(.disabled) {
              background-color: rgba(var(--theme-color-rgb), 0.08);
              color: var(--text-color);
            }
            
            &.active {
              background-color: rgba(var(--theme-color-rgb), 0.1);
              color: var(--text-color);
              border-color: rgba(var(--theme-color-rgb), 0.2);
            }
            
            &.disabled {
              opacity: 0.5;
              cursor: default;
            }
          }
        }
      }
    }
    
    .discount-calculation {
      margin: 5px 0 15px 0;
      padding: 8px 12px;
      background-color: rgba(var(--theme-color-rgb), 0.05);
      border-radius: 12px;
      
      .discount-info {
        font-size: 14px;
        text-align: center;
        color: var(--text-color);
        
        .period-name { font-weight: 700; color: var(--theme-color); }
        .discount-label { font-weight: 500; }
        .discount-value { font-weight: 700; color: var(--theme-color); }
        .saving-text { font-weight: 400; }
        .saving-amount { font-weight: 700; color: var(--theme-color); }
      }
    }
    
    /* 套餐特性：整体居中，内部左对齐 */
    .plan-features {
      margin: 24px 0 10px 0;
      padding: 0 4px;
      display: flex;
      flex-direction: column;
      align-items: center;   // 让内部子元素在水平方向居中
      
      // JSON 格式内容：每个列表项左对齐
      .feature-item {
        display: flex;
        align-items: center;
        // 去掉 justify-content: center，保持左对齐（默认 flex-start）
        margin-bottom: 12px;
        
        .feature-icon {
          width: 20px;
          height: 20px;
          margin-right: 8px;
          
          &.enabled { color: var(--theme-color); }
          &.disabled { color: #ccc; }
        }
        
        span {
          font-size: 14px;
          color: var(--text-color);
          
          &.disabled-text { color: #999; }
        }
      }
      
      // HTML 格式内容：整体居中，但内部文字左对齐
      .html-content {
        font-size: 14px;
        line-height: 1.6;
        color: var(--text-color);
        text-align: left;     // 内部文字左对齐
      }
    }
  }
  
  /* 购买按钮 */
  .btn-purchase {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    margin: 12px 0 0 0;
    padding: 0 20px;
    height: 44px;
    color: white;
    border: none;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
    
    background-color: var(--theme-color);
    box-shadow: 0 4px 12px rgba(var(--theme-color-rgb), 0.25);
    
    &:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 18px rgba(var(--theme-color-rgb), 0.35);
      background-color: rgba(var(--theme-color-rgb), 0.88);
    }
    
    .btn-icon {
      width: 18px;
      height: 18px;
    }
  }
  
  /* 无套餐提示 */
  .no-plans-message {
    grid-column: 1 / -1;
    background-color: #ffffff;
    padding: 40px 20px;
    text-align: center;
    
    .info-icon {
      color: var(--theme-color);
      opacity: 0.7;
      margin-bottom: 16px;
    }
    
    h3 {
      font-size: 18px;
      font-weight: 600;
      margin: 0 0 10px;
      color: var(--text-color);
    }
    
    p {
      color: var(--secondary-text-color);
      margin-bottom: 24px;
    }
    
    .btn-reset-filter {
      padding: 8px 20px;
      background-color: var(--theme-color);
      color: white;
      border: none;
      border-radius: 12px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
      
      &:hover {
        background-color: var(--primary-color-hover);
        transform: translateY(-2px);
      }
    }
  }
  
  /* 暗黑模式骨架屏调整 */
  .dark .skeleton-header,
  .dark-theme .skeleton-header,
  .dark .skeleton-price,
  .dark-theme .skeleton-price,
  .dark .skeleton-feature,
  .dark-theme .skeleton-feature,
  .dark .skeleton-button,
  .dark-theme .skeleton-button {
    background-color: rgba(255, 255, 255, 0.08);
    
    &::after {
      background: linear-gradient(90deg, 
        rgba(255, 255, 255, 0) 0%, 
        rgba(255, 255, 255, 0.05) 50%, 
        rgba(255, 255, 255, 0) 100%);
    }
  }
}

@media (max-width: 768px) {
  .shop-container {
    padding: 15px;
    padding-bottom: 80px;
    
    .plans-wrapper {
      grid-template-columns: 1fr;
    }
  }
}
</style>
