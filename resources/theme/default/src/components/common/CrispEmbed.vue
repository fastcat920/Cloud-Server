<template>
  <!-- Crisp将会自动注入到页面，不需要特定的DOM元素 -->
  <!-- 添加一个包装容器，只是为了应用样式 -->
  <div class="crisp-embed-container" v-if="CONFIG.enabled">
    <!-- 通过JavaScript初始化Crisp，不需要视图内容 -->
  </div>
</template>

<script>
import { onMounted, onUnmounted, computed, watch, ref } from 'vue';
import { useStore } from 'vuex';
import { useI18n } from 'vue-i18n';
import { getUserInfo, getCommConfig, getUserSubscribe } from '@/api/user';
import { CUSTOMER_SERVICE_CONFIG } from '@/utils/baseConfig';
import { formatDate } from '@/utils/formatters';
import { Crisp } from 'crisp-sdk-web';

// 全局变量跟踪Crisp是否已在全局范围内初始化过
if (typeof window !== 'undefined') {
  window.FASTCAT_CRISP_INITIALIZED = window.FASTCAT_CRISP_INITIALIZED || false;
}

export default {
  name: 'CrispEmbed',
  
  setup() {
    const store = useStore();
    const { locale } = useI18n();
    const userInfo = ref(null);
    const userSubscribe = ref(null);
    const currencySymbol = ref('¥'); // 默认货币符号
    const crispInitialized = ref(window.FASTCAT_CRISP_INITIALIZED || false);
    const isMobile = ref(false);
    const syncedUserEmailKey = 'crisp_synced_user_email';
    let userSyncPromise = null;
    
    // 配置信息
    const CONFIG = computed(() => {
      return {
        enabled: CUSTOMER_SERVICE_CONFIG.enabled && CUSTOMER_SERVICE_CONFIG.type === 'crisp' && CUSTOMER_SERVICE_CONFIG.embedMode === 'embed',
        iconPosition: CUSTOMER_SERVICE_CONFIG.iconPosition || {
          desktop: { right: '20px', bottom: '20px' },
          mobile: { right: '20px', bottom: '100px' }
        }
      };
    });

    const getCrispLocale = () => locale.value === 'en-US' ? 'en' : 'zh';

    // Crisp 使用 ISO 639-1 语言代码；动态配置不会重置当前客服会话。
    const syncCrispLocale = () => {
      if (typeof window === 'undefined') return;
      window.CRISP_RUNTIME_CONFIG = window.CRISP_RUNTIME_CONFIG || {};
      window.CRISP_RUNTIME_CONFIG.locale = getCrispLocale();
      window.$crisp = window.$crisp || [];
      window.$crisp.push(['config', 'locale', [getCrispLocale()]]);
    };
    
    // 初始化Crisp客服系统
    const initCrisp = async () => {
      // 如果未启用，则不初始化
      if (!CONFIG.value.enabled) return;
      
      // 如果Crisp已经初始化过，更新配置并返回
      if (window.FASTCAT_CRISP_INITIALIZED && crispInitialized.value) {
        try {
          updateCrispConfig();
          if (store.getters.isLoggedIn) await syncUserDataToCrisp();
          return;
        } catch (error) {
          console.error('更新Crisp配置失败:', error);
          // 出错时，尝试重新初始化
        }
      }
      
      try {
        // 从配置中提取Crisp ID
        const crispIdMatch = CUSTOMER_SERVICE_CONFIG.customHtml?.match(/CRISP_WEBSITE_ID="([^"]*)"/);
        const websiteId = CUSTOMER_SERVICE_CONFIG.crispId || (crispIdMatch ? crispIdMatch[1] : '');
        
        if (!websiteId) {
          console.error('无法从配置中提取Crisp ID');
          return;
        }

        // 配置Crisp - 自动加载
        Crisp.configure(websiteId, { locale: getCrispLocale() });
        syncCrispLocale();
        
        // PC端和移动端统一显示在右侧
        Crisp.setPosition("right");

        // SDK支持在聊天脚本加载完成前排队设置用户数据，先标记初始化，
        // 避免首次登录用户的数据被 setUserDataToCrisp 的前置判断跳过。
        window.FASTCAT_CRISP_INITIALIZED = true;
        crispInitialized.value = true;

        // 会话真正加载后再补同步一次，兼容网络较慢及首次创建会话的情况。
        Crisp.session.onLoaded(async () => {
          if (store.getters.isLoggedIn) {
            await syncUserDataToCrisp(true);
          }
        });
        
        // 在用户登录后获取并设置用户数据
        if (store.getters.isLoggedIn) {
          await syncUserDataToCrisp();
        }
        
        // 设置Crisp CSS样式
        setCrispStyles();
        
      } catch (error) {
        console.error('初始化Crisp客服系统失败:', error);
      }
    };
    
    // 更新Crisp配置
    const updateCrispConfig = () => {
      syncCrispLocale();

      // PC端和移动端统一显示在右侧
      Crisp.setPosition("right");
      
      // 更新CSS样式
      setCrispStyles();
    };
    
    // 设置Crisp CSS样式
    const setCrispStyles = () => {
      setTimeout(() => {
        try {
          // 自定义样式
          const style = document.createElement('style');
          style.id = 'crisp-custom-styles';
          
          if (isMobile.value) {
            // 只对移动设备上的聊天按钮图标进行上移80px处理，不影响聊天框
            style.textContent = `
              /* Crisp聊天按钮在移动设备上的样式 - 上移80px */
              .crisp-client .cc-1xry,
              .crisp-client .cc-7doi,
              .crisp-client .cc-imbb, 
              .crisp-client .cc-1drt,
              .crisp-client .cc-1jrn,
              .crisp-client [class^="cc-"] [data-visible="true"][data-is-failure="false"],
              .crisp-client [class^="cc-"] [data-compose="true"],
              .crisp-client [class^="cc-"] [data-maximized="false"]
               {
                transform: translateY(-80px) !important;
                bottom: 80px !important;
              }
            `;
          }
          
          // 移除旧样式（如果存在）
          const oldStyle = document.getElementById('crisp-custom-styles');
          if (oldStyle) {
            oldStyle.remove();
          }
          
          // 添加新样式
          document.head.appendChild(style);
          
        } catch (error) {
          console.error('设置Crisp样式失败:', error);
        }
      }, 1000); // 延迟一秒确保Crisp元素已加载
    };
    
    // 获取用户数据
    const fetchUserData = async () => {
      const userInfoRequest = getUserInfo().then(response => {
        userInfo.value = response?.data ? response.data : response;
        // 邮箱是客服识别用户的核心字段，基本信息返回后立即注入，不等待可选接口。
        setUserIdentityToCrisp();
        return response;
      });
      const commConfigRequest = getCommConfig().then(response => {
        const commConfigData = response?.data ? response.data : response;
        if (commConfigData?.currency_symbol) currencySymbol.value = commConfigData.currency_symbol;
        return response;
      });
      const subscribeRequest = getUserSubscribe().then(response => {
        userSubscribe.value = response?.data ? response.data : response;
        // 某些面板只在订阅接口返回邮箱，作为用户基本信息接口的后备。
        setUserIdentityToCrisp();
        return response;
      });

      const [userInfoResult, commConfigResult, subscribeResult] = await Promise.allSettled([
        userInfoRequest,
        commConfigRequest,
        subscribeRequest
      ]);

      if (userInfoResult.status === 'rejected') {
        console.error('获取Crisp用户基本信息失败:', userInfoResult.reason);
      }

      if (commConfigResult.status === 'rejected') {
        console.error('获取Crisp货币配置失败:', commConfigResult.reason);
      }

      if (subscribeResult.status === 'rejected') {
        console.error('获取Crisp订阅信息失败:', subscribeResult.reason);
      }
    };

    const syncUserDataToCrisp = async (useExistingData = false) => {
      if (!store.getters.isLoggedIn) return;
      if (userSyncPromise) return userSyncPromise;

      userSyncPromise = (async () => {
        if (!useExistingData || (!userInfo.value && !userSubscribe.value)) {
          await fetchUserData();
        }
        setUserDataToCrisp();
      })();

      try {
        await userSyncPromise;
      } finally {
        userSyncPromise = null;
      }
    };
    
    const setUserIdentityToCrisp = () => {
      const userEmail = extractUserEmail();
      if (!userEmail) return '';

      const previousEmail = sessionStorage.getItem(syncedUserEmailKey);
      if (previousEmail && previousEmail !== userEmail) {
        Crisp.session.reset();
      }
      Crisp.user.setEmail(userEmail);
      Crisp.user.setNickname(userEmail.split('@')[0]);
      sessionStorage.setItem(syncedUserEmailKey, userEmail);
      return userEmail;
    };

    // 设置用户数据到Crisp
    const setUserDataToCrisp = () => {
      try {
        const userEmail = setUserIdentityToCrisp();
        
        // 获取其他用户数据
        const planName = extractPlanName();
        const expireDate = extractExpireDate();
        const usedTrafficGB = calculateUsedTraffic();
        const balance = extractBalance();
        const commissionBalance = extractCommissionBalance();
        
        // 设置会话数据
        const totalTrafficGB = calculateTotalTraffic();
        const resetDays = calculateDaysUntilReset();
        
        const sessionData = {
          email: userEmail || (locale.value === 'en-US' ? 'Unknown' : '未知'),
          plan: planName,
          expires: expireDate,
          used_traffic: usedTrafficGB + ' GB',
          total_traffic: totalTrafficGB + ' GB',
          reset_days: resetDays,
          balance: balance + ' ' + currencySymbol.value,
          commission_balance: commissionBalance + ' ' + currencySymbol.value
        };
        
        Crisp.session.setData(sessionData);
      } catch (error) {
        console.error('设置Crisp用户数据失败:', error);
      }
    };
    
    // 从用户数据中提取邮箱
    const extractUserEmail = () => {
      let userEmail = '';
      
      // 首先尝试从用户基本信息获取邮箱
      if (userInfo.value) {
        if (typeof userInfo.value === 'object') {
          if (userInfo.value.email) {
            userEmail = userInfo.value.email;
          } else if (userInfo.value.data && userInfo.value.data.email) {
            userEmail = userInfo.value.data.email;
          }
        }
      }
      
      // 如果仍未获取到邮箱，尝试从订阅信息获取
      if (!userEmail && userSubscribe.value) {
        if (typeof userSubscribe.value === 'object') {
          if (userSubscribe.value.email) {
            userEmail = userSubscribe.value.email;
          } else if (userSubscribe.value.data && userSubscribe.value.data.email) {
            userEmail = userSubscribe.value.data.email;
          }
        }
      }
      
      // 从存储中尝试获取
      if (!userEmail) {
        const storedUser = localStorage.getItem('user');
        if (storedUser) {
          try {
            const parsedUser = JSON.parse(storedUser);
            if (parsedUser && parsedUser.email) {
              userEmail = parsedUser.email;
            }
          } catch (e) {
            console.error('解析localStorage用户数据失败:', e);
          }
        }
      }
      
      return userEmail;
    };
    
    // 提取套餐名称
    const extractPlanName = () => {
      let planName = "未知套餐";
      
      if (userSubscribe.value && userSubscribe.value.plan && userSubscribe.value.plan.name) {
        // 首选从订阅信息中获取套餐名称
        planName = userSubscribe.value.plan.name;
      } else if (userSubscribe.value && userSubscribe.value.data && userSubscribe.value.data.plan && userSubscribe.value.data.plan.name) {
        planName = userSubscribe.value.data.plan.name;
      } else if (userInfo.value && userInfo.value.plan_name && userInfo.value.plan_name.trim() !== '') {
        // 从用户信息中获取套餐名称
        planName = userInfo.value.plan_name;
      } else if (userInfo.value && userInfo.value.group && userInfo.value.group.name && userInfo.value.group.name.trim() !== '') {
        // 尝试从group名称中获取
        planName = userInfo.value.group.name;
      }
      
      return planName;
    };
    
    // 提取到期时间
    const extractExpireDate = () => {
      let expireDate = "无限期";
      
      if (userSubscribe.value && userSubscribe.value.expired_at) {
        // 首选从订阅信息中获取到期时间
        expireDate = formatDate(userSubscribe.value.expired_at);
      } else if (userSubscribe.value && userSubscribe.value.data && userSubscribe.value.data.expired_at) {
        expireDate = formatDate(userSubscribe.value.data.expired_at);
      } else if (userInfo.value && userInfo.value.expired_at) {
        // 从用户信息中获取到期时间
        expireDate = formatDate(userInfo.value.expired_at);
      } else if (userInfo.value && userInfo.value.data && userInfo.value.data.expired_at) {
        expireDate = formatDate(userInfo.value.data.expired_at);
      }
      
      return expireDate;
    };
    
    // 计算已用流量
    const calculateUsedTraffic = () => {
      let transferEnable = 0;
      let u = 0;
      let d = 0;
      
      if (userSubscribe.value) {
        if (typeof userSubscribe.value === 'object') {
          // 首选从订阅信息中获取流量数据
          if (userSubscribe.value.transfer_enable !== undefined) {
            transferEnable = userSubscribe.value.transfer_enable || 0;
            u = userSubscribe.value.u || 0;
            d = userSubscribe.value.d || 0;
          } else if (userSubscribe.value.data && userSubscribe.value.data.transfer_enable !== undefined) {
            transferEnable = userSubscribe.value.data.transfer_enable || 0;
            u = userSubscribe.value.data.u || 0;
            d = userSubscribe.value.data.d || 0;
          }
        }
      } 
      
      if (transferEnable === 0 && userInfo.value) {
        if (typeof userInfo.value === 'object') {
          // 从用户信息中获取流量数据
          if (userInfo.value.transfer_enable !== undefined) {
            transferEnable = userInfo.value.transfer_enable || 0;
            u = userInfo.value.u || 0;
            d = userInfo.value.d || 0;
          } else if (userInfo.value.data && userInfo.value.data.transfer_enable !== undefined) {
            transferEnable = userInfo.value.data.transfer_enable || 0;
            u = userInfo.value.data.u || 0;
            d = userInfo.value.data.d || 0;
          }
        }
      }
      
      const usedBytes = Math.max(0, (Number(u) || 0) + (Number(d) || 0));
      
      // 将字节转换为GB
      return (usedBytes / (1024 * 1024 * 1024)).toFixed(2);
    };
    
    
    // 计算套餐总流量（GB）
    const calculateTotalTraffic = () => {
      let transferEnable = 0;
      if (userSubscribe.value) {
        if (typeof userSubscribe.value === 'object') {
          if (userSubscribe.value.transfer_enable !== undefined) {
            transferEnable = userSubscribe.value.transfer_enable || 0;
          } else if (userSubscribe.value.data && userSubscribe.value.data.transfer_enable !== undefined) {
            transferEnable = userSubscribe.value.data.transfer_enable || 0;
          }
        }
      }
      if (transferEnable === 0 && userInfo.value) {
        if (typeof userInfo.value === 'object') {
          if (userInfo.value.transfer_enable !== undefined) {
            transferEnable = userInfo.value.transfer_enable || 0;
          } else if (userInfo.value.data && userInfo.value.data.transfer_enable !== undefined) {
            transferEnable = userInfo.value.data.transfer_enable || 0;
          }
        }
      }
      return ((Number(transferEnable) || 0) / (1024 * 1024 * 1024)).toFixed(2);
    };
    
    // 计算距离下次流量重置的天数
    const calculateDaysUntilReset = () => {
      let resetDay = null;
      if (userSubscribe.value) {
        if (typeof userSubscribe.value === 'object') {
          if (userSubscribe.value.reset_day !== undefined && userSubscribe.value.reset_day !== null) {
            resetDay = userSubscribe.value.reset_day;
          } else if (userSubscribe.value.data && userSubscribe.value.data.reset_day !== undefined && userSubscribe.value.data.reset_day !== null) {
            resetDay = userSubscribe.value.data.reset_day;
          }
        }
      }
      if (resetDay === null && userInfo.value) {
        if (typeof userInfo.value === 'object') {
          if (userInfo.value.reset_day !== undefined && userInfo.value.reset_day !== null) {
            resetDay = userInfo.value.reset_day;
          } else if (userInfo.value.data && userInfo.value.data.reset_day !== undefined && userInfo.value.data.reset_day !== null) {
            resetDay = userInfo.value.data.reset_day;
          }
        }
      }
      if (resetDay === null || resetDay === undefined) return locale.value === 'en-US' ? 'N/A' : '暂无';
      if (resetDay === 0) return locale.value === 'en-US' ? 'Today' : '今天';
      const now = new Date();
      const currentDay = now.getDate();
      const currentMonth = now.getMonth();
      const currentYear = now.getFullYear();
      let nextReset;
      if (currentDay < resetDay) {
        nextReset = new Date(currentYear, currentMonth, resetDay);
      } else {
        nextReset = new Date(currentYear, currentMonth + 1, resetDay);
      }
      const diffTime = nextReset - now;
      const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
      return locale.value === 'en-US' ? `${diffDays} days` : `${diffDays} 天`;
    };


    // 提取用户余额（后端返回的是分，需要转换为元）
    const extractBalance = () => {
      let balanceInCents = 0;
      
      if (userInfo.value) {
        if (typeof userInfo.value === 'object') {
          if (userInfo.value.balance !== undefined) {
            balanceInCents = userInfo.value.balance || 0;
          } else if (userInfo.value.data && userInfo.value.data.balance !== undefined) {
            balanceInCents = userInfo.value.data.balance || 0;
          }
        }
      }
      
      // 将分转换为元，保留两位小数
      const balanceInYuan = (balanceInCents / 100).toFixed(2);
      return balanceInYuan;
    };

    // 提取佣金余额（后端返回的是分，需要转换为元）
    const extractCommissionBalance = () => {
      let commissionBalanceInCents = 0;

      if (userInfo.value && typeof userInfo.value === 'object') {
        if (userInfo.value.commission_balance !== undefined) {
          commissionBalanceInCents = userInfo.value.commission_balance || 0;
        } else if (userInfo.value.data?.commission_balance !== undefined) {
          commissionBalanceInCents = userInfo.value.data.commission_balance || 0;
        }
      }

      return ((Number(commissionBalanceInCents) || 0) / 100).toFixed(2);
    };
    
    // 检测是否为移动设备
    const checkIfMobile = () => {
      isMobile.value = window.innerWidth < 768;
    };
    
    // 处理窗口大小变化
    const handleResize = () => {
      // 更新移动设备状态
      checkIfMobile();
      
      if (crispInitialized.value) {
        // PC端和移动端统一显示在右侧
        Crisp.setPosition("right");
        
        // 重新应用CSS样式
        setCrispStyles();
      }
    };
    
    // 监听真实token变化：支持访客登录后补充身份，也支持账号A切换到账号B。
    watch(() => store.state.token, async (newToken, oldToken) => {
      if (newToken && crispInitialized.value) {
        userInfo.value = null;
        userSubscribe.value = null;
        await syncUserDataToCrisp();
      } else if (!newToken && oldToken && crispInitialized.value) {
        // 用户主动退出后恢复匿名会话，防止后续访客消息继续挂在旧账号下。
        Crisp.session.reset();
        sessionStorage.removeItem(syncedUserEmailKey);
        userInfo.value = null;
        userSubscribe.value = null;
      }
    });

    watch(locale, () => {
      syncCrispLocale();
    });
    
    onMounted(async () => {
      // 初始化检测屏幕大小
      checkIfMobile();
      
      // 初始化Crisp
      await initCrisp();
      
      // 监听窗口大小变化
      window.addEventListener('resize', handleResize);
      
      // 使用MutationObserver观察DOM变化，确保Crisp元素加载后应用样式
      const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
          if (mutation.addedNodes && mutation.addedNodes.length > 0) {
            for (let i = 0; i < mutation.addedNodes.length; i++) {
              const node = mutation.addedNodes[i];
              if (node.classList && (node.classList.contains('crisp-client') || node.querySelector('.crisp-client'))) {
                // 找到Crisp元素后应用样式并停止观察
                setCrispStyles();
                setTimeout(setCrispStyles, 500);  // 再次尝试，确保样式被应用
                setTimeout(setCrispStyles, 1500); // 第三次尝试，应对延迟加载
                break;
              }
            }
          }
        });
      });
      
      // 开始观察整个document
      observer.observe(document.body, {
        childList: true,
        subtree: true
      });
      
      // 保存observer引用以便在组件卸载时清理
      window.crispObserver = observer;
    });
    
    onUnmounted(() => {
      // 移除事件监听器
      window.removeEventListener('resize', handleResize);
      
      // 停止DOM观察
      if (window.crispObserver) {
        window.crispObserver.disconnect();
        window.crispObserver = null;
      }
      
      // 不重置Crisp，以保持会话状态
    });
    
    return {
      CONFIG
    };
  }
};
</script>

<style lang="scss" scoped>
.crisp-embed-container {
  /* 组件本身不需要特定的样式，Crisp会自动处理布局 */
}

/* 全局Crisp样式调整（需要在全局应用） */
:global() {
  /* 这些样式需要通过JavaScript动态调整 */
}
</style>
