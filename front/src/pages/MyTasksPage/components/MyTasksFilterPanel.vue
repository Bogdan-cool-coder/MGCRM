<template>
  <div class="tasks-filter-panel">
    <div class="tasks-filter-panel__row">
      <!-- Kind -->
      <Select
        v-model="model.kind"
        :options="kindOptions"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="t('activity.myTasksPage.filters.kind')"
        class="tasks-filter-panel__select"
      />

      <!-- Status -->
      <Select
        v-model="model.status"
        :options="statusOptions"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="t('activity.myTasksPage.filters.status')"
        class="tasks-filter-panel__select"
      />

      <!-- Priority -->
      <Select
        v-model="model.priority"
        :options="priorityOptions"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="t('activity.myTasksPage.filters.priority')"
        class="tasks-filter-panel__select"
      />

      <!-- Due from -->
      <DatePicker
        v-model="model.due_from"
        show-icon
        date-format="dd.mm.yy"
        :placeholder="t('activity.myTasksPage.filters.dueFrom')"
        show-clear
        class="tasks-filter-panel__date"
      />

      <!-- Due to -->
      <DatePicker
        v-model="model.due_to"
        show-icon
        date-format="dd.mm.yy"
        :placeholder="t('activity.myTasksPage.filters.dueTo')"
        show-clear
        class="tasks-filter-panel__date"
      />

      <!-- Search -->
      <IconField class="tasks-filter-panel__search">
        <InputIcon class="pi pi-search" />
        <InputText
          v-model="model.q"
          :placeholder="t('activity.myTasksPage.filters.search')"
        />
      </IconField>

      <!-- Reset -->
      <Button
        icon="pi pi-refresh"
        :label="t('activity.myTasksPage.filters.reset')"
        severity="secondary"
        text
        @click="$emit('reset')"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import InputText from 'primevue/inputtext'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import Button from 'primevue/button'
import type { TaskFilters } from '../composables/useMyTasks'

const props = defineProps<{
  modelValue: TaskFilters
}>()

const emit = defineEmits<{
  'update:modelValue': [v: TaskFilters]
  'reset': []
}>()

const { t } = useI18n()

const model = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const kindOptions = computed(() => [
  { value: 'call', label: t('activity.kinds.call') },
  { value: 'meeting', label: t('activity.kinds.meeting') },
  { value: 'task', label: t('activity.kinds.task') },
  { value: 'note', label: t('activity.kinds.note') },
])

const statusOptions = computed(() => [
  { value: 'new', label: t('activity.statuses.new') },
  { value: 'in_progress', label: t('activity.statuses.in_progress') },
  { value: 'done', label: t('activity.statuses.done') },
  { value: 'rejected', label: t('activity.statuses.rejected') },
])

const priorityOptions = computed(() => [
  { value: 'low', label: t('activity.priorities.low') },
  { value: 'normal', label: t('activity.priorities.normal') },
  { value: 'high', label: t('activity.priorities.high') },
  { value: 'critical', label: t('activity.priorities.critical') },
])
</script>

<style lang="scss" scoped>
.tasks-filter-panel {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  padding: $space-3;

  :global(.app-dark) & {
    border-color: var(--p-surface-700);
  }

  &__row {
    display: flex;
    align-items: center;
    gap: $space-2;
    flex-wrap: wrap;
  }

  &__select {
    min-width: 140px;
  }

  &__date {
    min-width: 150px;
  }

  &__search {
    flex: 1;
    min-width: 180px;
  }
}
</style>
