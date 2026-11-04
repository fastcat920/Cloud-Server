/**
 * Vuex存储配置
 */
import { createStore } from 'vuex';
import { THEME_CONFIG } from '@/utils/baseConfig';
// 导入API中的forceLogout函数，用于确保完整地清除登录状态
import { forceLogout } from '@/api/auth';

export default createStore({
  state: {
    // 用户信息
    user: null,
    token: localStorage.getItem('token') || '',
    
    // 主题设置
    theme: localStorage.getItem('theme') || THEME_CONFIG.defaultTheme,
    
    // 应用设置
    loading: false,
    error: null
  },
  
  getters: {
    // 是否已登录
    isLoggedIn: state => !!state.token,
    
    // 获取用户信息
    userInfo: state => state.user,
    
    // 获取当前主题
    currentTheme: state => state.theme,
    
    // 是否为暗黑模式
    isDarkTheme: state => state.theme === 'dark'
  },
  
  mutations: {
    // 设置用户信息
    SET_USER(state, user) {
      state.user = user;
    },
    
    // 设置令牌
    SET_TOKEN(state, token) {
      state.token = token;
      localStorage.setItem('token', token);
    },
    
    // 清除用户信息
    CLEAR_USER(state) {
      state.user = null;
      state.token = '';
      localStorage.removeItem('token');
      localStorage.removeItem('userInfo');
    },
    
    // 设置主题
    SET_THEME(state, theme) {
      state.theme = theme;
      localStorage.setItem('theme', theme);
    },
    
    // 设置加载状态
    SET_LOADING(state, status) {
      state.loading = status;
    },
    
    // 设置错误信息
    SET_ERROR(state, error) {
      state.error = error;
    }
  },
  
  actions: {
    // 登录
    login({ commit }, token) {
      commit('SET_TOKEN', token);
    },
    
    // 登出
    logout({ commit }) {
      commit('CLEAR_USER');
      // 调用forceLogout确保完全清除登录状态
      try {
        // 确保forceLogout方法可用
        if (typeof forceLogout === 'function') {
          forceLogout();
        }
      } catch (error) {
        console.error('在Store中调用forceLogout失败:', error);
      }
    },
    
    // 设置用户信息
    setUser({ commit }, user) {
      commit('SET_USER', user);
      localStorage.setItem('userInfo', JSON.stringify(user));
    },
    
    // 切换主题
    toggleTheme({ commit, state }) {
      const newTheme = state.theme === 'light' ? 'dark' : 'light';
      commit('SET_THEME', newTheme);
    },
    
    // 初始化用户信息
    initUserInfo({ commit }) {
      const userInfo = localStorage.getItem('userInfo');
      if (userInfo) {
        try {
          commit('SET_USER', JSON.parse(userInfo));
        } catch (err) {
          console.error('解析用户信息失败:', err);
          localStorage.removeItem('userInfo');
        }
      }
    }
  },
  
  modules: {
    // 可以在这里添加模块
  }
}); 