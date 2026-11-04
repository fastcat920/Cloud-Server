<template>
  <div class="order-confirm-container">
    <div class="order-confirm-inner">
      <!-- 用户现有套餐提示 -->
      <div class="alert-card" v-if="showExistingPlanWarning">
        <div class="alert-icon">
          <IconAlertTriangle :size="22" />
        </div>
        <div class="alert-content">
          <h4>{{ $t('order.existing_plan_warning_title') }}</h4>
          <p>{{ $t('order.existing_plan_warning_desc') }}</p>
        </div>
      </div>
      
      <!-- 内容主体 -->
      <div class="content-wrapper">
        <!-- 左侧内容：套餐信息和周期选择 -->
        <div class="left-column">
          <!-- 套餐信息卡片 - 骨架屏 -->
          <div class="plan-card glassmorphism" v-if="loading.plan">
            <div class="skeleton-card">
              <div class="skeleton-header"></div>
              <div class="skeleton-body">
                <div class="skeleton-title"></div>
              </div>
            </div>
          </div>
          
          <!-- 套餐信息卡片 - 实际内容 -->
          <div class="plan-card glassmorphism" v-else-if="plan">
            <div class="card-header">
              <div class="title-with-traffic">
                <h3 class="card-title">{{ plan.name }}</h3>
                <span class="traffic-badge" v-if="plan.transfer_enable">
                  {{ formatTraffic(plan.transfer_enable) }}
                </span>
              </div>
            </div>
          </div>
          
          <!-- 周期选择 -->
          <div class="section-wrapper" v-if="!loading.plan">
            <div class="section-title">
              <span>{{ $t('order.select_period') }}</span>
            </div>
            
            <div class="period-selection">
              <div class="period-cards">
                <button
                  v-for="(price, type) in availablePrices" 
                  :key="type"
                  type="button"
                  class="period-card"
                  :class="{ 'active': selectedPriceType === type }"
                  :aria-pressed="selectedPriceType === type"
                  @click="selectPriceType(type)"
                >
                  <div class="period-card-inner">
                    <div class="period-type">{{ $t(`shop.plan.price_options.${getPriceTypeKey(type)}`) }}</div>
                    <div class="period-price">
                      <span class="currency">{{ currencySymbol }}</span>
                      <span class="amount">{{ (price / 100).toFixed(2) }}</span>
                    </div>
                  </div>
                </button>
              </div>
            </div>
          </div>
          
          <!-- 周期选择骨架屏 -->
          <div class="section-wrapper" v-else>
            <div class="section-title">
              <span>{{ $t('order.select_period') }}</span>
            </div>
            
            <div class="period-selection">
              <div class="skeleton-period-cards">
                <div class="skeleton-period-card" v-for="i in 2" :key="'skeleton-period-'+i"></div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- 右侧内容：订单信息 -->
        <div class="right-column">
          <!-- 账户优惠券 -->
          <div class="section-wrapper">
            <div class="section-title">
              <span>{{ $t(promotionExclusive ? 'order.choose_promotion' : 'order.choose_coupon') }}</span>
            </div>
            <div ref="couponSelectorRef" class="coupon-selector" :class="{ open: couponDropdownOpen }">
              <button
                id="order-coupon-select"
                type="button"
                class="coupon-select-trigger"
                :disabled="loading.coupons || !selectedPriceType"
                :aria-label="$t(promotionExclusive ? 'order.choose_promotion' : 'order.choose_coupon')"
                :aria-expanded="couponDropdownOpen"
                aria-haspopup="listbox"
                aria-controls="order-coupon-options"
                @click="toggleCouponDropdown"
                @keydown.esc="couponDropdownOpen = false"
              >
                <span v-if="loading.coupons" class="coupon-placeholder">{{ $t('order.coupon_loading') }}</span>
                <span v-else-if="promotionExclusive && selectedPromotion" class="coupon-trigger-card promotion-trigger-card">
                  <span class="coupon-trigger-value">{{ promotionSaving(selectedPromotion) }}</span>
                  <span class="coupon-trigger-copy">
                    <strong>{{ promotionName(selectedPromotion) }}</strong>
                    <small>{{ $t('order.promotion_final', { amount: promotionFinal(selectedPromotion) }) }}</small>
                  </span>
                  <span v-if="selectedPromotion.recommended" class="best-coupon-badge">{{ $t('order.best_promotion') }}</span>
                </span>
                <span v-else-if="selectedCoupon" class="coupon-trigger-card">
                  <span class="coupon-trigger-value">{{ couponValue(selectedCoupon) }}</span>
                  <span class="coupon-trigger-copy">
                    <strong>{{ couponName(selectedCoupon) }}</strong>
                    <small>{{ $t('order.coupon_save', { amount: couponSavings(selectedCoupon) }) }}</small>
                  </span>
                  <span v-if="selectedCoupon.id === bestCouponId" class="best-coupon-badge">{{ $t('order.best_coupon') }}</span>
                </span>
                <span v-else class="coupon-placeholder">{{ disableAutoCoupon ? $t('order.no_coupon') : $t('order.no_eligible_coupon') }}</span>
                <span class="coupon-chevron" aria-hidden="true"></span>
              </button>

              <div v-if="couponDropdownOpen" id="order-coupon-options" class="coupon-dropdown" role="listbox" :aria-label="$t(promotionExclusive ? 'order.choose_promotion' : 'order.choose_coupon')">
                <template v-if="promotionExclusive">
                  <p class="promotion-hint">{{ $t('order.promotion_exclusive_hint') }}</p>
                  <button
                    v-for="option in promotionOptions"
                    :key="option.key"
                    type="button"
                    class="coupon-dropdown-option promotion-option"
                    :class="{ selected: selectedPromotion?.key === option.key }"
                    role="option"
                    :aria-selected="selectedPromotion?.key === option.key"
                    @click="selectPromotion(option)"
                  >
                    <span class="coupon-option-value">
                      <strong>{{ promotionSaving(option) }}</strong>
                      <small>{{ $t('order.promotion_saved') }}</small>
                    </span>
                    <span class="coupon-option-content">
                      <span class="coupon-option-heading">
                        <strong>{{ promotionName(option) }}</strong>
                        <em v-if="option.recommended">{{ $t('order.best_promotion') }}</em>
                      </span>
                      <small>{{ promotionDescription(option) }}</small>
                      <span class="coupon-option-saving">{{ $t('order.promotion_final', { amount: promotionFinal(option) }) }}</span>
                    </span>
                    <span class="coupon-option-check" aria-hidden="true">✓</span>
                  </button>
                </template>
                <template v-else>
                <div v-if="!availableCoupons.length" class="coupon-dropdown-empty">{{ $t('order.no_eligible_coupon') }}</div>
                <button
                  v-for="(coupon, index) in availableCoupons"
                  :key="coupon.id"
                  type="button"
                  class="coupon-dropdown-option"
                  :class="{ selected: selectedCouponId === coupon.id && !disableAutoCoupon }"
                  role="option"
                  :aria-selected="selectedCouponId === coupon.id && !disableAutoCoupon"
                  @click="selectCoupon(coupon.id)"
                >
                  <span class="coupon-option-value">
                    <strong>{{ couponValue(coupon) }}</strong>
                  </span>
                  <span class="coupon-option-content">
                    <span class="coupon-option-heading">
                      <strong>{{ couponName(coupon) }}</strong>
                      <em v-if="index === 0">{{ $t('order.best_coupon') }}</em>
                    </span>
                    <small>{{ couponDescription(coupon) || $t('order.coupon_no_description') }}</small>
                    <span class="coupon-option-saving">{{ $t('order.coupon_save', { amount: couponSavings(coupon) }) }}</span>
                  </span>
                  <span class="coupon-option-check" aria-hidden="true">✓</span>
                </button>
                <button
                  type="button"
                  class="coupon-none-option"
                  :class="{ selected: disableAutoCoupon }"
                  role="option"
                  :aria-selected="disableAutoCoupon"
                  @click="selectCoupon('none')"
                >
                  <span>{{ $t('order.no_coupon') }}</span>
                  <span v-if="disableAutoCoupon" aria-hidden="true">✓</span>
                </button>
                </template>
              </div>
            </div>
          </div>

          <!-- 订单摘要 -->
          <div class="section-wrapper order-summary-section">
            <div class="section-title">
              <span>{{ $t('order.order_summary') }}</span>
            </div>
            
            <div class="order-summary glassmorphism">
              <!-- 骨架屏 -->
              <div v-if="loading.plan">
                <div class="summary-row skeleton">
                  <div class="summary-label skeleton-text"></div>
                  <div class="summary-value skeleton-text"></div>
                </div>
                <div class="summary-divider"></div>
                <div class="summary-row skeleton total">
                  <div class="summary-label skeleton-text"></div>
                  <div class="summary-value skeleton-text"></div>
                </div>
              </div>
              
              <!-- 实际内容 -->
              <div v-else>
                <div class="summary-row">
                  <div class="summary-label">{{ $t('order.plan_amount') }}</div>
                  <div class="summary-value">{{ currencySymbol }}{{ (originalPrice / 100).toFixed(2) }}</div>
                </div>

                <div class="summary-row" v-if="activityDiscount > 0">
                  <div class="summary-label">{{ $t('order.activity_discount') }}</div>
                  <div class="summary-value discount">-{{ currencySymbol }}{{ (activityDiscount / 100).toFixed(2) }}</div>
                </div>
                <div class="summary-row" v-if="surplusAmount > 0">
                  <div class="summary-label">{{ $t('order.trade_off_amount') }}</div>
                  <div class="summary-value discount">-{{ currencySymbol }}{{ (surplusAmount / 100).toFixed(2) }}</div>
                </div>
                <div class="summary-row" v-if="discountAmount > 0">
                  <div class="summary-label">{{ $t('order.discount') }} 
                    <span v-if="couponInfo" class="coupon-name">({{ couponName(couponInfo) }})</span>
                  </div>
                  <div class="summary-value discount">-{{ currencySymbol }}{{ (discountAmount / 100).toFixed(2) }}</div>
                </div>
                <div class="summary-row" v-if="memberDiscount > 0">
                  <div class="summary-label">{{ $t('order.member_discount', { rate: memberDiscountRate }) }}</div>
                  <div class="summary-value discount">-{{ currencySymbol }}{{ (memberDiscount / 100).toFixed(2) }}</div>
                </div>
                <div class="summary-row" v-if="refundAmount > 0">
                  <div class="summary-label">{{ $t('order.refund_amount') }}</div>
                  <div class="summary-value refund">+{{ currencySymbol }}{{ (refundAmount / 100).toFixed(2) }}</div>
                </div>

                <div class="summary-row" v-if="balanceAmount > 0">
                  <div class="summary-label">{{ $t('order.balance_amount') }}</div>
                  <div class="summary-value discount">-{{ currencySymbol }}{{ (balanceAmount / 100).toFixed(2) }}</div>
                </div>

                <div class="summary-divider"></div>

                <div class="summary-row total">
                  <div class="summary-label">{{ $t('order.payable_amount') }}</div>
                  <div class="summary-value">{{ currencySymbol }}{{ (payableAmount / 100).toFixed(2) }}</div>
                </div>
              </div>
            </div>
          </div>
          
          <!-- 操作按钮 -->
          <div class="action-buttons">
            <button 
              class="btn-back" 
              @click="goBack"
              :disabled="loading.plan"
            >
              <IconArrowLeft :size="18" />
              <span>{{ $t('order.back_to_shop') }}</span>
            </button>
            
            <button 
              class="btn-order" 
              @click="submitOrder"
              :disabled="!selectedPriceType || loading.submitting || loading.plan"
            >
              <IconShoppingCart v-if="!loading.submitting" :size="18" />
              <span v-else class="loader"></span>
              <span>{{ $t('order.place_order') }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, reactive, onMounted, onBeforeUnmount, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useToast } from '@/composables/useToast';
import { useRoute, useRouter } from 'vue-router';
import { getCommConfig, fetchPlanById, previewOrder } from '@/api/shop';
import { createOrderAfterUnpaidCleanup } from '@/utils/orderCleanup';
import { getUserInfo } from '@/api/dashboard';
import { formatCouponValue } from '@/utils/coupon';
import {
  IconShoppingCart,
  IconArrowLeft,
  IconAlertTriangle
} from '@tabler/icons-vue';

export default {
  name: 'OrderConfirm',
  components: {
    IconShoppingCart,
    IconArrowLeft,
    IconAlertTriangle
  },
  setup() {
    const { t, locale } = useI18n();
    const { showToast } = useToast();
    const route = useRoute();
    const router = useRouter();
    
    // 加载状态
    const loading = reactive({
      plan: true,
      userInfo: true,
      submitting: false,
      coupons: false
    });
    
    // 套餐和用户数据
    const plan = ref(null);
    const userInfo = ref(null);
    const currency = ref('CNY');
    const currencySymbol = ref('¥');
    
    // 选中的价格类型
    const selectedPriceType = ref('');
    
    // 账户优惠券由后端统一校验、推荐和计算
    const availableCoupons = ref([]);
    const selectedCouponId = ref(null);
    const disableAutoCoupon = ref(false);
    const couponInfo = ref(null);
    const promotionExclusive = ref(false);
    const promotionOptions = ref([]);
    const selectedPromotion = ref(null);
    const promotionMode = ref('auto');
    const couponDropdownOpen = ref(false);
    const couponSelectorRef = ref(null);
    const activityDiscount = ref(0);
    const surplusAmount = ref(0);
    const refundAmount = ref(0);
    const memberDiscount = ref(0);
    const memberDiscountRate = ref(0);
    const previewOrderAmount = ref(null);
    const balanceAmount = ref(0);
    const previewPayableAmount = ref(null);
    
    // 计算原始价格
    const originalPrice = computed(() => {
      if (!plan.value || !selectedPriceType.value) return 0;
      return plan.value[selectedPriceType.value] || 0;
    });
    
    // 计算折扣金额
    const discountAmount = computed(() => {
      return Number(couponInfo.value?.calculated_discount || 0);
    });
    
    const orderAmount = computed(() => previewOrderAmount.value === null
      ? Math.max(0, originalPrice.value - surplusAmount.value + refundAmount.value - activityDiscount.value - discountAmount.value - memberDiscount.value)
      : previewOrderAmount.value);
    const payableAmount = computed(() => previewPayableAmount.value === null
      ? Math.max(0, orderAmount.value - balanceAmount.value)
      : previewPayableAmount.value);
    
    // 获取用户是否有有效套餐
    const userHasActivePlan = computed(() => {
      if (!userInfo.value) return false;
      return userInfo.value.plan_id && userInfo.value.expired_at && userInfo.value.expired_at * 1000 > Date.now();
    });
    
    // 获取可用的价格（过滤null值）
    const availablePrices = computed(() => {
      if (!plan.value) return {};
      
      const prices = {};
      const priceTypes = ['month_price', 'quarter_price', 'half_year_price', 'year_price', 'two_year_price', 'three_year_price', 'onetime_price'];
      
      priceTypes.forEach(type => {
        if (plan.value[type] !== null) {
          prices[type] = plan.value[type];
        }
      });
      
      return prices;
    });
    
    // 计算最佳性价比周期
    const bestValuePeriod = computed(() => {
      if (!plan.value) return '';
      
      // 周期价值权重（月为基准）
      const valueWeight = {
        month_price: 1,
        quarter_price: 3,
        half_year_price: 6,
        year_price: 12,
        two_year_price: 24,
        three_year_price: 36,
        onetime_price: 12 // 假设一次性计划相当于一年
      };
      
      let bestPeriod = '';
      let bestValue = 0;
      
      Object.entries(availablePrices.value).forEach(([type, price]) => {
        if (price <= 0) return;
        
        // 计算月均价值
        const monthlyValue = valueWeight[type] / price;
        
        if (monthlyValue > bestValue) {
          bestValue = monthlyValue;
          bestPeriod = type;
        }
      });
      
      return bestPeriod;
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
    
    // 格式化流量（GB）
    const formatTraffic = (gb) => {
      if (!gb) return '-';
      return `${gb} GB`;
    };
    
    // 选择价格类型
    const selectPriceType = (type) => {
      selectedPriceType.value = type;
      selectedCouponId.value = null;
      disableAutoCoupon.value = false;
      promotionMode.value = 'auto';
      selectedPromotion.value = null;
      refreshPreview();
    };
    
    const couponName = coupon => {
      const template = coupon?.template || coupon;
      return (locale.value === 'en-US' ? template?.name_en : template?.name) || template?.name || template?.name_en || '';
    };

    const selectedCoupon = computed(() => {
      if (disableAutoCoupon.value || !selectedCouponId.value) return null;
      return availableCoupons.value.find(coupon => Number(coupon.id) === Number(selectedCouponId.value)) || couponInfo.value;
    });
    const bestCouponId = computed(() => availableCoupons.value[0]?.id || null);
    const couponTemplate = coupon => coupon?.template || coupon || {};
    const couponValue = coupon => formatCouponValue(couponTemplate(coupon), {
      currency: currencySymbol.value,
      percentSuffix: locale.value === 'en-US' ? '% OFF' : '%'
    });
    const couponDescription = coupon => {
      const template = couponTemplate(coupon);
      return (locale.value === 'en-US' ? template.description_en : template.description) || template.description || template.description_en || '';
    };
    const couponSavings = coupon => `${currencySymbol.value}${(Number(coupon?.calculated_discount || 0) / 100).toFixed(2)}`;
    const localizedPromotionField = (promotion, field) => (
      locale.value === 'en-US' ? promotion?.[`${field}_en`] : promotion?.[field]
    ) || promotion?.[field] || promotion?.[`${field}_en`] || '';
    const promotionName = promotion => {
      if (promotion?.type === 'standard') {
        return Number(promotion.member_discount || 0) > 0
          ? t('order.member_promotion')
          : t('order.regular_promotion');
      }
      return localizedPromotionField(promotion, 'name') || t('order.regular_promotion');
    };
    const promotionDescription = promotion => {
      if (promotion?.type === 'standard') {
        return Number(promotion.member_discount || 0) > 0
          ? t('order.member_promotion_desc', { rate: memberDiscountRate.value })
          : t('order.regular_promotion_desc');
      }
      if (promotion?.type === 'coupon') return localizedPromotionField(promotion, 'description') || t('order.coupon_no_description');
      return localizedPromotionField(promotion, 'description') || t('order.flash_promotion_desc');
    };
    const promotionSaving = promotion => {
      const amount = Number(promotion?.discount_amount || 0);
      return amount > 0 ? `-${currencySymbol.value}${(amount / 100).toFixed(2)}` : t('order.no_discount');
    };
    const promotionFinal = promotion => `${currencySymbol.value}${(Number(promotion?.final_amount || 0) / 100).toFixed(2)}`;
    const toggleCouponDropdown = () => {
      if (!loading.coupons && selectedPriceType.value) couponDropdownOpen.value = !couponDropdownOpen.value;
    };
    const selectCoupon = value => {
      disableAutoCoupon.value = value === 'none';
      selectedCouponId.value = disableAutoCoupon.value ? null : Number(value);
      promotionMode.value = disableAutoCoupon.value ? 'auto' : 'coupon';
      couponDropdownOpen.value = false;
      refreshPreview();
    };
    const selectPromotion = option => {
      promotionMode.value = option.type;
      selectedCouponId.value = option.type === 'coupon' ? Number(option.coupon_id) : null;
      disableAutoCoupon.value = false;
      couponDropdownOpen.value = false;
      refreshPreview();
    };
    const closeCouponDropdown = event => {
      if (couponSelectorRef.value && !couponSelectorRef.value.contains(event.target)) couponDropdownOpen.value = false;
    };

    const refreshPreview = async () => {
      if (!plan.value?.id || !selectedPriceType.value) return;
      loading.coupons = true;
      try {
        const response = await previewOrder({
          plan_id: Number(plan.value.id),
          period: selectedPriceType.value,
          user_coupon_id: disableAutoCoupon.value ? null : selectedCouponId.value,
          disable_auto_coupon: disableAutoCoupon.value,
          promotion_mode: promotionMode.value
        });
        const data = response.data || {};
        availableCoupons.value = data.available_coupons || [];
        couponInfo.value = data.selected_coupon || null;
        selectedCouponId.value = couponInfo.value?.id || null;
        promotionExclusive.value = Boolean(data.promotion_exclusive);
        promotionOptions.value = Array.isArray(data.promotion_options) ? data.promotion_options : [];
        selectedPromotion.value = data.selected_promotion || null;
        activityDiscount.value = Number(data.activity_discount || 0);
        surplusAmount.value = Number(data.surplus_amount || 0);
        refundAmount.value = Number(data.refund_amount || 0);
        memberDiscount.value = Number(data.member_discount ?? data.vip_discount ?? 0);
        memberDiscountRate.value = Number(data.member_discount_rate || 0);
        previewOrderAmount.value = Number(data.final_amount ?? originalPrice.value);
        balanceAmount.value = Number(data.balance_amount || 0);
        previewPayableAmount.value = Number(data.payable_amount ?? previewOrderAmount.value);
      } catch (error) {
        showToast(error.response?.message || error.message, 'error');
      } finally {
        loading.coupons = false;
      }
    };

    // 提交订单
    const submitOrder = async () => {
      if (!selectedPriceType.value || loading.submitting) return;
      
      loading.submitting = true;
      
      try {
        const orderData = {
          plan_id: Number(plan.value.id),
          period: selectedPriceType.value
        };
        
        orderData.user_coupon_id = disableAutoCoupon.value ? null : selectedCouponId.value;
        orderData.disable_auto_coupon = disableAutoCoupon.value;
        orderData.promotion_mode = promotionMode.value;

        const response = await createOrderAfterUnpaidCleanup(orderData);
        
        if (response.data) {
          showToast(response.message || t('order.order_success'), 'success');
          router.push({
            path: '/payment',
            query: {
              trade_no: response.data
            }
          });
        } else {
          showToast(response.message || t('order.order_failed'), 'error');
        }
      } catch (error) {
        console.error('提交订单失败:', error);
        showToast(error.response?.message || error.message || t('order.order_failed'), 'error');
      } finally {
        loading.submitting = false;
      }
    };
    
    // 返回商店
    const goBack = () => {
      router.push('/shop');
    };
    
    // 获取套餐数据
    const fetchPlanData = async () => {
      loading.plan = true;
      try {
        if (!route.params.id) {
          showToast(t('order.no_plan_selected'), 'error');
          router.push('/shop');
          return;
        }
        
        const response = await fetchPlanById(route.params.id);
        if (response.data) {
          plan.value = response.data;
          
          if (route.query.period && plan.value[route.query.period] !== null) {
            selectedPriceType.value = route.query.period;
          } else {
            const firstValidPriceType = Object.keys(availablePrices.value)[0];
            selectedPriceType.value = firstValidPriceType || '';
          }
          await refreshPreview();
        } else {
          showToast(response.message || t('order.plan_not_found'), 'error');
          router.push('/shop');
        }
      } catch (error) {
        console.error('获取套餐数据失败:', error);
        showToast(error.response?.message || error.message || t('order.failed_to_fetch_plan'), 'error');
      } finally {
        loading.plan = false;
      }
    };
    
    // 获取用户信息
    const fetchUserInfo = async () => {
      loading.userInfo = true;
      try {
        const response = await getUserInfo();
        if (response.data) {
          userInfo.value = response.data;
        } else if (response.message) {
          showToast(response.message, 'warning');
        }
      } catch (error) {
        console.error('获取用户信息失败:', error);
        showToast(error.response?.message || error.message || t('nodes.userInfoError'), 'error');
      } finally {
        loading.userInfo = false;
      }
    };
    
    // 获取系统配置
    const fetchConfig = async () => {
      try {
        const response = await getCommConfig();
        if (response.data) {
          currency.value = response.data.currency || 'CNY';
          currencySymbol.value = response.data.currency_symbol || '¥';
        } else if (response.message) {
          showToast(response.message, 'warning');
        }
      } catch (error) {
        console.error('获取系统配置失败:', error);
        showToast(error.response?.message || error.message || t('shop.config_error'), 'error');
      }
    };
    
    // 计算是否应该显示已有套餐警告
    const showExistingPlanWarning = computed(() => {
      if (loading.userInfo || loading.plan || !plan.value || !userInfo.value) {
        return false;
      }
      return userHasActivePlan.value && plan.value.id !== userInfo.value.plan_id;
    });
    
    // 页面加载时获取数据
    onMounted(async () => {
      document.addEventListener('click', closeCouponDropdown);
      await Promise.all([fetchPlanData(), fetchUserInfo(), fetchConfig()]);
    });

    onBeforeUnmount(() => document.removeEventListener('click', closeCouponDropdown));

    watch(locale, () => {
      fetchPlanData();
    });
    
    return {
      plan,
      userInfo,
      loading,
      currency,
      currencySymbol,
      selectedPriceType,
      availableCoupons,
      selectedCouponId,
      disableAutoCoupon,
      couponInfo,
      promotionExclusive,
      promotionOptions,
      selectedPromotion,
      couponDropdownOpen,
      couponSelectorRef,
      selectedCoupon,
      bestCouponId,
      locale,
      originalPrice,
      discountAmount,
      activityDiscount,
      surplusAmount,
      refundAmount,
      memberDiscount,
      memberDiscountRate,
      orderAmount,
      balanceAmount,
      payableAmount,
      userHasActivePlan,
      availablePrices,
      bestValuePeriod,
      getPriceTypeKey,
      selectPriceType,
      refreshPreview,
      couponName,
      couponValue,
      couponDescription,
      couponSavings,
      promotionName,
      promotionDescription,
      promotionSaving,
      promotionFinal,
      toggleCouponDropdown,
      selectCoupon,
      selectPromotion,
      submitOrder,
      goBack,
      showExistingPlanWarning,
      formatTraffic
    };
  }
};
</script>

<style lang="scss" scoped>
.order-confirm-container {
  padding: 20px;
  display: flex;
  justify-content: center;
  min-height: calc(100vh - 100px);

  .coupon-selector { position: relative; z-index: 12; }
  .coupon-select-trigger { position: relative; width: 100%; min-height: 72px; padding: 7px 44px 7px 7px; color: var(--text-color); text-align: left; background: var(--input-background); border: 1px solid var(--card-border); border-radius: 15px; outline: none; cursor: pointer; transition: border-color .2s ease, box-shadow .2s ease, background-color .2s ease; }
  .coupon-select-trigger:hover:not(:disabled), .coupon-selector.open .coupon-select-trigger { border-color: var(--theme-color); box-shadow: 0 0 0 3px rgba(var(--theme-color-rgb), .1); }
  .coupon-select-trigger:focus-visible { border-color: var(--theme-color); box-shadow: 0 0 0 3px rgba(var(--theme-color-rgb), .14); }
  .coupon-select-trigger:disabled { color: var(--secondary-text-color); cursor: wait; opacity: .72; }
  .coupon-placeholder { display: flex; align-items: center; min-height: 56px; padding-left: 10px; color: var(--secondary-text-color); }
  .coupon-chevron { position: absolute; top: 50%; right: 17px; width: 8px; height: 8px; border-right: 2px solid var(--secondary-text-color); border-bottom: 2px solid var(--secondary-text-color); transform: translateY(-70%) rotate(45deg); transition: transform .2s ease; }
  .coupon-selector.open .coupon-chevron { transform: translateY(-25%) rotate(225deg); }
  .coupon-trigger-card { display: grid; grid-template-columns: 78px minmax(0, 1fr) auto; align-items: center; gap: 12px; min-width: 0; }
  .coupon-trigger-value { align-self: stretch; display: flex; align-items: center; justify-content: center; padding: 8px; color: #fff; font-size: 16px; font-weight: 800; text-align: center; background: linear-gradient(145deg, var(--theme-color), rgba(var(--theme-color-rgb), .72)); border-radius: 10px; }
  .coupon-trigger-copy { display: flex; flex-direction: column; min-width: 0; gap: 5px; }
  .coupon-trigger-copy strong { overflow: hidden; font-size: 14px; text-overflow: ellipsis; white-space: nowrap; }
  .coupon-trigger-copy small { overflow: hidden; color: var(--secondary-text-color); font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
  .best-coupon-badge { padding: 4px 7px; color: var(--theme-color); font-size: 10px; font-weight: 700; white-space: nowrap; background: rgba(var(--theme-color-rgb), .1); border-radius: 999px; }
  .coupon-dropdown { position: absolute; top: calc(100% + 8px); right: 0; left: 0; z-index: 30; display: grid; gap: 9px; max-height: 430px; padding: 10px; overflow-y: auto; background: var(--card-background); border: 1px solid var(--card-border); border-radius: 17px; box-shadow: 0 18px 46px rgba(28, 42, 72, .18); }
  .promotion-hint { margin: 0; padding: 8px 10px; color: var(--secondary-text-color); font-size: 11px; line-height: 1.5; background: rgba(var(--theme-color-rgb), .06); border-radius: 10px; }
  .coupon-dropdown-option { position: relative; display: grid; grid-template-columns: 100px minmax(0, 1fr) 20px; align-items: stretch; min-height: 94px; padding: 0; overflow: hidden; color: var(--text-color); text-align: left; background: var(--card-background); border: 1px solid var(--card-border); border-radius: 14px; cursor: pointer; transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease; }
  .coupon-dropdown-option:hover, .coupon-dropdown-option.selected { border-color: var(--theme-color); box-shadow: 0 5px 16px rgba(var(--theme-color-rgb), .12); }
  .coupon-dropdown-option:hover { transform: translateY(-1px); }
  .coupon-option-value { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; padding: 13px 9px; color: #fff; text-align: center; background: linear-gradient(145deg, var(--theme-color), rgba(var(--theme-color-rgb), .72)); }
  .coupon-option-value strong { font-size: 18px; line-height: 1.15; }
  .coupon-option-value small { font-size: 10px; line-height: 1.25; }
  .coupon-option-content { display: flex; flex-direction: column; justify-content: center; min-width: 0; gap: 5px; padding: 12px; }
  .coupon-option-heading { display: flex; align-items: center; gap: 7px; min-width: 0; }
  .coupon-option-heading strong { overflow: hidden; font-size: 14px; text-overflow: ellipsis; white-space: nowrap; }
  .coupon-option-heading em { flex: none; padding: 3px 6px; color: var(--theme-color); font-size: 9px; font-style: normal; font-weight: 700; background: rgba(var(--theme-color-rgb), .1); border-radius: 999px; }
  .coupon-option-content > small { overflow: hidden; color: var(--secondary-text-color); font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
  .coupon-option-saving { color: var(--theme-color); font-size: 12px; font-weight: 700; }
  .coupon-option-check { align-self: center; color: var(--theme-color); font-weight: 800; opacity: 0; }
  .coupon-dropdown-option.selected .coupon-option-check { opacity: 1; }
  .promotion-option .coupon-option-value small { opacity: .82; }
  .coupon-none-option { display: flex; align-items: center; justify-content: space-between; min-height: 44px; padding: 0 14px; color: var(--secondary-text-color); background: var(--input-background); border: 1px solid var(--card-border); border-radius: 12px; cursor: pointer; }
  .coupon-none-option:hover, .coupon-none-option.selected { color: var(--theme-color); border-color: var(--theme-color); }
  .coupon-dropdown-empty { padding: 16px; color: var(--secondary-text-color); text-align: center; }
  
  .order-confirm-inner {
    width: 100%;
    max-width: 900px;
    padding-bottom: 100px;
  }
  
  /* 警告卡片样式 */
  .alert-card {
    background-color: rgba(255, 152, 0, 0.08);
    border: 1px solid rgba(255, 152, 0, 0.2);
    border-radius: 20px;
    padding: 16px;
    margin-bottom: 30px;
    display: flex;
    align-items: center;
    width: 100%;
    box-shadow: 0 4px 15px rgba(255, 152, 0, 0.1);
    backdrop-filter: blur(10px);
    transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
    
    &:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(255, 152, 0, 0.15);
    }
    
    .alert-icon {
      margin-right: 14px;
      color: #ff9800;
      flex-shrink: 0;
      background-color: rgba(255, 152, 0, 0.1);
      width: 44px;
      height: 44px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      
      svg {
        width: 28px;
        height: 28px;
      }
    }
    
    .alert-content {
      flex: 1;
      
      h4 {
        font-size: 15px;
        font-weight: 600;
        margin: 0 0 6px 0;
        color: #ff9800;
      }
      
      p {
        font-size: 14px;
        margin: 0;
        color: var(--secondary-text-color);
        line-height: 1.5;
      }
    }
  }
  
  /* 内容主体 */
  .content-wrapper {
    display: flex;
    gap: 30px;
    
    .left-column, .right-column {
      flex: 1;
      min-width: 0;
    }
  }
  
  /* 标题样式 */
  .section-wrapper {
    margin-bottom: 25px;
    
    .section-title {
      font-size: 18px;
      font-weight: 600;
      margin-bottom: 15px;
      color: var(--text-color);
      position: relative;
      padding-left: 14px;
      
      &::before {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 4px;
        height: 18px;
        background-color: var(--theme-color);
        border-radius: 2px;
      }
    }
  }
  
  /* 套餐卡片样式 */
  .plan-card {
    background-color: #ffffff;
    border-radius: 20px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    padding: 20px; // 减小内边距优化视觉效果
    margin-bottom: 25px;
    border: 1px solid var(--card-border);
    transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
    
    &.glassmorphism {
      background-color: rgba(255, 255, 255, 0.7);
      backdrop-filter: blur(20px);
    }
    
    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0;
      
      .title-with-traffic {
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
        
        .card-title {
          font-size: 20px;
          font-weight: 600;
          margin: 0;
          letter-spacing: 0.3px;
          color: var(--text-color);
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
  }
  
  /* 周期选择样式 */
  .period-selection {
    margin-bottom: 20px;
    width: 100%;
    
    .skeleton-period-cards {
      display: flex;
      gap: 16px;
      
      .skeleton-period-card {
        flex: 1;
        height: 80px; // 骨架屏高度匹配紧凑设计
        background-color: rgba(0, 0, 0, 0.05);
        border-radius: 12px;
        position: relative;
        overflow: hidden;
        
        &::after {
          content: '';
          position: absolute;
          top: 0;
          right: 0;
          bottom: 0;
          left: 0;
          width: 30%;
          background: linear-gradient(90deg, 
            rgba(255, 255, 255, 0) 0%, 
            rgba(255, 255, 255, 0.15) 50%, 
            rgba(255, 255, 255, 0) 100%);
          transform: translateX(-100%);
          animation: shimmer 2s infinite;
        }
      }
    }
    
    .period-cards {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 15px;
      width: 100%;
      
      .period-card {
        cursor: pointer;
        border-radius: 20px;
        overflow: hidden;
        border: 2px solid var(--border-color);
        transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
        
        &.active {
          border-color: var(--theme-color);
          transform: translateY(-3px);
          box-shadow: 0 5px 15px rgba(var(--theme-color-rgb), 0.15);
          
          .period-card-inner {
            background-color: rgba(var(--theme-color-rgb), 0.1);
          }
          
          .period-price .currency,
          .period-price .amount {
            color: var(--theme-color);
          }
        }
        
        &:hover:not(.active) {
          transform: translateY(-3px);
          border-color: rgba(var(--theme-color-rgb), 0.3);
        }
        
        .period-card-inner {
          background-color: #ffffff;
          padding: 10px 6px !important;        // 进一步减小内边距
          min-height: 68px !important;         // 降低高度
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          transition: background-color 0.3s ease;
        }
        
        .period-type {
          font-size: 13px !important;          // 稍小字体
          font-weight: 600;
          margin-bottom: 4px !important;        // 减小间距
          color: var(--text-color);
          text-align: center;
        }
        
        .period-price {
          text-align: center;
          
          .currency {
            font-size: 12px !important;
            font-weight: 500;
            color: var(--text-color);
          }
          
          .amount {
            font-size: 18px !important;         // 价格字体稍小
            font-weight: 700;
            color: var(--text-color);
          }
        }
      }
    }
  }
  
  /* 优惠码输入 */
  .coupon-input {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
    
    .coupon-field {
      flex: 1;
      height: 48px;
      padding: 0 18px;
      border-radius: 10px;
      border: 1px solid var(--card-border);
      background-color: var(--input-bg-color);
      color: var(--text-color);
      font-size: 14px;
      outline: none;
      transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
      min-width: 0;
      
      &.applied {
        border-color: #4caf50;
        background-color: rgba(76, 175, 80, 0.05);
      }
      
      &:focus:not(.applied) {
        border-color: rgba(var(--theme-color-rgb), 0.5);
        box-shadow: 0 0 0 3px rgba(var(--theme-color-rgb), 0.2);
        transform: translateY(-1px);
      }
    }
    
    .btn-verify {
      height: 48px;
      padding: 0 24px;
      border-radius: 10px;
      background-color: var(--theme-color);
      color: white;
      font-size: 14px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 8px;
      border: none;
      cursor: pointer;
      transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
      box-shadow: 0 4px 10px rgba(var(--theme-color-rgb), 0.2);
      white-space: nowrap;
      flex-shrink: 0;
      
      &.applied {
        background-color: #4caf50;
        box-shadow: 0 4px 10px rgba(76, 175, 80, 0.2);
        cursor: default;
      }
      
      &:hover:not(:disabled):not(.applied) {
        background-color: color-mix(in srgb, var(--theme-color) 85%, black) !important;
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(var(--theme-color-rgb), 0.3);
      }
      
      &:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }
      
      .loader {
        width: 16px;
        height: 16px;
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-radius: 50%;
        border-top-color: white;
        animation: spin 1s linear infinite;
      }
    }
    
    .btn-remove-coupon {
      height: 48px;
      padding: 0 16px;
      border-radius: 10px;
      background-color: #f44336;
      color: white;
      font-size: 14px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 6px;
      border: none;
      cursor: pointer;
      transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
      box-shadow: 0 4px 10px rgba(244, 67, 54, 0.2);
      white-space: nowrap;
      flex-shrink: 0;
      
      &:hover {
        background-color: #d32f2f;
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(244, 67, 54, 0.3);
      }
    }
  }
  
  /* 订单摘要 */
  .order-summary {
    background-color: #ffffff;
    border-radius: 20px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    padding: 20px; // 减小内边距
    margin-bottom: 20px;
    border: 1px solid var(--card-border);
    
    &.glassmorphism {
      background-color: rgba(255, 255, 255, 0.7);
      backdrop-filter: blur(20px);
    }
    
    .summary-row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 10px;
      align-items: center;
      
      .summary-label {
        font-size: 14px;
        color: var(--secondary-text-color);
        
        .coupon-name {
          font-size: 12px;
          opacity: 0.8;
          font-style: italic;
        }
      }
      
      .summary-value {
        font-size: 14px;
        font-weight: 500;
        color: var(--text-color);
        
        &.discount {
          color: #f44336;
          font-weight: 600;
        }

        &.refund {
          color: var(--success-color);
          font-weight: 600;
        }
      }
      
      &.total {
        margin-top: 8px;
        
        .summary-label {
          font-size: 16px;
          font-weight: 600;
          color: var(--text-color);
        }
        
        .summary-value {
          font-size: 22px;
          font-weight: 700;
          color: var(--theme-color);
        }
      }
    }
    
    .summary-divider {
      height: 1px;
      background-color: var(--border-color);
      margin: 12px 0;
    }
  }
  
  /* 操作按钮 */
  .action-buttons {
    display: flex;
    justify-content: space-between;
    margin: 30px 0 40px 0;
    gap: 16px;
    
    .btn-back {
      height: 44px;
      padding: 0 20px;
      border-radius: 10px;
      background-color: transparent;
      color: var(--text-color);
      font-size: 14px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 8px;
      border: 1px solid var(--card-border);
      cursor: pointer;
      transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
      
      &:hover {
        background-color: rgba(0, 0, 0, 0.05);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
      }
    }
    
    .btn-order {
      height: 44px;
      padding: 0 24px;
      border-radius: 10px;
      background-color: var(--theme-color);
      color: white;
      font-size: 14px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 8px;
      border: none;
      cursor: pointer;
      transition: background-color var(--motion-base) cubic-bezier(0.4, 0, 0.2, 1),
        box-shadow var(--motion-base) cubic-bezier(0.4, 0, 0.2, 1),
        transform var(--motion-base) cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 0 4px 12px rgba(var(--theme-color-rgb), 0.2);
      
      &:hover:not(:disabled) {
        background-color: color-mix(in srgb, var(--theme-color) 85%, black) !important;
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(var(--theme-color-rgb), 0.3);
      }
      
      &:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }
      
      .loader {
        width: 16px;
        height: 16px;
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-radius: 50%;
        border-top-color: white;
        animation: spin 1s linear infinite;
      }
    }
  }
}

/* 骨架屏样式 */
.skeleton-card {
  width: 100%;
  height: 100%;
  position: relative;
  overflow: hidden;
  border-radius: 10px;
  
  .skeleton-header {
    height: 24px;
    width: 60%;
    background-color: rgba(0, 0, 0, 0.05);
    border-radius: 6px;
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
  }
  
  .skeleton-body {
    .skeleton-title {
      height: 40px;
      width: 100%;
      background-color: rgba(0, 0, 0, 0.05);
      border-radius: 12px;
      position: relative;
      overflow: hidden;
    }
  }
  
  &::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    left: 0;
    width: 30%;
    background: linear-gradient(90deg, 
      rgba(255, 255, 255, 0) 0%, 
      rgba(255, 255, 255, 0.15) 50%, 
      rgba(255, 255, 255, 0) 100%);
    transform: translateX(-100%);
    animation: shimmer 2s infinite;
    pointer-events: none;
  }
}

.skeleton-text {
  height: 16px;
  background-color: rgba(0, 0, 0, 0.05);
  border-radius: 6px;
  position: relative;
  overflow: hidden;
  width: 100px;
  
  &:first-child {
    width: 70%;
  }
}

@keyframes shimmer {
  0% { transform: translateX(-100%); }
  100% { transform: translateX(300%); }
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* 响应式 */
@media (max-width: 768px) {
  .order-confirm-container {
    margin-top: 15px;
    
    .content-wrapper {
      flex-direction: column;
      gap: 20px;
    }
    
    .action-buttons {
      margin: 24px 0 30px 0;
      flex-direction: row;
      gap: 12px;
      
      .btn-back, .btn-order {
        flex: 1;
        justify-content: center;
        font-size: 13px;
      }
    }
    
    .period-selection .period-cards {
      grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      gap: 12px !important;
    }
  }
}

@media (max-width: 480px) {
  .order-confirm-container {
    .section-title {
      font-size: 16px;
    }

    .coupon-trigger-card { grid-template-columns: 68px minmax(0, 1fr); }
    .best-coupon-badge { display: none; }
    .coupon-dropdown-option { grid-template-columns: 84px minmax(0, 1fr) 16px; }
    .coupon-option-value strong { font-size: 16px; }
    .coupon-option-content { padding: 10px; }
    
    .coupon-input {
      flex-direction: row;
      flex-wrap: wrap;
      gap: 8px;
      
      .coupon-field {
        min-width: 120px;
      }
      
      .btn-verify, .btn-remove-coupon {
        padding: 0 15px;
        white-space: nowrap;
      }
      
      &:has(.coupon-field.applied) {
        .coupon-field {
          width: 100%;
          flex: none;
          margin-bottom: 8px;
        }
        
        .btn-verify, .btn-remove-coupon {
          flex: 1;
          justify-content: center;
        }
      }
    }
    
    .plan-card, .order-summary {
      padding: 18px;
    }
    
    .period-selection .period-cards {
      grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      gap: 10px !important;
    }
  }
}

/* 强制周期卡片两列布局（移动端） */
@media screen and (max-width: 768px) {
  .period-cards {
    display: grid !important;
    grid-template-columns: repeat(2, 1fr) !important;
    gap: 12px !important;
  }
}
</style>

<!-- 全局暗黑模式样式（不 scoped，保证覆盖） -->
<style lang="scss">
/* 强制暗黑模式下卡片背景为 #1e293b */
.dark .plan-card,
.dark-theme .plan-card,
.dark .order-summary,
.dark-theme .order-summary,
.dark .period-card .period-card-inner,
.dark-theme .period-card .period-card-inner {
  background-color: #1e293b !important;
}

/* 玻璃态背景也覆盖 */
.dark .plan-card.glassmorphism,
.dark-theme .plan-card.glassmorphism,
.dark .order-summary.glassmorphism,
.dark-theme .order-summary.glassmorphism {
  background-color: #1e293b !important;
  backdrop-filter: blur(20px);
}

/* 骨架屏暗黑模式调整 */
.dark .skeleton-header,
.dark .skeleton-title,
.dark .skeleton-text,
.dark .skeleton-period-card,
.dark-theme .skeleton-header,
.dark-theme .skeleton-title,
.dark-theme .skeleton-text,
.dark-theme .skeleton-period-card {
  background-color: rgba(255, 255, 255, 0.08);
}

.dark .skeleton-period-card::after,
.dark-theme .skeleton-period-card::after {
  background: linear-gradient(90deg, 
    rgba(255, 255, 255, 0) 0%, 
    rgba(255, 255, 255, 0.05) 50%, 
    rgba(255, 255, 255, 0) 100%);
}
</style>
