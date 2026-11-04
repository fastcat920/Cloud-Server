<template>
  <div class="docs-container">
    <!-- 域名授权验证提示（如需启用请保留，否则可删除） -->
    <DomainAuthAlert 
      :is-authorized="authStatus.isAuthorized" 
      :api-domain="authStatus.apiDomain" 
    />
    
    <div class="docs-inner">
      <!-- 加载状态 -->
      <div v-if="loading" class="docs-loading">
        <LoadingSpinner />
        <p>{{ $t('docs.loading') }}</p>
      </div>

      <!-- 错误提示 -->
      <div v-else-if="error" class="docs-error">
        <IconAlertTriangle :size="48" class="error-icon" />
        <p>{{ error }}</p>
        <button class="retry-button" @click="fetchKnowledge">{{ $t('docs.retry') }}</button>
      </div>

      <!-- 文档列表 -->
      <div v-else-if="hasDocuments" class="docs-content">
        <div v-for="(items, category) in documents" :key="category" class="doc-category">
          <h2 class="category-title"><IconFolder :size="20" />{{ category }}</h2>
          <div class="doc-items">
            <button v-for="item in items" :key="item.id" type="button" class="doc-item" @click="goToDocument(item.id)">
              <div class="doc-icon"><IconFileText :size="20" /></div>
              <div class="doc-info">
                <h3 class="doc-title">{{ item.title }}</h3>
              </div>
              <div class="doc-action">
                <IconChevronRight :size="20" />
              </div>
            </button>
          </div>
        </div>
      </div>

      <!-- 空状态 -->
      <div v-else class="docs-empty">
        <IconFileSearch :size="48" class="empty-icon" />
        <p>{{ $t('docs.noDocuments') }}</p>
      </div>
    </div>
  </div>
</template>

<script setup name="DocsPage">
import { ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import LoadingSpinner from '@/components/common/LoadingSpinner.vue';
import { 
  IconChevronRight,
  IconAlertTriangle,
  IconFileSearch,
  IconFileText,
  IconFolder
} from '@tabler/icons-vue';
import { fetchKnowledgeList } from '@/api/docs';
import DomainAuthAlert from '@/components/common/DomainAuthAlert.vue';
import { applyDomainAuth } from '@/utils/licenseAuth';
import { useToast } from '@/composables/useToast';

const { t, locale } = useI18n();
const router = useRouter();
const { showToast } = useToast();

// 状态
const loading = ref(true);
const error = ref('');
const documents = ref({});
let listRequestSequence = 0;

// 域名授权
const authStatus = ref({
  isAuthorized: true,
  apiDomain: ''
});

const hasDocuments = computed(() => {
  return documents.value && 
    typeof documents.value === 'object' && 
    Object.keys(documents.value).length > 0 &&
    Object.values(documents.value).some(items => Array.isArray(items) && items.length > 0);
});

// 跳转详情
const goToDocument = (id) => { router.push(`/docs/${id}`); };

// 获取文档列表
const fetchKnowledge = async () => {
  const requestSequence = ++listRequestSequence;
  loading.value = true;
  error.value = '';
  try {
    const result = await fetchKnowledgeList();
    if (requestSequence !== listRequestSequence) return;
    if (result?.data) {
      documents.value = result.data;
    } else {
      documents.value = {};
    }
  } catch (err) {
    if (requestSequence !== listRequestSequence) return;
    console.error('Failed to fetch knowledge list:', err);
    error.value = err?.response?.message || t('docs.fetchError');
    showToast(error.value, 'error');
  } finally {
    if (requestSequence === listRequestSequence) loading.value = false;
  }
};

// 后端会按当前请求语言返回整篇本地化内容，切换语言时重新获取列表。
watch(locale, () => { fetchKnowledge(); });

onMounted(() => {
  authStatus.value = applyDomainAuth();
  fetchKnowledge();
});
</script>

<style lang="scss" scoped>
.docs-container {
  padding: 1.25rem;
  padding-bottom: calc(1.25rem + 64px);
  @media (min-width: 768px) {
    padding: 2rem 20px;
    padding-bottom: 3rem;
  }
}

.docs-inner {
  max-width: 900px;
  margin: 0 auto;
}

/* 卡片基础样式（亮色 #ffffff） */
.dashboard-card, .doc-item {
  background-color: #ffffff;
  border-radius: 20px;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
  padding: 20px;
  margin-bottom: 24px;
  border: 1px solid var(--card-border);
  transition: color 0.3s ease, background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, transform 0.3s ease;
  &:hover {
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    border-color: rgba(var(--theme-color-rgb), 0.3);
  }
}

.welcome-card {
  margin-bottom: 10px;
}

.docs-header {
  margin-bottom: 2rem;
  .docs-title {
    font-size: 1.75rem;
    font-weight: 700;
    margin-bottom: 1.5rem;
    background: linear-gradient(45deg, var(--theme-color), var(--secondary-color));
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
  }
}

.docs-content {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  width: 100%;
}

.doc-category {
  .category-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 1.3rem;
    font-weight: 600;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid rgba(var(--theme-color-rgb), 0.1);
    color: var(--text-color);
  }
}

.doc-items {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  @media (min-width: 768px) {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.25rem;
  }
  @media (min-width: 1024px) {
    grid-template-columns: repeat(3, 1fr);
  }
}

.doc-item {
  display: flex;
  align-items: center;
  padding: 1rem 1.25rem;
  background-color: #ffffff;
  cursor: pointer;
  border-radius: 20px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.05);
  border: 1px solid var(--card-border);
  transition: color 0.25s, background-color 0.25s, border-color 0.25s, box-shadow 0.25s, opacity 0.25s, transform 0.25s;

  .doc-icon {
    display: inline-flex;
    width: 36px;
    height: 36px;
    margin-right: 12px;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    color: var(--theme-color);
    background: rgba(var(--theme-color-rgb), .1);
    border-radius: 10px;
  }
  &:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0,0,0,0.08);
    border-color: rgba(var(--theme-color-rgb), 0.3);
    .doc-action {
      color: var(--theme-color);
      transform: translateX(3px);
    }
  }
  .doc-info {
    flex: 1;
    overflow: hidden;
    .doc-title {
      font-size: 1rem;
      font-weight: 600;
      margin: 0;
      color: var(--text-color);
      overflow: hidden;
      text-overflow: ellipsis;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      line-clamp: 2;
      -webkit-box-orient: vertical;
    }
    .doc-date {
      font-size: 0.8rem;
      color: var(--text-muted);
    }
  }
  .doc-action {
    color: var(--text-muted);
    margin-left: 1rem;
    transition: color 0.3s, background-color 0.3s, border-color 0.3s, box-shadow 0.3s, opacity 0.3s, transform 0.3s;
  }
}

.docs-loading, .docs-error, .docs-empty {
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
    box-shadow: 0 10px 25px rgba(var(--theme-color-rgb), 0.35);
  }
}

</style>

<!-- 全局暗黑模式覆盖（非 scoped） -->
<style lang="scss">
.dark .doc-item,
.dark-theme .doc-item {
  background-color: #1e293b !important;
}
</style>
