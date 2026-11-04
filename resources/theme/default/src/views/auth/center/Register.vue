<template>
  <div class="auth-container">
    <!-- 域名授权验证提示 -->
    <DomainAuthAlert 
      :is-authorized="authStatus.isAuthorized" 
      :api-domain="authStatus.apiDomain" 
    />
    
    <!-- 背景装饰 -->
    <div class="background-decoration">
      <div class="floating-ball ball-1"></div>
      <div class="floating-ball ball-2"></div>
      <div class="floating-ball ball-3"></div>
    </div>
    
    <!-- 顶部工具栏：语言选择器和主题切换 -->
    <div class="top-toolbar">
      <ThemeToggle />
      <LanguageSelector />
    </div>
    
    <div class="auth-card" v-if="configLoading">
      <div class="loading-container">
        <div class="loading-spinner"></div>
        <p class="">{{ $t('common.loading') }}</p>
      </div>
    </div>
    
    <div class="auth-card" v-else>
      <div class="auth-header">
        <div class="auth-logo">
          <img 
            :src="logoPath" 
            alt="Logo" 
            @error="handleLogoError" 
          />
        </div>
        <h1 class="auth-title">{{ $t('auth.registerTitle') }}</h1>
        <p class="auth-subtitle">{{ $t('auth.registerSubtitle') }}</p>
      </div>
      
      <form class="auth-form" @submit.prevent="handleRegister">
        <!-- 邮箱 -->
        <div class="form-group">
          <div class="input-with-suffix" v-if="config.email_whitelist_suffix && config.email_whitelist_suffix.length > 0">
            <div class="input-with-icon email-prefix">
              <IconMail class="input-icon" />
              <input 
                type="text" 
                id="emailPrefix" 
                class="form-control" 
                v-model="emailPrefix" 
                :placeholder="$t('auth.emailPrefixPlaceholder')"
                :aria-invalid="!!errors.email"
                :aria-describedby="errors.email ? 'register-email-error' : undefined"
                @input="handleEmailPrefixChange"
                :disabled="codeSent"
                required
              />
            </div>
            <div class="email-suffix-separator">@</div>
            <select v-model="selectedSuffix" class="email-suffix" :disabled="codeSent" :aria-label="$t('common.selectEmailDomain')" @change="updateEmail">
              <option v-for="suffix in config.email_whitelist_suffix" :key="suffix" :value="suffix">{{ suffix }}</option>
            </select>
          </div>
          <div class="input-with-icon" v-else>
            <IconMail class="input-icon" />
            <input 
              type="email" 
              id="email" 
              class="form-control" 
              v-model="formData.email" 
              :placeholder="$t('auth.emailPlaceholder')"
              :aria-invalid="!!errors.email"
              :aria-describedby="errors.email ? 'register-email-error' : undefined"
              :disabled="codeSent"
              required
            />
          </div>
          <span v-if="errors.email" id="register-email-error" class="error-message" role="alert">{{ errors.email }}</span>
        </div>
        
        <!-- 验证码 -->
        <div class="form-group" v-if="config.is_email_verify === 1">
          <div class="input-with-button">
            <div class="input-with-icon verification-input">
              <IconCode class="input-icon" />
              <input 
                type="text" 
                id="verificationCode" 
                class="form-control" 
                v-model="formData.verificationCode" 
                :placeholder="$t('auth.verificationCodePlaceholder')"
                inputmode="numeric"
                pattern="[0-9]*"
                maxlength="6"
                :aria-invalid="!!errors.verificationCode"
                :aria-describedby="errors.verificationCode ? 'register-code-error' : undefined"
                required
              />
            </div>
            <button 
              type="button" 
              class="send-code-btn" 
              @click="sendVerificationCode"
              :disabled="!isValidEmail(formData.email) || cooldown > 0 || loading"
            >
              <IconSend v-if="cooldown === 0" class="icon-left" />
              <span v-if="cooldown > 0" class="">{{ cooldown }}s</span>
              <span v-else class="">{{ $t('common.sendCode') }}</span>
            </button>
          </div>
          <span v-if="errors.verificationCode" id="register-code-error" class="error-message" role="alert">{{ errors.verificationCode }}</span>
        </div>
        
        <!-- 密码 -->
        <div class="form-group">
          <div class="input-with-icon">
            <IconLock class="input-icon" />
            <input 
              :type="showPassword ? 'text' : 'password'"
              id="password" 
              class="form-control" 
              v-model="formData.password" 
              :placeholder="$t('auth.passwordPlaceholder')"
              :aria-invalid="!!errors.password"
              :aria-describedby="errors.password ? 'register-password-error' : undefined"
              required
            />
            <button type="button" class="password-toggle" :aria-label="$t(showPassword ? 'common.hidePassword' : 'common.showPassword')" :aria-pressed="showPassword" @click="showPassword = !showPassword">
              <IconEye v-if="!showPassword" />
              <IconEyeOff v-else />
            </button>
          </div>
          <span v-if="errors.password" id="register-password-error" class="error-message" role="alert">{{ errors.password }}</span>
        </div>
        
        <!-- 确认密码 -->
        <div class="form-group">
          <div class="input-with-icon">
            <IconLock class="input-icon" />
            <input 
              :type="showConfirmPassword ? 'text' : 'password'"
              id="confirmPassword" 
              class="form-control" 
              v-model="formData.confirmPassword" 
              :placeholder="$t('auth.confirmPasswordPlaceholder')"
              :aria-invalid="!!errors.confirmPassword"
              :aria-describedby="errors.confirmPassword ? 'register-confirm-error' : undefined"
              required
            />
            <button type="button" class="password-toggle" :aria-label="$t(showConfirmPassword ? 'common.hidePassword' : 'common.showPassword')" :aria-pressed="showConfirmPassword" @click="showConfirmPassword = !showConfirmPassword">
              <IconEye v-if="!showConfirmPassword" />
              <IconEyeOff v-else />
            </button>
          </div>
          <span v-if="errors.confirmPassword" id="register-confirm-error" class="error-message" role="alert">{{ errors.confirmPassword }}</span>
        </div>
        
        <!-- 邀请码（选填时占位符后显示“（选填）”） -->
        <div class="form-group">
          <div class="input-with-icon">
            <IconTicket class="input-icon" />
            <input 
              type="text" 
              id="inviteCode" 
              class="form-control" 
              v-model="formData.inviteCode" 
              :placeholder="inviteCodePlaceholder"
              :required="config.is_invite_force === 1"
              :disabled="inviteCodeFromUrl"
              :readonly="inviteCodeFromUrl"
            />
          </div>
          <span v-if="errors.inviteCode" class="error-message">{{ errors.inviteCode }}</span>
        </div>
        
        <!-- 人机验证 -->
        <div class="form-group" v-if="config.is_recaptcha === 1">
          <div class="captcha-container">
            <div v-if="captchaConfig.type === 'google'" class="google-captcha" @click.stop>
              <div id="form-recaptcha"></div>
            </div>
            <div v-else-if="captchaConfig.type === 'cloudflare'" class="cloudflare-captcha" @click.stop>
              <div id="form-turnstile"></div>
            </div>
          </div>
        </div>
        
        <!-- 同意条款（无星号） -->
        <div class="form-group agreement-checkbox">
          <label class="checkbox-container">
            <input type="checkbox" v-model="formData.agreeTerms" />
            <span class="checkmark"></span>
            <span class="checkbox-label">
              {{ $t('auth.agreeToTerms') }} 
              <a :href="config.tos_url || '#'" target="_blank" class="">{{ $t('auth.termsOfService') }}</a>
            </span>
          </label>
          <span v-if="errors.agreeTerms" class="error-message">{{ errors.agreeTerms }}</span>
        </div>
        
        <div class="form-group">
          <button 
            type="submit" 
            class="btn btn-primary btn-block" 
            :disabled="loading || !formData.agreeTerms"
          >
            <span v-if="loading" class="loading-wrapper">
              <span class="">{{ $t('common.loading') }}</span>
            </span>
            <span v-else class="">
              {{ $t('auth.createAccount') }}
              <IconArrowRight class="icon-right" />
            </span>
          </button>
        </div>
      </form>
      
      <div class="auth-footer">
        <div class="register-link-wrapper">
          <span class="register-text">{{ $t('auth.alreadyHaveAccount') }}</span>
          <router-link to="/login" class="register-link">
            {{ $t('common.login') }}
          </router-link>
        </div>
      </div>
    </div>
    
    <!-- 验证码弹窗 -->
    <div class="captcha-modal" v-if="showCaptchaModal" :class="{ 'closing': isClosingModal }">
      <div class="captcha-modal-overlay" @click="closeCaptchaModal"></div>
      <div v-accessible-dialog="closeCaptchaModal" class="captcha-modal-content" :class="{ 'closing': isClosingModal }" :aria-label="$t('auth.captcha')">
        <div class="captcha-modal-header">
          <h3>{{ $t('auth.captcha') }}</h3>
          <button class="close-btn" type="button" :aria-label="$t('common.close')" @click="closeCaptchaModal">
            <span>&times;</span>
          </button>
        </div>
        <div class="captcha-modal-body">
          <p>{{ $t('auth.captchaRequired') }}</p>
          <div v-if="captchaConfig.type === 'google'" class="google-captcha">
            <div id="modal-recaptcha"></div>
          </div>
          <div v-else-if="captchaConfig.type === 'cloudflare'" class="cloudflare-captcha">
            <div id="modal-turnstile"></div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- 自定义弹窗 -->
    <AuthPopup
      :show-popup="showAuthPopup"
      :title="authPopupConfig.title"
      :content="authPopupConfig.content"
      :cooldown-hours="authPopupConfig.cooldownHours"
      :close-wait-seconds="authPopupConfig.closeWaitSeconds"
      @close="handleAuthPopupClose"
    />
  </div>
</template>

<script>
import { reactive, ref, onMounted, onBeforeUnmount, computed, getCurrentInstance, onActivated } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useToast } from '@/composables/useToast';
import ThemeToggle from '@/components/common/ThemeToggle.vue';
import LanguageSelector from '@/components/common/LanguageSelector.vue';
import { isValidEmail } from '@/utils/validators';
import IconMail from '@/components/icons/IconMail.vue';
import IconCode from '@/components/icons/IconCode.vue';
import IconLock from '@/components/icons/IconLock.vue';
import IconTicket from '@/components/icons/IconTicket.vue';
import IconSend from '@/components/icons/IconSend.vue';
import IconArrowRight from '@/components/icons/IconArrowRight.vue';
import IconEye from '@/components/icons/IconEye.vue';
import IconEyeOff from '@/components/icons/IconEyeOff.vue';
import { register, checkLoginStatus, getWebsiteConfig, sendEmailVerify } from '@/api/auth';
import { applyDomainAuth } from '@/utils/licenseAuth';
import DomainAuthAlert from '@/components/common/DomainAuthAlert.vue';
import { CAPTCHA_CONFIG, AUTH_CONFIG } from '@/utils/baseConfig';
import AuthPopup from '@/components/auth/AuthPopup.vue';
import { shouldShowAuthPopup } from '@/utils/authPopupState';

// 全局回调
window.onCaptchaVerified = function(response) {
  if (window._registerInstance) {
    window._registerInstance.handleCaptchaResponse(response);
  }
};
window.onCaptchaModalVerified = function(response) {
  if (window._registerInstance) {
    window._registerInstance.handleCaptchaModalResponse(response);
  }
};

let isGoogleRecaptchaRendered = false;

export default {
  name: 'RegisterView',
  components: {
    ThemeToggle,
    LanguageSelector,
    IconMail,
    IconCode,
    IconLock,
    IconTicket,
    IconSend,
    IconArrowRight,
    IconEye,
    IconEyeOff,
    DomainAuthAlert,
    AuthPopup
  },
  setup() {
    const { t } = useI18n();
    const router = useRouter();
    const { showToast } = useToast();
    
    const loading = ref(false);
    const configLoading = ref(true);
    const codeSent = ref(false);
    const cooldown = ref(0);
    const inviteCodeFromUrl = ref(false);
    
    const showCaptchaModal = ref(false);
    const captchaModalResponse = ref('');
    const isClosingModal = ref(false);
    
    const showAuthPopup = ref(false);
    const authPopupConfig = reactive({
      title: AUTH_CONFIG.popup?.title || '',
      content: AUTH_CONFIG.popup?.content || '',
      cooldownHours: AUTH_CONFIG.popup?.cooldownHours || 24,
      closeWaitSeconds: AUTH_CONFIG.popup?.closeWaitSeconds || 0
    });
    
    const handleAuthPopupClose = () => {
      showAuthPopup.value = false;
    };
    
    const logoPath = ref('/theme/default/images/logo.png');
    const handleLogoError = () => {
      logoPath.value = '/theme/default/images/logo.png';
    };
    
    const authStatus = ref({
      isAuthorized: true,
      apiDomain: ''
    });
    
    const config = reactive({
      tos_url: '',
      is_email_verify: 0,
      is_invite_force: 0,
      email_whitelist_suffix: [],
      is_recaptcha: 0,
      recaptcha_site_key: '',
      app_description: '',
      app_url: ''
    });
    
    const captchaConfig = reactive({
      type: CAPTCHA_CONFIG.captchaType,
      siteKey: '',
      verifyUrl: CAPTCHA_CONFIG[CAPTCHA_CONFIG.captchaType]?.verifyUrl || ''
    });
    
    const captchaResponse = ref('');
    const app = getCurrentInstance();
    
    if (app && app.proxy) {
      window._registerInstance = {
        handleCaptchaResponse: (response) => {
          captchaResponse.value = response;
        },
        handleCaptchaModalResponse: (response) => {
          if (response && typeof response === 'string' && response.trim().length > 0) {
            captchaModalResponse.value = response;
            setTimeout(() => {
              closeCaptchaModal();
              sendVerificationCodeWithCaptcha(response);
            }, 100);
          }
        }
      };
    }
    
    const emailPrefix = ref('');
    const selectedSuffix = ref('');
    
    const formData = reactive({
      email: '',
      verificationCode: '',
      password: '',
      confirmPassword: '',
      inviteCode: '',
      agreeTerms: AUTH_CONFIG.autoAgreeTerms
    });
    
    const errors = reactive({
      email: '',
      verificationCode: '',
      password: '',
      confirmPassword: '',
      inviteCode: '',
      agreeTerms: ''
    });
    
    const showPassword = ref(false);
    const showConfirmPassword = ref(false);
    
    // 邀请码占位符：必填时无后缀，选填时添加“（选填）”
    const inviteCodePlaceholder = computed(() => {
      const base = t('auth.inviteCodePlaceholder');
      if (config.is_invite_force === 1) {
        return base;
      } else {
        return `${base}（选填）`;
      }
    });
    
    const needCaptchaForEmailVerify = computed(() => config.is_recaptcha === 1);
    
    const fetchWebsiteConfig = async () => {
      try {
        configLoading.value = true;
        const response = await getWebsiteConfig();
        if (response && response.data) {
          Object.assign(config, response.data);
          if (Array.isArray(config.email_whitelist_suffix) && config.email_whitelist_suffix.length > 0) {
            selectedSuffix.value = config.email_whitelist_suffix[0];
          }
          if (config.is_recaptcha === 1) {
            captchaConfig.siteKey = config.recaptcha_site_key;
          }
        }
      } catch (error) {
        showToast(t('messages.configLoadFailed'), 'error');
      } finally {
        configLoading.value = false;
      }
    };
    
    const updateEmail = () => {
      if (emailPrefix.value && selectedSuffix.value) {
        formData.email = `${emailPrefix.value}@${selectedSuffix.value}`;
      } else {
        formData.email = emailPrefix.value;
      }
    };
    
    const handleEmailPrefixChange = () => updateEmail();
    
    const showCaptchaModalDialog = () => {
      showCaptchaModal.value = true;
      captchaModalResponse.value = '';
      setTimeout(() => {
        if (captchaConfig.type === 'google' && window.grecaptcha) {
          const container = document.getElementById('modal-recaptcha');
          if (container) {
            container.innerHTML = '';
            window.grecaptcha.render('modal-recaptcha', {
              'sitekey': captchaConfig.siteKey,
              'callback': 'onCaptchaModalVerified',
              'theme': document.body.classList.contains('dark-theme') ? 'dark' : 'light'
            });
          }
        } else if (captchaConfig.type === 'cloudflare' && window.turnstile) {
          const container = document.getElementById('modal-turnstile');
          if (container) {
            container.innerHTML = '';
            if (window.turnstile.reset) {
              try {
                window.turnstile.reset();
              } catch (error) {
                console.debug('Turnstile 重置失败', error);
              }
            }
            try {
              window.turnstile.render('#modal-turnstile', {
                'sitekey': captchaConfig.siteKey,
                'callback': function(token) {
                  if (window._registerInstance) window._registerInstance.handleCaptchaModalResponse(token);
                },
                'theme': document.body.classList.contains('dark-theme') ? 'dark' : 'light',
                'retry': 'auto',
                'retry-interval': 5000,
                'refresh-expired': 'manual',
                'tabindex': 0,
                'execution': 'render'
              });
            } catch (error) {
              console.error('Turnstile渲染错误:', error);
            }
          }
        }
      }, 300);
    };
    
    const closeCaptchaModal = () => {
      isClosingModal.value = true;
      setTimeout(() => {
        showCaptchaModal.value = false;
        isClosingModal.value = false;
      }, 300);
    };
    
    const sendVerificationCodeWithCaptcha = async (captchaData) => {
      try {
        loading.value = true;
        const sendData = { email: formData.email, isForgetPassword: false };
        if (captchaData) sendData.recaptcha_data = captchaData;
        const response = await sendEmailVerify(sendData);
        if (response && response.data === true) {
          codeSent.value = true;
          startCooldown();
          showToast(response.message || t('auth.codeSent'), 'success');
          if (AUTH_CONFIG.verificationCode && AUTH_CONFIG.verificationCode.showCheckSpamTip) {
            setTimeout(() => showToast(t('auth.checkSpam'), 'info'), AUTH_CONFIG.verificationCode.checkSpamTipDelay || 1000);
          }
        } else {
          throw new Error(response.message || t('auth.sendCodeFailed'));
        }
      } catch (error) {
        showToast(error.response?.message || error.message || t('auth.sendCodeFailed'), 'error');
      } finally {
        loading.value = false;
      }
    };
    
    const sendVerificationCode = async () => {
      errors.email = '';
      if (!formData.email) {
        errors.email = t('auth.emailRequired');
        return;
      }
      if (!isValidEmail(formData.email)) {
        errors.email = t('auth.emailInvalid');
        return;
      }
      if (config.is_recaptcha === 1 && captchaConfig.siteKey) {
        showCaptchaModalDialog();
      } else {
        sendVerificationCodeWithCaptcha();
      }
    };
    
    const startCooldown = () => {
      cooldown.value = 60;
      const timer = setInterval(() => {
        cooldown.value--;
        if (cooldown.value <= 0) clearInterval(timer);
      }, 1000);
    };
    
    const validateForm = () => {
      let isValid = true;
      errors.email = '';
      errors.verificationCode = '';
      errors.password = '';
      errors.confirmPassword = '';
      errors.inviteCode = '';
      errors.agreeTerms = '';
      
      if (config.is_email_verify !== 1) formData.verificationCode = '';
      
      if (!formData.email) {
        errors.email = t('auth.emailRequired');
        isValid = false;
      } else if (!isValidEmail(formData.email)) {
        errors.email = t('auth.emailInvalid');
        isValid = false;
      }
      
      if (config.is_email_verify === 1 && !formData.verificationCode) {
        errors.verificationCode = t('auth.codeRequired');
        isValid = false;
      }
      
      if (!formData.password) {
        errors.password = t('auth.passwordRequired');
        isValid = false;
      } else if (formData.password.length < 8) {
        errors.password = t('auth.passwordTooShort');
        isValid = false;
      }
      
      if (!formData.confirmPassword) {
        errors.confirmPassword = t('validation.confirmPasswordRequired');
        isValid = false;
      } else if (formData.password !== formData.confirmPassword) {
        errors.confirmPassword = t('validation.passwordsDontMatch');
        isValid = false;
      }
      
      if (config.is_invite_force === 1 && !formData.inviteCode) {
        errors.inviteCode = t('auth.inviteCodeRequired');
        isValid = false;
      }
      
      if (!formData.agreeTerms) {
        errors.agreeTerms = t('auth.mustAgreeToTerms');
        isValid = false;
      }
      
      if (config.is_recaptcha === 1) {
        const captchaElem = document.querySelector('[name="g-recaptcha-response"]') || document.querySelector('[name="cf-turnstile-response"]');
        if (!captchaElem?.value && !captchaResponse.value) {
          showToast(t('auth.captchaRequired'), 'error');
          isValid = false;
        }
      }
      return isValid;
    };
    
    const handleRegister = async () => {
      if (!validateForm()) return;
      try {
        loading.value = true;
        const registerData = {
          email: formData.email,
          password: formData.password
        };
        if (config.is_email_verify === 1) registerData.email_code = String(formData.verificationCode).trim();
        if (formData.inviteCode) registerData.invite_code = formData.inviteCode;
        if (config.is_recaptcha === 1) {
          const captchaElem = document.querySelector('[name="g-recaptcha-response"]') || document.querySelector('[name="cf-turnstile-response"]');
          registerData.recaptcha_data = captchaElem?.value || captchaResponse.value;
        }
        const response = await register(registerData);
        showToast(response.message || t('auth.registerSuccess'), 'success');
        setTimeout(() => router.push('/dashboard'), 300);
      } catch (error) {
        showToast(error.response?.message || error.message || t('auth.registerFailed'), 'error');
        if (window.grecaptcha) window.grecaptcha.reset();
      } finally {
        loading.value = false;
      }
    };
    
    const renderFormCaptcha = () => {
      if (config.is_recaptcha !== 1 || !captchaConfig.siteKey) return;
      setTimeout(() => {
        if (captchaConfig.type === 'google' && window.grecaptcha) {
          const container = document.getElementById('form-recaptcha');
          if (container && !isGoogleRecaptchaRendered) {
            container.innerHTML = '';
            try {
              window.grecaptcha.render('form-recaptcha', {
                'sitekey': captchaConfig.siteKey,
                'callback': (token) => { if (window._registerInstance) window._registerInstance.handleCaptchaResponse(token); },
                'theme': document.body.classList.contains('dark-theme') ? 'dark' : 'light'
              });
              isGoogleRecaptchaRendered = true;
            } catch (error) {
              if (error.toString().includes('has already been rendered')) isGoogleRecaptchaRendered = true;
              else isGoogleRecaptchaRendered = false;
            }
          }
        } else if (captchaConfig.type === 'cloudflare' && window.turnstile) {
          const container = document.getElementById('form-turnstile');
          if (container) {
            container.innerHTML = '';
            try {
              window.turnstile.render('#form-turnstile', {
                'sitekey': captchaConfig.siteKey,
                'callback': (token) => { if (window._registerInstance) window._registerInstance.handleCaptchaResponse(token); },
                'theme': document.body.classList.contains('dark-theme') ? 'dark' : 'light',
                'retry': 'auto',
                'retry-interval': 5000,
                'refresh-expired': 'manual',
                'tabindex': 0,
                'execution': 'render'
              });
            } catch (error) {
              console.error('表单验证码渲染错误:', error);
            }
          }
        }
      }, 500);
    };
    
    const loadCaptchaScript = () => new Promise((resolve) => {
      if (config.is_recaptcha !== 1 || !config.recaptcha_site_key) {
        resolve();
        return;
      }
      if (window.turnstile && captchaConfig.type === 'cloudflare') {
        try {
          if (window.turnstile.reset) window.turnstile.reset();
          const formContainer = document.getElementById('form-turnstile');
          if (formContainer) {
            formContainer.innerHTML = '';
            setTimeout(() => {
              window.turnstile.render('#form-turnstile', {
                'sitekey': captchaConfig.siteKey,
                'callback': 'onCaptchaVerified',
                'theme': document.body.classList.contains('dark-theme') ? 'dark' : 'light',
                'refresh-expired': 'auto'
              });
              resolve();
            }, 100);
            return;
          }
        } catch(e) { console.error('重置Turnstile失败', e); }
      }
      const cleanup = () => {
        ['form-recaptcha', 'form-turnstile', 'modal-recaptcha', 'modal-turnstile'].forEach(id => {
          const el = document.getElementById(id);
          if (el) el.innerHTML = '';
        });
        isGoogleRecaptchaRendered = false;
      };
      cleanup();
      if (!window.turnstile || captchaConfig.type !== 'cloudflare') {
        const script = document.createElement('script');
        script.src = captchaConfig.type === 'google'
          ? 'https://www.recaptcha.net/recaptcha/api.js?onload=captchaScriptLoaded&render=explicit'
          : 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=captchaScriptLoaded&render=explicit';
        script.async = true;
        script.defer = true;
        window.captchaScriptLoaded = () => {
          renderFormCaptcha();
          setTimeout(() => resolve(), 100);
        };
        document.head.appendChild(script);
      } else {
        renderFormCaptcha();
        resolve();
      }
    });
    
    const handleWindowFocus = () => {
      if (config.is_recaptcha === 1) setTimeout(() => renderFormCaptcha(), 300);
    };

    onMounted(() => {
      authStatus.value = applyDomainAuth();
      const urlParams = new URLSearchParams(window.location.search);
      const hashParams = new URLSearchParams(window.location.hash.replace('#', '?'));
      if (urlParams.has('logout') || hashParams.has('logout')) {
        showToast(t('auth.logoutSuccess'), 'success');
        const newUrl = window.location.href.replace(/\?logout=true|&logout=true/, '');
        if (window.history && window.history.replaceState) window.history.replaceState({}, document.title, newUrl);
        fetchWebsiteConfig();
        return;
      }
      try {
        if (window._isLoggingOut === true) {
          fetchWebsiteConfig();
          return;
        }
        if (checkLoginStatus()) {
          showToast(t('auth.alreadyLoggedIn'), 'info');
          setTimeout(() => router.push('/dashboard'), 500);
          return;
        }
        if (urlParams.has('code')) {
          formData.inviteCode = urlParams.get('code');
          inviteCodeFromUrl.value = true;
        } else if (hashParams.has('code')) {
          formData.inviteCode = hashParams.get('code');
          inviteCodeFromUrl.value = true;
        } else {
          const match = window.location.href.match(/[?&]code=([^&]+)/);
          if (match && match[1]) {
            formData.inviteCode = match[1];
            inviteCodeFromUrl.value = true;
          }
        }
      } catch (error) {
        console.debug('读取邀请码失败', error);
      }
      
      fetchWebsiteConfig().then(() => {
        if (config.is_recaptcha === 1) loadCaptchaScript();
        showAuthPopup.value = shouldShowAuthPopup(AUTH_CONFIG.popup);
      });
      window.addEventListener('focus', handleWindowFocus);
    });
    
    onActivated(() => {
      if (config.is_recaptcha === 1 && captchaConfig.type === 'cloudflare' && captchaConfig.siteKey) {
        loadCaptchaScript().then(() => {
          if (window.turnstile && window.turnstile.reset) {
            try {
              window.turnstile.reset();
              const container = document.getElementById('form-turnstile');
              if (container && window.turnstile.render) {
                container.innerHTML = '';
                window.turnstile.render('#form-turnstile', {
                  'sitekey': captchaConfig.siteKey,
                  'callback': (token) => { if (window._registerInstance) window._registerInstance.handleCaptchaResponse(token); },
                  'theme': document.body.classList.contains('dark-theme') ? 'dark' : 'light',
                  'retry': 'auto',
                  'retry-interval': 5000,
                  'refresh-expired': 'manual',
                  'tabindex': 0,
                  'execution': 'render'
                });
              }
            } catch(e) { console.log('Turnstile重置失败', e); }
          }
        });
      }
    });
    
    onBeforeUnmount(() => {
      window.removeEventListener('focus', handleWindowFocus);
      isGoogleRecaptchaRendered = false;
      if (window.turnstile && window.turnstile.reset) {
        try {
          window.turnstile.reset();
        } catch (error) {
          console.debug('清理 Turnstile 失败', error);
        }
      }
      const turnstileContainer = document.getElementById('modal-turnstile');
      if (turnstileContainer) turnstileContainer.innerHTML = '';
      captchaResponse.value = '';
      captchaModalResponse.value = '';
      window.captchaScriptLoaded = undefined;
      window.onCaptchaVerified = undefined;
      window.onCaptchaModalVerified = undefined;
      window._registerInstance = null;
    });
    
    const handleCaptchaResponse = (response) => { captchaResponse.value = response; };
    const handleCaptchaModalResponse = (response) => {
      if (response && typeof response === 'string' && response.trim().length > 0) {
        captchaModalResponse.value = response;
        setTimeout(() => {
          closeCaptchaModal();
          sendVerificationCodeWithCaptcha(response);
        }, 100);
      }
    };
    
    return {
      formData,
      errors,
      loading,
      configLoading,
      codeSent,
      cooldown,
      sendVerificationCode,
      handleRegister,
      isValidEmail,
      showPassword,
      showConfirmPassword,
      config,
      emailPrefix,
      selectedSuffix,
      handleEmailPrefixChange,
      inviteCodeFromUrl,
      authStatus,
      logoPath,
      handleLogoError,
      captchaConfig,
      handleCaptchaResponse,
      showCaptchaModal,
      closeCaptchaModal,
      handleCaptchaModalResponse,
      needCaptchaForEmailVerify,
      isClosingModal,
      renderFormCaptcha,
      showAuthPopup,
      authPopupConfig,
      handleAuthPopupClose,
      inviteCodePlaceholder
    };
  }
};
</script>


<style lang="scss" scoped>
.top-toolbar {
  position: fixed;
  top: 20px;
  right: 20px;
  display: flex;
  gap: 10px;
  z-index: 10;
}

.loading-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 300px;
  
  .loading-spinner {
    width: 30px;
    height: 30px;
    border: 3px solid rgba(var(--theme-color-rgb), 0.2);
    border-radius: 50%;
    border-top-color: var(--theme-color);
    animation: spin 1s linear infinite;
    margin-bottom: 1rem;
  }
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.required {
  color: var(--error-color, #ff4d4f);
  margin-left: 4px;
  font-size: 16px;
  vertical-align: middle;
}

.optional {
  color: var(--text-muted, #999);
  margin-left: 4px;
  font-size: 12px;
  font-weight: normal;
  vertical-align: baseline;
}

.input-with-suffix {
  display: flex;
  align-items: stretch;
  width: 100%;
  border-radius: 12px;
  background-color: var(--input-bg-color, #f9f9f9);
  border: 1px solid var(--input-border-color, transparent);
  transition: color 0.3s ease, background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, transform 0.3s ease;
  position: relative;
  
  &:hover {
    background-color: var(--input-hover-bg-color, #f5f5f5);
  }
  
  &:focus-within {
    border-color: var(--theme-color);
    box-shadow: 0 0 0 2px rgba(var(--theme-color-rgb), 0.2);
    background-color: var(--input-focus-bg-color, #fff);
  }
  
  .email-prefix {
    flex: 1;
    margin: 0;
    
    .form-control {
      border: none;
      border-radius: 8px 0 0 8px;
      height: 45px;
      font-size: 14px;
      letter-spacing: 0.2px;
      background-color: transparent;
      
      &:focus {
        box-shadow: none;
      }
    }
  }
  
  .email-suffix-separator {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    color: var(--secondary-text-color);
    font-size: 14px;
    background-color: transparent;
    opacity: 0.8;
  }
  
  .email-suffix {
    position: relative;
    display: flex;
    align-items: center;
    padding: 0 12px;
    min-width: 130px;
    cursor: pointer;
    border-left: 1px solid var(--input-border-color, rgba(0, 0, 0, 0.08));
    background-color: transparent;
    transition: color 0.3s ease, background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, transform 0.3s ease;
    border-radius: 0 8px 8px 0;
    
    &.disabled {
      opacity: 0.7;
      cursor: not-allowed;
    }
    
    &:hover:not(.disabled) {
      background-color: var(--input-hover-bg-color, #f5f5f5);
    }
    
    .suffix-text {
      flex: 1;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      color: var(--primary-text-color);
      font-size: 14px;
      padding: 0 4px;
      font-weight: 500;
    }
    
    .suffix-icon {
      width: 16px;
      height: 16px;
      margin-left: 8px;
      transition: transform 0.3s ease;
      color: var(--secondary-text-color);
      opacity: 0.8;
      
      &.rotate-180 {
        transform: rotate(180deg);
      }
    }
    
    .suffix-dropdown {
      position: absolute;
      top: calc(100% + 8px);
      left: -1px;
      right: -1px;
      background-color: var(--card-background);
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
      z-index: 100;
      max-height: 240px;
      overflow-y: auto;
      animation: dropdownFadeIn 0.2s ease;
      border: 1px solid var(--input-border-color, rgba(0, 0, 0, 0.08));
      min-width: 160px;
      
      &::-webkit-scrollbar {
        width: 6px;
        height: 6px;
      }
      
      &::-webkit-scrollbar-track {
        background: transparent;
        border-radius: 3px;
      }
      
      &::-webkit-scrollbar-thumb {
        background-color: var(--scrollbar-thumb-color, rgba(0, 0, 0, 0.2));
        border-radius: 3px;
        
        &:hover {
          background-color: var(--scrollbar-thumb-hover-color, rgba(0, 0, 0, 0.3));
        }
      }
      
      .suffix-option {
        padding: 10px 16px;
        cursor: pointer;
        transition: color 0.2s, background-color 0.2s, border-color 0.2s, box-shadow 0.2s, opacity 0.2s, transform 0.2s;
        color: var(--primary-text-color);
        font-size: 14px;
        display: flex;
        align-items: center;
        
        &:hover, &.active {
          background-color: rgba(var(--theme-color-rgb), 0.1);
          color: var(--theme-color);
        }
        
        &:first-child {
          border-top-left-radius: 8px;
          border-top-right-radius: 8px;
        }
        
        &:last-child {
          border-bottom-left-radius: 8px;
          border-bottom-right-radius: 8px;
        }
      }
    }
  }
}

@keyframes dropdownFadeIn {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.input-with-icon {
  position: relative;
  width: 100%;
  
  .input-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--secondary-text-color);
    width: 20px;
    height: 20px;
  }
  
  .password-toggle {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--secondary-text-color);
    cursor: pointer;
    padding: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color 0.2s ease;
    
    &:hover {
      color: var(--theme-color);
    }
  }
  
  .form-control {
    padding-left: 40px;
    height: 45px;
    border-radius: 12px;
    border: 1px solid var(--input-border-color, transparent);
    background-color: var(--input-bg-color, #f9f9f9);
    transition: color 0.3s ease, background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, transform 0.3s ease;
    color: var(--primary-text-color);
    
    &[type="password"],
    &[type="text"] {
      padding-right: 40px;
    }
    
    &:focus {
      outline: none;
      border-color: var(--theme-color);
      box-shadow: 0 0 0 2px rgba(var(--theme-color-rgb), 0.2);
      background-color: var(--input-focus-bg-color, #fff);
    }
    
    &::placeholder {
      color: var(--placeholder-color, #aaa);
    }
  }
}

.input-with-button {
  display: flex;
  align-items: stretch;
  height: 45px;
  width: 100%;
  
  .verification-input {
    flex: 1;
    
    .form-control {
      border-top-right-radius: 0;
      border-bottom-right-radius: 0;
      height: 100%;
    }
  }
  
  .send-code-btn {
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
    border-top-right-radius: 8px;
    border-bottom-right-radius: 8px;
    padding: 0 15px;
    min-width: 100px;
    white-space: nowrap;
    font-size: 0.875rem;
    border: none;
    background-color: var(--theme-color);
    color: white;
    margin: 0;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background-color 0.3s, opacity 0.3s, transform 0.3s;
    
    &:hover:not(:disabled) {
      background-color: var(--primary-color-hover);
    }
    
    &:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: translateY(0);
    }
    
    &:not(:disabled) {
      transform: translateY(0);
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
      
      &:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
      }
    }
    
    .icon-left {
      margin-right: 5px;
    }
  }
}

.btn {
  height: 45px;
  border-radius: 12px;
  transition: color 0.3s, background-color 0.3s, border-color 0.3s, box-shadow 0.3s, opacity 0.3s, transform 0.3s;
  display: flex;
  align-items: center;
  justify-content: center;
  
  &.btn-primary {
    background-color: var(--theme-color);
    border: none;
    color: white;
    font-weight: 600;
    
    &:hover:not(:disabled) {
      background-color: var(--primary-color-hover);
    }
    
    &:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }
    
    .icon-right {
      margin-left: 8px;
    }
  }
}

.agreement-checkbox {
  margin-top: 1.5rem;
  
  .checkbox-container {
    display: flex;
    align-items: flex-start;
    position: relative;
    padding-left: 30px;
    cursor: pointer;
    font-size: 0.9rem;
    user-select: none;
    
    input {
      position: absolute;
      opacity: 0;
      cursor: pointer;
      height: 0;
      width: 0;
      
      &:checked ~ .checkmark {
        background-color: var(--theme-color);
        border-color: var(--theme-color);
        
        &:after {
          display: block;
        }
      }
    }
    
    .checkmark {
      position: absolute;
      top: 0;
      left: 0;
      height: 20px;
      width: 20px;
      background-color: transparent;
      border: 2px solid var(--border-color);
      border-radius: 4px;
      transition: color 0.2s ease, background-color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease, transform 0.2s ease;
      
      &:after {
        content: "";
        position: absolute;
        display: none;
        left: 5px;
        top: 0.5px;
        width: 5px;
        height: 10px;
        border: solid white;
        border-width: 0 2px 2px 0;
        transform: rotate(45deg);
      }
    }
    
    .checkbox-label {
      color: var(--secondary-text-color);
      font-size: 0.875rem;
      line-height: 1.5;
      
      a {
        position: relative;
        color: var(--theme-color);
        text-decoration: none;
        background: linear-gradient(90deg, var(--theme-color) 0%, var(--theme-hover-color, #4bc8a2) 100%);
        background-size: 200% 100%;
        -webkit-background-clip: text;
        background-clip: text;
        -webkit-text-fill-color: transparent;
        transition: color 0.3s ease-in-out, background-color 0.3s ease-in-out, border-color 0.3s ease-in-out, box-shadow 0.3s ease-in-out, opacity 0.3s ease-in-out, transform 0.3s ease-in-out;
        
        &:hover {
          background-position: 100% 0;
        }
      }
    }
  }
}

.error-message {
  display: block;
  color: var(--error-color, #ff4d4f);
  font-size: 0.8rem;
  margin-top: 0.3rem;
}

/* 移动设备适配 */
@media (max-width: 576px) {
  .auth-card {
    padding: 1.5rem;
  }
  
  .auth-header {
    margin-bottom: 1.5rem;
    
    .auth-logo img {
      height: 50px;
    }
    
    .auth-title {
      font-size: 1.5rem;
    }
  }
  
  .input-with-button {
    flex-direction: row;
    height: 45px;
    gap: 0;
    
    .verification-input .form-control {
      border-top-right-radius: 0;
      border-bottom-right-radius: 0;
    }
    
    .send-code-btn {
      width: auto;
      min-width: 100px;
      border-top-left-radius: 0;
      border-bottom-left-radius: 0;
      border-top-right-radius: 8px;
      border-bottom-right-radius: 8px;
      height: 100%;
    }
  }
  
  /* 修改后的底部链接在移动端的换行处理 */
  .auth-footer .register-link-wrapper {
    flex-wrap: wrap;
    gap: 8px;
  }
}

/* 平板设备适配 */
@media (min-width: 576px) and (max-width: 992px) {
  .auth-container {
    padding: 2rem;
  }
}

/* 深色模式适配 */
.dark-theme {
  .loading-container .loading-spinner {
    color: var(--theme-color);
  }
  
  .input-with-suffix {
    background-color: var(--input-bg-color, #333);
    border-color: var(--input-border-color, #444);
    
    &:hover {
      background-color: var(--input-hover-bg-color, #383838);
    }
    
    &:focus-within {
      border-color: var(--theme-color);
      background-color: var(--input-focus-bg-color, #3a3a3a);
    }
    
    .email-suffix {
      border-left-color: var(--input-border-color, #444);
      
      &:hover:not(.disabled) {
        background-color: var(--input-hover-bg-color, #383838);
      }
      
      .suffix-dropdown {
        background-color: var(--card-background);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        border-color: var(--input-border-color, #444);
        
        .suffix-option {
          &:hover {
            background-color: rgba(var(--theme-color-rgb), 0.15);
          }
        }
      }
    }
  }
  
  .input-with-icon {
    .input-icon {
      color: var(--secondary-text-color);
    }
    
    .form-control {
      background-color: var(--input-bg-color, #333);
      border-color: var(--input-border-color, #444);
      
      &:focus {
        background-color: var(--input-focus-bg-color, #3a3a3a);
        border-color: var(--theme-color);
      }
      
      &::placeholder {
        color: var(--placeholder-color, #777);
      }
    }
  }
  
  .input-with-button {
    .send-code-btn {
      background-color: var(--theme-color);
      
      &:hover:not(:disabled) {
        background-color: var(--primary-color-hover);
      }
    }
  }
  
  .checkbox-container {
    .checkbox-label {
      color: var(--secondary-text-color);
    }
    
    .checkmark {
      background-color: transparent;
      border-color: var(--border-color, #555);
    }
  }
  
  .btn-primary {
    background-color: var(--theme-color);
    
    &:hover:not(:disabled) {
      background-color: var(--primary-color-hover);
    }
  }
}

.auth-footer {
  margin-top: 24px;
  
  .register-link-wrapper {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    font-size: 14px;
    color: var(--secondary-text-color);
    
    .register-text {
      color: var(--secondary-text-color);
    }
    
    .register-link {
      color: var(--theme-color);
      text-decoration: none;
      font-weight: 500;
      transition: opacity 0.2s ease, text-decoration 0.2s;
      
      &:hover {
        opacity: 0.8;
        text-decoration: underline;
      }
    }
  }
}

.loading-wrapper {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: color 0.3s ease, background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, transform 0.3s ease;
  
  svg {
    display: none;
  }
  
  &::before {
    content: "";
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 50%;
    border-top-color: white;
    animation: spin 1s linear infinite;
    margin-right: 8px;
  }
  
  span {
    display: inline-block;
    animation: pulse 1.5s infinite ease-in-out;
  }
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.7; }
}

.auth-card {
  opacity: 0;
  animation: fadeIn 0.3s ease forwards;
}

@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

.auth-logo {
  margin-bottom: 1.5rem;
  text-align: center;
  
  img {
    width: 60px;
    height: 60px;
    min-width: 60px;
    min-height: 60px;
    border-radius: 12px;
    object-fit: cover;
  }
}

.captcha-container {
  display: flex;
  justify-content: center;
  margin: 10px 0;
  
  .google-captcha,
  .cloudflare-captcha {
    display: flex;
    justify-content: center;
    margin: 10px 0;
    min-height: 78px;
    
    #form-recaptcha,
    #form-turnstile {
      display: inline-block;
      min-width: 302px;
      min-height: 78px;
    }
  }
}

.captcha-modal {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 1;
  transition: opacity 0.3s ease;
  
  &.closing {
    opacity: 0;
  }
  
  .captcha-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(3px);
  }
  
  .captcha-modal-content {
    position: relative;
    width: 100%;
    max-width: 420px;
    background-color: var(--card-background);
    border-radius: 12px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
    z-index: 1001;
    overflow: hidden;
    animation: modalFadeIn 0.3s ease;
    transform: translateY(0);
    transition: transform 0.3s ease, opacity 0.3s ease;
    opacity: 1;
    
    &.closing {
      transform: translateY(-20px);
      opacity: 0;
    }
    
    .captcha-modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 16px 20px;
      border-bottom: 1px solid var(--border-color);
      
      h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        color: var(--primary-text-color);
      }
      
      .close-btn {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 24px;
        line-height: 1;
        color: var(--secondary-text-color);
        padding: 0;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: color 0.2s;
        
        &:hover {
          color: var(--primary-text-color);
        }
      }
    }
    
    .captcha-modal-body {
      padding: 20px;
      
      p {
        margin-top: 0;
        margin-bottom: 16px;
        color: var(--secondary-text-color);
      }
      
      .google-captcha,
      .cloudflare-captcha {
        display: flex;
        justify-content: center;
        margin: 15px 0;
        min-height: 78px;
        
        #modal-recaptcha,
        #modal-turnstile {
          display: inline-block;
          min-width: 302px;
          min-height: 78px;
        }
      }
    }
  }
}

@keyframes modalFadeIn {
  from {
    opacity: 0;
    transform: translateY(-20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
