<template>
  <div class="my-tasks-preset-tabs">
    <button
      v-for="preset in presets"
      :key="preset.value"
      type="button"
      class="my-tasks-preset-tabs__tab"
      :class="{ 'my-tasks-preset-tabs__tab--active': modelValue === preset.value }"
      @click="emit('update:modelValue', preset.value)"
    >
      <span class="my-tasks-preset-tabs__label">{{ preset.label }}</span>
      <span
        v-if="getCount(preset.value) > 0"
        class="my-tasks-preset-tabs__badge"
        :class="{ 'my-tasks-preset-tabs__badge--active': modelValue === preset.value }"
      >
        {{ getCount(preset.value) }}
      </span>
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ActivityCountsDto } from '@/entities/activity'
import type { TaskPreset } from '../composables/useMyTasks'

const props = defineProps<{
  modelValue: TaskPreset
  counts: ActivityCountsDto | null
}>()

const emit = defineEmits<{
  'update:modelValue': [v: TaskPreset]
}>()

const { t } = useI18n()

const presets = computed(() => [
  { value: 'my_tasks' as TaskPreset, label: t('activity.presets.myTasks') },
  { value: 'today' as TaskPreset, label: t('activity.presets.today') },
  { value: 'overdue' as TaskPreset, label: t('activity.presets.overdue') },
  { value: 'all' as TaskPreset, label: t('activity.presets.all') },
  // B30: Keep «Выполненные» tab — wired to counts.completed + GET /api/activities/presets/completed
  { value: 'completed' as TaskPreset, label: t('activity.presets.completed') },
])

function getCount(preset: TaskPreset): number {
  if (!props.counts) return 0
  const map: Partial<Record<TaskPreset, number>> = {
    my_tasks: props.counts.my_tasks,
    today: props.counts.today,
    overdue: props.counts.overdue,
    completed: props.counts.completed,
  }
  return map[preset] ?? 0
}
</script>

<style lang="scss" scoped>
.my-tasks-preset-tabs {
  display: flex;
  align-items: flex-end;
  gap: 0;
  border-bottom: 2px solid $surface-200;
  flex-shrink: 0;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

.my-tasks-preset-tabs__tab {
  display: flex;
  align-items: center;
  gap: $space-1;
  padding: $space-2 $space-3;
  border: none;
  background: transparent;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-500;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  transition: all var(--app-transition-fast);
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-400);
  }

  &:hover {
    color: $surface-700;

    .app-dark & {
      color: var(--p-surface-200);
    }
  }

  &--active {
    color: $primary-900;
    border-bottom-color: $primary-900;

    .app-dark & {
      color: $primary-300;
      border-bottom-color: $primary-300;
    }
  }
}

.my-tasks-preset-tabs__badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 $space-1;
  border-radius: $radius-pill;
  font-size: $font-size-2xs;
  font-weight: $font-weight-bold;
  line-height: 1;
  background: $surface-100;
  color: $surface-500;

  .app-dark & {
    background: var(--p-surface-700);
    color: var(--p-surface-400);
  }

  &--active {
    background: $primary-100;
    color: $primary-900;

    .app-dark & {
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      background: rgba(23, 39, 71, 0.25);
      color: $primary-300;
    }
  }
}
</style>
