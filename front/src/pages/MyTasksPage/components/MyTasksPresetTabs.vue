<template>
  <Tabs v-model:value="model" class="my-tasks-preset-tabs">
    <TabList>
      <Tab
        v-for="preset in presets"
        :key="preset.value"
        :value="preset.value"
        class="my-tasks-preset-tabs__tab"
      >
        <span class="my-tasks-preset-tabs__label">{{ preset.label }}</span>
        <span
          v-if="getCount(preset.value) > 0"
          class="my-tasks-preset-tabs__badge"
        >{{ getCount(preset.value) }}</span>
      </Tab>
    </TabList>
  </Tabs>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
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

const model = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v as TaskPreset),
})

const presets = computed(() => [
  { value: 'my_tasks' as TaskPreset, label: t('activity.presets.myTasks') },
  { value: 'today' as TaskPreset, label: t('activity.presets.today') },
  { value: 'overdue' as TaskPreset, label: t('activity.presets.overdue') },
  { value: 'all' as TaskPreset, label: t('activity.presets.all') },
])

function getCount(preset: TaskPreset): number {
  if (!props.counts) return 0
  const map: Partial<Record<TaskPreset, number>> = {
    my_tasks: props.counts.my_tasks,
    today: props.counts.today,
    overdue: props.counts.overdue,
  }
  return map[preset] ?? 0
}
</script>

<style lang="scss" scoped>
.my-tasks-preset-tabs {
  background: transparent;
  border: none;
  box-shadow: none;

  :deep(.p-tablist) {
    border-bottom: 2px solid $surface-200;

    :global(.app-dark) & {
      border-color: var(--p-surface-700);
    }
  }
}

.my-tasks-preset-tabs__tab {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.my-tasks-preset-tabs__badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  min-width: 20px;
  height: 20px;
  padding: 0 $space-1;
  border-radius: 10px;
  background: var(--p-surface-200);
  color: var(--p-surface-600);
  line-height: 1;

  :global(.app-dark) & {
    background: var(--p-surface-700);
    color: var(--p-surface-300);
  }
}
</style>
