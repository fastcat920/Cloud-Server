/**
 * 认证相关逻辑
 */
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { login, register, resetPassword, getUserInfo, logout } from '@/api/auth';

export function useAuth() {
  const router = useRouter();
  const user = ref(null);
  const token = ref(localStorage.getItem('token') || '');
  const loading = ref(false);
  const error = ref('');
  
  // 计算属性：是否已登录
  const isLoggedIn = computed(() => !!token.value);
  
  // 登录
  const loginUser = async (credentials) => {
    loading.value = true;
    error.value = '';
    
    try {
      const response = await login(credentials);
      
      if (!response) {
        throw new Error('登录响应无效，请稍后再试');
      }
      
      // 更严格地检查响应数据
      if (!response.token) {
        throw new Error('登录响应中缺少令牌，请稍后再试');
      }
      
      token.value = response.token;
      
      // 确保 auth_data 被正确处理
      if (response.auth_data) {
        // auth_data 会在 login 函数内部存储到 localStorage
        // 这里确保本组件也能感知到 auth_data 的存在
        localStorage.setItem('auth_data', response.auth_data);
      }
      
      // 获取用户信息
      await fetchUserInfo();
      
      return { success: true };
    } catch (err) {
      error.value = err.message || '登录失败，请检查您的凭据';
      return { 
        success: false, 
        error: error.value 
      };
    } finally {
      loading.value = false;
    }
  };
  
  // 注册
  const registerUser = async (userData) => {
    loading.value = true;
    error.value = '';
    
    try {
      const response = await register(userData);
      
      if (!response || !response.success) {
        throw new Error(response?.message || '注册失败，请稍后再试');
      }
      
      return { 
        success: true,
        message: '注册成功，请登录'
      };
    } catch (err) {
      error.value = err.message || '注册失败，请稍后再试';
      return { 
        success: false, 
        error: error.value 
      };
    } finally {
      loading.value = false;
    }
  };
  
  // 重置密码
  const resetUserPassword = async (email) => {
    loading.value = true;
    error.value = '';
    
    try {
      const response = await resetPassword({ email });
      
      if (!response || !response.success) {
        throw new Error(response?.message || '重置密码请求失败，请稍后再试');
      }
      
      return { 
        success: true,
        message: '重置密码邮件已发送，请查收'
      };
    } catch (err) {
      error.value = err.message || '重置密码失败，请稍后再试';
      return { 
        success: false, 
        error: error.value 
      };
    } finally {
      loading.value = false;
    }
  };
  
  // 获取用户信息
  const fetchUserInfo = async () => {
    if (!token.value) return;
    
    loading.value = true;
    
    try {
      const userInfo = await getUserInfo();
      user.value = userInfo;
      localStorage.setItem('userInfo', JSON.stringify(userInfo));
    } catch (err) {
      // 静默处理获取用户信息失败的错误
    } finally {
      loading.value = false;
    }
  };
  
  // 退出登录
  const logoutUser = async () => {
    loading.value = true;
    error.value = '';
    
    try {
      // logout API已包含所有清除认证数据的逻辑
      const response = await logout();
      
      if (response && response.success) {
        // 清除组件状态
        token.value = '';
        user.value = null;
        
        // 如果需要重定向到登录页
        if (response.redirectToLogin && response.redirectUrl) {
          router.push(response.redirectUrl);
        } else {
          router.push('/login?logout=true');
        }
        
        return {
          success: true,
          message: '已成功退出登录'
        };
      } else {
        throw new Error(response?.error || '退出登录失败');
      }
    } catch (err) {
      error.value = err.message || '退出登录过程发生错误';
      return {
        success: false,
        error: error.value
      };
    } finally {
      loading.value = false;
    }
  };
  
  // 初始化用户信息
  const initUserInfo = () => {
    const userInfo = localStorage.getItem('userInfo');
    if (userInfo) {
      try {
        user.value = JSON.parse(userInfo);
      } catch (err) {
        // 静默处理解析用户信息失败的错误
        localStorage.removeItem('userInfo');
      }
    }
  };
  
  // 初始化
  initUserInfo();
  
  return {
    user,
    token,
    loading,
    error,
    isLoggedIn,
    loginUser,
    registerUser,
    resetUserPassword,
    fetchUserInfo,
    logoutUser
  };
} 