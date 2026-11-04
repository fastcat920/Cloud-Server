<template>
  <div class="invite-users-page" :class="{ embedded }">
    <section class="list-card">
      <div v-if="loading" class="state">{{ $t('invite.users.loading') }}</div>
      <div v-else-if="error" class="state error">
        <span>{{ error }}</span>
        <button type="button" @click="load(currentPage)">{{ $t('common.retry') }}</button>
      </div>
      <div v-else-if="!rows.length" class="state">{{ $t('invite.users.empty') }}</div>
      <template v-else>
        <div class="desktop-table">
          <table>
            <thead><tr><th>{{ $t('invite.users.email') }}</th><th>{{ $t('invite.users.registeredAt') }}</th><th>{{ $t('invite.users.status') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in rows" :key="row.id">
                <td>{{ row.email }}</td>
                <td>{{ formatDate(row.created_at) }}</td>
                <td><span class="status" :class="row.status">{{ statusText(row.status) }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="mobile-list">
          <article v-for="row in rows" :key="row.id">
            <div><strong>{{ row.email }}</strong><span class="status" :class="row.status">{{ statusText(row.status) }}</span></div>
            <small>{{ formatDate(row.created_at) }}</small>
          </article>
        </div>
        <footer class="pagination">
          <span>{{ $t('invite.users.total', { total }) }}</span>
          <div>
            <button type="button" :disabled="currentPage <= 1" @click="load(currentPage - 1)"><IconChevronLeft :size="18" />{{ $t('common.previous') }}</button>
            <strong>{{ currentPage }} / {{ totalPages }}</strong>
            <button type="button" :disabled="currentPage >= totalPages" @click="load(currentPage + 1)">{{ $t('common.next') }}<IconChevronRight :size="18" /></button>
          </div>
        </footer>
      </template>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconChevronLeft, IconChevronRight } from '@tabler/icons-vue';
import { getInvitedUsers } from '@/api/invite';

defineProps({
  embedded: { type: Boolean, default: false }
});

const { t, locale } = useI18n();
const rows = ref([]);
const loading = ref(true);
const error = ref('');
const currentPage = ref(1);
const pageSize = 10;
const total = ref(0);
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / pageSize)));

const formatDate = timestamp => timestamp
  ? new Date(Number(timestamp) * 1000).toLocaleString(locale.value === 'en-US' ? 'en-US' : 'zh-CN', { hour12: false })
  : '-';
const statusText = status => t(`invite.users.statuses.${status === 'effective' ? 'effective' : 'pending'}`);
const load = async page => {
  loading.value = true;
  error.value = '';
  currentPage.value = Math.min(Math.max(1, page), totalPages.value);
  try {
    const response = await getInvitedUsers(currentPage.value, pageSize);
    rows.value = Array.isArray(response?.data) ? response.data : [];
    total.value = Number(response?.total || 0);
  } catch (requestError) {
    rows.value = [];
    error.value = requestError?.response?.data?.message || requestError?.message || t('invite.users.loadFailed');
  } finally {
    loading.value = false;
  }
};

onMounted(() => load(1));
</script>

<style scoped lang="scss">
.invite-users-page { width: 100%; max-width: var(--page-max-width); margin: 0 auto; padding: 18px 0 60px; color: var(--text-color); }
.invite-users-page.embedded { max-width: none; padding: 0; }
.invite-users-page.embedded .list-card { border: 0; border-radius: 0; background: transparent; }
.list-card { overflow: hidden; border: 1px solid var(--border-color); border-radius: 20px; background: var(--card-background); }
.state { display: flex; min-height: 260px; align-items: center; justify-content: center; gap: 12px; color: var(--secondary-text-color); }
.state.error { color: #dc3545; }
.state button,.pagination button { display: inline-flex; min-height: 38px; align-items: center; justify-content: center; gap: 4px; padding: 7px 12px; color: var(--theme-color); border: 1px solid rgba(var(--theme-color-rgb), .24); border-radius: 10px; background: transparent; cursor: pointer; }
table { width: 100%; border-collapse: collapse; }
th,td { padding: 17px 20px; text-align: left; border-bottom: 1px solid var(--border-color); }
th { color: var(--secondary-text-color); font-size: 13px; font-weight: 600; }
tbody tr:last-child td { border-bottom: 0; }
.status { display: inline-flex; padding: 5px 10px; border-radius: 999px; font-size: 13px; font-weight: 600; }
.status.effective { color: #128244; background: rgba(18, 130, 68, .1); }
.status.pending { color: #a66a08; background: rgba(245, 158, 11, .12); }
.mobile-list { display: none; }
.pagination { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 16px 20px; border-top: 1px solid var(--border-color); color: var(--secondary-text-color); }
.pagination > div { display: flex; align-items: center; gap: 10px; }
.pagination strong { min-width: 58px; color: var(--text-color); text-align: center; }
.pagination button:disabled { opacity: .4; cursor: not-allowed; }
@media (max-width: 700px) {
  .invite-users-page { padding: 8px 10px 100px; }
  .desktop-table { display: none; }
  .mobile-list { display: block; }
  .mobile-list article { padding: 16px; border-bottom: 1px solid var(--border-color); }
  .mobile-list article > div { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .mobile-list strong { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .mobile-list small { display: block; margin-top: 8px; color: var(--secondary-text-color); }
  .pagination { align-items: stretch; flex-direction: column; }
  .pagination > div { justify-content: space-between; }
  .pagination button { padding-inline: 9px; }
}
</style>
