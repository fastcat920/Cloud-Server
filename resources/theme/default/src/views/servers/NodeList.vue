<template>
  <div class="nodes-container">
    <!-- 域名授权验证提示（如需启用请保留，否则可删除） -->
    <DomainAuthAlert 
      :is-authorized="authStatus.isAuthorized" 
      :api-domain="authStatus.apiDomain" 
    />
    
    <div class="nodes-inner">
      <!-- 节点列表状态 -->
      <div v-if="loading" class="nodes-loading">
        <LoadingSpinner />
        <p>{{ $t('nodes.loading') || '正在加载节点...' }}</p>
      </div>
      
      <!-- 错误提示 -->
      <div v-else-if="error" class="nodes-error">
        <IconAlertTriangle :size="48" class="error-icon" />
        <p>{{ error }}</p>
        <button class="retry-button" @click="fetchNodes">{{ $t('common.retry') || '重试' }}</button>
      </div>
      
      <!-- 节点列表内容 -->
      <div v-else-if="nodes.length > 0" class="nodes-content">
        <div class="node-items">
          <div v-for="node in nodes" :key="node.id" class="node-item">
            <!-- 节点状态指示器 -->
            <div class="node-status">
              <div class="status-indicator" :class="{ 'online': node.is_online === 1 }"></div>
            </div>
            
            <!-- 节点信息 -->
            <div class="node-info">
              <!-- 标签区域 -->
              <div class="node-tags">
                <span class="node-tag rate-tag" v-if="showNodeRate">x{{ node.rate }}</span>
                <span class="node-tag type-tag">{{ node.type }}</span>
                <template v-if="node.tags && node.tags.length > 0">
                  <span v-for="(tag, idx) in node.tags" :key="idx" class="node-tag">{{ tag }}</span>
                </template>
              </div>
              
              <!-- 节点名称 -->
              <h3 class="node-name">{{ node.name }}</h3>
              
              <!-- 节点主机信息 -->
              <p class="node-host" v-if="showNodeDetails">{{ node.host }}:{{ node.port }}</p>
            </div>
            
            <!-- 更多按钮 -->
            <div v-if="showNodeRate && allowViewNodeInfo" class="node-actions">
              <button class="more-btn" @click="openNodeDetail(node)">
                <IconDotsVertical :size="20" />
              </button>
            </div>
          </div>
        </div>
      </div>
      
      <!-- 空状态 -->
      <div v-else class="nodes-empty">
        <IconServer :size="48" class="empty-icon" />
        <p>{{ $t('nodes.noNodes') || '暂无可用节点' }}</p>
      </div>
    </div>
    
    <!-- 节点详情模态框 -->
    <NodeDetailModal 
      v-if="allowViewNodeInfo"
      :show="showDetailModal" 
      :node="selectedNode" 
      :userInfo="userInfo"
      @close="closeNodeDetail"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import LoadingSpinner from '@/components/common/LoadingSpinner.vue';
import { IconAlertTriangle, IconServer, IconDotsVertical } from '@tabler/icons-vue';
import { fetchServerNodes } from '@/api/servers';
import { getUserInfo } from '@/api/user';
import DomainAuthAlert from '@/components/common/DomainAuthAlert.vue';
import { applyDomainAuth } from '@/utils/licenseAuth';
import { NODES_CONFIG } from '@/utils/baseConfig';
import NodeDetailModal from '@/components/common/NodeDetailModal.vue';
import { useToast } from '@/composables/useToast';

const { t } = useI18n();
const { showToast } = useToast();

// 配置项
const showNodeDetails = ref(NODES_CONFIG.showNodeDetails);
const showNodeRate = ref(NODES_CONFIG.showNodeRate);
const allowViewNodeInfo = ref(NODES_CONFIG.allowViewNodeInfo);

// 状态
const loading = ref(true);
const error = ref('');
const nodes = ref([]);
const userInfo = ref(null);
const showDetailModal = ref(false);
const selectedNode = ref(null);
const authStatus = ref({ isAuthorized: true, apiDomain: '' });

// 获取用户信息
const fetchUserInfo = async () => {
  try {
    const res = await getUserInfo();
    if (res?.data) userInfo.value = res.data;
  } catch (err) {
    console.error('获取用户信息失败:', err);
    showToast(t('nodes.userInfoError'), 'error');
  }
};

// 获取节点列表
const fetchNodes = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetchServerNodes();
    nodes.value = res?.data || [];
  } catch (err) {
    console.error('获取节点列表失败:', err);
    error.value = err?.response?.message || t('nodes.loadFailed');
    showToast(error.value, 'error');
  } finally {
    loading.value = false;
  }
};

// 打开节点详情
const openNodeDetail = (node) => {
  selectedNode.value = node;
  showDetailModal.value = true;
};

// 关闭节点详情
const closeNodeDetail = () => {
  showDetailModal.value = false;
  setTimeout(() => { selectedNode.value = null; }, 300);
};

onMounted(() => {
  authStatus.value = applyDomainAuth();
  fetchUserInfo();
  fetchNodes();
});
</script>

<style lang="scss" scoped>
.nodes-container {
  padding: 1.25rem;
  padding-bottom: calc(1.25rem + 64px);
  @media (min-width: 768px) {
    padding: 2rem 20px;
    padding-bottom: 3rem;
  }
}

.nodes-inner {
  max-width: 900px;
  margin: 0 auto;
}

/* 卡片基础样式（亮色 #ffffff） */
.node-item {
  background-color: #ffffff;
  border-radius: 20px;
  padding: 1rem 1.25rem;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
  border: 1px solid var(--card-border);
  transition: color 0.25s ease, background-color 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease, opacity 0.25s ease, transform 0.25s ease;
  display: flex;
  align-items: center;
  
  &:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.08);
    border-color: rgba(var(--theme-color-rgb), 0.3);
  }
  
  .node-status {
    margin-right: 1rem;
    .status-indicator {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background-color: #ccc;
      &.online {
        background-color: #4caf50;
        animation: pulse 2s infinite;
      }
    }
  }
  
  .node-info {
    flex: 1;
    overflow: hidden;
    .node-tags {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      margin-bottom: 0.5rem;
      .node-tag {
        font-size: 0.75rem;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        background-color: rgba(var(--theme-color-rgb), 0.1);
        color: var(--theme-color);
        &.rate-tag {
          background-color: rgba(76, 175, 80, 0.1);
          color: #4caf50;
          font-weight: 600;
        }
        &.type-tag {
          background-color: rgba(33, 150, 243, 0.1);
          color: #2196f3;
        }
      }
    }
    .node-name {
      font-size: 1rem;
      font-weight: 600;
      margin: 0 0 0.35rem;
      color: var(--text-color);
      overflow: hidden;
      text-overflow: ellipsis;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      line-clamp: 2;
    }
    .node-host {
      font-size: 0.8rem;
      color: var(--text-muted);
      margin: 0;
    }
  }
  
  .node-actions {
    margin-left: 12px;
    .more-btn {
      background: none;
      border: none;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text-muted);
      cursor: pointer;
      transition: color 0.2s, background-color 0.2s, border-color 0.2s, box-shadow 0.2s, opacity 0.2s, transform 0.2s;
      &:hover {
        background-color: rgba(var(--theme-color-rgb), 0.1);
        color: var(--theme-color);
      }
    }
  }
}

/* 节点列表容器 */
.node-items {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;  /* 移动端卡片间隙增加（原 1rem → 1.25rem） */
}

/* 桌面端网格布局间隙 */
@media (min-width: 768px) {
  .node-items {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.25rem;  /* 桌面端保持 1.25rem */
  }
}

@media (min-width: 1024px) {
  .node-items {
    grid-template-columns: repeat(3, 1fr);
  }
}

/* 加载、错误、空状态 */
.nodes-loading, .nodes-error, .nodes-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 3rem 1rem;
  text-align: center;
  p {
    margin-top: 1rem;
    color: var(--text-muted);
    font-size: 1.1rem;
  }
  .error-icon, .empty-icon {
    color: var(--text-muted);
    opacity: 0.7;
  }
}

.retry-button {
  margin-top: 1.5rem;
  height: 40px;
  min-width: 120px;
  padding: 0 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border-radius: 20px;
  background-color: rgba(var(--theme-color-rgb), 0.85);
  color: white;
  font-weight: 500;
  font-size: 14px;
  border: 1px solid rgba(var(--theme-color-rgb), 0.3);
  box-shadow: 0 8px 20px rgba(var(--theme-color-rgb), 0.25);
  cursor: pointer;
  transition: color 0.3s, background-color 0.3s, border-color 0.3s, box-shadow 0.3s, opacity 0.3s, transform 0.3s;
  backdrop-filter: blur(8px);
  &:hover {
    transform: translateY(-2px);
    background-color: rgba(var(--theme-color-rgb), 0.95);
  }
}

@keyframes pulse {
  0% { box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.4); }
  70% { box-shadow: 0 0 0 8px rgba(76, 175, 80, 0); }
  100% { box-shadow: 0 0 0 0 rgba(76, 175, 80, 0); }
}
</style>

<!-- 全局暗黑模式覆盖（非 scoped） -->
<style lang="scss">
.dark .node-item,
.dark-theme .node-item {
  background-color: #1e293b !important;
}
</style>
