<template>
  <div class="payment-container">
    <div class="payment-inner">

      <div class="content-wrapper">
        <!-- 左侧内容：产品信息 -->
        <div class="left-column">
          <div class="section-wrapper">
            <div class="section-title">
              <span>{{ $t('payment.product_info') }}</span>
            </div>
            <div class="product-info" v-if="!loading.order">
              <div v-if="orderDetail.period === 'deposit'">
                <div class="info-row">
                  <div class="info-label">{{ $t('wallet.deposit.title') }}</div>
                  <div class="info-value">{{ formatAmount(orderDetail.total_amount) }}</div>
                </div>
              </div>
              <div v-else>
                <div class="info-row">
                  <div class="info-label">{{ $t('payment.plan_name') }}</div>
                  <div class="info-value">{{ orderDetail.plan?.name || '-' }}</div>
                </div>
                <div class="info-row">
                  <div class="info-label">{{ $t('payment.period') }}</div>
                  <div class="info-value">{{ formatPeriod(orderDetail.period) }}</div>
                </div>
                <div class="info-row">
                  <div class="info-label">{{ $t('payment.traffic') }}</div>
                  <div class="info-value">{{ formatTraffic(orderDetail.plan?.transfer_enable) }}</div>
                </div>
              </div>
            </div>
            <div class="skeleton-card" v-else>
              <div class="skeleton-text" v-for="i in 3" :key="'product-'+i"></div>
            </div>
          </div>
          
          <div class="section-wrapper">
            <div class="section-title">
              <span>{{ $t('payment.order_info') }}</span>
            </div>
            <div class="order-info" v-if="!loading.order">
              <div class="info-row">
                <div class="info-label">{{ $t('payment.trade_no') }}</div>
                <div class="info-value trade-no-value">{{ orderDetail.trade_no || '-' }}</div>
              </div>
              <div class="info-row">
                <div class="info-label">{{ $t('payment.created_at') }}</div>
                <div class="info-value">{{ formatDate(orderDetail.created_at) }}</div>
              </div>
              <div v-if="orderDetail.period === 'deposit'" class="info-row">
                <div class="info-label">{{ $t('wallet.deposit.title') }}</div>
                <div class="info-value amount">{{ formatAmount(orderDetail.total_amount) }}</div>
              </div>
              <div v-else class="info-row">
                <div class="info-label">{{ $t('payment.total_price') }}</div>
                <div class="info-value amount">{{ formatAmount(lockedPlanAmount) }}</div>
              </div>
              <div class="info-row discount-row" v-if="flashSaleDiscountAmount > 0">
                <div class="info-label">{{ $t('payment.activity_discount') }}</div>
                <div class="info-value discount">-{{ formatAmount(flashSaleDiscountAmount) }}</div>
              </div>
              <div class="info-row discount-row" v-if="couponDiscountAmount > 0">
                <div class="info-label">{{ $t('payment.coupon_discount') }}</div>
                <div class="info-value discount">-{{ formatAmount(couponDiscountAmount) }}</div>
              </div>
              <div class="info-row discount-row" v-if="memberDiscountAmount > 0">
                <div class="info-label">{{ $t('payment.member_discount') }}</div>
                <div class="info-value discount">-{{ formatAmount(memberDiscountAmount) }}</div>
              </div>
              <div class="info-row discount-row" v-if="orderDetail.surplus_amount > 0">
                <div class="info-label">{{ $t('payment.trade_off_amount') }}</div>
                <div class="info-value discount">-{{ formatAmount(orderDetail.surplus_amount) }}</div>
              </div>
              <div class="info-row" v-if="orderDetail.refund_amount !== null && orderDetail.refund_amount !== undefined && orderDetail.refund_amount > 0">
                <div class="info-label">{{ $t('payment.refund_amount') }}</div>
                <div class="info-value refund">+{{ formatAmount(orderDetail.refund_amount) }}</div>
              </div>
              <div class="info-row" v-if="orderDetail.balance_amount !== null && orderDetail.balance_amount !== undefined && orderDetail.balance_amount > 0">
                <div class="info-label">{{ $t('payment.use_credit') }}</div>
                <div class="info-value discount">-{{ formatAmount(orderDetail.balance_amount) }}</div>
              </div>
              <div class="info-row" v-if="selectedMethod && handleFeeAmount > 0">
                <div class="info-label">{{ $t('payment.handling_fee') }}</div>
                <div class="info-value fee">{{ formatAmount(handleFeeAmount) }}</div>
              </div>
              <div class="info-row final-row">
                <div class="info-label">{{ orderDetail.status === 3 || orderDetail.status === 4 || paymentSuccessful ? $t('payment.paid_amount') : $t('payment.payable_amount') }}</div>
                <div class="info-value final">{{ formatAmount(totalWithFee) }}</div>
              </div>
            </div>
            <div class="skeleton-card" v-else>
              <div class="skeleton-text" v-for="i in 5" :key="'order-'+i"></div>
            </div>
          </div>
        </div>
        
        <div class="right-column">
          <!-- 订单状态卡片（无背景卡片样式，图标放大，文字颜色保持原状态色） -->
          <div class="section-wrapper order-status">
            <div class="section-title">
              <span>{{ $t('payment.order_status') }}</span>
            </div>
            <div class="status-info" v-if="!loading.order">
              <div class="order-status-notice" :class="getStatusClass(orderDetail.status)">
                <div class="status-icon">
                  <IconClock v-if="orderDetail.status === 0 && orderDetail.total_amount > 0" :size="32" />
                  <IconClock v-else-if="orderDetail.status === 0 && orderDetail.total_amount === 0" :size="32" />
                  <IconLoader2 v-else-if="orderDetail.status === 1" :size="32" class="rotating-icon" />
                  <IconX v-else-if="orderDetail.status === 2" :size="32" />
                  <IconCheck v-else-if="orderDetail.status === 3" :size="32" />
                  <IconCheck v-else-if="orderDetail.status === 4" :size="32" />
                  <IconHelp v-else :size="32" />
                </div>
                <div class="status-text">
                  <h3 v-if="orderDetail.status === 0 && orderDetail.total_amount === 0" class="activate-status">{{ $t('payment.status.activate') }}</h3>
                  <h3 v-else>{{ getStatusText(orderDetail.status) }}</h3>
                  <p v-if="orderDetail.status === 0 && orderDetail.total_amount > 0">{{ $t('payment.description') }}</p>
                  <p v-else-if="orderDetail.status === 1">{{ $t('payment.payment_processing') }}</p>
                  <p v-else-if="orderDetail.status === 2">{{ $t('payment.order_cancelled') }}</p>
                  <p v-else-if="orderDetail.status === 3">{{ $t('payment.payment_successful_desc') }}</p>
                  <p v-else-if="orderDetail.status === 4">{{ $t('payment.payment_successful_desc') }}</p>
                  <p v-else-if="orderDetail.status !== 0">{{ $t('payment.unknown_status_desc') }}</p>
                </div>
              </div>
            </div>
            <div class="skeleton-card" v-else>
              <div class="skeleton-text"></div>
            </div>
          </div>
          
          <div class="section-wrapper free-order" v-if="!loading.order && orderDetail.total_amount === 0 && orderDetail.status === 0">
            <div class="section-title">
              <span>{{ $t('payment.free_order') }}</span>
            </div>
            <div class="free-notice">
              <IconAlertCircle :size="48" class="notice-icon success" />
              <div class="notice-text">
                <h3>{{ $t('payment.free_order_title') }}</h3>
                <p>{{ $t('payment.free_order_desc') }}</p>
              </div>
            </div>
          </div>
          
          <div class="section-wrapper" v-if="!loading.order && orderDetail.status === 0 && orderDetail.total_amount > 0">
            <div class="section-title">
              <span>{{ $t('payment.payment_method') }}</span>
            </div>
            <div class="payment-methods" v-if="!loading.methods">
              <button
                class="payment-method-item" 
                v-for="method in paymentMethods" 
                :key="method.id" 
                type="button"
                :class="{ 'active': selectedMethod === method.id }"
                :aria-pressed="selectedMethod === method.id"
                @click="selectMethod(method.id)"
              >
                <div class="method-icon">
                  <IconCreditCard v-if="!method.icon" />
                  <img v-else :src="method.icon" :alt="method.name" />
                </div>
                <div class="method-details">
                  <div class="method-name">{{ method.name }}</div>
                  <div class="method-fee" v-if="method.handling_fee_percent || method.handling_fee_fixed">
                    {{ formatFee(method) }}
                  </div>
                </div>
                <div class="method-check">
                  <IconCircleCheck v-if="selectedMethod === method.id" />
                  <IconCircle v-else />
                </div>
              </button>
            </div>
            <div class="skeleton-card" v-else>
              <div class="skeleton-payment-method" v-for="i in 2" :key="'method-'+i"></div>
            </div>
          </div>
          
          <div class="action-buttons">
            <div class="btn-group pay-row" v-if="fromOrderList && (paymentSuccessful || orderDetail.status !== 0)">
              <button class="btn-back main-action full-width" @click="goBack">
                <IconArrowLeft :size="18" />
                <span>{{ $t('payment.return_to_previous') }}</span>
              </button>
            </div>
            <div class="btn-group pay-row" v-if="(paymentSuccessful || orderDetail.status > 0) && !fromOrderList">
              <button class="btn-pay main-action full-width" @click="goToDashboard">
                <IconArrowRight :size="18" />
                <span v-if="orderDetail.period === 'deposit'">{{ $t('payment.continue_to_wallet') }}</span>
                <span v-else>{{ $t('payment.continue_to_dashboard') }}</span>
              </button>
            </div>
            <template v-if="!loading.order && orderDetail.status === 0 && !paymentSuccessful">
              <div class="btn-group pay-row" v-if="orderDetail.total_amount > 0">
                <button class="btn-pay main-action full-width" @click="processPayment"
                  :disabled="(orderDetail.total_amount > 0 && !selectedMethod) || waitingForPayment || loading.paying || loading.checking">
                  <div v-if="loading.paying" class="loader"></div>
                  <IconClock v-else-if="waitingForPayment" :size="18" />
                  <IconCreditCard v-else :size="18" />
                  <span>{{ waitingForPayment ? $t('payment.waiting_payment') : $t('payment.pay_now') }}</span>
                </button>
              </div>
              <div class="btn-group action-row" v-if="orderDetail.total_amount === 0">
                <button class="btn-back secondary-action" @click="cancelCurrentOrder" :disabled="loading.cancelling">
                  <IconX v-if="!loading.cancelling" :size="18" />
                  <div v-else class="loader"></div>
                  <span>{{ $t('payment.cancel_order') }}</span>
                </button>
                <button class="btn-pay main-action" @click="checkPayment" :disabled="loading.checking">
                  <IconCreditCard v-if="!loading.checking" :size="18" />
                  <div v-else class="loader"></div>
                  <span>{{ $t('payment.activate') }}</span>
                </button>
              </div>
              <div class="btn-group action-row" v-if="orderDetail.total_amount > 0">
                <button class="btn-back secondary-action" @click="cancelCurrentOrder" :disabled="loading.cancelling">
                  <IconX v-if="!loading.cancelling" :size="18" />
                  <div v-else class="loader"></div>
                  <span>{{ $t('payment.cancel_order') }}</span>
                </button>
                <button class="btn-check secondary-action" @click="checkPaymentStatus"
                  :disabled="(orderDetail.total_amount > 0 && !selectedMethod) || loading.checking || loading.paying">
                  <IconRefresh v-if="!loading.checking" :size="18" />
                  <div v-else class="loader"></div>
                  <span>{{ $t('payment.check_payment') }}</span>
                </button>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>
    
    <transition name="fade">
      <div class="payment-success-overlay" v-if="showSuccessAnimation">
        <div class="success-animation">
          <div class="check-container">
            <div class="check-background">
              <IconCheck :size="100" class="check-icon" />
            </div>
          </div>
          <h2>{{ $t('payment.payment_successful') }}</h2>
          <p>{{ $t('payment.payment_successful_desc') }}</p>
        </div>
        <div class="confetti-container" v-if="showConfettiAnimation">
          <ConfettiExplosion
            :particleCount="150"
            :force="0.4"
            :stageWidth="window.innerWidth"
            :stageHeight="window.innerHeight"
            :colors="['#ffcc00', '#ff8800', '#ff3333', '#26A65B', '#42A5F5', '#9C27B0']"
          />
        </div>
      </div>
    </transition>
    
    <transition name="modal-fade">
      <div class="cancel-modal" v-if="showCancelConfirm">
        <div class="cancel-modal-overlay" @click="closeModal"></div>
        <div class="cancel-modal-container">
          <div v-accessible-dialog="closeModal" class="cancel-modal-content" :aria-label="$t('payment.confirm_cancel_title')">
            <div class="cancel-modal-icon">
              <IconAlertTriangle :size="28" />
            </div>
            <div class="cancel-modal-header">
              <h3>{{ $t('payment.confirm_cancel_title') }}</h3>
              <p>{{ $t('payment.confirm_cancel_desc') }}</p>
            </div>
            <div class="cancel-modal-actions">
              <button class="cancel-btn" @click="closeModal">{{ $t('common.cancel') }}</button>
              <button class="confirm-btn" @click="confirmCancel">{{ $t('common.confirm') }}</button>
            </div>
          </div>
        </div>
      </div>
    </transition>
    
    <transition name="modal">
      <div class="modal-wrapper" v-if="showPaymentModal">
        <div class="modal-backdrop" @click="closePaymentModal"></div>
        <div class="modal-container">
          <div v-accessible-dialog="closePaymentModal" class="modal-card" :aria-label="selectedMethod ? getSelectedMethodName() : $t('payment.payment_method')">
            <button class="close-button" type="button" :aria-label="$t('common.close')" @click="closePaymentModal">×</button>
            <div class="modal-header">
              <div class="icon-wrapper payment">
                <IconCreditCard :size="32" />
              </div>
              <h3>{{ selectedMethod ? getSelectedMethodName() : $t('payment.payment_method') }}</h3>
              <p v-if="detectBrowser() === 'Safari' && PAYMENT_CONFIG.useSafariPaymentModal && paymentLink">
                {{ $t('payment.safari_payment_notice') }}
              </p>
              <p v-else>{{ $t('payment.scan_qrcode') }}</p>
              <div class="qrcode-container" v-if="paymentQRCode">
                <QrcodeVue 
                  :value="paymentQRCode" 
                  :size="PAYMENT_CONFIG.qrcodeSize" 
                  :background="PAYMENT_CONFIG.qrcodeBackground"
                  :foreground="PAYMENT_CONFIG.qrcodeColor"
                  level="H"
                  render-as="svg"
                />
              </div>
              <div class="payment-link" v-if="paymentLink">
                <button class="btn-link" @click="openPaymentLink">
                  <IconExternalLink :size="18" />
                  <span v-if="detectBrowser() === 'Safari' && PAYMENT_CONFIG.useSafariPaymentModal">
                    {{ $t('payment.safari_payment_button') }}
                  </span>
                  <span v-else>{{ $t('payment.open_in_current_tab') }}</span>
                </button>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn-secondary" @click="closePaymentModal">
                <IconX :size="18" />
                <span>{{ $t('common.cancel') }}</span>
              </button>
              <button class="btn-primary" @click="checkPayment">
                <IconRefresh :size="18" />
                <span>{{ $t('payment.check_payment') }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script>
import { ref, reactive, onMounted, computed, onBeforeUnmount, nextTick, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useToast } from '@/composables/useToast';
import { useRoute, useRouter } from 'vue-router';
import { getOrderDetail, getPaymentMethods, checkOrderStatus, cancelOrder, checkoutOrder } from '@/api/shop';
import { PAYMENT_CONFIG, detectBrowser } from '@/utils/baseConfig';
import QrcodeVue from 'qrcode.vue';
import ConfettiExplosion from 'vue-confetti-explosion';
import {
  IconCheck,
  IconX,
  IconCreditCard,
  IconCircle,
  IconCircleCheck,
  IconAlertCircle,
  IconArrowRight,
  IconAlertTriangle,
  IconRefresh,
  IconExternalLink,
  IconArrowLeft,
  IconClock,
  IconLoader2,
  IconHelp
} from '@tabler/icons-vue';

const PAYMENT_WAITING_PREFIX = 'fastcat_payment_waiting:';
const PAYMENT_WAITING_TTL = 2 * 60 * 60 * 1000;
const PAYMENT_SUCCESS_ANIMATION_TOTAL_MS = 2000;
const PAYMENT_SUCCESS_CONFETTI_DELAY_MS = 300;
const PAYMENT_SUCCESS_HIDE_DELAY_MS = 500;
const PAYMENT_SUCCESS_CONFETTI_VISIBLE_MS =
  PAYMENT_SUCCESS_ANIMATION_TOTAL_MS - PAYMENT_SUCCESS_CONFETTI_DELAY_MS - PAYMENT_SUCCESS_HIDE_DELAY_MS;

const paymentWaitingKey = tradeNo => `${PAYMENT_WAITING_PREFIX}${tradeNo}`;
const savePaymentWaiting = (tradeNo, methodId) => {
  if (!tradeNo) return;
  localStorage.setItem(paymentWaitingKey(tradeNo), JSON.stringify({ methodId, createdAt: Date.now() }));
};
const clearPaymentWaiting = tradeNo => {
  if (tradeNo) localStorage.removeItem(paymentWaitingKey(tradeNo));
};
const readPaymentWaiting = tradeNo => {
  if (!tradeNo) return null;
  try {
    const state = JSON.parse(localStorage.getItem(paymentWaitingKey(tradeNo)) || 'null');
    if (!state?.createdAt || Date.now() - state.createdAt > PAYMENT_WAITING_TTL) {
      clearPaymentWaiting(tradeNo);
      return null;
    }
    return state;
  } catch (_) {
    clearPaymentWaiting(tradeNo);
    return null;
  }
};

const playPaymentSuccessAnimation = (showSuccessAnimation, showConfettiAnimation, showToast, t, showNotification = true) => {
  showSuccessAnimation.value = true;
  nextTick(() => {
    setTimeout(() => { showConfettiAnimation.value = true; }, PAYMENT_SUCCESS_CONFETTI_DELAY_MS);
    if (showNotification) showToast(t('payment.pay_success'), 'success');
    setTimeout(() => {
      showConfettiAnimation.value = false;
      setTimeout(() => { showSuccessAnimation.value = false; }, PAYMENT_SUCCESS_HIDE_DELAY_MS);
    }, PAYMENT_SUCCESS_CONFETTI_VISIBLE_MS);
  });
};

export default {
  name: 'PaymentView',
  components: {
    IconCheck,
    IconX,
    IconCreditCard,
    IconCircle,
    IconCircleCheck,
    IconAlertCircle,
    IconArrowRight,
    IconAlertTriangle,
    IconRefresh,
    IconExternalLink,
    IconArrowLeft,
    QrcodeVue,
    ConfettiExplosion,
    IconClock,
    IconLoader2,
    IconHelp
  },
  setup() {
    const { t, locale } = useI18n();
    const { showToast } = useToast();
    const route = useRoute();
    const router = useRouter();
    
    const fromOrderList = ref(false);
    const loading = reactive({
      order: true,
      methods: true,
      checking: false,
      cancelling: false,
      paying: false
    });
    
    const orderDetail = ref({});
    const paymentMethods = ref([]);
    const selectedMethod = ref(null);
    const waitingForPayment = ref(false);
    let paymentCheckTimer = null;
    const paymentSuccessful = ref(false);
    const showSuccessAnimation = ref(false);
    const showConfettiAnimation = ref(false);
    const showCancelConfirm = ref(false);
    const showPaymentModal = ref(false);
    const paymentQRCode = ref(null);
    const paymentLink = ref(null);
    let externalPaymentStarted = false;
    let paymentPageWasLeft = false;
    let paymentReturnTimer = null;
    
    const handleFeeAmount = computed(() => {
      if (!selectedMethod.value || !orderDetail.value.total_amount) return 0;
      const method = paymentMethods.value.find(m => m.id === selectedMethod.value);
      if (!method) return 0;
      let fee = 0;
      if (method.handling_fee_percent) fee += (orderDetail.value.total_amount * method.handling_fee_percent / 100);
      if (method.handling_fee_fixed) fee += method.handling_fee_fixed;
      return Math.round(fee);
    });
    
    const totalWithFee = computed(() => (orderDetail.value.total_amount || 0) + handleFeeAmount.value);
    const flashSaleDiscountAmount = computed(() => Number(orderDetail.value.flash_sale_discount_amount || 0));
    const couponDiscountAmount = computed(() => Number(orderDetail.value.coupon_discount_amount || 0));
    const memberDiscountAmount = computed(() => Math.max(
      0,
      Number(orderDetail.value.discount_amount || 0) - flashSaleDiscountAmount.value - couponDiscountAmount.value
    ));
    const orderAmountBeforeBalance = computed(() =>
      Number(orderDetail.value.total_amount || 0) + Number(orderDetail.value.balance_amount || 0));
    const lockedPlanAmount = computed(() => {
      if (orderDetail.value.period === 'deposit') return orderAmountBeforeBalance.value;
      return Math.max(0,
        orderAmountBeforeBalance.value
        + Number(orderDetail.value.discount_amount || 0)
        + Number(orderDetail.value.surplus_amount || 0)
        - Number(orderDetail.value.refund_amount || 0)
      );
    });
    
    const fetchOrderDetail = async () => {
      loading.order = true;
      try {
        let tradeNo = null;
        const queryParams = new URLSearchParams(window.location.search || window.location.hash.split('?')[1] || '');
        if (queryParams.has('out_trade_no')) tradeNo = queryParams.get('out_trade_no');
        if (!tradeNo && queryParams.has('trade_no')) tradeNo = queryParams.get('trade_no');
        if (!tradeNo) tradeNo = route.query.out_trade_no || route.query.trade_no;
        if (!tradeNo) {
          showToast(t('payment.no_order_selected'), 'error');
          router.push('/shop');
          return;
        }
        const response = await getOrderDetail(tradeNo);
        if (response.data) {
          orderDetail.value = response.data;
          const waitingState = readPaymentWaiting(orderDetail.value.trade_no);
          if (waitingState) {
            if (!selectedMethod.value && waitingState.methodId) selectedMethod.value = waitingState.methodId;
            waitingForPayment.value = true;
            externalPaymentStarted = true;
            paymentPageWasLeft = true;
            if (orderDetail.value.status === 0 || orderDetail.value.status === 1 || orderDetail.value.status === 3 || orderDetail.value.status === 4) {
              startPaymentCheck();
            }
          } else {
            clearPaymentWaiting(orderDetail.value.trade_no);
          }
          if (orderDetail.value.status === 0 && orderDetail.value.total_amount === 0) startPaymentCheck();
        } else {
          showToast(t('payment.order_not_found'), 'error');
          router.push('/shop');
        }
      } catch (error) {
        console.error('获取订单详情失败:', error);
        showToast(t('payment.failed_to_fetch_order'), 'error');
        router.push('/shop');
      } finally {
        loading.order = false;
      }
    };
    
    const fetchPaymentMethods = async () => {
      loading.methods = true;
      try {
        const response = await getPaymentMethods();
        if (response.data) {
          paymentMethods.value = response.data;
          if (paymentMethods.value.length === 1 || PAYMENT_CONFIG.autoSelectFirstMethod) {
            if (paymentMethods.value.length > 0) selectedMethod.value = paymentMethods.value[0].id;
          }
        }
      } catch (error) {
        console.error('获取支付方式失败:', error);
        showToast(t('payment.failed_to_fetch_methods'), 'error');
      } finally {
        loading.methods = false;
      }
    };
    
    const selectMethod = (methodId) => { selectedMethod.value = methodId; };
    const formatDate = (timestamp) => timestamp ? new Date(timestamp * 1000).toLocaleString() : '-';
    const formatAmount = (amount) => amount !== null && amount !== undefined ? `¥${(amount / 100).toFixed(2)}` : '-';
    const formatPeriod = (period) => {
      if (period === 'reset_price' || period === 'deposit') return t(`payment.period_types.${period}`);
      const periodMap = {
        month_price: t('shop.plan.price_options.month'),
        quarter_price: t('shop.plan.price_options.quarter'),
        half_year_price: t('shop.plan.price_options.half_year'),
        year_price: t('shop.plan.price_options.year'),
        two_year_price: t('shop.plan.price_options.two_year'),
        three_year_price: t('shop.plan.price_options.three_year'),
        onetime_price: t('shop.plan.price_options.onetime')
      };
      return periodMap[period] || period;
    };
    const formatTraffic = (gb) => {
      if (!gb) return '-';
      return `${gb} GB`;
    };
    const formatFee = (method) => {
      let text = '';
      if (method.handling_fee_percent) text += `${method.handling_fee_percent}%`;
      if (method.handling_fee_fixed) {
        const fixed = (method.handling_fee_fixed / 100).toFixed(2);
        text += text ? ` + ¥${fixed}` : `¥${fixed}`;
      }
      return text ? `${t('payment.fee')}: ${text}` : '';
    };
    
    const checkPayment = async () => {
      if (orderDetail.value.total_amount > 0 && !selectedMethod.value) {
        showToast(t('payment.select_method_first'), 'warning');
        return;
      }
      loading.checking = true;
      try {
        if (orderDetail.value.total_amount === 0 && paymentMethods.value.length > 0) {
          selectedMethod.value = paymentMethods.value[0].id;
        }
        await checkoutOrder(orderDetail.value.trade_no, selectedMethod.value);
        showToast(t('payment.payment_processing'), 'info');
        startPaymentCheck();
      } catch (error) {
        console.error('结算订单失败:', error);
        showToast(t('payment.check_failed'), 'error');
        loading.checking = false;
      }
    };
    
    const performPaymentCheck = async (suppressToast = false) => {
      try {
        const response = await checkOrderStatus(orderDetail.value.trade_no);
        if (response.data === 1) {
          orderDetail.value.status = response.data;
          setTimeout(() => {
            if (orderDetail.value.status !== 3) {
              orderDetail.value.status = 3;
              handlePaymentSuccess(!suppressToast);
            }
          }, 1000);
        } else if (response.data !== 0 && response.data !== 2) {
          orderDetail.value.status = response.data;
          handlePaymentSuccess(!suppressToast);
        } else if (response.data === 2) {
          clearPaymentWaiting(orderDetail.value.trade_no);
          if (paymentCheckTimer) clearInterval(paymentCheckTimer);
          if (!suppressToast) showToast(t('payment.order_cancelled'), 'warning');
          loading.checking = false;
          loading.paying = false;
          orderDetail.value.status = response.data;
          closePaymentModal();
        }
        if (orderDetail.value.total_amount === 0) {
          loading.checking = false;
          loading.paying = false;
        }
        return response.data;
      } catch (error) {
        console.error('检查支付状态失败:', error);
        if (orderDetail.value.total_amount === 0) {
          loading.checking = false;
          loading.paying = false;
        }
        return null;
      }
    };
    
    const startPaymentCheck = async () => {
      if (paymentSuccessful.value) return;
      await performPaymentCheck();
      if (orderDetail.value.total_amount === 0 && !paymentSuccessful.value) {
        setTimeout(() => performPaymentCheck(true), 1000);
      } else if (orderDetail.value.total_amount > 0 && !paymentSuccessful.value) {
        if (paymentCheckTimer) clearInterval(paymentCheckTimer);
        if (PAYMENT_CONFIG.autoCheckPayment) {
          let checkCount = 0;
          paymentCheckTimer = setInterval(() => {
            checkCount++;
            performPaymentCheck();
            if (PAYMENT_CONFIG.autoCheckMaxTimes > 0 && checkCount >= PAYMENT_CONFIG.autoCheckMaxTimes) {
              clearInterval(paymentCheckTimer);
              loading.checking = false;
              if (!paymentSuccessful.value) showToast(t('payment.check_timeout'), 'info');
            }
          }, PAYMENT_CONFIG.autoCheckInterval);
        } else {
          let checkCount = 0;
          paymentCheckTimer = setInterval(() => {
            checkCount++;
            performPaymentCheck();
            if (checkCount >= 2) {
              clearInterval(paymentCheckTimer);
              loading.checking = false;
              if (!paymentSuccessful.value) showToast(t('payment.check_timeout'), 'info');
            }
          }, 5000);
        }
      }
    };

    const restorePaymentAfterReturn = async () => {
      if (!waitingForPayment.value || !externalPaymentStarted) return;
      loading.checking = true;
      try {
        const status = await performPaymentCheck(true);
        if (status === 0) {
          if (paymentCheckTimer) clearInterval(paymentCheckTimer);
          paymentCheckTimer = null;
          waitingForPayment.value = false;
          externalPaymentStarted = false;
          clearPaymentWaiting(orderDetail.value.trade_no);
          showToast(t('payment.payment_returned_unpaid'), 'info');
        }
      } finally {
        loading.checking = false;
      }
    };

    const markPaymentPageLeft = () => {
      if (waitingForPayment.value && externalPaymentStarted) paymentPageWasLeft = true;
    };

    const handlePaymentPageReturn = () => {
      if (document.visibilityState === 'hidden' || !paymentPageWasLeft) return;
      paymentPageWasLeft = false;
      if (paymentReturnTimer) clearTimeout(paymentReturnTimer);
      paymentReturnTimer = setTimeout(restorePaymentAfterReturn, 300);
    };

    const handleVisibilityChange = () => {
      if (document.visibilityState === 'hidden') markPaymentPageLeft();
      else handlePaymentPageReturn();
    };
    
    const handlePaymentSuccess = (showNotification = true) => {
      if (paymentSuccessful.value) return;
      if (paymentCheckTimer) clearInterval(paymentCheckTimer);
      paymentSuccessful.value = true;
      clearPaymentWaiting(orderDetail.value.trade_no);
      loading.checking = false;
      loading.paying = false;
      closePaymentModal();
      if (!fromOrderList.value) {
        playPaymentSuccessAnimation(showSuccessAnimation, showConfettiAnimation, showToast, t, showNotification);
      }
    };
    
    const cancelCurrentOrder = () => { showCancelConfirm.value = true; };
    const closeModal = () => { showCancelConfirm.value = false; };
    const closePaymentModal = () => {
      showPaymentModal.value = false;
      paymentQRCode.value = null;
      paymentLink.value = null;
    };
    const getSelectedMethodName = () => {
      if (!selectedMethod.value) return '';
      const method = paymentMethods.value.find(m => m.id === selectedMethod.value);
      return method ? method.name : '';
    };
    const processPayment = async () => {
      if (waitingForPayment.value) return;
      if (!selectedMethod.value) {
        showToast(t('payment.select_method_first'), 'warning');
        return;
      }
      waitingForPayment.value = true;
      loading.paying = true;
      try {
        const response = await checkoutOrder(orderDetail.value.trade_no, selectedMethod.value);
        if (!response.data) throw new Error('支付接口未返回支付信息');
        if (response.type === 0) {
          paymentQRCode.value = response.data;
          paymentLink.value = null;
          showPaymentModal.value = true;
        } else if (response.type === 1) {
          paymentLink.value = response.data;
          paymentQRCode.value = null;
          externalPaymentStarted = true;
          // 直接在当前页面打开支付链接，避免新开标签页。
          setTimeout(() => {
            window.location.href = response.data;
          }, 100);
        } else {
          throw new Error('支付接口返回了不支持的支付类型');
        }
        savePaymentWaiting(orderDetail.value.trade_no, selectedMethod.value);
        startPaymentCheck();
      } catch (error) {
        waitingForPayment.value = false;
        clearPaymentWaiting(orderDetail.value.trade_no);
        console.error('发起支付失败:', error);
        showToast(t('payment.check_failed'), 'error');
      } finally {
        loading.paying = false;
      }
    };
    const openPaymentLink = () => {
      if (paymentLink.value) {
        window.location.href = paymentLink.value;
      }
    };
    const confirmCancel = async () => {
      loading.cancelling = true;
      showCancelConfirm.value = false;
      try {
        await cancelOrder(orderDetail.value.trade_no);
        clearPaymentWaiting(orderDetail.value.trade_no);
        showToast(t('payment.cancel_success'), 'success');
        if (paymentCheckTimer) clearInterval(paymentCheckTimer);
        router.push('/shop');
      } catch (error) {
        console.error('取消订单失败:', error);
        showToast(t('payment.cancel_failed'), 'error');
      } finally {
        loading.cancelling = false;
      }
    };
    const goToDashboard = () => {
      if (orderDetail.value.period === 'deposit') router.push('/wallet/deposit');
      else router.push('/dashboard');
    };
    const checkPaymentStatus = async () => {
      loading.checking = true;
      try {
        const response = await checkOrderStatus(orderDetail.value.trade_no);
        if (response.data === 0) showToast(t('payment.payment_pending'), 'info');
        else if (response.data === 2) {
          clearPaymentWaiting(orderDetail.value.trade_no);
          showToast(t('payment.order_cancelled'), 'warning');
          if (paymentCheckTimer) clearInterval(paymentCheckTimer);
          orderDetail.value.status = response.data;
        } else {
          showToast(t('payment.payment_successful'), 'success');
          orderDetail.value.status = response.data;
          paymentSuccessful.value = true;
          clearPaymentWaiting(orderDetail.value.trade_no);
          if (paymentCheckTimer) clearInterval(paymentCheckTimer);
          closePaymentModal();
          playPaymentSuccessAnimation(showSuccessAnimation, showConfettiAnimation, showToast, t, true);
        }
      } catch (error) {
        console.error('检查支付状态失败:', error);
        showToast(t('payment.check_failed'), 'error');
      } finally {
        loading.checking = false;
        loading.paying = false;
      }
    };
    const getStatusText = (status) => {
      const map = { 0: t('payment.status.pending'), 1: t('payment.status.processing'), 2: t('payment.status.cancelled'), 3: t('payment.status.completed'), 4: t('payment.status.discounted') };
      return map[status] || t('payment.status.unknown');
    };
    const getStatusClass = (status) => {
      const map = { 0: 'status-pending', 1: 'status-processing', 2: 'status-cancelled', 3: 'status-completed', 4: 'status-discounted' };
      return map[status] || 'status-unknown';
    };
    const goBack = () => router.go(-1);

    watch(locale, () => {
      fetchOrderDetail();
      fetchPaymentMethods();
    });
    
    onMounted(() => {
      fetchOrderDetail();
      fetchPaymentMethods();
      document.addEventListener('visibilitychange', handleVisibilityChange);
      window.addEventListener('blur', markPaymentPageLeft);
      window.addEventListener('focus', handlePaymentPageReturn);
      if (route.query.from === 'orders') fromOrderList.value = true;
      else if (document.referrer && document.referrer.includes('/orders')) fromOrderList.value = true;
      else fromOrderList.value = false;
      watch(() => orderDetail.value.status, (newStatus) => {
        if ((newStatus === 3 || newStatus === 4) && !paymentSuccessful.value && !fromOrderList.value) {
          paymentSuccessful.value = true;
          playPaymentSuccessAnimation(showSuccessAnimation, showConfettiAnimation, showToast, t, false);
        }
      });
    });
    onBeforeUnmount(() => {
      if (paymentCheckTimer) clearInterval(paymentCheckTimer);
      if (paymentReturnTimer) clearTimeout(paymentReturnTimer);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
      window.removeEventListener('blur', markPaymentPageLeft);
      window.removeEventListener('focus', handlePaymentPageReturn);
    });
    
    return {
      loading, orderDetail, paymentMethods, selectedMethod, waitingForPayment, paymentSuccessful,
      showSuccessAnimation, showConfettiAnimation, fromOrderList,
      formatDate, formatAmount, formatPeriod, formatTraffic, formatFee,
      selectMethod, checkPayment, cancelCurrentOrder, goToDashboard,
      showCancelConfirm, confirmCancel, closeModal,
      showPaymentModal, paymentQRCode, paymentLink, PAYMENT_CONFIG,
      getSelectedMethodName, processPayment, openPaymentLink, closePaymentModal,
      handleFeeAmount, totalWithFee, flashSaleDiscountAmount, couponDiscountAmount,
      memberDiscountAmount, orderAmountBeforeBalance, lockedPlanAmount,
      window, checkPaymentStatus,
      detectBrowser, getStatusText, getStatusClass, goBack
    };
  }
};
</script>

<style lang="scss" scoped>
/* 所有卡片背景在亮色模式为白色 #ffffff */
.section-wrapper,
.skeleton-card,
.payment-methods .payment-method-item,
.modal-card,
.cancel-modal-content,
.free-notice {
  background-color: #ffffff;
}

.payment-container {
  padding: 20px;
  display: flex;
  justify-content: center;
  position: relative;
  
  .payment-inner {
    width: 100%;
    max-width: 900px;
  }
  
  .content-wrapper {
    display: flex;
    gap: 25px;
    @media (max-width: 768px) { flex-direction: column; }
    .left-column, .right-column { flex: 1; min-width: 0; }
  }
  
  .section-wrapper {
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
    .section-title {
      font-size: 16px;
      font-weight: 600;
      margin-bottom: 16px;
      color: var(--text-color);
      display: flex;
      align-items: center;
      &::after {
        content: '';
        flex: 1;
        height: 1px;
        background-color: var(--border-color);
        margin-left: 12px;
      }
    }
  }
  
  .product-info, .order-info {
    .info-row {
      display: flex;
      margin-bottom: 0px;
      padding: 10px;
      border-radius: 20px;
      transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
      &:hover { background-color: rgba(var(--theme-color-rgb), 0.05); }
      .info-label { width: 120px; color: var(--secondary-text-color); font-size: 14px; }
      .info-value { flex: 1; color: var(--text-color); font-weight: 500; font-size: 14px; }
      .trade-no-value { font-size: 10px; }
      .refund { color: var(--success-color); font-weight: 600; }
      &.final-row { border-top: 1px dashed var(--border-color); padding-top: 15px; .final { font-size: 24px; font-weight: 700; color: var(--theme-color); } }
      &.discount-row .discount { color: #f44336; }
    }
  }
  
  .payment-methods .payment-method-item {
    display: flex;
    align-items: center;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 12px;
    cursor: pointer;
    border: 1px solid var(--card-border);
    transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
    &:hover { border-color: rgba(var(--theme-color-rgb), 0.5); background-color: rgba(var(--theme-color-rgb), 0.05); transform: translateY(-2px); }
    &.active { border-color: var(--theme-color); background-color: rgba(var(--theme-color-rgb), 0.1); transform: translateY(-2px); box-shadow: 0 4px 15px rgba(var(--theme-color-rgb), 0.15); }
    .method-icon { width: 40px; height: 40px; margin-right: 15px; display: flex; align-items: center; justify-content: center; color: var(--theme-color); img { max-width: 100%; max-height: 100%; object-fit: contain; } }
    .method-details { flex: 1; .method-name { font-weight: 600; margin-bottom: 4px; color: var(--text-color); } .method-fee { font-size: 12px; color: var(--secondary-text-color); } }
    .method-check { color: var(--theme-color); }
  }
  
  .free-notice {
    display: flex;
    align-items: center;
    padding: 20px;
    border-radius: 10px;
    border: 1px solid rgba(76, 175, 80, 0.2);
    .notice-icon { margin-right: 20px; color: #4caf50; &.success { color: #4caf50; } }
    .notice-text { flex: 1; h3 { margin: 0 0 8px; color: #4caf50; font-size: 16px; } p { margin: 0; color: var(--text-color); } }
  }
  
  /* 订单状态卡片 - 无背景，图标32px，文字颜色保持状态色 */
  .order-status-notice {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 12px 0;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    
    .status-icon {
      flex-shrink: 0;
      svg {
        width: 32px !important;
        height: 32px !important;
      }
    }
    
    .status-text {
      flex: 1;
      h3 {
        font-size: 1rem;
        font-weight: 600;
        margin: 0 0 4px 0;
      }
      p {
        font-size: 0.85rem;
        margin: 0;
        color: var(--secondary-text-color);
        line-height: 1.4;
      }
    }
    
    /* 状态颜色变化（图标和标题） */
    &.status-pending .status-icon { color: #ff9800; }
    &.status-pending .status-text h3 { color: #f57c00; }
    &.status-processing .status-icon { color: #2196f3; }
    &.status-processing .status-text h3 { color: #1976d2; }
    &.status-cancelled .status-icon { color: #f44336; }
    &.status-cancelled .status-text h3 { color: #d32f2f; }
    &.status-completed .status-icon { color: #4caf50; }
    &.status-completed .status-text h3 { color: #388e3c; }
    &.status-discounted .status-icon { color: #9c27b0; }
    &.status-discounted .status-text h3 { color: #7b1fa2; }
    &.status-unknown .status-icon { color: #9e9e9e; }
    &.status-unknown .status-text h3 { color: #757575; }
  }
  
  .action-buttons {
    display: flex;
    flex-direction: column;
    gap: 15px;
    margin-top: 30px;
    margin-bottom: 20px;
    .btn-group {
      display: flex;
      gap: 15px;
      width: 100%;
      @media (max-width: 480px) { flex-direction: column; gap: 10px; }
      &.pay-row {
        @media (max-width: 480px) {
          .main-action {
            flex: 0 0 44px;
            min-height: 44px;
          }
        }
      }
      &.action-row {
        @media (max-width: 480px) {
          flex-direction: row;
          gap: 10px;

          .secondary-action {
            flex: 1 1 0;
            min-width: 0;
          }
        }
      }
      .main-action { flex: 3; }
      .secondary-action { flex: 1; min-width: 130px; }
    }
    .btn-back, .btn-pay, .btn-check {
      height: 48px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      border-radius: 10px;
      font-size: 14px;
      font-weight: 500;
      padding: 0 24px;
      cursor: pointer;
      transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
      &:disabled { opacity: 0.6; cursor: not-allowed; }
    }
    .btn-back { background-color: transparent; color: var(--text-color); border: 1px solid var(--border-color); &:hover:not(:disabled) { background-color: var(--hover-color); transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); } }
    .btn-pay { background-color: var(--theme-color); color: white; box-shadow: 0 4px 10px rgba(var(--theme-color-rgb), 0.25); &:hover:not(:disabled) { background-color: var(--primary-color-hover); transform: translateY(-2px); box-shadow: 0 6px 15px rgba(var(--theme-color-rgb), 0.35); } }
    .btn-check { background-color: var(--hover-color); color: var(--text-color); border: 1px solid var(--border-color); &:hover:not(:disabled) { background-color: var(--card-bg-color); transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); } }
    .full-width { width: 100%; }
    .loader { width: 18px; height: 18px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: white; animation: spin 1s linear infinite; }
  }
  
  /* 骨架屏 */
  .skeleton-card .skeleton-text, .skeleton-payment-method {
    background-color: rgba(0, 0, 0, 0.05);
    border-radius: 4px;
    margin-bottom: 15px;
    position: relative;
    overflow: hidden;
    &::after {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0; bottom: 0;
      background: linear-gradient(90deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.15) 50%, rgba(255,255,255,0) 100%);
      transform: translateX(-100%);
      animation: shimmer 2s infinite;
    }
  }
  .skeleton-payment-method { height: 70px; margin-bottom: 20px; }
  
  .payment-success-overlay {
    position: fixed; top:0; left:0; right:0; bottom:0;
    background-color: rgba(0,0,0,0.8); display: flex; align-items: center; justify-content: center; z-index: 1000;
    .success-animation { text-align: center; color: white; padding: 30px; max-width: 500px; z-index: 1001;
      .check-container { margin-bottom: 24px;
        .check-background { background-color: var(--theme-color); width: 140px; height: 140px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto; animation: zoomIn 0.5s ease, pulse 2s infinite; box-shadow: 0 0 30px rgba(var(--theme-color-rgb),0.7);
          .check-icon { color: white; animation: bounceIn 0.8s ease 0.2s both; }
        }
      }
      h2 { font-size: 28px; margin-bottom: 16px; animation: slideUp 0.5s ease 0.4s both; }
      p { font-size: 16px; opacity: 0.8; animation: slideUp 0.5s ease 0.6s both; }
    }
    .confetti-container { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 1000; pointer-events: none; }
  }
  
  .cancel-modal {
    position: fixed; top:0; left:0; width:100%; height:100%; z-index:2000; display:flex; align-items:center; justify-content:center;
    .cancel-modal-overlay { position: absolute; inset:0; background-color: rgba(0,0,0,0.5); }
    .cancel-modal-container { position: relative; width:90%; max-width:320px; z-index:2001; }
    .cancel-modal-content { border-radius:12px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.15); }
    .cancel-modal-icon { margin:24px auto 0; width:48px; height:48px; background-color:#ff980020; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#ff9800; }
    .cancel-modal-header { padding:16px 24px; text-align:center; h3 { font-size:18px; font-weight:600; margin:0 0 12px; color:var(--text-color); } p { font-size:14px; line-height:1.5; margin:0; color:var(--secondary-text-color); } }
    .cancel-modal-actions { display:flex; padding:0 16px 20px; gap:12px;
      button { flex:1; padding:10px 0; border-radius:6px; font-size:14px; font-weight:500; cursor:pointer; }
      .cancel-btn { background-color:transparent; border:1px solid var(--border-color); color:var(--text-color); &:hover { background-color:var(--hover-color); } }
      .confirm-btn { background-color:#ff4d4f; color:white; &:hover { background-color:#ff7875; } }
    }
  }
  
  .modal-wrapper {
    position: fixed; top:0; left:0; right:0; bottom:0; z-index:1000; display:flex; align-items:center; justify-content:center;
    .modal-backdrop { position: absolute; inset:0; background-color: rgba(0,0,0,0.65); }
    .modal-container { position: relative; width:100%; max-width:400px; margin:20px; }
    .modal-card {
      border-radius:20px; box-shadow:0 10px 35px rgba(0,0,0,0.15); border:1px solid rgba(var(--theme-color-rgb),0.1); overflow:hidden;
      .close-button { position:absolute; top:15px; right:15px; width:44px; height:44px; border-radius:10px; background-color:transparent; color:var(--text-color); font-size:22px; border:1px solid var(--border-color); cursor:pointer; display:flex; align-items:center; justify-content:center; }
      .modal-header { padding:28px 24px 20px; text-align:center; .icon-wrapper { width:64px; height:64px; margin:0 auto 20px; border-radius:50%; display:flex; align-items:center; justify-content:center; &.payment { background-color:rgba(var(--theme-color-rgb),0.15); color:var(--theme-color); } }
        h3 { font-size:20px; font-weight:600; margin:0 0 12px; color:var(--text-color); }
        p { font-size:15px; line-height:1.6; margin:0 0 24px; color:var(--secondary-text-color); }
        .qrcode-container { display:flex; justify-content:center; margin:10px 0 20px; canvas, svg { border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); } }
        .payment-link { margin-top:16px; .btn-link { padding:10px 16px; background-color:transparent; border:1px solid var(--border-color); border-radius:8px; display:inline-flex; align-items:center; gap:8px; font-size:14px; color:var(--theme-color); cursor:pointer; &:hover { background-color:rgba(var(--theme-color-rgb),0.05); border-color:var(--theme-color); } } }
      }
      .modal-footer { padding:16px 24px 28px; display:flex; gap:16px;
        button { flex:1; height:46px; border-radius:14px; font-size:15px; font-weight:600; display:flex; align-items:center; justify-content:center; gap:8px; cursor:pointer; transition:background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease; }
        .btn-secondary { background-color:transparent; border:1px solid var(--border-color); color:var(--text-color); &:hover { background-color:var(--hover-color); transform:translateY(-2px); } }
        .btn-primary { background-color:var(--theme-color); color:white; box-shadow:0 4px 10px rgba(var(--theme-color-rgb),0.25); &:hover { background-color:var(--primary-color-hover); transform:translateY(-2px); } }
      }
    }
  }
  
  .rotating-icon { animation: rotate 2s linear infinite; }
  @keyframes rotate { 100% { transform: rotate(360deg); } }
  @keyframes shimmer { 100% { transform: translateX(300%); } }
  @keyframes zoomIn { from { transform: scale(0); } to { transform: scale(1); } }
  @keyframes bounceIn { 0% { transform: scale(0); } 50% { transform: scale(1.2); } 100% { transform: scale(1); } }
  @keyframes slideUp { from { opacity:0; transform:translateY(30px); } to { opacity:1; transform:translateY(0); } }
  @keyframes pulse { 0% { box-shadow:0 0 20px rgba(var(--theme-color-rgb),0.7); } 50% { box-shadow:0 0 40px rgba(var(--theme-color-rgb),0.9); } 100% { box-shadow:0 0 20px rgba(var(--theme-color-rgb),0.7); } }
  @keyframes spin { to { transform: rotate(360deg); } }
  
  @media (max-width: 768px) {
    padding-bottom: 100px;
    .right-column { margin-bottom: 60px; }
  }
  @media (max-width: 480px) {
    padding-bottom: 120px;
    .right-column { margin-bottom: 90px; }
    .product-info .info-row, .order-info .info-row { flex-direction: row !important; align-items: center; justify-content: space-between; gap: 8px; .info-label { width: auto !important; min-width: 85px; } }
    .action-buttons {
      gap: 10px;
      margin-top: 20px;
      margin-bottom: 12px;

      .btn-back,
      .btn-pay,
      .btn-check {
        height: 44px;
        padding: 0 12px;
        gap: 6px;
        font-size: 13px;
        white-space: nowrap;
      }
    }
  }
}

/* 全局暗黑模式样式：卡片背景强制 #1e293b，订单状态卡片透明 */
.dark .section-wrapper,
.dark-theme .section-wrapper,
.dark .payment-methods .payment-method-item,
.dark-theme .payment-methods .payment-method-item,
.dark .modal-card,
.dark-theme .modal-card,
.dark .cancel-modal-content,
.dark-theme .cancel-modal-content,
.dark .free-notice,
.dark-theme .free-notice {
  background-color: #1e293b !important;
}

.dark .skeleton-text,
.dark .skeleton-payment-method,
.dark-theme .skeleton-text,
.dark-theme .skeleton-payment-method {
  background-color: rgba(255, 255, 255, 0.08) !important;
}

/* 暗黑模式下订单状态卡片透明，文字颜色适配套状态色（但仍保留状态区分） */
.dark .order-status-notice,
.dark-theme .order-status-notice {
  background: transparent !important;
  border: none !important;
  box-shadow: none !important;
}

/* 暗黑模式下状态标题颜色继承亮模式的色值（通常亮模式下的颜色在深色背景上也能看清） */
.dark .order-status-notice .status-text p,
.dark-theme .order-status-notice .status-text p {
  color: #94a3b8;
}
</style>
