<template>
  <div class="user-avatar-container" ref="avatarContainer">
    <button
      type="button"
      class="avatar-wrapper"
      :aria-label="$t('common.userCenter')"
      :aria-expanded="isDropdownOpen"
      @click="toggleDropdown"
    >
      <img 
        v-if="avatarUrl" 
        :src="avatarUrl" 
        alt="User Avatar" 
        class="avatar-image"
      />
      <div v-else class="avatar-placeholder">
        <IconUser class="user-icon" />
      </div>
    </button>
    
    <transition name="fade">
      <div 
        class="dropdown-menu" 
        v-if="isDropdownOpen"
      >
        <!-- 只保留退出登录 -->
        <button type="button" class="menu-item" @click="logout">
          <IconLogout class="menu-icon" />
          <span>{{ $t('common.logoutText') }}</span>
        </button>
      </div>
    </transition>
  </div>
</template>

<script>
import { ref, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useToast } from '@/composables/useToast';
import { isXiaoV2board } from '@/utils/baseConfig';
import IconUser from '@/components/icons/IconUser.vue';
import IconLogout from '@/components/icons/IconLogout.vue';

export default {
  name: 'UserAvatar',
  components: {
    IconUser,
    IconLogout
  },
  props: {
    username: {
      type: String,
      default: ''
    },
    avatarUrl: {
      type: String,
      default: ''
    }
  },
  setup() {
    const router = useRouter();
    const { t } = useI18n();
    const { showToast } = useToast();
    const isDropdownOpen = ref(false);
    const avatarContainer = ref(null);
    
    // 切换下拉菜单显示状态
    const toggleDropdown = () => {
      isDropdownOpen.value = !isDropdownOpen.value;
    };
    
    // 退出登录
    const logout = async () => {
      try {
        localStorage.removeItem('token');
        isDropdownOpen.value = false;
        showToast(t('auth.logoutSuccess'), 'success', 3000);
        setTimeout(() => {
          router.push('/login');
        }, 500);
      } catch (error) {
        console.error('退出登录失败:', error);
        showToast(t('auth.logoutFailed'), 'error');
      }
    };
    
    // 点击外部关闭下拉菜单
    const handleClickOutside = (event) => {
      if (avatarContainer.value && !avatarContainer.value.contains(event.target)) {
        isDropdownOpen.value = false;
      }
    };
    
    onMounted(() => {
      document.addEventListener('click', handleClickOutside);
    });
    
    onUnmounted(() => {
      document.removeEventListener('click', handleClickOutside);
    });
    
    return {
      isDropdownOpen,
      toggleDropdown,
      logout,
      avatarContainer,
      isXiaoV2board: isXiaoV2board()
    };
  }
};
</script>

<style lang="scss" scoped>
.user-avatar-container {
  position: relative;
}

.avatar-wrapper {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  cursor: pointer;
  overflow: hidden;
  background-color: rgba(var(--theme-color-rgb), 0.1);
  border: 1px solid rgba(var(--theme-color-rgb), 0.3);
  transition: color 0.3s ease, background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, transform 0.3s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  
  &:hover {
    box-shadow: 0 0 0 3px rgba(var(--theme-color-rgb), 0.15);
    transform: translateY(-2px);
  }
  
  .avatar-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  
  .avatar-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    
    .user-icon {
      width: 20px;
      height: 20px;
      color: var(--theme-color);
    }
  }
}

.dropdown-menu {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  width: 120px;
  background: rgba(var(--card-background-rgb), 0.9);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border-radius: 12px;
  box-shadow: 0 4px 20px var(--shadow-color);
  border: 1px solid var(--border-color);
  overflow: hidden;
  z-index: 100;
  animation: dropdownFadeIn 0.2s ease;
  
  .menu-item {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    cursor: pointer;
    transition: color 0.3s ease, background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, transform 0.3s ease;
    
    .menu-icon {
      width: 18px;
      height: 18px;
      margin-right: 10px;
      color: var(--text-color);
      transition: color 0.3s ease;
    }
    
    span {
      font-size: 14px;
      color: var(--text-color);
      transition: color 0.3s ease;
    }
    
    &:hover {
      background-color: rgba(var(--primary-color-rgb), 0.1);
      color: var(--primary-color);
      
      .menu-icon, span {
        color: var(--primary-color);
      }
    }
  }
}

// 淡入淡出动画
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease, transform 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
  transform: translateY(-10px);
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
</style>
