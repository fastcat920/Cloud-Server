<template>
  <div class="profile-container">
    <div class="profile-inner">
      <!-- 骨架屏加载状态 -->
      <div v-if="loading" class="profile-skeleton">
        <!-- 基本信息骨架屏 -->
        <div class="profile-card">
          <div class="skeleton-content">
            <div class="skeleton-item">
              <div class="skeleton-label"></div>
              <div class="skeleton-value"></div>
            </div>
            <div class="skeleton-item">
              <div class="skeleton-label"></div>
              <div class="skeleton-value"></div>
            </div>
          </div>
        </div>
        
        <!-- 设置骨架屏 -->
        <div class="profile-card">
          <div class="skeleton-content">
            <div class="skeleton-item skeleton-setting">
              <div class="skeleton-text">
                <div class="skeleton-label"></div>
                <div class="skeleton-description"></div>
              </div>
              <div class="skeleton-toggle"></div>
            </div>
            <div class="skeleton-item skeleton-setting">
              <div class="skeleton-text">
                <div class="skeleton-label"></div>
                <div class="skeleton-description"></div>
              </div>
              <div class="skeleton-toggle"></div>
            </div>
          </div>
        </div>
        
        <!-- 按钮骨架屏 -->
        <div class="profile-card">
          <div class="skeleton-content">
            <div class="skeleton-button"></div>
          </div>
        </div>
      </div>
      
      <!-- 错误提示 -->
      <div v-else-if="error" class="error-state">
        <IconAlertTriangle :size="48" class="error-icon" />
        <p>{{ error }}</p>
        <button class="retry-button" @click="fetchUserInfo">{{ $t('common.retry') }}</button>
      </div>
      
      <div v-else class="profile-content">
        <!-- 基本信息 -->
        <div class="profile-card account-email-card">
          <div class="account-list-row">
            <span class="account-row-icon"><IconMail :size="20" /></span>
            <span class="account-row-copy">
              <strong>{{ $t('profile.email') }}</strong>
              <small>{{ userInfo.email || '—' }}</small>
            </span>
          </div>
        </div>

        <!-- 礼品卡兑换 -->
        <div v-if="false && PROFILE_CONFIG.showGiftCardRedeem" class="profile-card">
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
        
        <!-- 邮件提醒设置 -->
        <div class="profile-card">
          <div class="settings-content">
            <div class="setting-item">
              <span class="account-setting-icon"><IconCalendarEvent :size="20" /></span>
              <div class="setting-info">
                <span class="setting-label">{{ $t('profile.expireRemind') }}</span>
              </div>
              <div class="setting-toggle">
                <label class="switch" :class="{ 'disabled': updatingSettings }">
                  <input type="checkbox" v-model="remindExpire" @change="updateRemindSettings('expire')" :disabled="updatingSettings" />
                  <span class="slider round" :class="{ 'loading': updatingExpire }"></span>
                </label>
              </div>
            </div>
            <div class="setting-item">
              <span class="account-setting-icon"><IconChartDonut :size="20" /></span>
              <div class="setting-info">
                <span class="setting-label">{{ $t('profile.trafficRemind') }}</span>
              </div>
              <div class="setting-toggle">
                <label class="switch" :class="{ 'disabled': updatingSettings }">
                  <input type="checkbox" v-model="remindTraffic" @change="updateRemindSettings('traffic')" :disabled="updatingSettings" />
                  <span class="slider round" :class="{ 'loading': updatingTraffic }"></span>
                </label>
              </div>
            </div>
          </div>
        </div>
        
        <!-- 安全设置 -->
        <div class="profile-card password-card">
          <div class="settings-content">
            <button type="button" class="password-row" @click="showChangeEmailModal = true">
              <div class="password-row-left">
                <IconMail :size="18" />
                <span>{{ $t('profile.changeEmail') }}</span>
              </div>
              <IconChevronRight :size="18" class="password-row-chevron" />
            </button>
            <button type="button" class="password-row" @click="showPasswordModal = true">
              <div class="password-row-left">
                <IconLock :size="18" />
                <span>{{ $t('profile.changePassword') }}</span>
              </div>
              <IconChevronRight :size="18" class="password-row-chevron" />
            </button>
          </div>
        </div>

        <!-- 账号操作 -->
        <div class="profile-card account-actions-card">
          <button class="logout-button" type="button" @click="handleLogout">
            <IconLogout :size="20" />
            <span>{{ $t('common.logoutText') }}</span>
          </button>
        </div>
        <button class="account-deletion-button" type="button" @click="showDeleteAccountModal = true">
          <IconUserX :size="18" />
          <span>{{ $t('profile.deleteAccount') }}</span>
        </button>
        
        <!-- 近期登录设备 -->
        <div v-if="false && PROFILE_CONFIG.showRecentDevices" class="profile-card">
          <div class="card-header">
            <h3>{{ $t('profile.recentDevices') }}</h3>
          </div>
          <div class="settings-content">
            <div v-if="loadingSessions" class="device-loading">
              <div class="session-skeleton" v-for="i in 3" :key="i">
                <div class="session-skeleton-icon"></div>
                <div class="session-skeleton-content">
                  <div class="session-skeleton-title"></div>
                  <div class="session-skeleton-info"></div>
                </div>
              </div>
            </div>
            <div v-else-if="sessionError" class="device-error">
              <p>{{ sessionError }}</p>
              <button class="refresh-btn" @click="fetchActiveSessions">{{ $t('common.retry') }}</button>
            </div>
            <div v-else-if="activeSessions.length === 0" class="device-empty">
              <IconDevices :size="48" class="empty-icon" />
              <p>{{ $t('profile.noDevices') }}</p>
            </div>
            <div v-else class="device-list">
              <div v-for="(session, index) in activeSessions" :key="index" class="device-item">
                <div class="device-icon">
                  <component :is="getDeviceIcon(session.ua)" :size="24" />
                </div>
                <div class="device-info">
                  <div class="device-name">{{ formatDeviceInfo(session.ua) }}</div>
                  <div class="device-meta">
                    <span class="device-ip">{{ session.ip || $t('profile.unknownIP') }}</span>
                    <span class="device-time">{{ formatTimestamp(session.login_at) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- 修改邮箱弹窗 -->
      <transition name="modal-fade">
        <div v-if="showChangeEmailModal" class="modal-overlay" @click.self="closeChangeEmailModal">
          <div v-accessible-dialog="closeChangeEmailModal" class="password-modal email-modal" :aria-label="$t('profile.changeEmailTitle')" @click.stop>
            <div class="pm-header">
              <div class="pm-header-icon">
                <IconMail :size="22" />
              </div>
              <div class="pm-header-text">
                <h3>{{ $t('profile.changeEmailTitle') }}</h3>
                <p>{{ $t('profile.changeEmailSubtitle') }}</p>
              </div>
              <button type="button" class="pm-close" :aria-label="$t('common.close')" @click="closeChangeEmailModal">
                <IconX :size="20" />
              </button>
            </div>
            <div class="pm-body">
              <div class="pm-field">
                <label>{{ $t('profile.currentEmail') }}</label>
                <div class="pm-input-wrap pm-readonly-wrap">
                  <IconMail class="pm-input-icon" :size="18" />
                  <input :value="changeEmailForm.currentEmail || '—'" type="text" readonly />
                </div>
              </div>
              <div class="pm-field">
                <label>{{ $t('profile.newEmail') }}</label>
                <div class="pm-input-wrap" :class="{ 'pm-error': changeEmailErrors.newEmail }">
                  <IconMail class="pm-input-icon" :size="18" />
                  <input
                    v-model.trim="changeEmailForm.newEmail"
                    :placeholder="$t('profile.newEmailPlaceholder')"
                    type="email"
                    autocomplete="email"
                  />
                </div>
                <div v-if="changeEmailErrors.newEmail" class="pm-hint error">{{ changeEmailErrors.newEmail }}</div>
              </div>
              <div class="pm-field">
                <label>{{ $t('profile.currentPassword') }}</label>
                <div class="pm-input-wrap" :class="{ 'pm-error': changeEmailErrors.password }">
                  <IconLock class="pm-input-icon" :size="18" />
                  <input
                    :type="showChangeEmailPassword ? 'text' : 'password'"
                    v-model="changeEmailForm.password"
                    :placeholder="$t('profile.currentPasswordPlaceholder')"
                    autocomplete="current-password"
                  />
                  <button class="pm-toggle" @click="showChangeEmailPassword = !showChangeEmailPassword" type="button">
                    <IconEye v-if="!showChangeEmailPassword" :size="18" />
                    <IconEyeOff v-else :size="18" />
                  </button>
                </div>
                <div v-if="changeEmailErrors.password" class="pm-hint error">{{ changeEmailErrors.password }}</div>
              </div>
              <div class="pm-field">
                <label>{{ $t('profile.emailCode') }}</label>
                <div class="pm-input-with-button">
                  <div class="pm-input-wrap" :class="{ 'pm-error': changeEmailErrors.emailCode }">
                    <IconShieldCheck class="pm-input-icon" :size="18" />
                    <input
                      v-model.trim="changeEmailForm.emailCode"
                      :placeholder="$t('profile.emailCodePlaceholder')"
                      inputmode="numeric"
                      maxlength="6"
                      autocomplete="one-time-code"
                    />
                  </div>
                  <button
                    type="button"
                    class="pm-send-code-btn"
                    @click="sendChangeEmailCode"
                    :disabled="sendingChangeEmailCode || changeEmailCountdown > 0"
                  >
                    <span v-if="sendingChangeEmailCode" class="loader"></span>
                    <span v-else>{{ changeEmailCountdown > 0 ? `${changeEmailCountdown}s` : $t('profile.sendEmailCode') }}</span>
                  </button>
                </div>
                <div v-if="changeEmailErrors.emailCode" class="pm-hint error">{{ changeEmailErrors.emailCode }}</div>
              </div>
            </div>
            <div class="pm-footer">
              <button type="button" class="pm-btn-cancel" @click="closeChangeEmailModal">{{ $t('common.cancel') }}</button>
              <button type="button" class="pm-btn-submit" @click="submitChangeEmail" :disabled="changingEmail">
                <span v-if="changingEmail" class="loader"></span>
                {{ $t('profile.confirmChangeEmail') }}
              </button>
            </div>
          </div>
        </div>
      </transition>

      <!-- 注销账号弹窗 -->
      <transition name="modal-fade">
        <div v-if="showDeleteAccountModal" class="modal-overlay" @click.self="closeDeleteAccountModal">
          <div v-accessible-dialog="closeDeleteAccountModal" class="password-modal deletion-modal" :aria-label="$t('profile.deleteAccountTitle')" @click.stop>
            <div class="pm-header deletion-header">
              <div class="pm-header-icon">
                <IconUserX :size="22" />
              </div>
              <div class="pm-header-text">
                <h3>{{ $t('profile.deleteAccountTitle') }}</h3>
                <p>{{ $t('profile.deleteAccountSubtitle') }}</p>
              </div>
              <button type="button" class="pm-close" :aria-label="$t('common.close')" @click="closeDeleteAccountModal">
                <IconX :size="20" />
              </button>
            </div>
            <div class="pm-body">
              <div class="deletion-warning">
                <IconAlertTriangle :size="21" />
                <p>{{ $t('profile.deleteAccountWarning') }}</p>
              </div>
              <div class="pm-field">
                <label>{{ $t('profile.currentEmail') }}</label>
                <div class="pm-input-wrap pm-readonly-wrap">
                  <IconMail class="pm-input-icon" :size="18" />
                  <input :value="userInfo.email || '—'" type="text" readonly />
                </div>
              </div>
              <div class="pm-field">
                <label>{{ $t('profile.deleteAccountEmailCode') }}</label>
                <div class="pm-input-with-button">
                  <div class="pm-input-wrap" :class="{ 'pm-error': deleteAccountErrors.emailCode }">
                    <IconShieldCheck class="pm-input-icon" :size="18" />
                    <input
                      v-model.trim="deleteAccountForm.emailCode"
                      :placeholder="$t('profile.emailCodePlaceholder')"
                      inputmode="numeric"
                      maxlength="6"
                      autocomplete="one-time-code"
                    />
                  </div>
                  <button type="button" class="pm-send-code-btn deletion-send-code" @click="sendDeleteAccountCode" :disabled="sendingDeleteAccountCode || deleteAccountCountdown > 0">
                    <span v-if="sendingDeleteAccountCode" class="loader"></span>
                    <span v-else>{{ deleteAccountCountdown > 0 ? `${deleteAccountCountdown}s` : $t('profile.sendEmailCode') }}</span>
                  </button>
                </div>
                <div v-if="deleteAccountErrors.emailCode" class="pm-hint error">{{ deleteAccountErrors.emailCode }}</div>
                <div class="pm-hint">{{ $t('profile.deleteAccountCodeHint') }}</div>
              </div>
            </div>
            <div class="pm-footer">
              <button type="button" class="pm-btn-cancel" @click="closeDeleteAccountModal">{{ $t('common.cancel') }}</button>
              <button type="button" class="pm-btn-submit deletion-submit" @click="submitDeleteAccount" :disabled="deletingAccount || !canDeleteAccount">
                <span v-if="deletingAccount" class="loader"></span>
                {{ $t('profile.confirmDeleteAccount') }}
              </button>
            </div>
          </div>
        </div>
      </transition>

      <!-- 修改密码弹窗 -->
      <transition name="modal-fade">
        <div v-if="showPasswordModal" class="modal-overlay" @click.self="showPasswordModal = false">
          <div v-accessible-dialog="() => showPasswordModal = false" class="password-modal" :aria-label="$t('profile.changePasswordTitle')" @click.stop>
            <!-- 标题栏 -->
            <div class="pm-header">
              <div class="pm-header-icon">
                <IconLock :size="22" />
              </div>
              <div class="pm-header-text">
                <h3>{{ $t('profile.changePasswordTitle') }}</h3>
                <p>{{ $t('profile.changePasswordSubtitle') }}</p>
              </div>
              <button type="button" class="pm-close" :aria-label="$t('common.close')" @click="showPasswordModal = false">
                <IconX :size="20" />
              </button>
            </div>
            <!-- 表单 -->
            <div class="pm-body">
              <div class="pm-field">
                <label>{{ $t('profile.oldPassword') }}</label>
                <div class="pm-input-wrap">
                  <IconLock class="pm-input-icon" :size="18" />
                  <input
                    :type="showOldPwd ? 'text' : 'password'"
                    v-model="passwordForm.oldPassword"
                    :placeholder="$t('profile.currentPasswordPlaceholder')"
                    autocomplete="current-password"
                  />
                  <button class="pm-toggle" @click="showOldPwd = !showOldPwd" type="button">
                    <IconEye v-if="!showOldPwd" :size="18" />
                    <IconEyeOff v-else :size="18" />
                  </button>
                </div>
              </div>
              <div class="pm-field">
                <label>{{ $t('profile.newPassword') }}</label>
                <div class="pm-input-wrap" :class="{ 'pm-error': passwordForm.newPassword && !isNewPasswordValid }">
                  <IconLockCog class="pm-input-icon" :size="18" />
                  <input
                    :type="showNewPwd ? 'text' : 'password'"
                    v-model="passwordForm.newPassword"
                    :placeholder="$t('profile.newPasswordPlaceholder')"
                    autocomplete="new-password"
                  />
                  <button class="pm-toggle" @click="showNewPwd = !showNewPwd" type="button">
                    <IconEye v-if="!showNewPwd" :size="18" />
                    <IconEyeOff v-else :size="18" />
                  </button>
                </div>
                <!-- 密码强度指示 -->
                <div v-if="passwordForm.newPassword" class="pm-strength">
                  <div class="pm-strength-bar">
                    <div class="pm-strength-fill" :class="strengthClass" :style="{ width: strengthPercent + '%' }"></div>
                  </div>
                  <span class="pm-strength-label" :class="strengthClass">{{ strengthLabel }}</span>
                </div>
              </div>
              <div class="pm-field">
                <label>{{ $t('profile.confirmPassword') }}</label>
                <div class="pm-input-wrap" :class="{ 'pm-error': passwordMismatch }">
                  <IconLockCog class="pm-input-icon" :size="18" />
                  <input
                    :type="showConfirmPwd ? 'text' : 'password'"
                    v-model="passwordForm.confirmPassword"
                    :placeholder="$t('profile.confirmPasswordPlaceholder')"
                    autocomplete="new-password"
                  />
                  <button class="pm-toggle" @click="showConfirmPwd = !showConfirmPwd" type="button">
                    <IconEye v-if="!showConfirmPwd" :size="18" />
                    <IconEyeOff v-else :size="18" />
                  </button>
                </div>
                <div v-if="passwordMismatch" class="pm-hint error">{{ $t('profile.passwordMismatch') }}</div>
              </div>
            </div>
            <!-- 按钮 -->
            <div class="pm-footer">
              <button type="button" class="pm-btn-cancel" @click="showPasswordModal = false">{{ $t('common.cancel') }}</button>
              <button type="button" class="pm-btn-submit" @click="changePassword" :disabled="changingPassword || !validatePasswordForm()">
                <span v-if="changingPassword" class="loader"></span>
                {{ $t('profile.confirmChange') }}
              </button>
            </div>
          </div>
        </div>
      </transition>
      
      <!-- 重置订阅弹窗 -->
      <transition name="modal-fade">
        <div v-if="showResetModal" class="modal-overlay" @click="showResetModal = false">
          <div v-accessible-dialog="() => showResetModal = false" class="modal-content" :aria-label="$t('profile.resetSecurityTitle')" @click.stop>
            <div class="modal-header">
              <h3>{{ $t('profile.resetSecurityTitle') }}</h3>
              <button class="modal-close" type="button" :aria-label="$t('common.close')" @click="showResetModal = false"><IconX :size="20" /></button>
            </div>
            <div class="modal-body">
              <p>{{ $t('profile.resetSecurityConfirm') }}</p>
            </div>
            <div class="modal-footer">
              <button class="btn-cancel" @click="showResetModal = false">{{ $t('common.cancel') }}</button>
              <button class="btn-submit danger" @click="resetSecurity" :disabled="resetting">
                <span v-if="resetting" class="loader"></span>{{ $t('common.confirm') }}
              </button>
            </div>
          </div>
        </div>
      </transition>
    </div>
    
    <!-- 底部安全区域 -->
    <div class="bottom-safe-area"></div>
  </div>

</template>

<script setup name="UserProfile">
import { ref, computed, onMounted, watch, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { 
  getUserInfo, 
  changePassword as apiChangePassword, 
  sendChangeEmailVerify as apiSendChangeEmailVerify,
  changeEmail as apiChangeEmail,
  sendDeleteAccountVerify as apiSendDeleteAccountVerify,
  deleteAccount as apiDeleteAccount,
  resetSecurity as apiResetSecurity, 
  updateRemindSettings as apiUpdateRemind, 
  redeemGiftCard as apiRedeemGiftCard, 
  getActiveSession
} from '@/api/user';
import { 
  IconAlertTriangle,
  IconLock,
  IconX,
  IconGift,
  IconDevices,
  IconDeviceMobile,
  IconDeviceDesktop,
  IconBrowser,
  IconLogout,
  IconEye,
  IconEyeOff,
  IconChevronRight,
  IconMail,
  IconCalendarEvent,
  IconChartDonut,
  IconLockCog,
  IconShieldCheck,
  IconUserX,
} from '@tabler/icons-vue';
import useToast from '@/hooks/useToast';
import { reloadMessages } from '@/i18n';
import { PROFILE_CONFIG } from '@/utils/baseConfig';
import { isValidEmail } from '@/utils/validators';
import { forceLogout } from '@/api/auth';

defineOptions({ name: 'UserProfile' });

reloadMessages();

const { t } = useI18n();
const { success, error: showError } = useToast();

const loading = ref(true);
const error = ref('');
const userInfo = ref({});
const showPasswordModal = ref(false);
const showChangeEmailModal = ref(false);
const showResetModal = ref(false);
const showDeleteAccountModal = ref(false);
const changingPassword = ref(false);
const changingEmail = ref(false);
const resetting = ref(false);
const remindExpire = ref(false);
const remindTraffic = ref(false);
const activeSessions = ref([]);
const loadingSessions = ref(false);
const sessionError = ref('');

const giftCardCode = ref('');
const isRedeeming = ref(false);

const updatingSettings = ref(false);
const updatingExpire = ref(false);
const updatingTraffic = ref(false);

const passwordForm = ref({ oldPassword: '', newPassword: '', confirmPassword: '' });
const changeEmailForm = ref({
  currentEmail: '',
  newEmail: '',
  password: '',
  emailCode: ''
});
const changeEmailErrors = ref({ currentEmail: '', newEmail: '', password: '', emailCode: '' });
const showChangeEmailPassword = ref(false);
const sendingChangeEmailCode = ref(false);
const changeEmailCountdown = ref(0);
let changeEmailTimer = null;
const deleteAccountForm = ref({ emailCode: '' });
const deleteAccountErrors = ref({ emailCode: '' });
const sendingDeleteAccountCode = ref(false);
const deleteAccountCountdown = ref(0);
const deletingAccount = ref(false);
let deleteAccountTimer = null;
const showOldPwd = ref(false);
const showNewPwd = ref(false);
const showConfirmPwd = ref(false);
const passwordMismatch = computed(() => passwordForm.value.confirmPassword && passwordForm.value.newPassword !== passwordForm.value.confirmPassword);
const canDeleteAccount = computed(() => /^\d{6}$/.test(deleteAccountForm.value.emailCode));

// 密码强度评估
const isNewPasswordValid = computed(() => {
  const pwd = passwordForm.value.newPassword;
  if (!pwd) return true;
  return pwd.length >= 8;
});
const passwordStrength = computed(() => {
  const pwd = passwordForm.value.newPassword;
  if (!pwd) return 0;
  let score = 0;
  if (pwd.length >= 8) score++;
  if (pwd.length >= 12) score++;
  if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) score++;
  if (/[0-9]/.test(pwd)) score++;
  if (/[^a-zA-Z0-9]/.test(pwd)) score++;
  return score;
});
const strengthPercent = computed(() => Math.min(passwordStrength.value * 20, 100));
const strengthClass = computed(() => {
  const s = passwordStrength.value;
  if (s <= 1) return 'weak';
  if (s <= 3) return 'medium';
  return 'strong';
});
const strengthLabel = computed(() => {
  const s = passwordStrength.value;
  if (s <= 1) return t('profile.passwordStrengthWeak');
  if (s <= 3) return t('profile.passwordStrengthMedium');
  return t('profile.passwordStrengthStrong');
});
const validatePasswordForm = () => passwordForm.value.oldPassword && passwordForm.value.newPassword && passwordForm.value.confirmPassword && !passwordMismatch.value;
const validateChangeEmailForm = () => {
  changeEmailErrors.value = { currentEmail: '', newEmail: '', password: '', emailCode: '' };
  const newEmail = (changeEmailForm.value.newEmail || '').trim();
  const password = (changeEmailForm.value.password || '').trim();
  const emailCode = (changeEmailForm.value.emailCode || '').trim();

  let valid = true;
  if (!newEmail) {
    changeEmailErrors.value.newEmail = t('profile.newEmailRequired');
    valid = false;
  } else if (!isValidEmail(newEmail)) {
    changeEmailErrors.value.newEmail = t('auth.emailInvalid');
    valid = false;
  } else if (newEmail.toLowerCase() === (changeEmailForm.value.currentEmail || '').trim().toLowerCase()) {
    changeEmailErrors.value.newEmail = t('profile.newEmailSame');
    valid = false;
  }

  if (!password) {
    changeEmailErrors.value.password = t('profile.currentPasswordRequired');
    valid = false;
  }

  if (!emailCode) {
    changeEmailErrors.value.emailCode = t('profile.emailCodeRequired');
    valid = false;
  } else if (!/^\d{6}$/.test(emailCode)) {
    changeEmailErrors.value.emailCode = t('profile.emailCodeInvalid');
    valid = false;
  }

  return valid;
};

const route = useRoute();
const router = useRouter();

const fetchUserInfo = async (showLoading = true) => {
  if (showLoading) loading.value = true;
  error.value = '';
  try {
    const response = await getUserInfo();
    if (response?.data) {
      userInfo.value = response.data;
      changeEmailForm.value.currentEmail = response.data.email || '';
      remindExpire.value = !!response.data.remind_expire;
      remindTraffic.value = !!response.data.remind_traffic;
    } else error.value = t('errors.unknown');
  } catch (err) {
    console.error(err);
    error.value = err?.message || t('common.networkError');
    showError(error.value);
  } finally {
    if (showLoading && !loadingSessions.value) loading.value = false;
    checkOpenPasswordModal();
  }
};

const checkOpenPasswordModal = () => {
  if (route.query.openPasswordModal === 'true') {
    setTimeout(() => {
      showPasswordModal.value = true;
      const query = { ...route.query };
      delete query.openPasswordModal;
      router.replace({ query });
    }, 500);
  }
};

watch(() => route.query, (newQuery) => {
  if (newQuery.openPasswordModal === 'true') {
    setTimeout(() => {
      showPasswordModal.value = true;
      const query = { ...newQuery };
      delete query.openPasswordModal;
      router.replace({ query });
    }, 500);
  }
}, { immediate: true });

const resetChangeEmailForm = () => {
  changeEmailErrors.value = { currentEmail: '', newEmail: '', password: '', emailCode: '' };
  changeEmailForm.value = {
    currentEmail: userInfo.value.email || '',
    newEmail: '',
    password: '',
    emailCode: ''
  };
  showChangeEmailPassword.value = false;
};

const closeChangeEmailModal = () => {
  showChangeEmailModal.value = false;
  resetChangeEmailForm();
  if (changeEmailTimer) {
    clearInterval(changeEmailTimer);
    changeEmailTimer = null;
  }
  changeEmailCountdown.value = 0;
  sendingChangeEmailCode.value = false;
  changingEmail.value = false;
};

watch(showChangeEmailModal, (visible) => {
  if (visible) {
    resetChangeEmailForm();
  }
});

const startChangeEmailCountdown = () => {
  changeEmailCountdown.value = 60;
  if (changeEmailTimer) clearInterval(changeEmailTimer);
  changeEmailTimer = setInterval(() => {
    if (changeEmailCountdown.value <= 1) {
      clearInterval(changeEmailTimer);
      changeEmailTimer = null;
      changeEmailCountdown.value = 0;
      return;
    }
    changeEmailCountdown.value -= 1;
  }, 1000);
};

const sendChangeEmailCode = async () => {
  if (sendingChangeEmailCode.value || changeEmailCountdown.value > 0) return;
  const newEmail = (changeEmailForm.value.newEmail || '').trim();
  const password = (changeEmailForm.value.password || '').trim();
  changeEmailErrors.value.newEmail = '';
  changeEmailErrors.value.password = '';
  changeEmailErrors.value.emailCode = '';

  if (!newEmail) {
    changeEmailErrors.value.newEmail = t('profile.newEmailRequired');
    return;
  }
  if (!isValidEmail(newEmail)) {
    changeEmailErrors.value.newEmail = t('auth.emailInvalid');
    return;
  }
  if (!password) {
    changeEmailErrors.value.password = t('profile.currentPasswordRequired');
    return;
  }
  if (newEmail.toLowerCase() === (changeEmailForm.value.currentEmail || '').trim().toLowerCase()) {
    changeEmailErrors.value.newEmail = t('profile.newEmailSame');
    return;
  }

  sendingChangeEmailCode.value = true;
  try {
    await apiSendChangeEmailVerify({
      new_email: newEmail,
      password
    });
    success(t('profile.emailCodeSent'));
    startChangeEmailCountdown();
  } catch (err) {
    showError(err?.response?.message || err?.message || t('profile.emailCodeSendError'));
  } finally {
    sendingChangeEmailCode.value = false;
  }
};

const submitChangeEmail = async () => {
  if (changingEmail.value) return;
  if (!validateChangeEmailForm()) return;
  changingEmail.value = true;
  try {
    await apiChangeEmail({
      new_email: changeEmailForm.value.newEmail.trim().toLowerCase(),
      password: changeEmailForm.value.password,
      email_code: changeEmailForm.value.emailCode.trim()
    });
    success(t('profile.changeEmailSuccess'));
    closeChangeEmailModal();
    forceLogout();
    setTimeout(() => {
      router.replace('/login');
    }, 500);
  } catch (err) {
    showError(err?.response?.message || err?.message || t('profile.changeEmailError'));
  } finally {
    changingEmail.value = false;
  }
};

const updateRemindSettings = async (type) => {
  updatingSettings.value = true;
  if (type === 'expire') updatingExpire.value = true;
  else updatingTraffic.value = true;
  try {
    const data = { remind_expire: remindExpire.value ? 1 : 0, remind_traffic: remindTraffic.value ? 1 : 0 };
    await apiUpdateRemind(data);
    success(t('profile.updateSuccess'));
  } catch (err) {
    console.error(err);
    remindExpire.value = !!userInfo.value.remind_expire;
    remindTraffic.value = !!userInfo.value.remind_traffic;
    showError(t('profile.updateError'));
  } finally {
    await fetchUserInfo(false);
    updatingSettings.value = false;
    updatingExpire.value = false;
    updatingTraffic.value = false;
  }
};

const changePassword = async () => {
  if (!validatePasswordForm()) return;
  changingPassword.value = true;
  try {
    await apiChangePassword({ old_password: passwordForm.value.oldPassword, new_password: passwordForm.value.newPassword });
    success(t('profile.passwordChanged'));
    passwordForm.value = { oldPassword: '', newPassword: '', confirmPassword: '' };
    showPasswordModal.value = false;
  } catch (err) { showError(t('profile.passwordError')); }
  finally { changingPassword.value = false; }
};

const resetSecurity = async () => {
  resetting.value = true;
  try {
    await apiResetSecurity();
    success(t('profile.resetSuccess'));
    showResetModal.value = false;
  } catch (err) { showError(t('profile.resetError')); }
  finally { resetting.value = false; }
};

const handleLogout = async () => {
  try {
    forceLogout();
    success(t('auth.logoutSuccess'));
    setTimeout(() => {
      router.push('/login');
    }, 500);
  } catch (err) {
    console.error('退出登录失败:', err);
    showError(t('auth.logoutFailed'));
  }
};

const startDeleteAccountCountdown = () => {
  deleteAccountCountdown.value = 60;
  if (deleteAccountTimer) clearInterval(deleteAccountTimer);
  deleteAccountTimer = setInterval(() => {
    if (deleteAccountCountdown.value <= 1) {
      clearInterval(deleteAccountTimer);
      deleteAccountTimer = null;
      deleteAccountCountdown.value = 0;
      return;
    }
    deleteAccountCountdown.value -= 1;
  }, 1000);
};

const closeDeleteAccountModal = () => {
  if (deletingAccount.value) return;
  showDeleteAccountModal.value = false;
  deleteAccountForm.value = { emailCode: '' };
  deleteAccountErrors.value = { emailCode: '' };
  sendingDeleteAccountCode.value = false;
  deleteAccountCountdown.value = 0;
  if (deleteAccountTimer) {
    clearInterval(deleteAccountTimer);
    deleteAccountTimer = null;
  }
};

const sendDeleteAccountCode = async () => {
  if (sendingDeleteAccountCode.value || deleteAccountCountdown.value > 0) return;
  sendingDeleteAccountCode.value = true;
  deleteAccountErrors.value.emailCode = '';
  try {
    await apiSendDeleteAccountVerify();
    success(t('profile.deleteAccountCodeSent'));
    startDeleteAccountCountdown();
  } catch (err) {
    showError(err?.response?.message || err?.message || t('profile.deleteAccountCodeSendError'));
  } finally {
    sendingDeleteAccountCode.value = false;
  }
};

const submitDeleteAccount = async () => {
  deleteAccountErrors.value = { emailCode: '' };
  if (!/^\d{6}$/.test(deleteAccountForm.value.emailCode)) {
    deleteAccountErrors.value.emailCode = t('profile.emailCodeInvalid');
  }
  if (deleteAccountErrors.value.emailCode) return;

  deletingAccount.value = true;
  try {
    await apiDeleteAccount({
      email_code: deleteAccountForm.value.emailCode,
      confirm: 'DELETE'
    });
    forceLogout();
    success(t('profile.deleteAccountSuccess'));
    setTimeout(() => router.replace('/login'), 500);
  } catch (err) {
    showError(err?.response?.message || err?.message || t('profile.deleteAccountError'));
    deletingAccount.value = false;
  }
};

const redeemGiftCard = async () => {
  if (!giftCardCode.value) { showError(t('profile.giftCardEmpty')); return; }
  isRedeeming.value = true;
  try {
    await apiRedeemGiftCard(giftCardCode.value);
    success(t('profile.giftCardSuccess'));
    giftCardCode.value = '';
    await fetchUserInfo(false);
  } catch (err) { showError(t('profile.giftCardError')); }
  finally { isRedeeming.value = false; }
};

const fetchActiveSessions = async () => {
  loadingSessions.value = true;
  sessionError.value = '';
  try {
    const response = await getActiveSession();
    if (response?.data) {
      const sessions = Array.isArray(response.data) ? response.data : (typeof response.data === 'object' && response.data !== null ? Object.values(response.data) : []);
      activeSessions.value = sessions.sort((a, b) => (b.login_at || 0) - (a.login_at || 0)).slice(0, 10);
    } else sessionError.value = t('profile.sessionError');
  } catch (err) { sessionError.value = err?.message || t('common.networkError'); }
  finally {
    loadingSessions.value = false;
  }
};

const getDeviceIcon = (ua) => {
  if (!ua) return IconBrowser;
  const uaLower = ua.toLowerCase();
  if (uaLower.includes('iphone') || uaLower.includes('ipad') || uaLower.includes('ipod')) return IconDeviceMobile;
  if (uaLower.includes('android')) return IconDeviceMobile;
  if (uaLower.includes('windows') || uaLower.includes('macintosh') || uaLower.includes('mac os') || uaLower.includes('linux')) return IconDeviceDesktop;
  return IconBrowser;
};

const formatDeviceInfo = (ua) => {
  if (!ua) return t('profile.unknownDevice');
  const uaLower = ua.toLowerCase();
  let deviceType = '';
  if (uaLower.includes('iphone')) deviceType = 'iPhone';
  else if (uaLower.includes('ipad')) deviceType = 'iPad';
  else if (uaLower.includes('ipod')) deviceType = 'iPod';
  else if (uaLower.includes('android')) deviceType = 'Android';
  else if (uaLower.includes('windows')) deviceType = 'Windows';
  else if (uaLower.includes('macintosh') || uaLower.includes('mac os')) deviceType = 'MacOS';
  else if (uaLower.includes('linux')) deviceType = 'Linux';
  else deviceType = t('profile.unknownDevice');
  let browserType = '';
  if (uaLower.includes('edg/') || uaLower.includes('edge/')) browserType = 'Edge';
  else if (uaLower.includes('chrome/') && !uaLower.includes('chromium/')) browserType = 'Chrome';
  else if (uaLower.includes('firefox/')) browserType = 'Firefox';
  else if (uaLower.includes('safari/') && !uaLower.includes('chrome/') && !uaLower.includes('android')) browserType = 'Safari';
  else if (uaLower.includes('opera/') || uaLower.includes('opr/')) browserType = 'Opera';
  else browserType = t('profile.unknownBrowser');
  return `${deviceType} - ${browserType}`;
};

const formatTimestamp = (timestamp) => {
  if (!timestamp) return '';
  const num = Number(timestamp);
  if (isNaN(num) || num < 0 || num > 4102444800) return '';
  try {
    return new Intl.DateTimeFormat('zh-CN', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }).format(new Date(num * 1000));
  } catch { return ''; }
};

onMounted(() => {
  loading.value = true;
  fetchUserInfo(false).finally(() => { loading.value = false; checkOpenPasswordModal(); });
});

onUnmounted(() => {
  if (changeEmailTimer) {
    clearInterval(changeEmailTimer);
    changeEmailTimer = null;
  }
  if (deleteAccountTimer) {
    clearInterval(deleteAccountTimer);
    deleteAccountTimer = null;
  }
});
</script>

<style lang="scss" scoped>
/* ========== 卡片背景色 ========== */
.profile-card,
.dashboard-card {
  background-color: #ffffff;  /* 白天模式 */
  border-radius: 20px;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
  margin-bottom: 24px;
  border: 1px solid var(--card-border);
  transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
  overflow: hidden;
  
  &:hover {
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    border-color: rgba(var(--theme-color-rgb), 0.3);
  }
}

/* 暗黑模式卡片背景 */
.dark-theme .profile-card,
.dark-theme .dashboard-card,
.dark .profile-card,
.dark .dashboard-card {
  background-color: #1e293b !important;
}

/* 紧凑间距调整（保持卡片上下宽度降低） */
.profile-card {
  margin-bottom: 12px;

  .account-list-row {
    display: flex;
    min-height: 68px;
    padding: 10px 16px;
    align-items: center;
    gap: 12px;
  }

  .account-row-icon,
  .account-setting-icon {
    display: inline-flex;
    width: 36px;
    height: 36px;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    color: var(--theme-color);
    background: rgba(var(--theme-color-rgb), .1);
    border-radius: 10px;
  }

  .account-row-copy {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 3px;

    strong { color: var(--heading-color); font-size: 14px; font-weight: 600; }
    small { overflow: hidden; color: var(--supporting-text-color); font-size: 13px; text-overflow: ellipsis; white-space: nowrap; }
  }

  .card-header {
    padding: 10px 16px;
    border-bottom: 1px solid var(--border-color);
    background-color: rgba(var(--theme-color-rgb), 0.03);
    h3 {
      font-size: 15px;
      font-weight: 600;
      margin: 0;
      color: var(--text-color);
    }
  }
  
  .info-content {
    padding: 12px 16px;
    .info-list {
      display: grid;
      grid-template-columns: 1fr;
      gap: 12px;
      @media(min-width: 768px) {
        grid-template-columns: repeat(2, 1fr);
      }
      .info-item {
        display: flex;
        flex-direction: column;
        .info-label {
          font-size: 13px;
          color: var(--text-muted);
          margin-bottom: 4px;
        }
        .info-value {
          font-size: 14px;
          font-weight: 500;
          color: var(--text-color);
        }
      }
    }
  }
  
  .settings-content {
    padding: 8px 16px;

    /* 修改密码行 — 整行可点击 */
    .password-row {
      display: flex;
      position: relative;
      align-items: center;
      justify-content: space-between;
      padding: 12px 0;
      cursor: pointer;
      transition: opacity 0.2s ease;
      &:hover {
        opacity: 0.7;
      }
      &:not(:last-child)::after {
        position: absolute;
        right: 0;
        bottom: 0;
        left: 26px;
        height: 1px;
        background-color: var(--border-color);
        content: '';
      }
      .password-row-left {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--text-color);
        font-size: 14px;
        font-weight: 500;
      }
      .password-row-chevron {
        color: var(--secondary-text-color);
        flex-shrink: 0;
      }
    }

    .setting-item {
      display: flex;
      position: relative;
      justify-content: space-between;
      align-items: center;
      padding: 10px 0;
      gap: 12px;
      &:not(:last-child)::after {
        position: absolute;
        right: 0;
        bottom: 0;
        left: 48px;
        height: 1px;
        background-color: var(--border-color);
        content: '';
      }
      .setting-info {
        flex: 1;
        margin-right: 16px;
        .setting-label {
          display: block;
          font-size: 14px;
          font-weight: 500;
          color: var(--text-color);
          margin-bottom: 2px;
        }
        .setting-description {
          font-size: 12px;
          color: var(--text-muted);
        }
      }
    }
    
    .action-buttons {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin: 8px 0;
      .action-btn {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 20px;
        background-color: rgba(var(--theme-color-rgb), 0.1);
        color: var(--theme-color);
        border: 1px solid rgba(var(--theme-color-rgb), 0.2);
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
        &:hover {
          background-color: rgba(var(--theme-color-rgb), 0.2);
          transform: translateY(-1px);
        }
        &.danger {
          background-color: rgba(244, 67, 54, 0.1);
          color: #f44336;
          border-color: rgba(244, 67, 54, 0.2);
          &:hover {
            background-color: rgba(244, 67, 54, 0.2);
          }
        }
      }
    }
    
    .gift-card-form {
      margin: 4px 0;
      .input-group {
        display: flex;
        gap: 8px;
        @media(max-width:576px) {
          flex-direction: column;
        }
        .gift-card-input {
          flex: 1;
          padding: 8px 12px;
          border: 1px solid var(--card-border);
          border-radius: 20px;
          background-color: var(--bg-secondary);
          color: var(--text-color);
          font-size: 14px;
          transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
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
          gap: 6px;
          padding: 8px 14px;
          border-radius: 20px;
          background-color: var(--theme-color);
          color: white;
          border: none;
          font-size: 13px;
          font-weight: 500;
          cursor: pointer;
          transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
          @media(max-width:576px) {
            width: 100%;
          }
          &:hover:not(:disabled) {
            background-color: rgba(var(--theme-color-rgb), 0.9);
            transform: translateY(-1px);
          }
          &:disabled {
            opacity: 0.7;
            cursor: not-allowed;
          }
          .loader {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s linear infinite;
          }
        }
      }
    }
  }
}

.account-actions-card {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.logout-button,
.account-deletion-button {
  display: inline-flex;
  width: auto;
  min-height: 48px;
  margin: 0;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border: 0;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  transition: background-color .2s ease, border-color .2s ease, color .2s ease, transform .2s ease, box-shadow .2s ease;

  &:hover { transform: translateY(-1px); }
  &:active { transform: translateY(0); }
}
.logout-button {
  width: 100%;
  min-width: 0;
  margin-left: auto;
  margin-right: auto;
  color: #475569;
  background: #f1f5f9;
  border-color: #dbe3ed;

  &:hover {
    color: #334155;
    background: #e8eef5;
    border-color: #cbd5e1;
    box-shadow: 0 4px 12px rgba(71, 85, 105, .1);
  }
}
.account-deletion-button {
  display: flex;
  width: fit-content;
  min-height: 40px;
  margin: 12px auto 0;
  color: #dc2626;
  background: transparent;

  &:hover {
    color: #b91c1c;
    background: rgba(239, 68, 68, .08);
    box-shadow: none;
  }
}
.dark-theme .logout-button,
.dark .logout-button {
  color: #cbd5e1;
  background: rgba(148, 163, 184, .1);
  border-color: rgba(148, 163, 184, .18);
}
.dark-theme .account-deletion-button,
.dark .account-deletion-button {
  color: #fca5a5;
  background: transparent;
}

/* 开关按钮 - 恢复原始尺寸 */
.switch {
  position: relative;
  display: inline-block;
  width: 46px;
  height: 24px;
  
  &.disabled {
    opacity: 0.7;
    cursor: not-allowed;
  }
  
  input {
    opacity: 0;
    width: 0;
    height: 0;
    
    &:checked + .slider {
      background-color: var(--switch-track-on);
      box-shadow: none;
    }
    
    &:checked + .slider:before {
      background-color: var(--switch-thumb-on);
      transform: translateX(22px);
    }
    
    &:disabled + .slider {
      cursor: not-allowed;
    }
  }
  
  .slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: var(--switch-track-off);
    box-shadow: inset 0 0 0 1px var(--switch-track-border);
    transition: .4s;
    
    &.loading {
      overflow: hidden;
      
      &:before {
        animation: pulse 1.5s infinite;
      }
      
      &:after {
        content: "";
        position: absolute;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
        animation: sweep 1.5s infinite;
      }
    }
    
    &:before {
      position: absolute;
      content: "";
      height: 18px;
      width: 18px;
      left: 3px;
      bottom: 3px;
      background-color: var(--switch-thumb-off);
      transition: .4s;
      z-index: 1;
    }
    
    &.round {
      border-radius: 34px;
      
      &:before {
        border-radius: 50%;
      }
    }
  }
}

/* 其余样式（骨架屏、设备列表等保持紧凑） */
.profile-container {
  padding: 20px;
  padding-bottom: calc(20px + 70px);
  @media (min-width: 768px) {
    padding: 1.5rem 20px;
    padding-bottom: 2rem;
  }
}

.profile-inner {
  max-width: 900px;
  margin: 0 auto;
}

.bottom-safe-area {
  height: 20px;
  width: 100%;
  margin-top: 20px;
  margin-bottom: 60px;
  @media (min-width: 768px) { display: none; }
}

/* 骨架屏样式调整 */
.profile-skeleton .profile-card {
  position: relative;
  overflow: hidden;
  &::after {
    content: '';
    position: absolute;
    top: 0; right: 0; bottom: 0; left: 0;
    transform: translateX(-100%);
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), rgba(255,255,255,0.2), transparent);
    animation: shimmer 2s infinite;
    z-index: 1;
  }
}
.skeleton-title { height: 18px; width: 100px; border-radius: 4px; background-color: var(--skeleton-color); }
.skeleton-content { padding: 12px 16px; }
.skeleton-item { margin-bottom: 12px; &:last-child { margin-bottom: 0; } }
.skeleton-label { height: 12px; width: 80px; border-radius: 4px; margin-bottom: 6px; background-color: var(--skeleton-color); }
.skeleton-value { height: 14px; width: 120px; border-radius: 4px; background-color: var(--skeleton-color); }
.skeleton-description { height: 11px; width: 160px; border-radius: 4px; margin-top: 5px; background-color: var(--skeleton-color); }
.skeleton-setting { padding: 10px 0; .skeleton-text { margin-right: 12px; } }
.skeleton-button { height: 34px; width: 100px; border-radius: 20px; background-color: var(--skeleton-color); }

:root { --skeleton-color: rgba(0,0,0,0.08); }
body.dark-theme { --skeleton-color: rgba(255,255,255,0.06); }
@keyframes shimmer { 100% { transform: translateX(100%); } }

/* 错误状态 */
.error-state {
  display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2rem 1rem; text-align: center;
  p { margin-top: 0.75rem; color: var(--text-muted); font-size: 1rem; }
  .error-icon { color: var(--text-muted); opacity: 0.7; }
  .retry-button {
    margin-top: 1rem; height: 36px; min-width: 100px; padding: 0 14px; font-size: 13px;
  }
}

/* 设备列表紧凑 */
.device-list {
  .device-item {
    padding: 8px 12px;
    .device-icon { width: 32px; height: 32px; margin-right: 10px; }
    .device-info {
      .device-name { font-size: 14px; margin-bottom: 2px; }
      .device-meta { font-size: 12px; gap: 6px; }
    }
  }
}
.device-loading .session-skeleton { padding: 8px 12px; margin-bottom: 8px; .session-skeleton-icon { width: 32px; height: 32px; } }

/* ========== 退出登录卡片 ========== */
.logout-card {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 14px 20px;
  margin-bottom: 24px;
  border-radius: 20px;
  background-color: rgba(244, 67, 54, 0.08);
  border: 1px solid rgba(244, 67, 54, 0.18);
  box-shadow: none;
  cursor: pointer;
  transition: background-color var(--motion-base) ease, border-color var(--motion-base) ease,
    color var(--motion-base) ease, transform var(--motion-base) ease;
  .logout-text {
    font-size: 15px;
    font-weight: 600;
    color: #e57373;
  }
  .logout-icon {
    color: #e57373;
    transition: transform 0.2s ease;
  }
  &:hover {
    box-shadow: 0 2px 8px rgba(244, 67, 54, 0.15);
    background-color: rgba(244, 67, 54, 0.12);
    border-color: rgba(244, 67, 54, 0.25);
    transform: translateY(-1px);
    .logout-icon { transform: translateX(3px); }
  }
  &:active {
    transform: translateY(0);
  }
}
.dark-theme .logout-card,
.dark .logout-card {
  background-color: rgba(244, 67, 54, 0.12) !important;
  border-color: rgba(244, 67, 54, 0.2) !important; }


/* 弹窗遮罩层 */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(9, 13, 24, 0.58);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  padding: 20px;
}

/* ========== 账号安全弹窗 ========== */
.password-modal {
  width: 100%;
  max-width: 440px;
  max-height: calc(100vh - 40px);
  display: flex;
  flex-direction: column;
  background: var(--card-background);
  border-radius: 22px;
  border: 1px solid var(--card-border);
  box-shadow: 0 24px 64px rgba(5, 10, 24, 0.24);
  overflow: hidden;

  &.email-modal {
    max-width: 480px;
  }

  &.deletion-modal {
    max-width: 500px;
  }
}

.deletion-header .pm-header-icon {
  color: #dc2626;
  background: rgba(239, 68, 68, .12);
}

.deletion-warning {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 13px 14px;
  color: #b91c1c;
  background: rgba(239, 68, 68, .08);
  border: 1px solid rgba(239, 68, 68, .2);
  border-radius: 13px;

  svg { flex: 0 0 auto; margin-top: 1px; }
  p { margin: 0; font-size: 13px; line-height: 1.6; }
}

.dark-theme .deletion-warning,
.dark .deletion-warning {
  color: #fecaca;
  background: rgba(239, 68, 68, .12);
  border-color: rgba(248, 113, 113, .22);
}

.pm-header {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 22px 24px 18px;
  border-bottom: 1px solid var(--card-border);

  .pm-header-icon {
    width: 46px;
    height: 46px;
    border-radius: 14px;
    background: rgba(var(--theme-color-rgb), 0.12);
    color: var(--theme-color);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }
  .pm-header-text {
    flex: 1;
    min-width: 0;

    h3 {
      margin: 0 0 4px;
      font-size: 18px;
      font-weight: 700;
      line-height: 1.35;
      color: var(--text-color);
    }
    p {
      margin: 0;
      font-size: 12px;
      line-height: 1.5;
      color: var(--secondary-text-color);
    }
  }
  .pm-close {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: none;
    background: transparent;
    color: var(--secondary-text-color);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;

    &:hover {
      background: rgba(var(--text-color-rgb), 0.06);
      color: var(--text-color);
    }
  }
}
.pm-body {
  padding: 22px 24px 24px;
  display: flex;
  flex-direction: column;
  gap: 17px;
  overflow-y: auto;
  overscroll-behavior: contain;

  .pm-field {
    label {
      display: block;
      margin-bottom: 7px;
      font-size: 13px;
      font-weight: 650;
      line-height: 1.4;
      color: var(--text-color);
    }

    .pm-input-wrap {
      min-width: 0;
      height: 48px;
      display: flex;
      align-items: center;
      border: 1px solid var(--card-border);
      border-radius: 13px;
      background: rgba(var(--text-color-rgb), 0.025);
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
      overflow: hidden;

      &:focus-within {
        border-color: var(--theme-color);
        background: var(--card-background);
        box-shadow: 0 0 0 3px rgba(var(--theme-color-rgb), 0.12);
      }

      &.pm-error {
        border-color: var(--error-color);

        &:focus-within {
          box-shadow: 0 0 0 3px rgba(var(--error-color-rgb), 0.12);
        }
      }

      .pm-input-icon {
        color: var(--secondary-text-color);
        margin-left: 14px;
        flex-shrink: 0;
      }

      input {
        flex: 1;
        min-width: 0;
        height: 100%;
        border: none;
        outline: none;
        background: transparent;
        padding: 0 13px;
        font-size: 14px;
        color: var(--text-color);

        &::placeholder {
          color: rgba(var(--text-color-rgb), 0.4);
        }
      }

      .pm-toggle {
        width: 44px;
        height: 100%;
        padding: 0;
        border: none;
        background: transparent;
        color: var(--secondary-text-color);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: color 0.2s ease, background-color 0.2s ease;

        &:hover {
          color: var(--text-color);
          background: rgba(var(--text-color-rgb), 0.05);
        }
      }
    }

    .pm-readonly-wrap {
      background: rgba(var(--text-color-rgb), 0.045);

      input {
        color: var(--secondary-text-color);
        cursor: default;
      }
    }

    .pm-input-with-button {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 118px;
      align-items: stretch;
      gap: 10px;
    }

    .pm-send-code-btn {
      height: 48px;
      padding: 0 14px;
      border: 1px solid rgba(var(--theme-color-rgb), 0.2);
      border-radius: 13px;
      background: rgba(var(--theme-color-rgb), 0.1);
      color: var(--theme-color);
      font-size: 13px;
      font-weight: 650;
      white-space: nowrap;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;

      &:hover:not(:disabled) {
        background: rgba(var(--theme-color-rgb), 0.16);
        border-color: rgba(var(--theme-color-rgb), 0.32);
      }

      &:disabled {
        opacity: 0.58;
        cursor: not-allowed;
      }

      .loader {
        width: 15px;
        height: 15px;
        border: 2px solid rgba(var(--theme-color-rgb), 0.22);
        border-top-color: var(--theme-color);
        border-radius: 50%;
        animation: spin 0.6s linear infinite;
      }
    }

    .pm-hint {
      margin-top: 6px;
      font-size: 12px;
      line-height: 1.4;

      &.error { color: var(--error-color); }
    }
  }
}

/* 密码强度 */
.pm-strength {
  margin-top: 8px;
  display: flex;
  align-items: center;
  gap: 10px;
  .pm-strength-bar {
    flex: 1;
    height: 4px;
    border-radius: 2px;
    background: var(--border-color);
    overflow: hidden;
    .pm-strength-fill {
      height: 100%;
      border-radius: 2px;
      transition: width 0.3s ease, background-color 0.3s ease;
      &.weak { background: var(--error-color); }
      &.medium { background: var(--warning-color); }
      &.strong { background: var(--success-color); }
    }
  }
  .pm-strength-label {
    font-size: 12px;
    font-weight: 600;
    &.weak { color: var(--error-color); }
    &.medium { color: var(--warning-color); }
    &.strong { color: var(--success-color); }
  }
}
.pm-footer {
  display: flex;
  gap: 12px;
  padding: 18px 24px 22px;
  border-top: 1px solid var(--card-border);
  background: rgba(var(--text-color-rgb), 0.012);

  button {
    flex: 1;
    min-width: 0;
    height: 46px;
    padding: 0 14px;
    border-radius: 13px;
    font-size: 14px;
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
    transition: color var(--motion-base) ease, background-color var(--motion-base) ease, border-color var(--motion-base) ease, box-shadow var(--motion-base) ease, transform var(--motion-base) ease;
    border: none;
  }
  .pm-btn-cancel {
    background: rgba(var(--text-color-rgb), 0.06);
    color: var(--text-color);
    &:hover { background: rgba(var(--text-color-rgb), 0.10); }
  }
  .pm-btn-submit {
    background: var(--theme-color);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;

    &:hover:not(:disabled) {
      background: var(--primary-color-hover);
      box-shadow: 0 4px 12px rgba(var(--theme-color-rgb), 0.25);
      transform: translateY(-1px);
    }
    &:disabled {
      opacity: 0.52;
      cursor: not-allowed;
    }
    .loader {
      width: 16px;
      height: 16px;
      border: 2px solid rgba(255,255,255,0.3);
      border-top-color: #fff;
      border-radius: 50%;
      animation: spin 0.6s linear infinite;
      display: inline-block;
      margin-right: 6px;
      vertical-align: middle;
    }
  }

  .deletion-submit {
    background: #dc2626;

    &:hover:not(:disabled) {
      background: #b91c1c;
      box-shadow: 0 4px 12px rgba(220, 38, 38, .28);
    }
  }
}

@media (max-width: 560px) {
  .modal-overlay {
    align-items: flex-end;
    padding: 12px;
  }

  .password-modal,
  .password-modal.email-modal,
  .password-modal.deletion-modal {
    max-width: none;
    max-height: calc(100dvh - 24px);
    border-radius: 22px;
  }

  .pm-header {
    padding: 18px 18px 15px;

    .pm-header-icon {
      width: 42px;
      height: 42px;
    }
  }

  .pm-body {
    padding: 18px;
    gap: 15px;

    .pm-field .pm-input-with-button {
      grid-template-columns: minmax(0, 1fr) 104px;
      gap: 8px;
    }
  }

  .pm-footer {
    padding: 14px 18px 18px;
  }
}

/* 弹窗紧凑 (保留给其他弹窗) */
.modal-content { max-width: 400px; }
.modal-body { padding: 16px; .form-group { margin-bottom: 14px; } }
.modal-footer { padding: 12px 16px; }
/* 动画和辅助 */
@keyframes spin { to { transform: rotate(360deg); } }
.modal-fade-enter-active, .modal-fade-leave-active { transition: opacity 0.3s ease; }
.modal-fade-enter-from, .modal-fade-leave-to { opacity: 0; }
@keyframes pulse {
  0% { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.7); }
  70% { box-shadow: 0 0 0 5px rgba(255, 255, 255, 0); }
  100% { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0); }
}
@keyframes sweep {
  0% { transform: translateX(-100%); }
  100% { transform: translateX(100%); }
}
</style>
