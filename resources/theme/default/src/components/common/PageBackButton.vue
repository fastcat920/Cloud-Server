<template>
  <button
    type="button"
    class="page-back-button"
    :aria-label="$t('common.back')"
    :title="$t('common.back')"
    @click="$emit('click')"
  >
    <IconArrowLeft :size="20" stroke-width="2" />
    <span>{{ $t('common.back') }}</span>
  </button>
</template>

<script>
import { IconArrowLeft } from '@tabler/icons-vue';

export default {
  name: 'PageBackButton',
  components: { IconArrowLeft },
  emits: ['click']
};
</script>

<style lang="scss" scoped>
.page-back-button {
  height: 40px;
  padding: 0 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  border: 1px solid var(--card-border, var(--border-color));
  border-radius: 12px;
  background: var(--card-background, rgba(255, 255, 255, 0.88));
  color: var(--text-color);
  font-size: 14px;
  font-weight: 600;
  line-height: 1;
  cursor: pointer;
  box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  transition: color 0.2s ease, border-color 0.2s ease, background-color 0.2s ease, transform 0.2s ease;

  &:hover {
    color: var(--theme-color);
    border-color: rgba(var(--theme-color-rgb), 0.35);
    background: rgba(var(--theme-color-rgb), 0.08);
    transform: translateX(-2px);
  }

  &:active {
    transform: translateX(-1px) scale(0.98);
  }

  &:focus-visible {
    outline: 2px solid rgba(var(--theme-color-rgb), 0.35);
    outline-offset: 2px;
  }
}
</style>
