<template>
  <main class="wallet-page">
    <header class="page-intro">
      <div>
        <span class="eyebrow">{{ copy.eyebrow }}</span>
        <h1>{{ copy.title }}</h1>
        <p>{{ copy.description }}</p>
      </div>
      <button type="button" class="refresh-button" :disabled="loading" @click="load">
        {{ loading ? copy.loading : copy.refresh }}
      </button>
    </header>

    <div class="tabs" role="tablist" :aria-label="copy.title">
      <button
        v-for="item in tabs"
        :key="item.key"
        type="button"
        role="tab"
        :aria-selected="tab === item.key"
        :class="{ active: tab === item.key }"
        @click="tab = item.key"
      >
        {{ item.label }} <span>{{ count(item.key) }}</span>
      </button>
    </div>

    <div v-if="loading" class="state-card">{{ copy.loading }}</div>
    <div v-else-if="error" class="state-card error">{{ error }}</div>
    <div v-else-if="!filtered.length" class="state-card">{{ copy.empty }}</div>
    <section v-else class="coupon-list" :aria-label="copy.title">
      <article v-for="coupon in filtered" :key="coupon.id" class="coupon-card" :class="coupon.status">
        <div class="coupon-value">
          <strong>{{ discount(coupon.template) }}</strong>
          <span>{{ couponType(coupon.template) }}</span>
          <span class="mobile-status-badge">{{ statusText(coupon.status) }}</span>
        </div>

        <div class="coupon-content">
          <div class="coupon-heading">
            <div>
              <h2>{{ localized(coupon.template, 'name') }}</h2>
              <p v-if="couponDescription(coupon.template)">{{ couponDescription(coupon.template) }}</p>
            </div>
            <span class="status-badge">{{ statusText(coupon.status) }}</span>
          </div>

          <dl class="coupon-rules">
            <div>
              <dt>{{ copy.plans }}</dt>
              <dd class="tag-list">
                <span v-for="item in planLabels(coupon.template)" :key="item">{{ item }}</span>
              </dd>
            </div>
            <div>
              <dt>{{ copy.periods }}</dt>
              <dd class="tag-list">
                <span v-for="item in periodLabels(coupon.template)" :key="item">{{ item }}</span>
              </dd>
            </div>
            <div v-if="restrictionLabels(coupon.template).length">
              <dt>{{ copy.restrictions }}</dt>
              <dd class="tag-list restrictions">
                <span v-for="item in restrictionLabels(coupon.template)" :key="item">{{ item }}</span>
              </dd>
            </div>
          </dl>

          <div class="coupon-meta">
            <span>{{ copy.validity }}：{{ date(coupon.starts_at) }} – {{ date(coupon.expires_at) }}</span>
            <span>{{ copy.source }}：{{ sourceText(coupon.source) }}</span>
          </div>
        </div>

        <div class="coupon-action">
          <button v-if="coupon.status === 'available'" type="button" @click="useCoupon">
            {{ copy.useNow }}
          </button>
          <button
            v-else-if="coupon.status === 'locked'"
            type="button"
            :disabled="!lockedOrderTradeNo(coupon)"
            @click="viewLockedOrder(coupon)"
          >
            {{ copy.viewOrder }}
          </button>
        </div>
      </article>
    </section>
  </main>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { fetchCouponWallet, fetchPlans, getCommConfig } from '@/api/shop';
import { formatCouponValue, isPercentageCoupon } from '@/utils/coupon';

const { locale } = useI18n();
const router = useRouter();
const coupons = ref([]);
const plans = ref([]);
const currencySymbol = ref('¥');
const loading = ref(true);
const error = ref('');
const tab = ref('available');
const en = computed(() => locale.value === 'en-US');

const copy = computed(() => en.value ? {
  eyebrow: 'Account benefits',
  title: 'My coupons',
  description: 'Eligible coupons are selected automatically at checkout, and you can choose not to use one.',
  refresh: 'Refresh',
  loading: 'Loading…',
  empty: 'No coupons in this category',
  source: 'Source',
  validity: 'Valid',
  plans: 'Plans',
  periods: 'Billing periods',
  restrictions: 'Conditions',
  allPlans: 'All plans',
  allPeriods: 'All periods',
  firstOrder: 'First order only',
  notStackable: 'Cannot stack with membership-level discount',
  fixedCoupon: 'Amount coupon',
  percentCoupon: 'Discount coupon',
  useNow: 'Use now',
  viewOrder: 'View'
} : {
  eyebrow: '账户权益',
  title: '我的优惠券',
  description: '购买套餐时会自动推荐符合条件的优惠券，也可以选择不使用。',
  refresh: '刷新',
  loading: '加载中…',
  empty: '暂无此类优惠券',
  source: '来源',
  validity: '有效期',
  plans: '适用套餐',
  periods: '适用周期',
  restrictions: '限制条件',
  allPlans: '全部套餐',
  allPeriods: '全部周期',
  firstOrder: '仅限首单',
  notStackable: '不可叠加会员等级折扣',
  fixedCoupon: '金额券',
  percentCoupon: '折扣券',
  useNow: '去使用',
  viewOrder: '查看'
});

const tabs = computed(() => [
  { key: 'available', label: en.value ? 'Available' : '可用' },
  { key: 'pending', label: en.value ? 'Upcoming' : '待生效' },
  { key: 'used', label: en.value ? 'Used' : '已使用' },
  { key: 'expired', label: en.value ? 'Expired' : '已过期' }
]);

const periodNames = computed(() => en.value ? {
  month_price: 'Monthly',
  quarter_price: 'Quarterly',
  half_year_price: 'Half-year',
  year_price: 'Yearly',
  two_year_price: '2 years',
  three_year_price: '3 years',
  onetime_price: 'One-time'
} : {
  month_price: '月付',
  quarter_price: '季度',
  half_year_price: '半年',
  year_price: '一年',
  two_year_price: '两年',
  three_year_price: '三年',
  onetime_price: '一次性'
});

const groupedStatus = status => {
  if (status === 'locked') return 'available';
  if (status === 'revoked') return 'expired';
  return status;
};
const filtered = computed(() => coupons.value.filter(item => groupedStatus(item.status) === tab.value));
const count = key => coupons.value.filter(item => groupedStatus(item.status) === key).length;
const localized = (row, key) => !row ? '' : (en.value ? row[`${key}_en`] : row[key]) || row[key] || row[`${key}_en`] || '';
const couponDescription = template => String(localized(template, 'description') || '').trim();
const discount = item => formatCouponValue(item, {
  currency: currencySymbol.value,
  percentSuffix: en.value ? '% OFF' : '%'
});
const couponType = item => isPercentageCoupon(item) ? copy.value.percentCoupon : copy.value.fixedCoupon;
const date = value => value ? new Date(Number(value) * 1000).toLocaleDateString(en.value ? 'en-US' : 'zh-CN') : '—';
const statusText = status => ({
  available: en.value ? 'Available' : '可用',
  locked: en.value ? 'Reserved' : '已锁定',
  pending: en.value ? 'Upcoming' : '待生效',
  used: en.value ? 'Used' : '已使用',
  expired: en.value ? 'Expired' : '已过期',
  revoked: en.value ? 'Revoked' : '已撤销'
}[status] || status);
const sourceText = source => ({
  manual: en.value ? 'Manual grant' : '后台手动发放',
  newcomer: en.value ? 'Newcomer reward' : '新人邀请奖励',
  referral_newcomer: en.value ? 'Newcomer reward' : '新人邀请奖励',
  distribution_task: en.value ? 'Platform distribution' : '平台批量发放',
  campaign: en.value ? 'Campaign reward' : '活动奖励'
}[source] || source || '—');

const planLabels = template => {
  const ids = Array.isArray(template?.plan_ids) ? template.plan_ids.map(Number) : [];
  if (!ids.length) return [copy.value.allPlans];
  return ids.map(id => plans.value.find(plan => Number(plan.id) === id)?.name || `#${id}`);
};
const periodLabels = template => {
  const periods = Array.isArray(template?.periods) ? template.periods : [];
  if (!periods.length) return [copy.value.allPeriods];
  return periods.map(period => periodNames.value[period] || period);
};
const restrictionLabels = template => {
  const restrictions = [];
  if (Number(template?.first_order_only) === 1) restrictions.push(copy.value.firstOrder);
  if (Number(template?.stackable) !== 1) restrictions.push(copy.value.notStackable);
  return restrictions;
};
const useCoupon = () => router.push('/shop');
const lockedOrderTradeNo = coupon => coupon?.locked_order_trade_no || coupon?.locked_trade_no || '';
const viewLockedOrder = coupon => {
  const tradeNo = lockedOrderTradeNo(coupon);
  if (!tradeNo) return;
  router.push({
    path: '/payment',
    query: { trade_no: tradeNo, from: 'coupons' }
  });
};

const load = async () => {
  loading.value = true;
  error.value = '';
  const [walletResult, planResult, configResult] = await Promise.allSettled([
    fetchCouponWallet(),
    fetchPlans(),
    getCommConfig()
  ]);

  if (walletResult.status === 'fulfilled') {
    coupons.value = Array.isArray(walletResult.value.data) ? walletResult.value.data : [];
  } else {
    const reason = walletResult.reason;
    error.value = reason?.response?.message || reason?.message || (en.value ? 'Failed to load coupons' : '优惠券加载失败');
  }
  plans.value = planResult.status === 'fulfilled' && Array.isArray(planResult.value.data) ? planResult.value.data : [];
  if (configResult.status === 'fulfilled') currencySymbol.value = configResult.value.data?.currency_symbol || '¥';
  loading.value = false;
};

onMounted(load);
watch(locale, load);
</script>

<style scoped lang="scss">
.wallet-page {
  width: 100%;
  max-width: 900px;
  margin: 0 auto;
  padding: 18px 20px 48px;
  color: var(--text-color);
}

.page-intro {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 24px;
  margin-bottom: 24px;

  .eyebrow { color: var(--theme-color); font-size: 13px; font-weight: 700; }
  h1 { margin: 6px 0; color: var(--heading-color); font-size: 30px; }
  p { max-width: 680px; margin: 0; color: var(--secondary-text-color); }
}

.refresh-button,
.coupon-action button {
  min-height: 42px;
  border: 0;
  border-radius: 12px;
  background: var(--theme-color);
  color: #fff;
  font-weight: 700;
  cursor: pointer;
  transition: opacity var(--motion-fast) ease, transform var(--motion-fast) ease, box-shadow var(--motion-fast) ease;

  &:hover { box-shadow: 0 8px 18px rgba(var(--theme-color-rgb), .22); transform: translateY(-1px); }
  &:focus-visible { outline: 3px solid rgba(var(--theme-color-rgb), .28); outline-offset: 3px; }
  &:disabled { cursor: wait; opacity: .6; transform: none; }
}
.refresh-button { flex: 0 0 auto; padding: 10px 18px; }

.tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 20px;
  overflow-x: auto;
  scrollbar-width: none;

  &::-webkit-scrollbar { display: none; }
  button {
    min-height: 42px;
    padding: 9px 15px;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    background: var(--card-background);
    color: var(--text-color);
    white-space: nowrap;
    cursor: pointer;
  }
  button:focus-visible { outline: 3px solid rgba(var(--theme-color-rgb), .22); outline-offset: 2px; }
  button.active { border-color: var(--theme-color); background: var(--theme-color); color: #fff; }
  span { margin-left: 5px; opacity: .72; }
}

.coupon-list { display: grid; gap: 16px; }
.coupon-card {
  display: grid;
  grid-template-columns: 158px minmax(0, 1fr) 112px;
  min-height: 224px;
  overflow: hidden;
  border: 1px solid var(--border-color);
  border-radius: 20px;
  background: var(--card-background);
  box-shadow: 0 8px 24px rgba(28, 42, 72, .06);
}

.coupon-value {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-width: 0;
  padding: 24px 16px;
  background: linear-gradient(145deg, var(--theme-color), rgba(var(--theme-color-rgb), .74));
  color: #fff;
  text-align: center;

  &::before,
  &::after {
    position: absolute;
    right: -9px;
    width: 18px;
    height: 18px;
    border: 1px solid var(--border-color);
    border-radius: 50%;
    background: var(--background-color);
    content: '';
  }
  &::before { top: -9px; }
  &::after { bottom: -9px; }
  strong { font-size: clamp(25px, 3vw, 34px); line-height: 1; white-space: nowrap; }
  span { margin-top: 10px; font-size: 12px; font-weight: 600; opacity: .86; }
}

.coupon-content { min-width: 0; padding: 22px 24px; }
.coupon-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;

  h2 { margin: 0; color: var(--heading-color); font-size: 18px; line-height: 1.35; }
  p { margin: 6px 0 0; color: var(--secondary-text-color); font-size: 13px; line-height: 1.6; }
}
.status-badge {
  flex: 0 0 auto;
  padding: 4px 9px;
  border-radius: 999px;
  background: rgba(var(--theme-color-rgb), .1);
  color: var(--theme-color);
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
}
.mobile-status-badge { display: none; }

.coupon-rules {
  display: grid;
  gap: 9px;
  margin: 18px 0 0;

  > div { display: grid; grid-template-columns: 76px minmax(0, 1fr); align-items: start; gap: 10px; }
  dt { padding-top: 4px; color: var(--secondary-text-color); font-size: 12px; }
  dd { margin: 0; }
}
.tag-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;

  span {
    padding: 3px 8px;
    border-radius: 7px;
    background: rgba(var(--theme-color-rgb), .08);
    color: var(--text-color);
    font-size: 12px;
    line-height: 1.45;
  }
  &.restrictions span { border: 1px solid rgba(var(--theme-color-rgb), .12); background: rgba(var(--theme-color-rgb), .05); }
}
.coupon-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 18px;
  margin-top: 16px;
  color: var(--secondary-text-color);
  font-size: 12px;
}

.coupon-action {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px 20px 20px 0;

  button { width: 92px; padding: 10px 14px; }
}
.coupon-card.used,
.coupon-card.expired,
.coupon-card.revoked {
  opacity: .66;
  filter: saturate(.18);
}
.coupon-card.pending .coupon-value,
.coupon-card.locked .coupon-value { filter: saturate(.5); }
.state-card {
  padding: 50px;
  border: 1px solid var(--border-color);
  border-radius: 18px;
  background: var(--card-background);
  text-align: center;

  &.error { color: #d94b4b; }
}

@media (max-width: 760px) {
  .wallet-page { padding: 8px 10px 100px; }
  .page-intro {
    align-items: flex-start;
    h1 { font-size: 25px; }
    p { font-size: 13px; }
  }
  .refresh-button { padding-inline: 14px; }
  .tabs {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 6px;
    overflow: visible;

    button {
      min-width: 0;
      padding: 8px 4px;
      font-size: 13px;
    }
    span { margin-left: 3px; }
  }
  .coupon-card {
    grid-template-columns: minmax(0, 1fr);
    grid-template-areas: 'value' 'content' 'action';
    min-height: 0;
  }
  .coupon-value {
    grid-area: value;
    align-items: flex-start;
    min-height: 96px;
    padding: 20px;
    text-align: left;

    &::before {
      top: auto;
      right: auto;
      bottom: -9px;
      left: -9px;
    }
    &::after {
      top: auto;
      right: -9px;
      bottom: -9px;
    }
  }
  .coupon-value strong { font-size: 30px; }
  .coupon-value > span:not(.mobile-status-badge) { margin-top: 7px; }
  .coupon-value .mobile-status-badge {
    position: absolute;
    top: 18px;
    right: 18px;
    display: inline-flex;
    align-items: center;
    min-height: 28px;
    margin: 0;
    padding: 4px 10px;
    border: 1px solid rgba(255, 255, 255, .3);
    border-radius: 999px;
    background: rgba(255, 255, 255, .16);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    opacity: 1;
  }
  .coupon-content { grid-area: content; padding: 18px 16px 12px; }
  .coupon-heading { gap: 8px; }
  .coupon-heading h2 { font-size: 16px; }
  .coupon-heading p { font-size: 12px; }
  .status-badge { display: none; }
  .coupon-rules { margin-top: 14px; }
  .coupon-rules > div { grid-template-columns: 64px minmax(0, 1fr); gap: 6px; }
  .coupon-meta { flex-direction: column; gap: 4px; }
  .coupon-action { grid-area: action; padding: 4px 16px 16px; }
  .coupon-action:empty { display: none; }
  .coupon-action button { width: 100%; min-height: 44px; }
}

@media (max-width: 390px) {
  .page-intro p { display: none; }
  .coupon-value { min-height: 90px; padding: 18px 16px; }
  .coupon-value strong { font-size: 27px; }
  .coupon-value .mobile-status-badge { top: 16px; right: 16px; }
  .coupon-content { padding-inline: 12px; }
  .coupon-rules > div { grid-template-columns: 60px minmax(0, 1fr); }
  .coupon-action { padding-inline: 12px; }
}

@media (prefers-reduced-motion: reduce) {
  .refresh-button,
  .coupon-action button { transition: none; }
}
</style>
