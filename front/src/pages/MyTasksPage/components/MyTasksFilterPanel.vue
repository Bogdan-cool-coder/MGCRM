<template>
  <div class="tasks-filter-panel">
    <!-- Search row -->
    <div class="tasks-filter-panel__search-row">
      <div class="tasks-filter-panel__search-wrap">
        <i class="pi pi-search tasks-filter-panel__search-icon" />
        <input
          v-model="localFilters.q"
          class="tasks-filter-panel__search-input"
          :placeholder="t('tasks.filter.searchPlaceholder')"
        />
      </div>
      <button type="button" class="tasks-filter-panel__close-btn" @click="emit('close')">
        <i class="pi pi-times" />
      </button>
    </div>

    <!-- Preset pills -->
    <div class="tasks-filter-panel__presets">
      <button
        v-for="preset in FILTER_PRESETS"
        :key="preset.value"
        type="button"
        class="tasks-filter-panel__preset-pill"
        :class="[
          `tasks-filter-panel__preset-pill--${preset.severity}`,
          { 'tasks-filter-panel__preset-pill--active': localFilters.activePresets?.includes(preset.value) },
        ]"
        @click="togglePreset(preset.value)"
      >
        <i v-if="localFilters.activePresets?.includes(preset.value)" class="pi pi-check tasks-filter-panel__preset-check" />
        {{ t(`tasks.filter.presets.${preset.value}`) }}
      </button>
    </div>

    <!-- Field grid -->
    <div class="tasks-filter-panel__grid">
      <!-- Kind -->
      <div class="tasks-filter-panel__field">
        <label class="tasks-filter-panel__label">{{ t('tasks.filter.fields.kind') }}</label>
        <Select
          v-model="localFilters.kind"
          :options="kindOptions"
          option-label="label"
          option-value="value"
          show-clear
          class="tasks-filter-panel__control"
        />
      </div>

      <!-- Status -->
      <div class="tasks-filter-panel__field">
        <label class="tasks-filter-panel__label">{{ t('tasks.filter.fields.status') }}</label>
        <Select
          v-model="localFilters.status"
          :options="statusOptions"
          option-label="label"
          option-value="value"
          show-clear
          class="tasks-filter-panel__control"
        />
      </div>

      <!-- Priority -->
      <div class="tasks-filter-panel__field">
        <label class="tasks-filter-panel__label">{{ t('tasks.filter.fields.priority') }}</label>
        <Select
          v-model="localFilters.priority"
          :options="priorityOptions"
          option-label="label"
          option-value="value"
          show-clear
          class="tasks-filter-panel__control"
        />
      </div>

      <!-- Responsible -->
      <div class="tasks-filter-panel__field">
        <label class="tasks-filter-panel__label">{{ t('tasks.filter.fields.responsible') }}</label>
        <Select
          v-model="localFilters.responsible_id"
          :options="users"
          option-label="full_name"
          option-value="id"
          show-clear
          filter
          :loading="usersLoading"
          class="tasks-filter-panel__control"
        />
      </div>

      <!-- Due from -->
      <div class="tasks-filter-panel__field">
        <label class="tasks-filter-panel__label">{{ t('tasks.filter.fields.dueFrom') }}</label>
        <DatePicker
          v-model="localFilters.due_from"
          date-format="dd.mm.yy"
          show-clear
          class="tasks-filter-panel__control"
        />
      </div>

      <!-- Due to -->
      <div class="tasks-filter-panel__field">
        <label class="tasks-filter-panel__label">{{ t('tasks.filter.fields.dueTo') }}</label>
        <DatePicker
          v-model="localFilters.due_to"
          date-format="dd.mm.yy"
          show-clear
          class="tasks-filter-panel__control"
        />
      </div>
    </div>

    <!-- Footer -->
    <div class="tasks-filter-panel__footer">
      <button type="button" class="tasks-filter-panel__reset-btn" @click="onReset">
        {{ t('tasks.filter.reset') }}
      </button>
      <Button
        icon="pi pi-check"
        :label="t('tasks.filter.apply')"
        severity="primary"
        size="small"
        @click="onApply"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Button from 'primevue/button'
import { useUsersCache } from '@/composables/crm/useUsersCache'
import type { TaskFilters } from '../composables/useMyTasks'

interface LocalFilters extends TaskFilters {
  activePresets: string[]
  responsible_id: number | null
}

const props = defineProps<{
  modelValue: TaskFilters
}>()

const emit = defineEmits<{
  'update:modelValue': [v: TaskFilters]
  'reset': []
  'close': []
}>()

const { t } = useI18n()
const { users, loading: usersLoading, load: loadUsers } = useUsersCache()

const FILTER_PRESETS = [
  { value: 'my_tasks', severity: 'brand' },
  { value: 'today', severity: 'brand' },
  { value: 'overdue', severity: 'danger' },
  { value: 'no_due', severity: 'warning' },
  { value: 'completed', severity: 'success' },
  { value: 'all', severity: 'brand' },
]

const localFilters = ref<LocalFilters>({
  ...props.modelValue,
  activePresets: [],
  responsible_id: null,
})

watch(() => props.modelValue, (val) => {
  localFilters.value = {
    ...val,
    activePresets: localFilters.value.activePresets,
    responsible_id: localFilters.value.responsible_id,
  }
}, { deep: true })

function togglePreset(preset: string) {
  const arr = localFilters.value.activePresets
  const idx = arr.indexOf(preset)
  if (idx >= 0) {
    localFilters.value.activePresets = arr.filter((p) => p !== preset)
  } else {
    localFilters.value.activePresets = [...arr, preset]
  }
}

function onReset() {
  localFilters.value = {
    kind: null,
    status: null,
    priority: null,
    due_from: null,
    due_to: null,
    q: '',
    activePresets: [],
    responsible_id: null,
  }
  emit('reset')
}

function onApply() {
  emit('update:modelValue', {
    kind: localFilters.value.kind,
    status: localFilters.value.status,
    priority: localFilters.value.priority,
    due_from: localFilters.value.due_from,
    due_to: localFilters.value.due_to,
    q: localFilters.value.q,
  })
}

const kindOptions = computed(() => [
  { value: 'call', label: t('activity.kinds.call') },
  { value: 'meeting', label: t('activity.kinds.meeting') },
  { value: 'task', label: t('activity.kinds.task') },
  { value: 'note', label: t('activity.kinds.note') },
  { value: 'follow_up', label: t('activity.kinds.follow_up') },
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

onMounted(() => {
  void loadUsers()
})
</script>

<style lang="scss" scoped>
.tasks-filter-panel {
  border-bottom: 1px solid $surface-200;
  background: $surface-50;
  padding: $space-4 $space-5;
  display: flex;
  flex-direction: column;
  gap: $space-3;

  .app-dark & {
    background: var(--p-surface-800);
    border-color: var(--p-surface-700);
  }
}

// ── Search row ────────────────────────────────────────────────────────────────

.tasks-filter-panel__search-row {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.tasks-filter-panel__search-wrap {
  display: flex;
  align-items: center;
  gap: $space-2;
  max-width: 460px;
  flex: 1;
  height: 38px;
  border: 1px solid $surface-300;
  border-radius: $radius-md;
  background: $surface-card;
  padding: 0 $space-3;
  transition: border-color var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-600);
    background: var(--p-surface-700);
  }

  &:focus-within {
    border-color: $primary-900;

    .app-dark & {
      border-color: $primary-300;
    }
  }
}

.tasks-filter-panel__search-icon {
  font-size: $font-size-sm;
  color: $surface-400;
  flex-shrink: 0;
}

.tasks-filter-panel__search-input {
  flex: 1;
  border: none;
  background: transparent;
  font-size: $font-size-sm;
  color: $surface-800;
  outline: none;

  .app-dark & {
    color: var(--p-surface-100);
  }

  &::placeholder {
    color: $surface-400;

    .app-dark & {
      color: var(--p-surface-500);
    }
  }
}

.tasks-filter-panel__close-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: none;
  background: transparent;
  color: $surface-500;
  cursor: pointer;
  border-radius: $radius-sm;
  transition: all var(--app-transition-fast);
  margin-left: auto;

  .app-dark & {
    color: var(--p-surface-400);
  }

  &:hover {
    color: $surface-800;
    background: $surface-100;

    .app-dark & {
      color: var(--p-surface-200);
      background: var(--p-surface-700);
    }
  }
}

// ── Preset pills ──────────────────────────────────────────────────────────────

.tasks-filter-panel__presets {
  display: flex;
  gap: $space-2;
  flex-wrap: wrap;
  align-items: center;
}

.tasks-filter-panel__preset-pill {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  padding: 6px $space-3;
  border: 1px solid $surface-300;
  border-radius: $radius-pill;
  background: $surface-card;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  cursor: pointer;
  transition: all var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-600);
    background: var(--p-surface-700);
    color: var(--p-surface-300);
  }

  &:hover {
    border-color: $primary-900;
    color: $primary-900;
  }

  &--active {
    &.tasks-filter-panel__preset-pill--brand {
      background: $primary-100;
      border-color: $primary-900;
      color: $primary-900;

      .app-dark & {
        // stylelint-disable-next-line scale-unlimited/declaration-strict-value
        background: rgba(23, 39, 71, 0.25);
        border-color: $primary-300;
        color: $primary-300;
      }
    }

    &.tasks-filter-panel__preset-pill--danger {
      background: $color-danger-bg;
      border-color: $color-danger;
      color: $color-danger-text;

      .app-dark & {
        background: var(--p-red-900);
        border-color: var(--p-red-500);
        color: var(--p-red-300);
      }
    }

    &.tasks-filter-panel__preset-pill--warning {
      background: $color-warning-bg;
      border-color: $color-warning;
      color: $color-warning-text;

      .app-dark & {
        background: var(--p-yellow-900);
        border-color: var(--p-yellow-500);
        color: var(--p-yellow-300);
      }
    }

    &.tasks-filter-panel__preset-pill--success {
      background: $task-tag-meeting-bg;
      border-color: $task-tag-meeting-text;
      color: $task-tag-meeting-text;

      .app-dark & {
        background: var(--p-green-900);
        border-color: var(--p-green-500);
        color: var(--p-green-300);
      }
    }
  }
}

.tasks-filter-panel__preset-check {
  font-size: $font-size-xs;
}

// ── Grid ──────────────────────────────────────────────────────────────────────

.tasks-filter-panel__grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px 18px;

  @media (max-width: 1024px) {
    grid-template-columns: repeat(2, 1fr);
  }
}

.tasks-filter-panel__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.tasks-filter-panel__label {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.tasks-filter-panel__control {
  height: 36px;
  width: 100%;
}

// ── Footer ────────────────────────────────────────────────────────────────────

.tasks-filter-panel__footer {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.tasks-filter-panel__reset-btn {
  display: inline-flex;
  align-items: center;
  height: 32px;
  padding: 0 $space-2;
  border: none;
  background: transparent;
  font-size: $font-size-sm;
  color: $surface-500;
  cursor: pointer;
  border-radius: $radius-sm;
  transition: color var(--app-transition-fast);

  .app-dark & {
    color: var(--p-surface-400);
  }

  &:hover {
    color: $surface-800;

    .app-dark & {
      color: var(--p-surface-200);
    }
  }
}
</style>
