<template>
  <!--
    Automation-runs filter panel (Periphery uplift §D.1) — inline panel rendered
    under the toolbar by the parent (pattern Б: filter-trigger toggles this panel),
    mirroring DealsFilterOverlay's inline-under-toolbar placement. Content is 1:1
    with the previous always-on filter row (automation / status / action / period)
    plus Apply + Reset. All filter refs are two-way bound via defineModel; the
    parent still owns fetchRuns.
  -->
  <div class="automation-runs-filter">
    <div class="automation-runs-filter__grid">
      <div class="automation-runs-filter__field">
        <label class="automation-runs-filter__label">{{ t('automation.runs.col.automation') }}</label>
        <Select
          v-model="automationId"
          :options="automations"
          option-label="name"
          option-value="id"
          :placeholder="t('automation.runs.filter.automation')"
          show-clear
          class="automation-runs-filter__control"
        />
      </div>

      <div class="automation-runs-filter__field">
        <label class="automation-runs-filter__label">{{ t('automation.runs.col.status') }}</label>
        <Select
          v-model="status"
          :options="statusOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('automation.runs.filter.status')"
          show-clear
          class="automation-runs-filter__control"
        />
      </div>

      <div class="automation-runs-filter__field">
        <label class="automation-runs-filter__label">{{ t('automation.runs.col.action') }}</label>
        <Select
          v-model="actionKind"
          :options="actionOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('automation.runs.filter.action')"
          show-clear
          class="automation-runs-filter__control"
        />
      </div>

      <div class="automation-runs-filter__field">
        <label class="automation-runs-filter__label">{{ t('automation.runs.filter.period') }}</label>
        <DatePicker
          v-model="dateRange"
          selection-mode="range"
          :placeholder="t('automation.runs.filter.period')"
          date-format="dd.mm.yy"
          show-clear
          class="automation-runs-filter__control"
        />
      </div>
    </div>

    <div class="automation-runs-filter__footer">
      <Button
        :label="t('common.reset')"
        severity="secondary"
        text
        @click="emit('reset')"
      />
      <Button
        :label="t('automation.runs.applyFilter')"
        icon="pi pi-check"
        @click="emit('apply')"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import type { AutomationDto, RunStatus, ActionKind } from '@/entities/automation'

interface StatusOption {
  label: string
  value: RunStatus
}
interface ActionOption {
  label: string
  value: ActionKind
}

defineProps<{
  automations: AutomationDto[]
  statusOptions: StatusOption[]
  actionOptions: ActionOption[]
}>()

const emit = defineEmits<{
  apply: []
  reset: []
}>()

// Two-way bound filter refs (owned by the page composable).
const automationId = defineModel<number | null>('automationId', { required: true })
const status = defineModel<RunStatus | null>('status', { required: true })
const actionKind = defineModel<ActionKind | null>('actionKind', { required: true })
const dateRange = defineModel<Date[] | null>('dateRange', { required: true })

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.automation-runs-filter {
  border-bottom: 1px solid var(--p-surface-200);
  background: var(--p-surface-50);
  padding: $space-4 $space-5;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-100);
    border-bottom-color: var(--p-surface-300);
  }
}

.automation-runs-filter__grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  gap: 14px 18px; // matches DealsFilterOverlay grid gaps (no exact token pair)
  margin-bottom: $space-3;
}

.automation-runs-filter__field {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.automation-runs-filter__label {
  display: block;
  font-size: $font-size-xs;
  color: $surface-500;
  margin-bottom: $space-1;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// Unified 38px control height (matches DealsFilterOverlay).
$_filter-h: 38px;

.automation-runs-filter__control {
  width: 100%;

  :deep(.p-select),
  :deep(.p-select-label-container) {
    height: $_filter-h;
    min-height: $_filter-h;
  }
  :deep(.p-datepicker-input) {
    height: $_filter-h;
    min-height: $_filter-h;
  }
}

:deep(.p-select.automation-runs-filter__control) {
  height: $_filter-h;
  min-height: $_filter-h;
  align-items: center;
}

.automation-runs-filter__footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: $space-2;
  padding-top: $space-3;
  border-top: 1px solid $surface-100;

  .app-dark & {
    border-top-color: var(--p-surface-700);
  }
}

@media (max-width: 991px) {
  .automation-runs-filter__grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
