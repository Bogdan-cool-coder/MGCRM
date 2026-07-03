<template>
  <div class="plan-save-bar">
    <span v-if="dirtyCount > 0" class="plan-save-bar__count">
      {{ t('dashboard.plans.dirty_count', { n: dirtyCount }) }}
    </span>
    <span v-else class="plan-save-bar__clean">{{ t('dashboard.plans.no_changes') }}</span>

    <div class="plan-save-bar__actions">
      <Button
        v-if="showCopy"
        severity="secondary"
        icon="pi pi-copy"
        :label="t('dashboard.plans.copy_prev')"
        :loading="copying"
        :disabled="!canEdit"
        @click="emit('copy-prev')"
      />
      <Button
        icon="pi pi-save"
        :label="t('dashboard.plans.save')"
        :badge="dirtyCount > 0 ? String(dirtyCount) : undefined"
        :loading="saving"
        :disabled="!canEdit || dirtyCount === 0"
        @click="emit('save')"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'

withDefaults(
  defineProps<{
    dirtyCount: number
    saving: boolean
    canEdit: boolean
    /** Whether to render the «Копировать пред. период» button (tasks hide it). */
    showCopy?: boolean
    copying?: boolean
  }>(),
  { showCopy: true, copying: false },
)

const emit = defineEmits<{
  save: []
  'copy-prev': []
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.plan-save-bar {
  position: sticky;
  bottom: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-4;
  flex-wrap: wrap;
  margin-top: $space-4;
  padding: $space-3 $space-5;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-md;

  .app-dark & {
    border-color: var(--p-surface-200);
  }
}

.plan-save-bar__count {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  font-variant-numeric: tabular-nums;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

.plan-save-bar__clean {
  font-size: $font-size-sm;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.plan-save-bar__actions {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
}
</style>
