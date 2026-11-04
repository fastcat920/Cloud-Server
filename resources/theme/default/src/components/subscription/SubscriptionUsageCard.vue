<template>
  <section class="client-subscription-card" :class="{ loading, expired: isExpired }">
    <div v-if="loading" class="subscription-skeleton" aria-busy="true">
      <span></span><span></span><span></span>
    </div>
    <template v-else>
      <h2 class="subscription-plan-name">
        {{ planName || $t('dashboard.noSubscription') }}
      </h2>
      <p class="subscription-expiry" :class="{ warning: isExpiringSoon, danger: isExpired }">
        <template v-if="isExpired">{{ $t('dashboard.expiredAt', { date: expiryDate || $t('dashboard.none') }) }}</template>
        <template v-else>
          {{ $t('dashboard.expiryDate') }}
          {{ isPermanent ? $t('dashboard.permanent') : (expiryDate || $t('dashboard.none')) }}
          {{ $t('dashboard.expiryDate1') }}
          {{ $t('dashboard.remainingDays') }}
          {{ isRemainingDaysPermanent ? $t('dashboard.permanent') : `${remainingDays}${$t('dashboard.days')}` }}
          {{ $t('dashboard.remainingDays1') }}
        </template>
      </p>
      <p v-if="showNewPeriodHint" class="subscription-reset recovery-hint">
        {{ $t('dashboard.newPeriodTrafficExhausted') }}
      </p>
      <p v-else-if="!isExpired && resetDay !== null && resetDay !== undefined" class="subscription-reset">
        {{ Number(resetDay) === 0
          ? $t('dashboard.trafficResetToday')
          : `${$t('dashboard.nextResetTime')}${resetDay} ${$t('dashboard.days')}${$t('dashboard.nextResetTime1')}` }}
      </p>

      <div class="subscription-progress-row">
        <div class="subscription-progress-track">
          <span :style="{ width: `${safePercent}%`, backgroundColor: progressColor }"></span>
        </div>
        <strong :style="{ color: progressColor }">{{ safePercent.toFixed(1) }}%</strong>
      </div>
      <p class="subscription-traffic" :style="{ color: trafficActionColor }">
        {{ $t('dashboard.usedTraffic') }}{{ usedTraffic || '0 GB' }}&nbsp;/&nbsp;
        {{ $t('dashboard.planTraffic') }}{{ totalTraffic || '0 GB' }}
      </p>

      <div v-if="showRenew || showReset" class="subscription-actions">
        <button
          v-if="showRenew"
          class="subscription-action renew"
          :class="{ warning: isExpiringSoon && !isExpired, danger: isExpired }"
          type="button"
          @click="$emit('renew')"
        >
          <IconRefresh :size="16" />
          <span>{{ $t('dashboard.renewPlan') }}</span>
        </button>
        <button
          v-if="showReset"
          class="subscription-action reset"
          :class="{
            warning: isLowTraffic && !isTrafficDepleted,
            danger: isTrafficDepleted
          }"
          type="button"
          @click="$emit('reset')"
        >
          <IconRotateClockwise :size="16" />
          <span>{{ trafficActionMode === 'new-period' ? $t('dashboard.startNewPeriod') : $t('dashboard.resetTraffic') }}</span>
        </button>
      </div>
    </template>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { IconRefresh, IconRotateClockwise } from '@tabler/icons-vue';

const props = defineProps({
  planName: { type: String, default: '' },
  expiryDate: { type: String, default: '' },
  isPermanent: { type: Boolean, default: false },
  remainingDays: { type: [String, Number], default: 0 },
  isRemainingDaysPermanent: { type: Boolean, default: false },
  resetDay: { type: [String, Number], default: null },
  usedPercent: { type: Number, default: 0 },
  usedTraffic: { type: String, default: '0 GB' },
  totalTraffic: { type: String, default: '0 GB' },
  isExpired: { type: Boolean, default: false },
  isExpiringSoon: { type: Boolean, default: false },
  isTrafficDepleted: { type: Boolean, default: false },
  isLowTraffic: { type: Boolean, default: false },
  showRenew: { type: Boolean, default: false },
  showReset: { type: Boolean, default: false },
  trafficActionMode: {
    type: String,
    default: 'reset',
    validator: value => ['reset', 'new-period'].includes(value)
  },
  loading: { type: Boolean, default: false }
});

defineEmits(['renew', 'reset']);

const safePercent = computed(() => Math.min(100, Math.max(0, Number(props.usedPercent) || 0)));
const showNewPeriodHint = computed(() => (
  !props.isExpired && props.isTrafficDepleted && props.trafficActionMode === 'new-period'
));
const trafficActionColor = computed(() => {
  if (props.isTrafficDepleted) return 'var(--danger-color, #ef4444)';
  if (props.isLowTraffic) return 'var(--warning-color, #f59e0b)';
  return undefined;
});
const progressColor = computed(() => {
  if (props.isTrafficDepleted || safePercent.value >= 100) return 'var(--danger-color, #ef4444)';
  if (props.isLowTraffic || safePercent.value >= 90) return 'var(--warning-color, #f59e0b)';
  return 'var(--theme-color)';
});
</script>

<style lang="scss" scoped>
.client-subscription-card {
  padding: 14px 16px 16px;
  background: var(--card-background);
  border: 1px solid var(--card-border);
  border-radius: 20px;
  box-shadow: 0 4px 16px var(--card-shadow);
  color: var(--text-color);
}

.subscription-plan-name {
  margin: 0;
  color: var(--heading-color);
  font-size: 16px;
  font-weight: 600;
  line-height: 1.35;
}

.subscription-expiry,
.subscription-reset {
  margin: 5px 0 0;
  color: var(--supporting-text-color);
  font-size: 12px;
  line-height: 1.45;
}

.subscription-traffic {
  margin: 5px 0 0;
  color: var(--text-color);
  font-size: 12px;
  line-height: 1.45;
}

.subscription-expiry.warning { color: var(--warning-color, #f59e0b); font-weight: 700; }
.subscription-expiry.danger { color: var(--danger-color, #ef4444); font-weight: 700; }

.subscription-progress-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 12px;

  strong { flex: 0 0 auto; font-size: 12px; }
}

.subscription-progress-track {
  flex: 1;
  height: 6px;
  overflow: hidden;
  background: var(--progress-background, rgba(var(--theme-color-rgb), 0.12));
  border-radius: 999px;

  span { display: block; height: 100%; border-radius: inherit; transition: width .25s ease; }
}

.subscription-traffic { color: var(--text-color); font-weight: 600; }

.subscription-actions {
  display: flex;
  gap: 10px;
  margin-top: 14px;
}

.subscription-action {
  display: inline-flex;
  flex: 1;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 40px;
  padding: 8px 12px;
  color: #fff;
  background: var(--theme-color);
  border: 0;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
}

.subscription-action.reset {
  background: var(--theme-color);
}

.subscription-action.reset.warning {
  background: var(--warning-color, #f59e0b);
}

.subscription-action.reset.danger {
  background: var(--danger-color, #ef4444);
}

.subscription-action.renew.warning {
  background: var(--warning-color, #f59e0b);
}

.subscription-action.renew.danger {
  background: var(--danger-color, #ef4444);
}

.subscription-skeleton { display: grid; gap: 10px; }
.subscription-skeleton span { height: 12px; background: var(--skeleton-background); border-radius: 999px; animation: pulse 1.4s ease-in-out infinite; }
.subscription-skeleton span:nth-child(1) { width: 36%; }
.subscription-skeleton span:nth-child(2) { width: 68%; }
.subscription-skeleton span:nth-child(3) { width: 100%; }

@keyframes pulse { 50% { opacity: .45; } }

:global(body.dark-theme) .client-subscription-card { box-shadow: none; }

@media (max-width: 480px) {
  .client-subscription-card { padding: 12px 16px; }
  .subscription-progress-track { height: 5px; }
}
</style>
