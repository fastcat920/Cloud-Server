<template>
  <div class="gift-container">
    <div class="gift-inner">
      <!-- 礼品卡兑换卡片 -->
      <div class="dashboard-card">
        <div class="card-header">
          <h3>{{ $t('profile.giftCard') }}</h3>
        </div>
        <div class="settings-content">
          <div class="gift-card-form">
            <div class="input-group">
              <input
                type="text"
                v-model="giftCardCode"
                :placeholder="$t('profile.giftCardPlaceholder')"
                class="gift-card-input"
              />
              <button
                class="gift-card-btn"
                @click="redeemGiftCard"
                :disabled="isRedeeming || !giftCardCode"
              >
                <span v-if="isRedeeming" class="loader"></span>
                <IconGift v-else :size="18" />
                {{ $t('profile.giftCardSubmit') }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- 使用说明与兑换记录切换卡片 -->
      <div class="dashboard-card gift-content-card">
        <div class="gift-tabs" role="tablist" :aria-label="$t('profile.giftCard')">
          <button
            id="gift-instructions-tab"
            type="button"
            role="tab"
            :aria-selected="activeTab === 'instructions'"
            aria-controls="gift-instructions-panel"
            :class="{ active: activeTab === 'instructions' }"
            @click="activeTab = 'instructions'"
          >
            {{ $t('profile.giftCardUseTitle') }}
          </button>
          <button
            id="gift-records-tab"
            type="button"
            role="tab"
            :aria-selected="activeTab === 'records'"
            aria-controls="gift-records-panel"
            :class="{ active: activeTab === 'records' }"
            @click="activeTab = 'records'"
          >
            {{ $t('profile.giftCardRedemptionHistory') }}
          </button>
        </div>

        <section
          v-show="activeTab === 'instructions'"
          id="gift-instructions-panel"
          class="gift-tab-panel instruction-panel"
          role="tabpanel"
          aria-labelledby="gift-instructions-tab"
        >
          <div class="instruction-content" v-html="$t('profile.giftCardUsecontent')"></div>
        </section>

        <section
          v-show="activeTab === 'records'"
          id="gift-records-panel"
          class="gift-tab-panel redemption-panel"
          role="tabpanel"
          aria-labelledby="gift-records-tab"
        >
          <div class="redemption-toolbar">
            <span>{{ $t('profile.giftCardRedemptionHistory') }}</span>
            <button class="refresh-button" type="button" :aria-label="$t('common.refresh')" :disabled="loadingRecords" @click="fetchRedemptions(currentPage)">
              <IconRefresh :size="17" :class="{ spinning: loadingRecords }" />
            </button>
          </div>

          <div v-if="loadingRecords && redemptions.length === 0" class="record-state">
            <span class="record-loader"></span>
            <p>{{ $t('profile.giftCardRecordsLoading') }}</p>
          </div>
          <div v-else-if="recordsError" class="record-state error-state">
            <IconAlertTriangle :size="34" />
            <p>{{ recordsError }}</p>
            <button type="button" class="retry-button" @click="fetchRedemptions(currentPage)">{{ $t('common.retry') }}</button>
          </div>
          <div v-else-if="redemptions.length === 0" class="record-state">
            <IconHistory :size="38" />
            <p>{{ $t('profile.giftCardRecordsEmpty') }}</p>
          </div>
          <div v-else class="redemption-table-wrap">
            <table class="redemption-table">
              <thead>
                <tr>
                  <th>{{ $t('profile.giftCardRecordCode') }}</th>
                  <th>{{ $t('profile.giftCardRecordContent') }}</th>
                  <th>{{ $t('profile.giftCardRecordTime') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="record in redemptions" :key="record.id">
                  <td class="record-code">{{ record.code_masked || '—' }}</td>
                  <td class="record-content">{{ formatRedemptionValue(record) }}</td>
                  <td class="record-time">{{ formatRedemptionTime(record.redeemed_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="totalPages > 1 && !recordsError" class="record-pagination">
            <button type="button" :disabled="loadingRecords || currentPage <= 1" @click="fetchRedemptions(currentPage - 1)">
              <IconChevronLeft :size="17" />
            </button>
            <span>{{ currentPage }} / {{ totalPages }}</span>
            <button type="button" :disabled="loadingRecords || currentPage >= totalPages" @click="fetchRedemptions(currentPage + 1)">
              <IconChevronRight :size="17" />
            </button>
          </div>
        </section>
      </div>

    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconAlertTriangle, IconChevronLeft, IconChevronRight, IconGift, IconHistory, IconRefresh } from '@tabler/icons-vue';
import { useToast } from '@/composables/useToast';
import { getCommConfig, getGiftCardRedemptions, redeemGiftCard as apiRedeemGiftCard } from '@/api/user';

const { t, locale } = useI18n();
const { showToast } = useToast();

const giftCardCode = ref('');
const isRedeeming = ref(false);
const activeTab = ref('instructions');
const redemptions = ref([]);
const loadingRecords = ref(true);
const recordsError = ref('');
const currentPage = ref(1);
const totalRecords = ref(0);
const pageSize = 10;
const currencySymbol = ref('¥');
const totalPages = computed(() => Math.max(1, Math.ceil(totalRecords.value / pageSize)));

const formatRedemptionValue = record => {
  const type = Number(record.type);
  const value = Number(record.value) || 0;
  if (type === 1) return t('profile.giftCardAmountContent', { amount: `${currencySymbol.value}${(value / 100).toFixed(2)}` });
  if (type === 2) return t('profile.giftCardDurationContent', { days: value });
  if (type === 3) return t('profile.giftCardTrafficContent', { value });
  if (type === 4) return t('profile.giftCardTrafficReset');
  if (type === 5) {
    const duration = value === 0 ? t('profile.giftCardPermanent') : t('profile.giftCardDurationShort', { days: value });
    return record.plan_name ? t('profile.giftCardPlanContent', { plan: record.plan_name, duration }) : duration;
  }
  return '—';
};

const formatRedemptionTime = timestamp => {
  const value = Number(timestamp);
  if (!value) return '—';
  return new Intl.DateTimeFormat(locale.value, {
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit'
  }).format(new Date(value * 1000));
};

const fetchRedemptions = async (page = 1) => {
  if (loadingRecords.value && redemptions.value.length > 0) return;
  loadingRecords.value = true;
  recordsError.value = '';
  try {
    const response = await getGiftCardRedemptions({ current: page, pageSize });
    redemptions.value = Array.isArray(response?.data) ? response.data : [];
    totalRecords.value = Number(response?.total) || 0;
    currentPage.value = Number(response?.current) || page;
  } catch (err) {
    console.error('Failed to load gift card redemptions:', err);
    recordsError.value = err?.response?.message || err?.message || t('profile.giftCardRecordsError');
  } finally {
    loadingRecords.value = false;
  }
};

onMounted(async () => {
  fetchRedemptions(1);
  try {
    const response = await getCommConfig();
    currencySymbol.value = response?.data?.currency_symbol || '¥';
  } catch (err) {
    console.error('Failed to load currency config:', err);
  }
});

watch(locale, () => {
  fetchRedemptions(currentPage.value);
});

const giftCardErrorKeys = {
  'gift card cannot be empty': 'profile.giftCardEmpty',
  'the gift card does not exist': 'profile.giftCardNotFound',
  'the gift card is not yet valid': 'profile.giftCardNotActive',
  'the gift card has expired': 'profile.giftCardExpired',
  'the gift card usage limit has been reached': 'profile.giftCardLimitReached',
  'the gift card has already been used by this user': 'profile.giftCardAlreadyUsed',
  'not suitable gift card type': 'profile.giftCardTypeUnsupported',
  'unknown gift card type': 'profile.giftCardUnknownType',
  'save failed': 'profile.giftCardSaveFailed',
  'the user does not exist': 'profile.giftCardUserNotFound'
};

const getGiftCardErrorMessage = source => {
  const rawMessage = source?.response?.data?.message
    || source?.response?.message
    || source?.message
    || (typeof source === 'string' ? source : '');
  const normalizedMessage = String(rawMessage).trim().replace(/[.!。！]+$/, '').toLowerCase();
  const translationKey = giftCardErrorKeys[normalizedMessage];

  if (translationKey) return t(translationKey);
  if (/[\u3400-\u9fff]/.test(rawMessage)) return rawMessage;
  if (String(locale.value).toLowerCase().startsWith('en') && rawMessage) return rawMessage;
  return t('profile.giftCardError');
};

const redeemGiftCard = async () => {
  const code = giftCardCode.value.trim();
  if (!code) {
    showToast(t('profile.giftCardEmpty'), 'error');
    return;
  }

  isRedeeming.value = true;

  try {
    const response = await apiRedeemGiftCard(code);

    if (response === true || response?.data === true || response?.success === true) {
      showToast(t('profile.giftCardSuccess'), 'success');
      giftCardCode.value = '';
      await fetchRedemptions(1);
    } else {
      showToast(getGiftCardErrorMessage(response), 'error');
    }
  } catch (err) {
    console.error('Failed to redeem gift card:', err);
    showToast(getGiftCardErrorMessage(err), 'error');
  } finally {
    isRedeeming.value = false;
  }
};
</script>

<style lang="scss" scoped>
.gift-container {
  padding: 1.25rem;
  padding-bottom: calc(1.25rem + 70px);

  @media (min-width: 768px) {
    padding: 2rem 20px;
    padding-bottom: 3rem;
  }
}

.gift-inner {
  max-width: 900px;
  margin: 0 auto;
}

/* 卡片通用样式（亮色 #ffffff） */
.dashboard-card {
  background-color: #ffffff;
  border-radius: 20px;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
  margin-bottom: 24px;
  border: 1px solid var(--card-border);
  transition: color 0.3s ease, background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, transform 0.3s ease;
  overflow: hidden;

  &:hover {
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    border-color: rgba(var(--theme-color-rgb), 0.3);
  }

  .card-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    background-color: rgba(var(--theme-color-rgb), 0.03);

    h3 {
      font-size: 16px;
      font-weight: 600;
      margin: 0;
      color: var(--text-color);
    }
  }

  .settings-content {
    padding: 16px 20px;
  }
}

/* 兑换表单 */
.gift-card-form {
  .input-group {
    display: flex;
    gap: 12px;

    @media (max-width: 576px) {
      flex-direction: column;
      gap: 12px;
    }

    .gift-card-input {
      flex: 1;
      padding: 10px 12px;
      border: 1px solid var(--card-border);
      border-radius: 20px;
      background-color: var(--bg-secondary);
      color: var(--text-color);
      font-size: 15px;
      transition: color 0.3s ease, background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, transform 0.3s ease;

      &:focus {
        outline: none;
        border-color: var(--theme-color);
        box-shadow: 0 0 0 3px rgba(var(--theme-color-rgb), 0.1);
      }
    }

    .gift-card-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 10px 16px;
      border-radius: 20px;
      background-color: var(--theme-color);
      color: white;
      border: none;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      transition: color 0.3s ease, background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, transform 0.3s ease;

      @media (max-width: 576px) {
        width: 100%;
        padding: 12px 16px;
      }

      &:hover:not(:disabled) {
        background-color: rgba(var(--theme-color-rgb), 0.9);
        transform: translateY(-2px);
      }

      &:disabled {
        opacity: 0.7;
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

/* 使用说明与兑换记录切换 */
.gift-content-card {
  padding: 6px 20px 20px;
}

.gift-tabs {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 4px;
  margin: 0 -14px 18px;
  padding: 6px;
  background: rgba(var(--theme-color-rgb), .06);
  border-radius: 12px;

  button {
    display: flex;
    min-height: 40px;
    align-items: center;
    justify-content: center;
    padding: 8px 12px;
    color: var(--secondary-text-color);
    background: transparent;
    border: 0;
    border-radius: 8px;
    font: inherit;
    font-weight: 500;
    cursor: pointer;
    transition: color .2s ease, background-color .2s ease, box-shadow .2s ease;

    &:hover { color: var(--theme-color); }
    &:focus-visible { outline: 3px solid rgba(var(--theme-color-rgb), .22); outline-offset: 2px; }
    &.active { color: #fff; background: var(--theme-color); box-shadow: 0 4px 12px rgba(var(--theme-color-rgb), .18); }
  }
}

.gift-tab-panel { min-width: 0; }
.instruction-panel { padding: 4px 0 0; }

.redemption-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 36px;
  padding: 0 0 8px;

  > span {
    color: var(--heading-color);
    font-size: 14px;
    font-weight: 600;
  }
}

.refresh-button {
  width: 34px;
  height: 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  color: var(--theme-color);
  background: rgba(var(--theme-color-rgb), .09);
  border: 0;
  border-radius: 10px;
  cursor: pointer;

  &:disabled { cursor: wait; opacity: .65; }
  .spinning { animation: spin .8s linear infinite; }
}

.record-state {
  min-height: 150px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 24px;
  color: var(--secondary-text-color);
  text-align: center;

  p { margin: 0; font-size: 14px; }
  &.error-state svg { color: var(--error-color); }
  .retry-button {
    min-height: 34px;
    padding: 0 14px;
    color: var(--theme-color);
    background: rgba(var(--theme-color-rgb), .1);
    border: 0;
    border-radius: 10px;
    cursor: pointer;
  }
}

.record-loader {
  width: 25px;
  height: 25px;
  border: 3px solid rgba(var(--theme-color-rgb), .18);
  border-top-color: var(--theme-color);
  border-radius: 50%;
  animation: spin .8s linear infinite;
}

.redemption-table-wrap {
  padding: 14px 20px 18px;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.redemption-table {
  width: 100%;
  min-width: 560px;
  border-collapse: collapse;
  table-layout: fixed;

  th,
  td {
    padding: 13px 14px;
    color: var(--text-color);
    font-size: 14px;
    line-height: 1.5;
    text-align: left;
    vertical-align: middle;
    border-bottom: 1px solid var(--border-color);
  }

  th {
    color: var(--heading-color);
    font-weight: 700;
    background: rgba(var(--text-color-rgb), .025);
    white-space: nowrap;
  }

  th:nth-child(1) { width: 32%; }
  th:nth-child(2) { width: 35%; }
  th:nth-child(3) { width: 33%; }

  tbody tr {
    transition: background-color .2s ease;
    &:hover { background: rgba(var(--theme-color-rgb), .04); }
    &:last-child td { border-bottom: 0; }
  }

  .record-code {
    color: var(--heading-color);
    font-size: 15px;
    font-weight: 600;
    white-space: nowrap;
  }
  .record-content { color: var(--heading-color); font-weight: 500; }
  .record-time { color: var(--secondary-text-color); white-space: nowrap; }
}

.record-pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 14px 20px;
  border-top: 1px solid var(--border-color);

  span { color: var(--secondary-text-color); font-size: 13px; }
  button {
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    color: var(--text-color);
    background: rgba(var(--text-color-rgb), .05);
    border: 0;
    border-radius: 9px;
    cursor: pointer;

    &:hover:not(:disabled) { color: var(--theme-color); background: rgba(var(--theme-color-rgb), .1); }
    &:disabled { cursor: not-allowed; opacity: .4; }
  }
}

@media (max-width: 576px) {
  .gift-content-card { padding-right: 16px; padding-left: 16px; }
  .gift-tabs { margin-right: -10px; margin-left: -10px; }

  .redemption-table-wrap {
    padding: 0;
    overflow: visible;
  }

  .redemption-table {
    min-width: 0;
    table-layout: auto;

    thead { display: none; }
    tbody { display: block; }

    tr {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, auto);
      align-items: center;
      column-gap: 14px;
      row-gap: 6px;
      padding: 14px 0;
      border-bottom: 1px solid var(--border-color);

      &:last-child { border-bottom: 0; }
    }

    td {
      min-width: 0;
      padding: 0;
      border: 0;
      line-height: 1.45;
    }

    .record-code,
    .record-content {
      color: var(--heading-color);
      font-size: 15px;
      font-weight: 600;
    }

    .record-code {
      overflow: hidden;
      align-self: center;
      grid-column: 1;
      grid-row: 1 / 3;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .record-content {
      grid-column: 2;
      grid-row: 1;
      text-align: right;
      white-space: nowrap;
    }

    .record-time {
      color: var(--secondary-text-color);
      font-size: 12px;
      font-weight: 400;
    }

    .record-time {
      grid-column: 1 / 3;
      grid-row: 2;
      text-align: right;
      white-space: nowrap;
    }
  }
}

/* 说明卡片内容样式 */
.instruction-content {
  font-size: 14px;
  line-height: 1.6;
  color: var(--text-color);
  
  :deep(p) {
    margin-bottom: 0.75rem;
  }
  
  :deep(ul), :deep(ol) {
    margin: 0.5rem 0;
    padding-left: 1.5rem;
  }
  
  :deep(li) {
    margin-bottom: 0.25rem;
  }
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>

<!-- 全局暗黑模式覆盖（非 scoped） -->
<style lang="scss">
.dark .dashboard-card,
.dark-theme .dashboard-card {
  background-color: #1e293b !important;
}
</style>
