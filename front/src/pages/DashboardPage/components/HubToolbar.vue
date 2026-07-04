<template>
  <!--
    Analytics-hub toolbar (restyle §1.2): one row — icon tile + title + segmented
    tab-strip + Excel export. Replaces the old PageHeader + separate tab strip.
    Purely presentational: props down, emits up. All hub logic (onTabSelect,
    tabStripKey veto-snap, onExport) stays in index.vue.
  -->
  <div class="hub-toolbar">
    <span class="hub-toolbar__icon-tile">
      <i class="pi pi-chart-bar" aria-hidden="true" />
    </span>

    <div class="hub-toolbar__heading">
      <h1 class="hub-toolbar__title">{{ t('dashboard.hub.title') }}</h1>
    </div>

    <span class="hub-toolbar__spacer" />

    <!-- Segmented tab-strip (veto-snap key owned by the shell) -->
    <SelectButton
      :key="tabStripKey"
      :model-value="activeTab"
      :options="tabOptions"
      option-label="label"
      option-value="value"
      :allow-empty="false"
      class="hub-toolbar__segmented"
      @update:model-value="(v) => emit('update:activeTab', v as HubTab | null)"
    />

    <!-- Excel export (report + plans tabs) -->
    <Button
      v-if="showExport"
      icon="pi pi-file-excel"
      :label="t('dashboard.hub.export')"
      severity="secondary"
      outlined
      :loading="exporting"
      @click="emit('export')"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import SelectButton from 'primevue/selectbutton'
import type { HubTab } from '../composables/useAnalyticsHub'

interface TabOption {
  label: string
  value: HubTab
}

defineProps<{
  activeTab: HubTab
  tabOptions: TabOption[]
  /** Bumped by the shell after a leave-veto so the strip re-reads the model. */
  tabStripKey: number
  showExport: boolean
  exporting: boolean
}>()

const emit = defineEmits<{
  'update:activeTab': [HubTab | null]
  export: []
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.hub-toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: $space-3;
  padding: $space-3 $space-5;
  background: $surface-card;
  border-bottom: 1px solid $surface-200;

  .app-dark & {
    border-bottom-color: var(--p-surface-200);
  }
}

.hub-toolbar__icon-tile {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  flex-shrink: 0;
  border-radius: $radius-md;
  background: var(--p-primary-100);
  color: var(--p-primary-color);
  font-size: $font-size-lg;

  .app-dark & {
    background: var(--p-primary-900);
    color: var(--p-primary-100);
  }
}

.hub-toolbar__heading {
  min-width: 0;
}

.hub-toolbar__title {
  margin: 0;
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  line-height: $line-height-tight;
}

.hub-toolbar__spacer {
  flex: 1;
  min-width: $space-3;
}

@media (max-width: 767px) {
  .hub-toolbar__segmented {
    order: 3;
    flex: 1 1 100%;
  }
}
</style>
