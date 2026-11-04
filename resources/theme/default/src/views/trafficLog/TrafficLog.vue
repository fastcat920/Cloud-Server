<template>
  <div class="trafficlog-container">
    <!-- 域名授权验证提示 -->
    <DomainAuthAlert 
      :is-authorized="authStatus.isAuthorized" 
      :api-domain="authStatus.apiDomain" 
    />
    
    <div class="trafficlog-inner">
      <!-- 加载状态 -->
      <div v-if="loading" class="status-message">
        <div class="loading-spinner"></div>
        <p>{{ $t('trafficLog.loadingTraffic') }}</p>
      </div>
      
      <!-- 错误状态 -->
      <div v-else-if="error" class="status-message">
        <p>{{ $t('trafficLog.errorLoadingTraffic') }}</p>
        <button @click="fetchTrafficData">{{ $t('trafficLog.retry') }}</button>
      </div>
      
      <!-- 卡片列表 -->
      <div v-else-if="trafficData.length" class="traffic-cards">
        <div class="traffic-card" v-for="(item, index) in trafficData" :key="index">
          <!-- 第一行：日期 + 倍率（右对齐） -->
          <div class="card-header-row">
            <span class="date"><IconCalendar :size="16" />{{ formatDate(item.record_at) }}</span>
          </div>
          
          <!-- 第二行：标签（三列） -->
          <div class="labels-row">
            <div class="label-item upload"><IconUpload :size="14" />{{ $t('trafficLog.uploadTraffic') }}</div>
            <div class="label-item download"><IconDownload :size="14" />{{ $t('trafficLog.downloadTraffic') }}</div>
            <div class="label-item total"><IconChartDonut :size="14" />{{ $t('trafficLog.totalTraffic') }}</div>
          </div>
          
          <!-- 第三行：数值（三列） -->
          <div class="values-row">
            <div class="value-item">{{ formatTraffic(item.u) }}</div>
            <div class="value-item">{{ formatTraffic(item.d) }}</div>
            <div class="value-item total-value">{{ formatTraffic((item.u || 0) + (item.d || 0)) }}</div>
          </div>
        </div>
      </div>
      
      <!-- 空状态 -->
      <div v-else class="status-message">
        <p>{{ $t('trafficLog.noTrafficData') }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { getTrafficLog } from '@/api/trafficLog';
import { formatTraffic, formatDate } from '@/utils/formatters';
import { TRAFFICLOG_CONFIG } from '@/utils/baseConfig';
import { applyDomainAuth } from '@/utils/licenseAuth';
import DomainAuthAlert from '@/components/common/DomainAuthAlert.vue';
import { IconCalendar, IconUpload, IconDownload, IconChartDonut } from '@tabler/icons-vue';

// 授权状态验证
const authStatus = ref({
  isAuthorized: true,
  apiDomain: ''
});

// 流量数据
const trafficData = ref([]);
const loading = ref(true);
const error = ref(false);

// 获取流量数据
const fetchTrafficData = async () => {
  loading.value = true;
  error.value = false;
  
  try {
    const response = await getTrafficLog();
    
    if (response && response.data && Array.isArray(response.data) && response.data.length > 0) {
      // 按时间排序，最新的在前面
      let data = response.data.sort((a, b) => b.record_at - a.record_at);
      
      // 按照配置限制天数
      if (TRAFFICLOG_CONFIG.daysToShow > 0 && data.length > 0) {
        const uniqueDates = [...new Set(data.map(item => {
          const date = new Date(item.record_at * 1000);
          return `${date.getFullYear()}-${date.getMonth() + 1}-${date.getDate()}`;
        }))];
        
        if (uniqueDates.length > TRAFFICLOG_CONFIG.daysToShow) {
          const datesToKeep = uniqueDates.slice(0, TRAFFICLOG_CONFIG.daysToShow);
          data = data.filter(item => {
            const date = new Date(item.record_at * 1000);
            const dateStr = `${date.getFullYear()}-${date.getMonth() + 1}-${date.getDate()}`;
            return datesToKeep.includes(dateStr);
          });
        }
      }
      
      trafficData.value = data;
    } else {
      trafficData.value = [];
    }
  } catch (err) {
    console.error('[TrafficLog] API 请求失败:', err);
    error.value = true;
  } finally {
    loading.value = false;
  }
};

// 页面挂载时获取数据
onMounted(() => {
  authStatus.value = applyDomainAuth();
  fetchTrafficData();
});
</script>

<style lang="scss" scoped>
.trafficlog-container {
  padding: 20px;
  padding-bottom: 80px;
  display: flex;
  justify-content: center;
  
  .trafficlog-inner {
    width: 100%;
    max-width: 900px;
  }
  
  .status-message {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 24px;
    text-align: center;
    
    p {
      margin-top: 16px;
      color: var(--secondary-text-color);
    }
    
    button {
      margin-top: 16px;
      padding: 8px 16px;
      background-color: var(--theme-color);
      color: white;
      border: none;
      border-radius: 20px;
      cursor: pointer;
      font-weight: 500;
      
      &:hover {
        background-color: rgba(var(--theme-color-rgb), 0.85);
      }
    }
  }
  
  .loading-spinner {
    width: 40px;
    height: 40px;
    border: 3px solid rgba(var(--theme-color-rgb), 0.1);
    border-top-color: var(--theme-color);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
  }
  
  @keyframes spin {
    to { transform: rotate(360deg); }
  }
  
  .traffic-cards {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
  
  .traffic-card {
    background-color: #ffffff;
    border-radius: 14px;
    padding: 14px;
    box-shadow: none;
    border: 1px solid var(--card-border);
    transition: color 0.2s, background-color 0.2s, border-color 0.2s, box-shadow 0.2s, opacity 0.2s, transform 0.2s;
    
    &:hover {
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
      border-color: rgba(var(--theme-color-rgb), 0.3);
    }
    
    .card-header-row {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      margin-bottom: 12px;
      
      .date {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 15px;      /* 日期字体调小 */
        font-weight: 600;
        color: var(--theme-color);
      }
      
      .rate {
        font-size: 12px;
        color: var(--secondary-text-color);
      }
    }
    
    .labels-row {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8px;
      margin-bottom: 6px;
      
      .label-item {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        font-size: 13px;
        font-weight: 500;
        color: var(--secondary-text-color);
        text-align: center;

        &.upload { color: #3b82f6; }
        &.download { color: #22a06b; }
        &.total { color: #8b5cf6; }
      }
    }
    
    .values-row {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8px;
      
      .value-item {
        font-size: 15px;
        font-weight: 500;
        color: var(--primary-text-color);
        font-family: monospace;
        text-align: center;
        
        &.total-value {
          font-weight: 700;
          color: var(--theme-color);
        }
      }
    }
  }
  
  /* 桌面端微调 */
  @media (min-width: 768px) {
    .traffic-card {
      padding: 20px 28px;
      
      .card-header-row .date {
        font-size: 15px;      /* 桌面端也保持 15px（之前 19px → 15px） */
      }
      
      .labels-row .label-item {
        font-size: 14px;
      }
      
      .values-row .value-item {
        font-size: 17px;
      }
    }
  }
  
  /* 移动端紧凑 */
  @media (max-width: 480px) {
    .traffic-card {
      padding: 12px 16px;
      
      .card-header-row {
        margin-bottom: 8px;
        padding-bottom: 6px;
        .date {
          font-size: 13px;    /* 移动端日期进一步调小 */
        }
        .rate {
          font-size: 11px;
        }
      }
      
      .labels-row {
        gap: 6px;
        margin-bottom: 4px;
        .label-item {
          font-size: 11px;
        }
      }
      
      .values-row {
        gap: 6px;
        .value-item {
          font-size: 13px;
        }
      }
    }
  }
}
</style>

<!-- 全局暗黑模式覆盖（非 scoped） -->
<style lang="scss">
.dark .traffic-card,
.dark-theme .traffic-card {
  background-color: #1e293b !important;
}

.dark .traffic-card .card-header-row .date,
.dark-theme .traffic-card .card-header-row .date {
  color: #f1f5f9 !important;
}

.dark .traffic-card .labels-row .label-item,
.dark-theme .traffic-card .labels-row .label-item {
  color: #94a3b8 !important;
}

.dark .traffic-card .values-row .value-item:not(.total-value),
.dark-theme .traffic-card .values-row .value-item:not(.total-value) {
  color: #cbd5e1 !important;
}

.dark .traffic-card .total-value,
.dark-theme .traffic-card .total-value {
  color: #4caf50 !important;
}

.dark .traffic-card .card-header-row,
.dark-theme .traffic-card .card-header-row {
  border-bottom-color: #334155 !important;
}
</style>
