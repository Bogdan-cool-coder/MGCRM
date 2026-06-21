<template>
  <div class="automation-list-panel">
    <!-- Header filters -->
    <div class="automation-list-panel__header">
      <span class="automation-list-panel__title">
        {{ t('automation.list.panelTitle') }}
      </span>

      <div class="d-flex align-items-center gap-2 flex-wrap">
        <Select
          v-model="filterTrigger"
          :options="triggerFilterOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('automation.list.filterTrigger')"
          show-clear
          class="automation-list-panel__filter"
        />
        <Select
          v-model="filterAction"
          :options="actionFilterOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('automation.list.filterAction')"
          show-clear
          class="automation-list-panel__filter"
        />
        <Button
          :label="t('automation.list.addButton')"
          icon="pi pi-plus"
          size="small"
          @click="emit('addAutomation')"
        />
      </div>
    </div>

    <!-- DataTable -->
    <DataTable
      :value="filteredAutomations"
      :loading="loading"
      striped-rows
      class="automation-list-panel__table"
      :empty-message="' '"
      row-hover
    >
      <!-- Empty state -->
      <template #empty>
        <div class="automation-list-panel__empty">
          <i class="pi pi-bolt automation-list-panel__empty-icon" />
          <p class="automation-list-panel__empty-title">{{ t('automation.list.empty') }}</p>
          <p class="automation-list-panel__empty-sub">{{ t('automation.list.emptyHint') }}</p>
          <Button
            :label="t('automation.list.addFirstButton')"
            icon="pi pi-plus"
            size="small"
            @click="emit('addAutomation')"
          />
        </div>
      </template>

      <!-- Name -->
      <Column
        field="name"
        :header="t('automation.list.col.name')"
        class="automation-list-panel__col-name"
      >
        <template #body="{ data }">
          <button class="automation-list-panel__name-btn" @click="emit('editAutomation', data)">
            {{ data.name }}
          </button>
        </template>
      </Column>

      <!-- Stage -->
      <Column :header="t('automation.list.col.stage')">
        <template #body="{ data }">
          <span v-if="data.stage_name">{{ data.stage_name }}</span>
          <span v-else class="text-muted">—</span>
        </template>
      </Column>

      <!-- Trigger -->
      <Column :header="t('automation.list.col.trigger')">
        <template #body="{ data }">
          {{ t(`automation.trigger.${data.trigger_kind}`) }}
        </template>
      </Column>

      <!-- Action -->
      <Column :header="t('automation.list.col.action')">
        <template #body="{ data }">
          <div class="d-flex align-items-center gap-1">
            <i :class="['pi', ACTION_ICONS[data.action_kind as ActionKind] ?? 'pi-bolt', 'automation-list-panel__action-icon']" />
            {{ t(`automation.action.${data.action_kind}`) }}
          </div>
        </template>
      </Column>

      <!-- Active toggle -->
      <Column :header="t('automation.list.col.active')" style="width: 80px">
        <template #body="{ data }">
          <ToggleSwitch
            :model-value="data.is_active"
            size="small"
            @update:model-value="(v) => emit('toggle', data.id, v)"
          />
        </template>
      </Column>

      <!-- Row actions -->
      <Column style="width: 80px">
        <template #body="{ data }">
          <div class="d-flex gap-1">
            <Button
              icon="pi pi-pencil"
              severity="secondary"
              text
              size="small"
              :title="t('common.edit')"
              @click="emit('editAutomation', data)"
            />
            <Button
              icon="pi pi-trash"
              severity="danger"
              text
              size="small"
              :title="t('common.delete')"
              @click="emit('deleteAutomation', data.id)"
            />
          </div>
        </template>
      </Column>
    </DataTable>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import type { AutomationDto, ActionKind, TriggerKind } from '@/entities/automation'

const props = defineProps<{
  automations: AutomationDto[]
  loading: boolean
}>()

const emit = defineEmits<{
  addAutomation: []
  editAutomation: [automation: AutomationDto]
  deleteAutomation: [id: number]
  toggle: [id: number, isActive: boolean]
}>()

const { t } = useI18n()

const ACTION_ICONS: Record<ActionKind, string> = {
  tg_notify: 'pi-telegram',
  create_task: 'pi-clipboard',
  set_field: 'pi-pencil-square',
  generate_document: 'pi-file',
  change_owner: 'pi-user-edit',
  change_stage: 'pi-arrow-right-circle',
  webhook: 'pi-wifi',
  email: 'pi-envelope',
}

// Filter state
const filterTrigger = ref<TriggerKind | null>(null)
const filterAction = ref<ActionKind | null>(null)

const TRIGGER_KINDS: TriggerKind[] = [
  'on_enter_stage',
  'on_create',
  'idle_in_stage_days',
  'date_field_approaching',
]

const ACTION_KINDS: ActionKind[] = [
  'tg_notify',
  'create_task',
  'set_field',
  'generate_document',
  'change_owner',
  'change_stage',
  'webhook',
  'email',
]

const triggerFilterOptions = computed(() =>
  TRIGGER_KINDS.map((k) => ({ label: t(`automation.trigger.${k}`), value: k })),
)

const actionFilterOptions = computed(() =>
  ACTION_KINDS.map((k) => ({ label: t(`automation.action.${k}`), value: k })),
)

const filteredAutomations = computed(() => {
  let list = props.automations
  if (filterTrigger.value) list = list.filter((a) => a.trigger_kind === filterTrigger.value)
  if (filterAction.value) list = list.filter((a) => a.action_kind === filterAction.value)
  return list
})
</script>

<style lang="scss" scoped>
.automation-list-panel {
  &__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: $space-3;
    margin-bottom: $space-3;
    flex-wrap: wrap;
  }

  &__title {
    font-size: $font-size-base;
    font-weight: $font-weight-semibold;
    color: var(--p-text-color);
  }

  &__filter {
    min-width: 160px;
  }

  &__col-name {
    font-weight: $font-weight-medium;
  }

  &__name-btn {
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    color: var(--p-primary-color);
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    text-align: left;

    &:hover {
      text-decoration: underline;
    }
  }

  &__table {
    :deep(th) {
      background-color: var(--p-surface-50);
    }
  }

  :global(.app-dark) &__table {
    :deep(th) {
      background-color: var(--p-surface-900);
    }
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-3;
    padding: $space-8 $space-4;
    text-align: center;
  }

  &__empty-icon {
    font-size: $font-size-icon-xl; // 2.5rem
    color: var(--p-surface-400);
  }

  &__action-icon {
    font-size: $font-size-sm; // snap from 0.85rem (13.6px→14px)
  }

  &__empty-title {
    font-size: $font-size-base;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    margin: 0;
  }

  &__empty-sub {
    font-size: $font-size-sm;
    color: var(--p-text-muted-color);
    margin: 0;
  }
}
</style>
