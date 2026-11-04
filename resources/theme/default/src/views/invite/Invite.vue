<template>
  <div class="account-container">
    <!-- 通用确认弹窗 -->
    <transition name="modal">
      <div v-if="showConfirmModal" class="custom-modal">
        <div class="modal-overlay" @click="cancelConfirmation"></div>
        <div v-accessible-dialog="cancelConfirmation" class="modal-container" :aria-label="$t('invite.confirm.title')">
          <div class="modal-header">
            <h3>{{ $t('invite.confirm.title') }}</h3>
            <button class="modal-close" type="button" :aria-label="$t('common.close')" @click="cancelConfirmation">
              <IconX />
            </button>
          </div>
          <div class="modal-body">
            <p>{{ confirmModalMessage }}</p>
          </div>
          <div class="modal-footer">
            <button class="btn-outline cancel-btn" @click="cancelConfirmation">
              {{ $t('invite.confirm.cancel') }}
            </button>
            <button class="btn-primary confirm-btn" @click="confirmAction">
              {{ $t('invite.confirm.confirm') }}
            </button>
          </div>
        </div>
      </div>
    </transition>
    
    <div class="account-inner">
      <section v-if="loading.referralProgram" class="growth-level-card growth-level-skeleton" aria-busy="true">
        <div class="level-skeleton-head"><i></i><span></span><b></b></div>
        <div class="level-skeleton-body"><span></span><span></span><span></span></div>
      </section>
      <section v-else-if="referralProgram" class="growth-level-card">
        <div class="current-level-block">
          <div class="current-level-title">
            <span class="level-icon"><IconCrown :size="25" /></span>
            <h2>{{ localizedLevel(referralProgram.level) || '—' }}</h2>
          </div>
          <div class="level-benefits">
            <span>{{ $t('invite.commissionRate') }}：<strong>{{ currentCommissionRate }}%</strong></span>
            <span>{{ $t('invite.planDiscount') }}：<strong>{{ referralProgram.level?.member_discount || 0 }}%</strong></span>
          </div>
        </div>

        <div v-if="referralProgram.next_level" class="next-level-block">
          <div class="next-level-head">
            <div><span>{{ $t('invite.nextLevel') }}：</span><strong>{{ localizedLevel(referralProgram.next_level) }}</strong></div>
            <span>{{ $t('invite.effectiveInviteProgress') }} <strong>{{ referralProgram.effective_invites || 0 }} / {{ referralProgram.next_level.required_invites || 0 }}</strong></span>
          </div>
          <div class="growth-progress"><i :style="{ width: nextLevelProgress + '%' }"></i></div>
          <div v-if="referralProgram.next_level.reward" class="level-meta-row level-detail"><b>{{ $t('invite.achievementReward') }}</b><span>{{ achievementReward.label }}：<strong>{{ achievementReward.value }}</strong></span></div>
          <div class="level-meta-row next-level-privileges">
            <b>{{ $t('invite.levelPrivileges') }}</b>
            <div class="level-privilege-values">
              <span>{{ $t('invite.commissionRate') }}：<strong>{{ referralProgram.next_level.commission_rate || 0 }}%</strong></span>
              <span>{{ $t('invite.planDiscount') }}：<strong>{{ referralProgram.next_level.member_discount || 0 }}%</strong></span>
            </div>
          </div>
          <p v-if="Number(referralProgram.next_level.required_revenue || 0)" class="level-revenue-condition">{{ $t('invite.revenueProgress') }}：{{ currencySymbol }}{{ formatAmount(referralProgram.referral_revenue || 0) }} / {{ currencySymbol }}{{ formatAmount(referralProgram.next_level.required_revenue) }}</p>
        </div>
        <div v-else class="highest-level-tip">{{ $t('invite.highestLevel') }}</div>
      </section>

      <!-- FastCatAPP 双余额卡 -->
      <div class="invite-balance-grid">
        <div class="invite-balance-card">
          <div class="balance-card-title"><IconCurrencyDollar :size="14" />{{ $t('invite.balance.title') }}</div>
          <div class="balance-value">{{ currencySymbol }}{{ formatAmount(inviteStats.availableCommission) }}</div>
        </div>
        <div class="invite-balance-card">
          <div class="balance-card-title"><IconBuildingBank :size="14" />{{ $t('common.myWallet') }}</div>
          <div class="balance-value">{{ currencySymbol }}{{ formatAmount(walletBalance) }}</div>
        </div>
      </div>

      <div class="invite-primary-actions" :class="{ 'single-action': withdrawClose !== 0 }">
        <button v-if="withdrawClose === 0" class="btn-primary" @click="toggleWithdrawCard">
          <IconBuildingBank :size="18" />{{ $t('invite.balance.withdraw') }}
        </button>
        <button class="btn-primary transfer-action" @click="toggleTransferCard">
          <IconArrowsExchange :size="18" />{{ $t('invite.balance.transferToBalance') }}
        </button>
      </div>

      <!-- 统计卡片组 -->
      <div class="invite-section-title"><IconChartBar :size="18" />{{ $t('invite.statsTitle') }}</div>
      <div class="stats-grid">
        <template v-if="loading.inviteData">
          <div v-for="i in 4" :key="i" class="stats-card skeleton-card">
            <div class="skeleton-icon"></div>
            <div class="skeleton-content">
              <div class="skeleton-row-sm"></div>
              <div class="skeleton-row-xs"></div>
            </div>
          </div>
        </template>
        <template v-else>
          <div class="stats-card">
            <div class="stats-icon">
              <IconUsers :size="24" />
            </div>
            <div class="stats-info">
              <div class="stats-label">{{ $t('invite.stats.registeredUsers') }}</div>
              <div class="stats-value">{{ inviteStats.registeredUsers }}</div>
            </div>
          </div>
          <div class="stats-card">
            <div class="stats-icon">
              <IconUsers :size="24" />
            </div>
            <div class="stats-info">
              <div class="stats-label">{{ $t('invite.stats.effectiveInvites') }}</div>
              <div class="stats-value">
                <span v-if="loading.referralProgram" class="stats-inline-skeleton" aria-hidden="true"></span>
                <template v-else>{{ inviteStats.effectiveInvites }}</template>
              </div>
            </div>
          </div>
          <div class="stats-card">
            <div class="stats-icon">
              <IconPigMoney :size="24" />
            </div>
            <div class="stats-info">
              <div class="stats-label">{{ $t('invite.stats.availableCommission') }}</div>
              <div class="stats-value">{{ currencySymbol }}{{ formatAmount(inviteStats.totalCommission) }}</div>
            </div>
          </div>
          <div class="stats-card">
            <div class="stats-icon">
              <IconHourglassHigh :size="24" />
            </div>
            <div class="stats-info">
              <div class="stats-label">{{ $t('invite.stats.pendingCommission') }}</div>
              <div class="stats-value">{{ currencySymbol }}{{ formatAmount(inviteStats.pendingCommission) }}</div>
            </div>
          </div>
        </template>
      </div>
      
      <!-- 合并卡片：邀请链接 + 返佣记录（选项卡切换） -->
      <div class="dashboard-card combined-card">
        <div class="card-header tab-header">
          <div class="tab-buttons">
            <button 
              class="tab-btn" 
              :class="{ active: activeTab === 'invite' }"
              @click="activeTab = 'invite'"
            >
              {{ $t('invite.inviteLink.title') }}
            </button>
            <button
              class="tab-btn"
              :class="{ active: activeTab === 'users' }"
              @click="activeTab = 'users'"
            >
              {{ $t('invite.users.title') }}
            </button>
            <button 
              class="tab-btn" 
              :class="{ active: activeTab === 'records' }"
              @click="activeTab = 'records'"
            >
              {{ $t('invite.records.title') }}
            </button>
          </div>
        </div>
        
        <!-- 邀请链接内容 -->
        <div v-if="activeTab === 'invite'" class="card-body">
          <p v-if="rewardRestrictionText" class="invite-reward-warning">{{ rewardRestrictionText }}</p>
          <div class="tab-content-toolbar">
            <span><IconLink :size="16" />{{ $t('invite.inviteLink.inviteCode') }} ({{ inviteCodes.length }})</span>
            <button class="btn-action" @click="createInviteCode" :disabled="creatingCode">
              <div v-if="creatingCode" class="loading-icon"></div>
              <IconPlus v-else class="action-icon" />
              {{ creatingCode ? $t('invite.inviteLink.creating') : $t('invite.inviteLink.createCode') }}
            </button>
          </div>
          <div v-if="loading.inviteData" class="skeleton-loading">
            <div class="skeleton-row"></div>
            <div class="skeleton-row"></div>
          </div>
          <template v-else>
            <div v-if="inviteCodes.length > 0" class="invite-codes-list">
              <div 
                v-for="(code, index) in inviteCodes" 
                :key="code.id || index"
                class="invite-code-item"
              >
                <IconLink class="invite-code-icon" :size="18" />
                <div class="code-info">
                  <div class="code-main-row">
                    <div class="code-value">{{ code.code }}</div>
                    <div class="code-actions">
                      <button class="btn-primary tiny" @click="copyInviteCode(code.code)">
                        {{ $t('invite.inviteLink.copyCode') }}
                      </button>
                      <button class="btn-primary tiny" @click="copyInviteLink(code.code)">
                        {{ $t('invite.inviteLink.copyLink') }}
                      </button>
                    </div>
                  </div>
                  <div class="code-date"><IconClock :size="11" />{{ formatCodeDate(code.created_at) }}</div>
                </div>
              </div>
            </div>
            <div v-else class="no-invite-code">
              <p>{{ $t('invite.inviteLink.noInviteCode') }}</p>
              <button class="btn-primary create-code-btn" @click="createInviteCode" :disabled="creatingCode">
                <div v-if="creatingCode" class="loading-icon"></div>
                <span v-else class="create-btn-content">
                  <IconPlus class="btn-icon" />
                  {{ $t('invite.inviteLink.createCode') }}
                </span>
              </button>
            </div>
          </template>
        </div>

        <!-- 邀请用户内容 -->
        <div v-if="activeTab === 'users'" class="card-body invite-users-tab">
          <p class="invite-effective-note">
            <IconInfoCircle :size="16" />
            <span>{{ $t('invite.users.definition') }}</span>
          </p>
          <InviteUsersPanel embedded />
        </div>
        
        <!-- 返佣记录内容 -->
        <div v-if="activeTab === 'records'" class="card-body">
          <div class="tab-content-toolbar">
            <span><IconReceipt :size="16" />{{ $t('invite.records.title') }}</span>
            <button class="btn-action" @click="refreshRecords" :disabled="loading.inviteDetails">
              <IconRefresh class="action-icon" :class="{ spin: loading.inviteDetails }" />
              {{ loading.inviteDetails ? $t('invite.records.refreshing') : $t('invite.records.refresh') }}
            </button>
          </div>
          <div v-if="loading.inviteDetails" class="skeleton-loading">
            <div class="skeleton-table">
              <div class="skeleton-header-row">
                <div class="skeleton-header-cell"></div>
                <div class="skeleton-header-cell"></div>
              </div>
              <div v-for="i in 3" :key="i" class="skeleton-row-full">
                <div class="skeleton-cell"></div>
                <div class="skeleton-cell"></div>
              </div>
            </div>
          </div>
          <div v-else>
            <div class="records-table-wrapper">
              <template v-if="inviteRecords.length > 0">
                <!-- 桌面端表格 -->
                <table class="records-table desktop-table">
                  <thead>
                    <tr>
                      <th>{{ $t('invite.records.date') }}</th>
                      <th>{{ $t('invite.records.type') }}</th>
                      <th>{{ $t('invite.records.change') }}</th>
                      <th>{{ $t('invite.records.status.title') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="record in inviteRecords" :key="record.id">
                      <td data-label="时间">{{ formatDate(record.created_at) }}</td>
                      <td>{{ recordTypeText(record.type) }}</td>
                      <td><strong :class="record.amount >= 0 ? 'amount-income' : 'amount-expense'">{{ signedAmount(record.amount) }}</strong></td>
                      <td data-label="状态">
                        <span class="status-badge" :class="record.status === 'completed' ? 'confirmed' : 'pending'">
                          {{ recordStatusText(record.status) }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </table>
                
                <!-- 手机端卡片列表 -->
                <div class="mobile-records-list">
                  <div 
                    v-for="record in inviteRecords" 
                    :key="record.id"
                    class="mobile-record-card"
                  >
                    <div class="record-header">
                      <span class="record-date">{{ formatDate(record.created_at) }}</span>
                      <span class="status-badge" :class="record.status === 'completed' ? 'confirmed' : 'pending'">
                        {{ recordStatusText(record.status) }}
                      </span>
                    </div>
                    <div class="record-body">
                      <div class="record-row">
                        <span class="record-label">{{ recordTypeText(record.type) }}</span>
                        <span class="record-value" :class="record.amount >= 0 ? 'amount-income' : 'amount-expense'">{{ signedAmount(record.amount) }}</span>
                      </div>
                    </div>
                  </div>
                </div>
                
                <!-- 分页控件 -->
                <div class="pagination-container" v-if="totalPages > 1">
                  <div class="pagination">
                    <button 
                      class="page-button prev" 
                      @click="handlePageChange(currentPage - 1)" 
                      :disabled="currentPage === 1"
                    >
                      <IconChevronLeft :size="16" />
                    </button>
                    <div class="page-info">
                      {{ $t('common.page') }} {{ currentPage }} / {{ totalPages }}
                    </div>
                    <button 
                      class="page-button next" 
                      @click="handlePageChange(currentPage + 1)" 
                      :disabled="currentPage === totalPages"
                    >
                      <IconChevronRight :size="16" />
                    </button>
                  </div>
                </div>
              </template>
              <div v-else class="empty-records">
                <p>{{ $t('invite.records.noRecords') }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- 划转弹窗（佣金转余额） -->
      <transition name="modal-fade">
        <div v-if="showTransferCardState" class="modal-overlay" @click="closeTransferCard">
          <div v-accessible-dialog="closeTransferCard" class="modal-content" :aria-label="$t('invite.transfer.title')" @click.stop>
            <div class="modal-header">
              <h3>{{ $t('invite.transfer.title') }}</h3>
              <button class="modal-close" type="button" :aria-label="$t('common.close')" @click="closeTransferCard">
                <IconX :size="20" />
              </button>
            </div>
            <div class="modal-body">
              <div class="alert alert-warning">
                <IconAlertTriangle :size="22" class="alert-icon" />
                <div class="alert-content">
                  <div class="alert-title">{{ $t('invite.transfer.warning') }}</div>
                  <div class="alert-desc">{{ $t('invite.transfer.warningDesc') }}</div>
                </div>
              </div>
              
              <div class="transfer-form">
                <div class="form-group">
                  <label class="form-label">{{ $t('invite.transfer.amount') }}</label>
                  <div class="input-with-prefix">
                    <div class="input-prefix">{{ currencySymbol }}</div>
                    <input 
                      type="number" 
                      v-model="transferAmount" 
                      class="form-control" 
                      :placeholder="$t('invite.transfer.amountPlaceholder')"
                      min="0"
                      :max="inviteStats.availableCommission"
                      step="0.01"
                    />
                  </div>
                  <div class="form-hint">
                    {{ $t('invite.transfer.availableCommission') }}: {{ currencySymbol }}{{ formatAmount(inviteStats.availableCommission) }}
                  </div>
                  <div v-if="transferError" class="error-message">{{ transferError }}</div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn-cancel" @click="closeTransferCard">
                {{ $t('common.cancel') }}
              </button>
              <button 
                class="btn-submit" 
                @click="confirmTransfer"
                :disabled="isTransferDisabled || transferLoading"
              >
                <div v-if="transferLoading" class="loader"></div>
                <span v-else>{{ $t('invite.transfer.confirm') }}</span>
              </button>
            </div>
          </div>
        </div>
      </transition>
      
      <!-- 提现弹窗 -->
      <transition name="modal-fade">
        <div v-if="showWithdrawCard" class="modal-overlay" @click="closeWithdrawCard">
          <div v-accessible-dialog="closeWithdrawCard" class="modal-content" :aria-label="$t('invite.withdraw.title')" @click.stop>
            <div class="modal-header">
              <h3>{{ $t('invite.withdraw.title') }}</h3>
              <button class="modal-close" type="button" :aria-label="$t('common.close')" @click="closeWithdrawCard">
                <IconX :size="20" />
              </button>
            </div>
            <div class="modal-body">
              <div v-if="withdrawMethods.length === 0" class="alert alert-warning">
                <IconAlertTriangle :size="22" class="alert-icon" />
                <div class="alert-content">
                  <div class="alert-title">{{ $t('invite.withdraw.tip') }}</div>
                  <div class="alert-desc">{{ $t('invite.withdraw.noPlatforms') }}</div>
                </div>
              </div>
              
              <div v-else-if="inviteStats.availableCommission <= 0" class="alert alert-warning">
                <IconAlertTriangle :size="22" class="alert-icon" />
                <div class="alert-content">
                  <div class="alert-title">{{ $t('invite.withdraw.tip') }}</div>
                  <div class="alert-desc">{{ $t('invite.withdraw.insufficientFunds') }}</div>
                </div>
              </div>
              
              <div v-else class="transfer-form">
                <div class="form-group">
                  <label class="form-label">{{ $t('invite.withdraw.platform') }}</label>
                  <div class="withdraw-methods">
                    <button
                      v-for="method in withdrawMethods"
                      :key="method"
                      class="withdraw-method"
                      :class="{ 'active': selectedWithdrawMethod === method }"
                      @click="selectWithdrawMethod(method)"
                    >
                      {{ method }}
                    </button>
                  </div>
                </div>
                
                <div class="form-group">
                  <label class="form-label">{{ $t('invite.withdraw.account') }}</label>
                  <div class="input-with-prefix account-input">
                    <input 
                      type="text" 
                      v-model="withdrawAccount" 
                      class="form-control" 
                      :placeholder="$t('invite.withdraw.accountPlaceholder')"
                    />
                  </div>
                </div>
                
                <div class="form-group">
                  <label class="form-label">{{ $t('invite.withdraw.amount') }}</label>
                  <div class="input-with-prefix">
                    <div class="input-prefix">{{ currencySymbol }}</div>
                    <input 
                      type="number" 
                      v-model="withdrawAmount" 
                      class="form-control" 
                      :placeholder="$t('invite.withdraw.amountPlaceholder')"
                      min="0"
                      :max="inviteStats.availableCommission"
                      step="0.01"
                    />
                  </div>
                  <div class="form-hint">
                    {{ $t('invite.withdraw.availableCommission') }}: {{ currencySymbol }}{{ formatAmount(inviteStats.availableCommission) }}
                    <span v-if="minWithdrawAmount > 0" class="min-withdraw-hint">
                      ({{ $t('invite.withdraw.minWithdrawAmount') }}: {{ currencySymbol }}{{ formatAmount(minWithdrawAmount) }})
                    </span>
                  </div>
                </div>
                
                <div v-if="withdrawError" class="error-message">{{ withdrawError }}</div>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn-cancel" @click="closeWithdrawCard">
                {{ $t('common.cancel') }}
              </button>
              <button 
                class="btn-submit" 
                @click="submitWithdraw"
                :disabled="!withdrawAccount || !selectedWithdrawMethod || withdrawLoading || inviteStats.availableCommission <= 0"
              >
                <div v-if="withdrawLoading" class="loader"></div>
                <span v-else>{{ $t('invite.withdraw.confirm') }}</span>
              </button>
            </div>
          </div>
        </div>
      </transition>
    </div>
  </div>
</template>

<script>
import { useI18n } from 'vue-i18n';
import { ref, computed, onMounted, reactive } from 'vue';
import { useToast } from '@/composables/useToast';
import { INVITE_CONFIG } from '@/utils/baseConfig';
import { getInviteData, getReferralProgram, getInviteDetails, getCommissionConfig, generateInviteCode, transferCommission, withdrawCommission } from '@/api/invite';
import { getUserInfo } from '@/api/user';
import InviteUsersPanel from './InviteUsers.vue';
import {
  IconUsers,
  IconChartBar,
  IconRefresh,
  IconPlus,
  IconX,
  IconChevronLeft,
  IconChevronRight,
  IconAlertTriangle,
  IconReceipt,
  IconLink,
  IconClock,
  IconPigMoney,
  IconHourglassHigh,
  IconCurrencyDollar,
  IconBuildingBank,
  IconArrowsExchange,
  IconCrown,
  IconInfoCircle,
} from '@tabler/icons-vue';

export default {
  name: 'InviteView',
  components: {
    IconUsers,
    IconChartBar,
    IconRefresh,
    IconPlus,
    IconX,
    IconChevronLeft,
    IconChevronRight,
    IconAlertTriangle,
    IconReceipt,
    IconLink,
    IconClock,
    IconPigMoney,
    IconHourglassHigh,
    IconCurrencyDollar,
    IconBuildingBank,
    IconArrowsExchange,
    IconCrown,
    IconInfoCircle,
    InviteUsersPanel,
  },
  setup() {
    const { showToast } = useToast();
    const { t, locale } = useI18n();
    
    // ============ 响应式数据 ============
    const activeTab = ref('invite');
    
    const loading = reactive({
      inviteData: true,
      inviteDetails: true,
      commConfig: true,
      referralProgram: true
    });
    
    const creatingCode = ref(false);
    const currencySymbol = ref('¥');
    const inviteCodes = ref([]);
    const referralProgram = ref(null);
    const rewardRestriction = ref(null);
    const walletBalance = ref(0);
    const inviteStats = reactive({
      registeredUsers: 0,
      pendingCommission: 0,
      totalCommission: 0,
      commissionRate: 0,
      effectiveInvites: 0,
      availableCommission: 0
    });
    const currentCommissionRate = computed(() => Number(referralProgram.value?.commission_rate ?? inviteStats.commissionRate ?? 0));
    const rewardRestrictionText = computed(() => {
      const policy = rewardRestriction.value?.policy;
      if (!policy) return '';
      const key = {
        block_inviter_commission: 'blockInviterCommission',
        block_invitee_rewards: 'blockInviteeRewards',
        block_both: 'blockBoth'
      }[policy];
      return key ? t(`invite.inviteLink.rewardRestrictions.${key}`) : '';
    });
    const inviteRecords = ref([]);
    const nextLevelProgress = computed(() => referralProgram.value?.next_level
      ? Math.min(
        100,
        Number(referralProgram.value.effective_invites || 0) / Math.max(1, Number(referralProgram.value.next_level.required_invites || 1)) * 100,
        Number(referralProgram.value.next_level.required_revenue || 0)
          ? Number(referralProgram.value.referral_revenue || 0) / Number(referralProgram.value.next_level.required_revenue) * 100
          : 100
      )
      : 100);
    const achievementReward = computed(() => {
      const reward = referralProgram.value?.next_level?.reward;
      if (!reward) return { label: '', value: '' };
      if (reward.reward_type === 'traffic') return { label: locale.value === 'en-US' ? 'Traffic' : '流量', value: `${reward.reward_value} GB` };
      if (reward.reward_type === 'duration') return { label: locale.value === 'en-US' ? 'Plan duration' : '套餐时长', value: locale.value === 'en-US' ? `${reward.reward_value} days` : `${reward.reward_value} 天` };
      const label = reward.reward_type === 'commission_balance'
        ? (locale.value === 'en-US' ? 'commission' : '推广佣金')
        : (locale.value === 'en-US' ? 'balance' : '账户余额');
      return { label, value: `${currencySymbol.value}${formatAmount(reward.reward_value)}` };
    });
    const localizedLevel = row => !row ? '' : (locale.value === 'en-US' ? row.name_en : row.name) || row.name || row.name_en || '';
    
    // 分页相关
    const currentPage = ref(1);
    const pageSize = ref(10);
    const totalRecords = ref(0);
    const totalPages = computed(() => Math.ceil(totalRecords.value / pageSize.value));
    
    // 转账相关
    const showTransferCardState = ref(false);
    const transferAmount = ref(0);
    const transferError = ref('');
    const transferLoading = ref(false);
    const isTransferDisabled = computed(() => transferAmount.value <= 0 || transferAmount.value > inviteStats.availableCommission);
    
    // 提现相关
    const withdrawClose = ref(1);
    const withdrawMethods = ref([]);
    const showWithdrawCard = ref(false);
    const withdrawAccount = ref('');
    const withdrawAmount = ref('');
    const selectedWithdrawMethod = ref('');
    const withdrawError = ref('');
    const withdrawLoading = ref(false);
    const minWithdrawAmount = ref(0);
    
    // 确认弹窗相关
    const showConfirmModal = ref(false);
    const confirmModalMessage = ref('');
    const pendingAction = ref(null);
    
    // ============ 辅助函数 ============
    const formatAmount = (amount) => {
      if (amount === undefined || amount === null) return '0.00';
      return parseFloat(amount).toFixed(2);
    };
    const signedAmount = amount => `${Number(amount) >= 0 ? '+' : '-'}${currencySymbol.value}${formatAmount(Math.abs(Number(amount || 0)))}`;
    const recordTypeText = type => t(`invite.records.types.${type}`);
    const recordStatusText = status => t(`invite.records.status.${status || 'unknown'}`);
    
    const formatDate = (timestamp) => {
      if (!timestamp) return '-';
      const date = new Date(timestamp * 1000);
      return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
    };
    
    const formatCodeDate = (timestamp) => {
      if (!timestamp) return '';
      const date = new Date(timestamp * 1000);
      return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    };
    
    const copyToClipboard = async (text, successMsg) => {
      try {
        await navigator.clipboard.writeText(text);
        showToast(successMsg, 'success');
      } catch (err) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        try {
          document.execCommand('copy');
          showToast(successMsg, 'success');
        } catch (e) {
          showToast(t('common.copyFailed'), 'error');
        }
        document.body.removeChild(textarea);
      }
    };
    
    const copyInviteCode = (code) => {
      if (!code) return;
      copyToClipboard(code, t('invite.inviteLink.copied') || '邀请码已复制');
    };
    
    const copyInviteLink = (code) => {
      if (!code) return;
      let link = '';
      if (INVITE_CONFIG.inviteLinkConfig && INVITE_CONFIG.inviteLinkConfig.linkMode === 'custom') {
        const customDomain = INVITE_CONFIG.inviteLinkConfig.customDomain;
        const domain = customDomain.endsWith('/') ? customDomain.slice(0, -1) : customDomain;
        link = `${domain}/#/register?code=${code}`;
      } else {
        link = `${window.location.origin}/#/register?code=${code}`;
      }
      copyToClipboard(link, t('invite.inviteLink.copied'));
    };
    
    // ============ API 请求函数 ============
    const fetchCommConfig = async () => {
      loading.commConfig = true;
      try {
        const res = await getCommissionConfig();
        if (res.data) {
          currencySymbol.value = res.data.currency_symbol || '¥';
          withdrawClose.value = Number(res.data.withdraw_close);
          withdrawMethods.value = res.data.withdraw_methods || [];
          if (res.data.min_withdraw_amount) {
            minWithdrawAmount.value = parseFloat(res.data.min_withdraw_amount) / 100;
          }
        }
      } catch (err) {
        console.error('获取佣金配置失败:', err);
      } finally {
        loading.commConfig = false;
      }
    };
    
    const fetchInviteData = async () => {
      loading.inviteData = true;
      loading.referralProgram = true;

      const inviteDataRequest = getInviteData()
        .then((res) => {
          if (res.data) {
            inviteCodes.value = res.data.codes || [];
            if (res.data.stat) {
              inviteStats.registeredUsers = res.data.stat[0] || 0;
              inviteStats.totalCommission = ((res.data.stat[1] || 0) / 100);
              inviteStats.pendingCommission = ((res.data.stat[2] || 0) / 100);
              inviteStats.commissionRate = res.data.stat[3] || 0;
              inviteStats.availableCommission = ((res.data.stat[4] || 0) / 100);
            }
          }
        })
        .catch((err) => {
          console.error('获取邀请数据失败:', err);
          showToast(t('invite.records.fetchDataError'), 'error');
        })
        .finally(() => {
          loading.inviteData = false;
        });

      const referralProgramRequest = getReferralProgram()
        .then((programRes) => {
          referralProgram.value = programRes.data?.program || null;
          rewardRestriction.value = programRes.data?.reward_restriction || null;
          inviteStats.effectiveInvites = Number(referralProgram.value?.effective_invites || 0);
        })
        .catch((programError) => {
          console.error('获取推广计划失败:', programError);
          referralProgram.value = null;
          rewardRestriction.value = null;
        })
        .finally(() => {
          loading.referralProgram = false;
        });

      await Promise.allSettled([inviteDataRequest, referralProgramRequest]);
    };

    const fetchWalletBalance = async () => {
      try {
        const res = await getUserInfo();
        walletBalance.value = Number(res?.data?.balance || 0) / 100;
      } catch (err) {
        console.error('获取钱包余额失败:', err);
      }
    };
    
    const fetchInviteDetails = async (page = 1) => {
      loading.inviteDetails = true;
      currentPage.value = page;
      try {
        const res = await getInviteDetails(page, pageSize.value);
        if (res.data) {
          inviteRecords.value = Array.isArray(res.data) ? res.data.map(record => ({
            id: record.id,
            created_at: record.created_at,
            type: record.type || 'commission_income',
            amount: Number(record.amount || 0) / 100,
            balance_after: record.balance_after === null || record.balance_after === undefined ? null : Number(record.balance_after) / 100,
            status: record.status || 'completed',
            description: record.description || '',
            trade_no: record.trade_no || ''
          })) : [];
          totalRecords.value = res.total || inviteRecords.value.length;
        }
      } catch (err) {
        console.error('获取邀请明细失败:', err);
        showToast(t('invite.records.fetchError'), 'error');
        inviteRecords.value = [];
        totalRecords.value = 0;
      } finally {
        loading.inviteDetails = false;
      }
    };
    
    // ============ 业务操作函数 ============
    const createInviteCode = async () => {
      if (creatingCode.value) return;
      creatingCode.value = true;
      try {
        await generateInviteCode();
        await fetchInviteData();
        showToast(t('invite.inviteLink.created'), 'success');
      } catch (error) {
        showToast(error.message || t('common.error'), 'error');
      } finally {
        creatingCode.value = false;
      }
    };
    
    const refreshRecords = () => {
      if (loading.inviteDetails) return;
      currentPage.value = 1;
      fetchInviteDetails(1);
    };

    const handlePageChange = (page) => {
      if (page < 1 || page > totalPages.value) return;
      currentPage.value = page;
      fetchInviteDetails(page);
    };
    
    // 转账相关
    const toggleTransferCard = () => {
      showTransferCardState.value = true;
      transferAmount.value = 0;
      transferError.value = '';
    };
    
    const closeTransferCard = () => {
      showTransferCardState.value = false;
      transferAmount.value = 0;
      transferError.value = '';
    };
    
    const confirmTransfer = async () => {
      if (!transferAmount.value || transferAmount.value <= 0) {
        transferError.value = t('invite.transfer.invalidAmount');
        return;
      }
      if (transferAmount.value > inviteStats.availableCommission) {
        transferError.value = t('invite.transfer.insufficientFunds');
        return;
      }
      
      transferLoading.value = true;
      try {
        const amountInCents = Math.round(transferAmount.value * 100);
        const res = await transferCommission(amountInCents);
        if (res.data === true) {
          showToast(t('invite.transfer.success'), 'success');
          await Promise.all([fetchInviteData(), fetchInviteDetails(1), fetchWalletBalance()]);
          closeTransferCard();
        }
      } catch (error) {
        transferError.value = error.message || t('invite.transfer.failure');
      } finally {
        transferLoading.value = false;
      }
    };
    
    // 提现相关
    const toggleWithdrawCard = () => {
      showWithdrawCard.value = true;
      withdrawAccount.value = '';
      withdrawAmount.value = '';
      withdrawError.value = '';
      if (withdrawMethods.value.length > 0) {
        selectedWithdrawMethod.value = withdrawMethods.value[0];
      }
    };
    
    const closeWithdrawCard = () => {
      showWithdrawCard.value = false;
      withdrawAccount.value = '';
      withdrawAmount.value = '';
      withdrawError.value = '';
    };
    
    const selectWithdrawMethod = (method) => {
      selectedWithdrawMethod.value = method;
    };
    
    const submitWithdraw = async () => {
      if (!withdrawAccount.value || !selectedWithdrawMethod.value) {
        withdrawError.value = t('validation.required');
        return;
      }
      const amount = parseFloat(withdrawAmount.value);
      if (isNaN(amount) || amount <= 0) {
        withdrawError.value = t('invite.withdraw.invalidAmount');
        return;
      }
      if (amount > inviteStats.availableCommission) {
        withdrawError.value = t('invite.withdraw.insufficientFunds');
        return;
      }
      if (amount < minWithdrawAmount.value && minWithdrawAmount.value > 0) {
        withdrawError.value = t('invite.withdraw.belowMinAmount');
        return;
      }
      
      withdrawLoading.value = true;
      try {
        const amountInCents = Math.round(amount * 100);
        const res = await withdrawCommission(amountInCents, withdrawAccount.value, selectedWithdrawMethod.value);
        if (res.data === true) {
          showToast(t('invite.withdraw.success'), 'success');
          await Promise.all([fetchInviteData(), fetchInviteDetails(1)]);
          closeWithdrawCard();
        }
      } catch (error) {
        withdrawError.value = error.message || t('invite.withdraw.failure');
      } finally {
        withdrawLoading.value = false;
      }
    };
    
    // 确认弹窗相关
    const confirmAction = () => {
      if (pendingAction.value) {
        pendingAction.value();
      }
      showConfirmModal.value = false;
    };
    
    const cancelConfirmation = () => {
      showConfirmModal.value = false;
      pendingAction.value = null;
    };
    
    // ============ 生命周期 ============
    onMounted(async () => {
      await Promise.all([fetchCommConfig(), fetchInviteData(), fetchInviteDetails(1), fetchWalletBalance()]);
    });
    
    return {
      loading,
      creatingCode,
      inviteCodes,
      referralProgram,
      rewardRestrictionText,
      nextLevelProgress,
      achievementReward,
      localizedLevel,
      currentCommissionRate,
      locale,
      inviteStats,
      walletBalance,
      inviteRecords,
      currencySymbol,
      copyInviteCode,
      copyInviteLink,
      createInviteCode,
      refreshRecords,
      formatDate,
      formatCodeDate,
      formatAmount,
      signedAmount,
      recordTypeText,
      recordStatusText,
      fetchInviteDetails,
      showConfirmModal,
      confirmModalMessage,
      confirmAction,
      cancelConfirmation,
      showTransferCardState,
      transferAmount,
      transferError,
      transferLoading,
      isTransferDisabled,
      toggleTransferCard,
      closeTransferCard,
      confirmTransfer,
      withdrawClose,
      withdrawMethods,
      showWithdrawCard,
      withdrawAccount,
      withdrawAmount,
      selectedWithdrawMethod,
      withdrawError,
      withdrawLoading,
      minWithdrawAmount,
      toggleWithdrawCard,
      closeWithdrawCard,
      selectWithdrawMethod,
      submitWithdraw,
      currentPage,
      pageSize,
      totalPages,
      handlePageChange,
      t,
      activeTab
    };
  }
};
</script>

<style lang="scss" scoped>
.account-container {
  padding: 20px;
  display: flex;
  justify-content: center;

  .invite-effective-note {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 0 0 12px;
    padding: 10px 14px;
    color: var(--secondary-text-color);
    font-size: 12px;
    line-height: 1.5;
    background: rgba(var(--theme-color-rgb), .07);
    border: 1px solid rgba(var(--theme-color-rgb), .14);
    border-radius: 12px;

    svg { flex: 0 0 auto; color: var(--theme-color); }
  }

  .growth-level-card { margin-bottom: 18px; overflow: hidden; border: 1px solid var(--border-color); border-radius: 20px; background: var(--card-bg-color, #fff); }
  .growth-level-skeleton { min-height: 158px; padding: 20px; }
  .level-skeleton-head { display: flex; align-items: center; gap: 12px; }
  .level-skeleton-head i { width: 44px; height: 44px; flex: 0 0 44px; border-radius: 14px; }
  .level-skeleton-head span { width: 128px; height: 18px; border-radius: 999px; }
  .level-skeleton-head b { width: 210px; height: 14px; margin-left: auto; border-radius: 999px; }
  .level-skeleton-body { display: grid; gap: 10px; margin-top: 22px; }
  .level-skeleton-body span { height: 10px; border-radius: 999px; }
  .level-skeleton-body span:nth-child(1) { width: 55%; }
  .level-skeleton-body span:nth-child(2) { width: 100%; }
  .level-skeleton-body span:nth-child(3) { width: 72%; }
  .level-skeleton-head i,.level-skeleton-head span,.level-skeleton-head b,.level-skeleton-body span { background: var(--skeleton-background); animation: invite-skeleton-pulse 1.4s ease-in-out infinite; }
  .current-level-block,.next-level-block { padding: 20px; }
  .current-level-block { display: flex; align-items: center; justify-content: space-between; gap: 18px; }
  .next-level-block { border-top: 1px solid var(--border-color); }
  .current-level-title { display: flex; min-width: 0; flex: 0 1 auto; align-items: center; gap: 12px; }
  .current-level-title h2 { overflow: hidden; margin: 0; color: var(--text-color); font-size: 23px; text-overflow: ellipsis; white-space: nowrap; }
  .level-icon { display: grid; width: 44px; height: 44px; flex: 0 0 44px; place-items: center; color: var(--theme-color); border-radius: 14px; background: rgba(var(--theme-color-rgb), .12); }
  .level-benefits { display: flex; min-width: 0; flex: 1 1 auto; flex-wrap: wrap; justify-content: flex-end; gap: 6px 24px; margin-left: auto; color: var(--secondary-text-color); font-size: 14px; text-align: right; }
  .level-benefits span { white-space: nowrap; }
  .level-benefits strong,.level-detail strong,.next-level-privileges strong,.next-level-head strong { color: var(--text-color); }
  .next-level-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
  .next-level-head>div { display: flex; min-width: 0; align-items: baseline; gap: 7px; }
  .next-level-head>div strong { overflow: hidden; font-size: 17px; text-overflow: ellipsis; white-space: nowrap; }
  .next-level-head>span { flex: 0 0 auto; color: var(--secondary-text-color); font-size: 13px; }
  .growth-progress { height: 9px; margin-top: 15px; overflow: hidden; border-radius: 10px; background: rgba(var(--theme-color-rgb), .12); }
  .growth-progress i { display: block; height: 100%; border-radius: inherit; background: var(--theme-color); }
  .level-detail,.level-revenue-condition { margin: 13px 0 0; color: var(--secondary-text-color); font-size: 13px; }
  .level-meta-row { display: flex; align-items: baseline; gap: 8px; }
  .level-meta-row>b { flex: 0 0 auto; color: var(--text-color); }
  .next-level-privileges { margin-top: 13px; color: var(--secondary-text-color); font-size: 13px; }
  .level-privilege-values { display: flex; flex-wrap: wrap; gap: 6px 20px; }
  .level-privilege-values span { white-space: nowrap; }
  .highest-level-tip { padding: 18px 20px; color: var(--secondary-text-color); border-top: 1px solid var(--border-color); }
  @media (max-width: 600px) {
    .current-level-block,.next-level-block { padding: 17px 16px; }
    .current-level-title h2 { font-size: 20px; }
    .current-level-block { gap: 12px; }
    .level-benefits { gap: 4px 12px; }
    .next-level-head { align-items: center; }
    .next-level-head>span { text-align: right; }
    .level-meta-row { gap: 6px; }
    .level-privilege-values { gap: 4px 12px; }
  }
  
  .account-inner {
    width: 100%;
    max-width: 900px;
  }
  
  .dashboard-card {
    background-color: var(--card-bg-color, #ffffff);
    border-radius: 20px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    padding: 20px;
    margin-bottom: 24px;
    border: 1px solid var(--border-color, #e5e7eb);
    
    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 15px;
      
      .card-title {
        font-size: 18px;
        font-weight: 600;
        margin: 0;
        color: var(--text-color, #1f2937);
      }
      
      .card-actions {
        display: flex;
        gap: 10px;
        
        .btn-action {
          white-space: nowrap;
        }
      }
    }
  }
  
  .combined-card .tab-header {
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    padding-bottom: 12px;
    
    .tab-buttons {
      display: flex;
      gap: 16px;
      
      .tab-btn {
        background: none;
        border: none;
        font-size: 16px;
        font-weight: 500;
        color: var(--secondary-text-color, #6b7280);
        cursor: pointer;
        padding: 8px 0;
        position: relative;
        transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
        
        &::after {
          content: '';
          position: absolute;
          bottom: -13px;
          left: 0;
          right: 0;
          height: 2px;
          background-color: var(--theme-color, #3b82f6);
          transform: scaleX(0);
          transition: transform 0.2s;
        }
        
        &.active {
          color: var(--theme-color, #3b82f6);
          &::after {
            transform: scaleX(1);
          }
        }
        
        &:hover {
          color: var(--theme-color, #3b82f6);
        }
      }
    }
    
    .card-actions {
      margin-left: auto;
    }
  }
  
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 24px;
    
    @media (max-width: 768px) {
      grid-template-columns: repeat(2, 1fr);
    }
    
    .stats-card {
      background-color: var(--card-bg-color, #ffffff);
      border-radius: 20px;
      padding: 12px;
      display: flex;
      align-items: center;
      border: 1px solid var(--border-color, #e5e7eb);
      
      .stats-icon {
        width: 36px;
        height: 36px;
        background-color: rgba(var(--theme-color-rgb, 59, 130, 246), 0.1);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 12px;
        color: var(--theme-color, #3b82f6);
        
        svg {
          width: 24px;
          height: 24px;
        }
      }
      
      .stats-info {
        flex: 1;
        
        .stats-value {
          font-size: 16px;
          font-weight: 600;
          color: var(--text-color, #1f2937);
        }
        
        .stats-label {
          font-size: 12px;
          color: var(--secondary-text-color, #6b7280);
        }
      }
    }

    .stats-inline-skeleton {
      display: inline-block;
      width: 42px;
      height: 14px;
      border-radius: 999px;
      background: var(--skeleton-background);
      animation: invite-skeleton-pulse 1.4s ease-in-out infinite;
    }
  }

  .balance-container {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    
    @media (max-width: 768px) {
      flex-direction: column;
      align-items: flex-start;
    }
    
    .balance-value {
      font-size: 32px;
      font-weight: 600;
      color: var(--theme-color, #3b82f6);
    }
    
    .balance-actions {
      display: flex;
      gap: 10px;
      
      @media (max-width: 480px) {
        flex-direction: column;
        width: 100%;
        
        .btn-primary {
          width: 100%;
          justify-content: center;
        }
      }
    }
  }
  
  .invite-codes-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    
    .invite-code-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      padding: 12px 14px;
      background-color: rgba(var(--theme-color-rgb, 59, 130, 246), 0.05);
      border-radius: 10px;
      border: 1px solid var(--border-color, #e5e7eb);
      flex-wrap: nowrap;
      
      .code-info {
        min-width: 0;
        flex: 1;

        .code-main-row {
          display: flex;
          min-width: 0;
          align-items: center;
          gap: 8px;
        }
        
        .code-label {
          font-size: 12px;
          color: var(--secondary-text-color, #6b7280);
          margin-bottom: 4px;
        }
        
        .code-value {
          min-width: 0;
          flex: 0 0 auto;
          font-size: 16px;
          font-weight: 600;
          color: var(--text-color, #1f2937);
          font-family: monospace;
          white-space: nowrap;
        }
        
        .code-date {
          margin-top: 4px;
          font-size: 11px;
          color: var(--secondary-text-color, #6b7280);
        }
      }
      
      .code-actions {
        display: flex;
        gap: 4px;
        flex-shrink: 0;
        
        .btn-primary.tiny {
          width: auto;
          min-width: 0;
          padding: 4px 7px;
          height: 28px;
          font-size: 11px;
          white-space: nowrap;
        }
      }

    }
  }
  
  .records-table-wrapper {
    overflow-x: auto;
    
    .desktop-table {
      width: 100%;
      border-collapse: collapse;
      
      th, td {
        padding: 12px 16px;
        text-align: left;
        border-bottom: 1px solid var(--border-color, #e5e7eb);
      }
      
      th {
        font-weight: 600;
        color: var(--text-color, #1f2937);
        background-color: rgba(var(--theme-color-rgb, 59, 130, 246), 0.05);
      }
      
      td {
        color: var(--secondary-text-color, #6b7280);
      }
      
      .status-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        
        &.confirmed {
          color: #4caf50;
          background-color: rgba(76, 175, 80, 0.1);
        }
        
        &.pending {
          color: #ff9800;
          background-color: rgba(255, 152, 0, 0.1);
        }
      }
    }
    
    .mobile-records-list {
      display: none;
      flex-direction: column;
      gap: 12px;
      
      .mobile-record-card {
        background-color: rgba(var(--theme-color-rgb, 59, 130, 246), 0.05);
        border-radius: 10px;
        padding: 12px;
        border: 1px solid var(--border-color, #e5e7eb);
        
        .record-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          padding-bottom: 10px;
          margin-bottom: 10px;
          border-bottom: 1px solid var(--border-color, #e5e7eb);
          
          .record-date {
            font-size: 12px;
            color: var(--secondary-text-color, #6b7280);
          }
          
          .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            
            &.confirmed {
              color: #4caf50;
              background-color: rgba(76, 175, 80, 0.1);
            }
            
            &.pending {
              color: #ff9800;
              background-color: rgba(255, 152, 0, 0.1);
            }
          }
        }
        
        .record-body {
          .record-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            
            .record-label {
              font-size: 13px;
              color: var(--secondary-text-color, #6b7280);
            }
            
            .record-value {
              font-size: 14px;
              font-weight: 500;
              color: var(--text-color, #1f2937);
              
              &.commission {
                color: var(--theme-color, #3b82f6);
                font-weight: 600;
              }
            }
          }
        }
      }
    }
    
    @media (max-width: 768px) {
      .desktop-table {
        display: none;
      }
      .mobile-records-list {
        display: flex;
      }
    }
  }
  
  .pagination-container {
    margin-top: 1.5rem;
    display: flex;
    justify-content: center;
  }
  
  .pagination {
    display: flex;
    align-items: center;
    background-color: var(--card-bg, #ffffff);
    border-radius: 20px;
    padding: 0.5rem 0.75rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    border: 1px solid var(--border-color, #e5e7eb);
    
    .page-button {
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 6px;
      background: transparent;
      border: none;
      color: var(--text-color, #1f2937);
      cursor: pointer;
      
      &:hover:not(:disabled) {
        background-color: rgba(var(--theme-color-rgb, 59, 130, 246), 0.1);
        color: var(--theme-color, #3b82f6);
      }
      
      &:disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }
    }
    
    .page-info {
      margin: 0 1rem;
      font-size: 0.9rem;
      color: var(--text-color, #1f2937);
    }
  }
  
  .empty-records, .no-invite-code {
    padding: 40px 20px;
    text-align: center;
    color: var(--secondary-text-color, #6b7280);
    
    .create-code-btn {
      margin-top: 16px;
    }
  }
  
  .btn-primary {
    background-color: var(--theme-color, #3b82f6);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 20px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
    
    &:hover:not(:disabled) {
      opacity: 0.9;
      transform: translateY(-1px);
    }
    
    &:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }
    
    &.small {
      padding: 6px 12px;
      height: 32px;
      font-size: 12px;
    }
    
    &.tiny {
      padding: 4px 10px;
      height: 28px;
      font-size: 11px;
    }
  }
  
  .btn-icon {
    width: 16px;
    height: 16px;
  }
  
  .btn-action {
    background: transparent;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 6px;
    color: var(--text-color, #1f2937);
    transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
    font-size: 13px;
    
    &:hover:not(:disabled) {
      background-color: rgba(var(--theme-color-rgb, 59, 130, 246), 0.1);
    }
    
    &:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
  }
}

/* FastCatAPP invitation layout */
.invite-balance-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}

.invite-balance-card {
  padding: 16px;
  color: var(--theme-color);
  background: rgba(var(--theme-color-rgb), 0.12);
  border: 1px solid rgba(var(--theme-color-rgb), 0.28);
  border-radius: 20px;
  box-shadow: 0 4px 16px rgba(var(--theme-color-rgb), 0.12);

  .balance-card-title { display: flex; align-items: center; gap: 5px; font-size: 12px; opacity: .88; }
  .balance-value { margin-top: 8px; font-size: 24px; font-weight: 700; }
}

.invite-primary-actions {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 20px;

  .btn-primary { display: inline-flex; min-width: 0; flex: 1; align-items: center; justify-content: center; gap: 7px; white-space: nowrap; }
  &.single-action { grid-template-columns: 1fr; }
  .btn-primary { width: 100%; padding-right: 8px; padding-left: 8px; }
  .transfer-action { width: 100%; }
}

.invite-section-title {
  display: flex;
  margin-bottom: 12px;
  align-items: center;
  gap: 6px;
  color: var(--heading-color);
  font-size: 16px;
  font-weight: 700;

  svg { color: var(--theme-color); }
}

.combined-card .tab-header {
  padding: 6px !important;
  background: rgba(var(--theme-color-rgb), .06);
  border: 0 !important;
  border-radius: 12px !important;

  .tab-buttons { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); width: 100%; gap: 4px; }
  .tab-btn { display: flex; min-width: 0; min-height: 40px; align-items: center; justify-content: center; padding-right: 6px !important; padding-left: 6px !important; color: var(--secondary-text-color); border-radius: 8px !important; white-space: nowrap; }
  .tab-btn.active { color: #fff !important; background: var(--theme-color) !important; }
  .card-actions { margin-left: 8px; }
}

.invite-code-item {
  display: flex;
  align-items: center;
  gap: 10px;

  .invite-code-icon { flex: 0 0 auto; color: var(--theme-color); }
  .code-date { display: flex; align-items: center; gap: 3px; }
  .code-value { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 600; }
  .btn-primary.tiny { color: var(--theme-color) !important; background: rgba(var(--theme-color-rgb), .1) !important; border: 0 !important; box-shadow: none !important; }
}

.tab-content-toolbar {
  display: flex;
  margin-bottom: 12px;
  align-items: center;
  justify-content: space-between;
  gap: 12px;

  > span { display: inline-flex; align-items: center; gap: 6px; color: var(--heading-color); font-size: 14px; font-weight: 600; }
  > span svg { color: var(--theme-color); }
  .btn-action { color: #fff !important; background: var(--theme-color) !important; }
}
.invite-reward-warning {
  margin: 0 0 12px;
  color: #dc2626;
  font-size: 12px;
  font-weight: 500;
  line-height: 1.6;
  text-align: center;
}
.invite-users-tab { padding-top: 8px; }
.amount-income { color: #16a34a !important; }
.amount-expense { color: #dc2626 !important; }

@media (max-width: 576px) {
  .invite-balance-card { padding: 14px; }
  .invite-balance-card .balance-value { font-size: 21px; }
  .combined-card .tab-header { align-items: stretch; flex-direction: column; }
  .combined-card .tab-header .card-actions { margin: 6px 0 0; }
  .combined-card .tab-header .btn-action { width: 100%; justify-content: center; }
  .combined-card .tab-header .tab-btn { font-size: 13px; }
  .tab-content-toolbar { align-items: flex-start; flex-direction: column; }
}

@keyframes invite-skeleton-pulse { 50% { opacity: .45; } }

@media (prefers-reduced-motion: reduce) {
  .level-skeleton-head i,.level-skeleton-head span,.level-skeleton-head b,.level-skeleton-body span,.stats-inline-skeleton { animation: none; }
}

/* 暗黑模式修复：卡片背景与页面背景一致（不独立），弹窗背景独立 */
.dark-theme .dashboard-card,
.dark .dashboard-card,
.dark-theme .stats-card,
.dark .stats-card,
.dark-theme .invite-code-item,
.dark .invite-code-item,
.dark-theme .mobile-record-card,
.dark .mobile-record-card,
.dark-theme .pagination,
.dark .pagination {
  background-color: var(--page-bg, #1e293b) !important;
  border-color: #1e293b !important;
}

.dark-theme .dashboard-card .card-title,
.dark .dashboard-card .card-title,
.dark-theme .stats-card .stats-value,
.dark .stats-card .stats-value,
.dark-theme .stats-card .stats-info .stats-label,
.dark .stats-card .stats-info .stats-label,
.dark-theme .code-label,
.dark .code-label,
.dark-theme .code-value,
.dark .code-value,
.dark-theme .code-date,
.dark .code-date,
.dark-theme .record-date,
.dark .record-date,
.dark-theme .record-label,
.dark .record-label,
.dark-theme .record-value,
.dark .record-value,
.dark-theme .page-info,
.dark .page-info,
.dark-theme .empty-records,
.dark .empty-records {
  color: #f1f5f9 !important;
}

.dark-theme .tab-btn,
.dark .tab-btn {
  color: #94a3b8 !important;
}

.dark-theme .tab-btn.active,
.dark .tab-btn.active {
  color: var(--theme-color, #3b82f6) !important;
}

.dark-theme .btn-action,
.dark .btn-action {
  color: #f1f5f9 !important;
}

.dark-theme .btn-action:hover:not(:disabled),
.dark .btn-action:hover:not(:disabled) {
  background-color: rgba(59, 130, 246, 0.2) !important;
}

.dark-theme .records-table th,
.dark .records-table th {
  background-color: rgba(59, 130, 246, 0.15) !important;
  color: #f1f5f9 !important;
}

.dark-theme .records-table td,
.dark .records-table td {
  border-bottom-color: #1e293b !important;
  color: #cbd5e1 !important;
}
</style>

<!-- 全局样式：修复弹窗透明度和关闭按钮位置，弹窗背景独立 -->
<style lang="scss">
/* 弹窗遮罩层 */
.modal-overlay {
  position: fixed !important;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.6) !important;
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1040;
}

/* 弹窗内容容器 */
.modal-content,
.modal-container {
  background: var(--card-bg-color, #ffffff) !important;
  background-color: var(--card-bg-color, #ffffff) !important;
  border-radius: 20px;
  width: 90%;
  max-width: 480px;
  overflow: hidden;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
  z-index: 1050;
}

/* 弹窗头部 - 修复关闭按钮位置 */
.modal-header {
  display: flex !important;
  justify-content: space-between !important;
  align-items: center !important;
  padding: 16px 20px !important;
  background: var(--card-bg-color, #ffffff) !important;
  border-bottom: 1px solid var(--border-color, #e5e7eb) !important;
  
  h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: var(--text-color, #1f2937);
  }
  
  .modal-close {
    background: transparent;
    border: none;
    cursor: pointer;
    padding: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-color, #1f2937);
    
    &:hover {
      opacity: 0.7;
    }
  }
}

.modal-body,
.modal-footer {
  background: var(--card-bg-color, #ffffff) !important;
}

.modal-footer {
  padding: 16px 20px !important;
  border-top: 1px solid var(--border-color, #e5e7eb) !important;
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

.modal-body {
  padding: 20px !important;
}

/* 暗黑模式弹窗：保持独立的卡片色（不与页面背景相同） */
.dark-theme .modal-header,
.dark .modal-header,
.dark-theme .modal-body,
.dark .modal-body,
.dark-theme .modal-footer,
.dark .modal-footer,
.dark-theme .modal-content,
.dark .modal-content,
.dark-theme .modal-container,
.dark .modal-container {
  background: #1e293b !important;
  background-color: #1e293b !important;
}

.dark-theme .modal-header,
.dark .modal-header {
  border-bottom-color: #334155 !important;
  
  h3 {
    color: #f1f5f9 !important;
  }
  
  .modal-close {
    color: #f1f5f9 !important;
  }
}

.dark-theme .modal-footer,
.dark .modal-footer {
  border-top-color: #334155 !important;
}

/* 其他弹窗内元素暗黑适配 */
.dark-theme .alert.alert-warning,
.dark .alert.alert-warning {
  background-color: rgba(255, 152, 0, 0.15) !important;
}

.dark-theme .alert .alert-desc,
.dark .alert .alert-desc {
  color: #f1f5f9 !important;
}

.dark-theme .form-label,
.dark .form-label {
  color: #f1f5f9 !important;
}

.dark-theme .input-with-prefix,
.dark .input-with-prefix {
  border-color: #334155 !important;
}

.dark-theme .input-prefix,
.dark .input-prefix {
  background-color: #334155 !important;
  color: #f1f5f9 !important;
  border-right-color: #475569 !important;
}

.dark-theme .form-control,
.dark .form-control {
  color: #f1f5f9 !important;
}

.dark-theme .form-hint,
.dark .form-hint {
  color: #94a3b8 !important;
}

.dark-theme .btn-cancel,
.dark .btn-cancel {
  border-color: #475569 !important;
  color: #f1f5f9 !important;
}

.dark-theme .btn-cancel:hover,
.dark .btn-cancel:hover {
  background-color: rgba(255, 255, 255, 0.1) !important;
}

.dark-theme .withdraw-method,
.dark .withdraw-method {
  border-color: #475569 !important;
  color: #f1f5f9 !important;
}

.dark-theme .withdraw-method.active,
.dark .withdraw-method.active {
  background-color: var(--theme-color, #3b82f6) !important;
  color: white !important;
}

/* 按钮和加载动画 */
.btn-cancel,
.btn-outline.cancel-btn {
  background: transparent;
  border: 1px solid var(--border-color, #e5e7eb);
  padding: 8px 20px;
  border-radius: 6px;
  cursor: pointer;
  color: var(--text-color, #1f2937);
  
  &:hover {
    background-color: rgba(0, 0, 0, 0.05);
  }
}

.btn-submit,
.btn-primary.confirm-btn {
  background-color: var(--theme-color, #3b82f6);
  color: white;
  border: none;
  padding: 8px 20px;
  border-radius: 6px;
  cursor: pointer;
  
  &:hover:not(:disabled) {
    opacity: 0.9;
  }
  
  &:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }
}

.loader {
  width: 16px;
  height: 16px;
  border: 2px solid white;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

.loading-icon {
  width: 16px;
  height: 16px;
  border: 2px solid var(--theme-color, #3b82f6);
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.spin {
  animation: spin 1s linear infinite;
}

/* 表单通用样式 */
.form-group {
  margin-bottom: 20px;
  
  .form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: var(--text-color, #1f2937);
  }
  
  .input-with-prefix {
    display: flex;
    align-items: center;
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 20px;
    overflow: hidden;
    
    .input-prefix {
      padding: 10px 12px;
      background-color: var(--bg-color-secondary, #f3f4f6);
      color: var(--text-color, #1f2937);
      border-right: 1px solid var(--border-color, #e5e7eb);
    }
    
    .form-control {
      flex: 1;
      padding: 10px 12px;
      border: none;
      outline: none;
      background: transparent;
      color: var(--text-color, #1f2937);
      
      &:focus {
        outline: none;
      }
    }
  }
  
  .form-hint {
    margin-top: 6px;
    font-size: 12px;
    color: var(--secondary-text-color, #6b7280);
  }
  
  .error-message {
    margin-top: 6px;
    font-size: 12px;
    color: #ef4444;
  }
}

.alert {
  display: flex;
  gap: 12px;
  padding: 12px;
  border-radius: 20px;
  margin-bottom: 20px;
  
  &.alert-warning {
    background-color: rgba(255, 152, 0, 0.1);
    border: 1px solid rgba(255, 152, 0, 0.3);
    
    .alert-title {
      color: #ff9800;
      font-weight: 600;
      margin-bottom: 4px;
    }
    
    .alert-desc {
      color: var(--text-color, #1f2937);
      font-size: 13px;
    }
  }
}

.withdraw-methods {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  
  .withdraw-method {
    padding: 8px 16px;
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 20px;
    background: transparent;
    cursor: pointer;
    transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
    color: var(--text-color, #1f2937);
    
    &:hover {
      border-color: var(--theme-color, #3b82f6);
    }
    
    &.active {
      background-color: var(--theme-color, #3b82f6);
      color: white;
      border-color: var(--theme-color, #3b82f6);
    }
  }
}
</style>
