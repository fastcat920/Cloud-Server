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
    
    <div class="auth-card">
      <div class="auth-header">
        <div class="auth-logo">
          <img 
            :src="logoPath" 
            alt="Logo" 
            @error="handleLogoError" 
          />
        </div>
        <h1 class="auth-title">{{ $t('auth.loginTitle') }}</h1>
        <p class="auth-subtitle">{{ $t('auth.loginSubtitle') }}</p>
      </div>
      
      <form class="auth-form" @submit.prevent="handleLogin">
        <!-- 邮箱输入框（去掉 label） -->
        <div class="form-group">
          <div class="input-with-icon">
            <IconMail class="input-icon" />
            <input
              type="email"
              id="email"
              v-model="formData.email"
              class="form-control"
              :placeholder="$t('auth.emailPlaceholder')"
              :aria-invalid="!!errors.email"
              :aria-describedby="errors.email ? 'login-email-error' : undefined"
              required
            />
          </div>
          <div v-if="errors.email" id="login-email-error" class="error-message" role="alert">{{ errors.email }}</div>
        </div>
        
        <!-- 密码输入框（去掉 label） -->
        <div class="form-group">
          <div class="input-with-icon">
            <IconLock class="input-icon" />
            <input
              :type="showPassword ? 'text' : 'password'"
              id="password"
              v-model="formData.password"
              class="form-control"
              :placeholder="$t('auth.passwordPlaceholder')"
              :aria-invalid="!!errors.password"
              :aria-describedby="errors.password ? 'login-password-error' : undefined"
              required
            />
            <button type="button" class="password-toggle" :aria-label="$t(showPassword ? 'common.hidePassword' : 'common.showPassword')" :aria-pressed="showPassword" @click="showPassword = !showPassword">
              <IconEye v-if="!showPassword" />
              <IconEyeOff v-else />
            </button>
          </div>
          <div v-if="errors.password" id="login-password-error" class="error-message" role="alert">{{ errors.password }}</div>
        </div>
        
        <div class="form-options">
          <div class="remember-me">
            <label class="checkbox-container">
              <input type="checkbox" v-model="formData.rememberMe" />
              <span class="checkmark"></span>
              <span class="checkbox-label">{{ $t('common.rememberMe') }}</span>
            </label>
          </div>
          <router-link to="/forgot-password" class="forgot-password">
            {{ $t('common.forgotPassword') }}
          </router-link>
        </div>
        
        <button
          type="submit"
          class="btn btn-primary btn-block"
          :disabled="loading"
        >
          <span v-if="loading" class="loading-wrapper">
            <span>{{ $t('common.loading') }}</span>
          </span>
          <span v-else>
            {{ $t('common.login') }}
            <IconArrowRight class="icon-right" />
          </span>
        </button>
      </form>
      
      <div class="auth-footer">
        <div class="register-link-wrapper">
          <span class="register-text">{{ $t('auth.noAccount') }}</span>
          <router-link to="/register" class="register-link">
            {{ $t('auth.createAccount') }}
          </router-link>
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
import { ref, reactive, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useToast } from '@/composables/useToast';
import ThemeToggle from '@/components/common/ThemeToggle.vue';
import LanguageSelector from '@/components/common/LanguageSelector.vue';
import IconMail from '@/components/icons/IconMail.vue';
import IconLock from '@/components/icons/IconLock.vue';
import IconArrowRight from '@/components/icons/IconArrowRight.vue';
import IconEye from '@/components/icons/IconEye.vue';
import IconEyeOff from '@/components/icons/IconEyeOff.vue';
import { login, checkLoginStatus } from '@/api/auth';
import { validateEmail, validateRequired } from '@/utils/validators';
import { applyDomainAuth } from '@/utils/licenseAuth';
import DomainAuthAlert from '@/components/common/DomainAuthAlert.vue';
import { handleTokenLogin, hasVerifyToken } from '@/utils/tokenLogin';
import { AUTH_CONFIG } from '@/utils/baseConfig';
import AuthPopup from '@/components/auth/AuthPopup.vue';
import { shouldShowAuthPopup } from '@/utils/authPopupState';

export default {
  name: 'LoginView',
  components: {
    ThemeToggle,
    LanguageSelector,
    IconMail,
    IconLock,
    IconArrowRight,
    IconEye,
    IconEyeOff,
    DomainAuthAlert,
    AuthPopup
  },
  
  setup() {
    const router = useRouter();
    const { t } = useI18n();
    const { showToast } = useToast();
    
    // logo路径处理
    const logoPath = ref('/theme/default/images/logo.png');
    const handleLogoError = () => {
      logoPath.value = '/theme/default/images/logo.png';
    };
    
    // 表单数据
    const formData = reactive({
      email: '',
      password: '',
      rememberMe: false
    });
    
    // 错误信息
    const errors = reactive({
      email: '',
      password: ''
    });
    
    // 加载状态
    const loading = ref(false);
    
    // 密码可见性
    const showPassword = ref(false);
    
    // 自定义弹窗相关
    const showAuthPopup = ref(false);
    const authPopupConfig = reactive({
      title: AUTH_CONFIG.popup?.title || '',
      content: AUTH_CONFIG.popup?.content || '',
      cooldownHours: AUTH_CONFIG.popup?.cooldownHours || 24,
      closeWaitSeconds: AUTH_CONFIG.popup?.closeWaitSeconds || 0
    });
    
    // 处理弹窗关闭事件
    const handleAuthPopupClose = () => {
      showAuthPopup.value = false;
    };
    
    // 授权状态
    const authStatus = ref({
      isAuthorized: true,
      apiDomain: ''
    });
    
    // 仅保存账号；密码交给浏览器密码管理器，避免在 localStorage 中留下明文密码。
    const saveCredentials = (email, _password, remember) => {
      localStorage.removeItem('savedPassword');
      if (remember) {
        localStorage.setItem('savedEmail', email);
        localStorage.setItem('rememberMe', 'true');
      } else {
        localStorage.removeItem('savedEmail');
        localStorage.setItem('rememberMe', 'false');
      }
    };
    
    // 读取保存的账号，并迁移清理旧版本遗留的明文密码。
    const loadSavedCredentials = () => {
      const rememberMe = localStorage.getItem('rememberMe') === 'true';
      const savedEmail = localStorage.getItem('savedEmail');
      localStorage.removeItem('savedPassword');
      
      if (rememberMe && savedEmail) {
        formData.email = savedEmail;
        formData.rememberMe = true;
        return true;
      }
      return false;
    };
    
    // 验证表单
    const validateForm = () => {
      let isValid = true;
      
      errors.email = '';
      errors.password = '';
      
      if (!validateRequired(formData.email)) {
        errors.email = t('validation.emailRequired');
        isValid = false;
      } else if (!validateEmail(formData.email)) {
        errors.email = t('validation.emailInvalid');
        isValid = false;
      }
      
    if (!validateRequired(formData.password)) {
      errors.password = t('validation.passwordRequired');
      isValid = false;
    } else if (formData.password.length < 8) {
      errors.password = t('auth.passwordTooShort');
      isValid = false;
    }
      
      return isValid;
    };
    
    // 处理登录
    const handleLogin = async () => {
      if (!validateForm()) {
        return;
      }
      
      loading.value = true;
      
      try {
        const response = await login(formData);
        saveCredentials(formData.email, formData.password, formData.rememberMe);
        showToast(response.message || t('auth.loginSuccess'), 'success', 3000);
        setTimeout(() => {
          router.push('/dashboard');
        }, 300);
      } catch (error) {
        showToast(error.response?.message || error.message || t('auth.loginFailed'), 'error');
      } finally {
        loading.value = false;
      }
    };
    
    // 检查登录状态
    onMounted(async () => {
      authStatus.value = applyDomainAuth();
      loadSavedCredentials();
      
      const hasToken = hasVerifyToken();
      
      if (hasToken) {
        loading.value = true;
        try {
          const tokenLoginResult = await handleTokenLogin({
            onLoginSuccess: () => {
              console.log('令牌验证登录成功');
            }
          });
          if (tokenLoginResult.success) {
            return;
          }
        } catch (error) {
          console.error('令牌登录过程中出错:', error);
        } finally {
          loading.value = false;
        }
      }
      
      const urlParams = new URLSearchParams(window.location.search);
      const isJustLoggedOut = urlParams.get('logout') === 'true';
      
      if (isJustLoggedOut) {
        console.log('检测到用户刚刚登出');
        showToast(t('auth.logoutSuccess'), 'success', 3000);
        if (window.history && window.history.replaceState) {
          const newUrl = window.location.href.replace('?logout=true', '').replace('&logout=true', '');
          window.history.replaceState({}, document.title, newUrl);
        }
        return;
      }
      
      showAuthPopup.value = shouldShowAuthPopup(AUTH_CONFIG.popup);
      
      try {
        if (window._isLoggingOut === true) {
          console.log('检测到全局登出标记，跳过登录状态检查');
          return;
        }
        const loginStatus = checkLoginStatus();
        if (loginStatus) {
          console.log('用户已登录，准备跳转到控制面板');
          showToast(t('auth.alreadyLoggedIn'), 'info');
          setTimeout(() => {
            router.push('/dashboard');
          }, 500);
        }
      } catch (error) {
        console.error("登录状态检查失败", error);
      }
    });
    
    return {
      formData,
      errors,
      loading,
      showPassword,
      handleLogin,
      authStatus,
      logoPath,
      handleLogoError,
      showAuthPopup,
      authPopupConfig,
      handleAuthPopupClose
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

.required {
  color: #ff4d4f;
  margin-left: 4px;
  font-size: 16px;
  vertical-align: middle;
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
      box-shadow: 0 0 0 2px var(--primary-color-focus);
      background-color: var(--input-focus-bg-color, #fff);
    }
    
    &::placeholder {
      color: var(--placeholder-color, #aaa);
    }
  }
}

.form-options {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
  
  .remember-me .checkbox-container {
    display: flex;
    align-items: center;
    position: relative;
    padding-left: 30px;
    cursor: pointer;
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
    }
  }
  
  .forgot-password {
    color: var(--theme-color);
    font-size: 0.875rem;
    text-decoration: none;
    transition: color 0.3s ease, opacity 0.3s ease;
    
    &:hover {
      opacity: 0.8;
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

.error-message {
  display: block;
  color: #ff4d4f;
  font-size: 0.8rem;
  margin-top: 0.3rem;
}

/* 移动设备适配 */
@media (max-width: 576px) {
  .auth-card {
    padding: 1.5rem;
  }
  
  .form-options {
    flex-direction: row;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    
    .remember-me {
      flex: 0 0 auto;
    }
    
    .forgot-password {
      flex: 0 0 auto;
      margin-left: auto;
    }
  }
  
  .register-link-wrapper {
    flex-wrap: wrap;
    gap: 8px;
    justify-content: center;
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
  
  .checkbox-container {
    .checkbox-label {
      color: var(--secondary-text-color);
    }
    
    .checkmark {
      background-color: transparent;
      border-color: var(--border-color, #555);
    }
  }
}

/* 新样式：注册链接行 */
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
      transition: opacity 0.2s ease;
      
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

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
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
</style>
