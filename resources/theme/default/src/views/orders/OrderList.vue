<template>
  <div class="orders-container">
    <div class="orders-inner">
      
      <!-- 加载状态 -->
      <div v-if="loading" class="orders-loading">
        <LoadingSpinner />
        <p>{{ headerTexts.loading }}</p>
      </div>
      
      <!-- 错误提示 -->
      <div v-else-if="error" class="orders-error">
        <IconAlertTriangle :size="48" class="error-icon" />
        <p>{{ error }}</p>
        <button class="retry-button" @click="fetchOrders(1)">{{ $t('common.retry') }}</button>
      </div>
      
      <!-- 订单列表 - 统一卡片视图 -->
      <div v-else-if="orders.length > 0" class="orders-content">
        <div class="order-cards">
          <div
            v-for="order in paginatedOrders"
            :key="order.trade_no"
            class="order-card"
            role="button"
            tabindex="0"
            @click="viewOrderDetail(order.trade_no)"
            @keydown.enter="viewOrderDetail(order.trade_no)"
            @keydown.space.prevent="viewOrderDetail(order.trade_no)"
          >
            <div class="order-card-main">
              <div class="order-name">{{ getOrderName(order) }}</div>
              <span class="period"><IconClock :size="14" />{{ formatCycle(order.period) }}</span>
              <div class="order-card-body">
                <div class="detail-item order-date">{{ formatDate(order.created_at) }}</div>
                <div class="detail-item trade-no">{{ $t('orders.tradeNo') }}：{{ order.trade_no }}</div>
              </div>
            </div>
            <div class="order-card-side">
              <span class="status-badge" :class="getStatusClass(order.status)">
                {{ getStatusText(order.status) }}
              </span>
              <span class="price">{{ formatAmount(order.total_amount) }}</span>
            </div>
            <IconChevronRight class="order-chevron" :size="20" />
          </div>
        </div>
        
        <!-- 分页控制 -->
        <div class="pagination-container" v-if="totalPages > 1">
          <div class="pagination">
            <button 
              class="page-button prev" 
              @click="prevPage" 
              :disabled="currentPage === 1"
              :class="{ 'disabled': currentPage === 1 }"
            >
              <IconChevronLeft :size="16" />
            </button>
            
            <div class="page-info">
              {{ $t('common.page') }} {{ currentPage }} / {{ totalPages }}
            </div>
            
            <button 
              class="page-button next" 
              @click="nextPage" 
              :disabled="currentPage === totalPages"
              :class="{ 'disabled': currentPage === totalPages }"
            >
              <IconChevronRight :size="16" />
            </button>
          </div>
        </div>
      </div>
      
      <!-- 空状态 -->
      <div v-else class="orders-empty">
        <IconReceipt :size="48" class="empty-icon" />
        <p>{{ headerTexts.noOrders }}</p>
        <button class="shop-button" @click="$router.push('/shop')">
          <IconShoppingCart :size="16" />
          <span>{{ headerTexts.goShopping }}</span>
        </button>
      </div>
      
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, inject, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import LoadingSpinner from '@/components/common/LoadingSpinner.vue';
import { 
  IconAlertTriangle,
  IconShoppingCart,
  IconReceipt,
  IconChevronLeft,
  IconChevronRight,
  IconClock
} from '@tabler/icons-vue';
import { fetchOrderList } from '@/api/orderlist';
import { getCommConfig } from '@/api/shop';

const { t, locale } = useI18n();
const router = useRouter();
const $toast = inject('$toast');

// 状态变量
const loading = ref(true);
const error = ref('');
const orders = ref([]);
const currencySymbol = ref('¥');
const totalRecords = ref(0);
const serverPaginated = ref(false);

// 分页相关
const currentPage = ref(1);
const pageSize = 10;

// 计算总页数
const totalPages = computed(() => {
  const total = serverPaginated.value ? totalRecords.value : orders.value.length;
  return Math.max(1, Math.ceil(total / pageSize));
});

// 获取当前页的订单
const paginatedOrders = computed(() => {
  if (serverPaginated.value) return orders.value;
  const startIndex = (currentPage.value - 1) * pageSize;
  const endIndex = startIndex + pageSize;
  return orders.value.slice(startIndex, endIndex);
});

const nextPage = async () => {
  if (currentPage.value < totalPages.value) {
    const page = currentPage.value + 1;
    if (serverPaginated.value) await fetchOrders(page);
    else currentPage.value = page;
  }
};

const prevPage = async () => {
  if (currentPage.value > 1) {
    const page = currentPage.value - 1;
    if (serverPaginated.value) await fetchOrders(page);
    else currentPage.value = page;
  }
};

// 获取订单列表
const fetchOrders = async (page = 1) => {
  loading.value = true;
  error.value = '';

  try {
    const result = await fetchOrderList(page, pageSize);
    if (result && Array.isArray(result.data)) {
      orders.value = result.data;
      totalRecords.value = Number(result.total) || result.data.length;
      serverPaginated.value = result.paginated === true;
      currentPage.value = page;
    } else {
      orders.value = [];
      totalRecords.value = 0;
      serverPaginated.value = false;
      currentPage.value = 1;
    }
  } catch (err) {
    console.error('Failed to fetch orders:', err);
    error.value = err?.response?.message || t('orders.loadFailed');
    if ($toast) $toast.error(error.value);
  } finally {
    loading.value = false;
  }
};

// 格式化日期
const formatDate = (timestamp) => {
  if (!timestamp) return '--';
  const date = new Date(timestamp * 1000);
  return date.toLocaleString(locale.value === 'zh-CN' ? 'zh-CN' : 'en-US', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit'
  });
};

// 格式化周期（映射到显示文本）
const formatCycle = (cycle) => {
  if (!cycle) return '--';
  const cycleMap = {
    'month_price': t('shop.plan.price_options.month'),
    'quarter_price': t('shop.plan.price_options.quarter'),
    'half_year_price': t('shop.plan.price_options.half_year'),
    'year_price': t('shop.plan.price_options.year'),
    'two_year_price': t('shop.plan.price_options.two_year'),
    'three_year_price': t('shop.plan.price_options.three_year'),
    'onetime_price': t('shop.plan.price_options.onetime'),
    'reset_price': t('shop.plan.price_options.reset_price'),
    'deposit': t('shop.plan.price_options.deposit')
  };
  return cycleMap[cycle] || cycle;
};

// 格式化金额
const formatAmount = (amount) => {
  if (amount === null || amount === undefined) return '--';
  return currencySymbol.value + (amount / 100).toFixed(2);
};

// 订单名称（优先使用真实产品名称）
const getOrderName = (order) => {
  if (order.plan?.name) {
    return order.plan.name;
  }
  if (order.product_name) {
    return order.product_name;
  }
  if (order.period === 'deposit') {
    return t('wallet.deposit.title');
  }
  const cycleMap = {
    'month_price': t('shop.plan.price_options.month'),
    'quarter_price': t('shop.plan.price_options.quarter'),
    'half_year_price': t('shop.plan.price_options.half_year'),
    'year_price': t('shop.plan.price_options.year'),
    'two_year_price': t('shop.plan.price_options.two_year'),
    'three_year_price': t('shop.plan.price_options.three_year'),
    'onetime_price': t('shop.plan.price_options.onetime'),
    'reset_price': t('shop.plan.price_options.reset_price')
  };
  return cycleMap[order.period] || t('orders.defaultOrderName');
};

// 状态文本映射（响应式）
const statusTextMap = computed(() => ({
  0: t('orders.status.pending'),
  1: t('orders.status.processing'),
  2: t('orders.status.cancelled'),
  3: t('orders.status.completed'),
  4: t('orders.status.discounted'),
  unknown: t('orders.status.unknown')
}));

const getStatusText = (status) => {
  return statusTextMap.value[status] || `${statusTextMap.value.unknown} (${status})`;
};

const getStatusClass = (status) => {
  const statusClassMap = {
    0: 'status-pending',
    1: 'status-processing',
    2: 'status-cancelled',
    3: 'status-completed',
    4: 'status-discounted'
  };
  return statusClassMap[status] || 'status-unknown';
};

// 查看订单详情（跳转到支付页面）
const viewOrderDetail = (tradeNo) => {
  router.push({
    path: '/payment',
    query: { trade_no: tradeNo, from: 'orders' }
  });
};

// 表头文本（响应式）
const headerTexts = computed(() => ({
  tradeNo: t('orders.tradeNo'),
  createdAt: t('orders.createdAt'),
  cycle: t('orders.cycle'),
  totalAmount: t('orders.totalAmount'),
  statusLabel: t('orders.statusLabel'),
  actions: t('orders.actions'),
  noOrders: t('orders.noOrders'),
  goShopping: t('orders.goShopping'),
  loading: t('orders.loading')
}));

// 获取货币符号
const fetchCurrencySymbol = async () => {
  try {
    const response = await getCommConfig();
    if (response && response.data && response.data.currency_symbol) {
      currencySymbol.value = response.data.currency_symbol;
    }
  } catch (error) {
    console.error('获取货币符号失败:', error);
  }
};

onMounted(() => {
  fetchCurrencySymbol();
  fetchOrders();
});

// 监听语言变化强制更新视图
watch(locale, () => {
  fetchOrders(currentPage.value);
});
</script>

<style lang="scss" scoped>
.orders-container {
  padding: 1.25rem;
  padding-bottom: calc(1.25rem + 64px);
  
  @media (min-width: 768px) {
    padding: 2rem 20px;
    padding-bottom: 3rem;
  }
}

.orders-inner {
  max-width: 900px;
  margin: 0 auto;
  width: 100%;
}

/* 加载、错误、空状态 */
.orders-loading, 
.orders-error, 
.orders-empty {
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
  
  .error-icon, 
  .empty-icon {
    color: var(--text-muted);
    opacity: 0.7;
  }
}

.retry-button,
.shop-button {
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
  cursor: pointer;
  transition: color 0.3s ease, background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease, opacity 0.3s ease, transform 0.3s ease;
  
  &:hover {
    transform: translateY(-2px);
    background-color: rgba(var(--theme-color-rgb), 0.95);
  }
}

/* 卡片列表容器 */
.order-cards {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  width: 100%;
}

/* 单个卡片样式 */
.order-card {
  position: relative;
  background-color: #ffffff;
  padding: 14px;
  border-radius: 14px;
  box-shadow: none;
  border: 1px solid var(--card-border);
  overflow: hidden;
  transition: transform 0.2s, box-shadow 0.2s;
  width: 100%;
  cursor: pointer;
  
  &:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
  }

  &:focus-visible {
    outline: 2px solid var(--theme-color);
    outline-offset: 3px;
  }
  
  .order-card-header {
    padding: 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    
    .order-name {
      font-size: 15px;
      font-weight: 600;
      color: var(--text-color);
    }
    
    .status-badge {
      display: inline-block;
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
    }
  }
  
  .order-card-period-price {
    padding: 0;
    margin-top: 8px;
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    
    .period {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 0.85rem;
      color: var(--text-muted);
    }
    
    .price {
      font-size: 14px;
      font-weight: 700;
      color: var(--theme-color);
    }
  }
  
  .order-card-body {
    padding: 6px 0 0;
    
    .detail-item {
      display: flex;
      margin-bottom: 0.5rem;
      font-size: 0.85rem;
      
      .label {
        width: 70px;
        flex-shrink: 0;
        color: var(--text-muted);
      }
      
      .value {
        color: var(--text-color);
        word-break: break-all;
        &.trade-no {
          font-size: 0.75rem;
          font-family: monospace;
        }
      }
    }
  }

  .order-chevron {
    position: static;
    margin-top: 1px;
    color: var(--secondary-text-color);
    transform: none;
  }

  display: grid;
  grid-template-columns: minmax(0, 1fr) auto 20px;
  align-items: start;
  column-gap: 12px;

  .order-card-main {
    min-width: 0;

    .order-name {
      overflow: hidden;
      color: var(--heading-color);
      font-size: 15px;
      font-weight: 600;
      line-height: 1.35;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .period {
      display: inline-flex;
      margin-top: 8px;
      align-items: center;
      gap: 4px;
      color: var(--secondary-text-color);
      font-size: 12px;
    }
  }

  .order-card-side {
    display: flex;
    min-width: 76px;
    align-items: center;
    flex-direction: column;
    gap: 6px;

    .price {
      color: var(--theme-color);
      font-size: 14px;
      font-weight: 700;
      line-height: 1.3;
    }
  }

  .order-card-body {
    padding: 0;

    .detail-item {
      display: block;
      margin: 6px 0 0;
      overflow: hidden;
      color: var(--secondary-text-color);
      font-size: 11px;
      line-height: 1.35;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .trade-no { opacity: .82; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
  }
}

/* 状态徽章颜色 */
.status-badge {
  display: inline-flex;
  min-width: 60px;
  min-height: 24px;
  padding: 3px 8px;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.2;
  white-space: nowrap;

  &.status-pending {
    background-color: rgba(255, 152, 0, 0.15);
    color: #ff9800;
  }
  &.status-processing {
    background-color: rgba(33, 150, 243, 0.15);
    color: #2196f3;
  }
  &.status-cancelled {
    background-color: rgba(244, 67, 54, 0.15);
    color: #f44336;
  }
  &.status-completed {
    background-color: rgba(76, 175, 80, 0.15);
    color: #4caf50;
  }
  &.status-discounted {
    background-color: rgba(156, 39, 176, 0.15);
    color: #9c27b0;
  }
  &.status-unknown {
    background-color: rgba(158, 158, 158, 0.15);
    color: #9e9e9e;
  }
}

/* 分页样式 */
.pagination-container {
  margin-top: 1.5rem;
  display: flex;
  justify-content: center;
}

.pagination {
  display: flex;
  align-items: center;
  background-color: #ffffff;
  border-radius: 20px;
  padding: 0.5rem 0.75rem;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
  border: 1px solid var(--card-border);
  
  .page-button {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    background: transparent;
    border: none;
    color: var(--text-color);
    cursor: pointer;
    
    &:hover:not(.disabled) {
      background-color: rgba(var(--theme-color-rgb), 0.1);
      color: var(--theme-color);
    }
    
    &.disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
  }
  
  .page-info {
    margin: 0 1rem;
    font-size: 0.9rem;
    color: var(--text-color);
  }
}

@media (max-width: 480px) {
  .order-card { grid-template-columns: minmax(0, 1fr) auto 18px; column-gap: 8px; }
  .order-card .order-card-side { min-width: 68px; }
}
</style>

<!-- 全局暗黑模式覆盖（非 scoped） -->
<style lang="scss">
.dark .order-card,
.dark-theme .order-card {
  background-color: #1e293b !important;
}

.dark .pagination,
.dark-theme .pagination {
  background-color: #1e293b !important;
}
</style>
