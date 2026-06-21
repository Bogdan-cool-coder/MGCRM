<template>
  <span class="deal-health-chip" :class="chipClass">
    <i :class="['pi', chipIcon]" />
    {{ chipLabel }}
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { NextTaskDto } from '@/entities/sales'

const props = defineProps<{
  nextTask: NextTaskDto | null
}>()

const { t } = useI18n()

const chipClass = computed((): string => {
  if (!props.nextTask) return 'deal-health-chip--no-task'
  if (props.nextTask.is_overdue) return 'deal-health-chip--overdue'
  return ''
})

const chipIcon = computed((): string => {
  if (!props.nextTask) return 'pi-clock'
  if (props.nextTask.is_overdue) return 'pi-exclamation-triangle'
  return 'pi-check-square'
})

const chipLabel = computed((): string => {
  if (!props.nextTask) return t('sales.deal.page.health.noTask')
  const kind = kindLabel(props.nextTask.type)
  if (props.nextTask.is_overdue) {
    return t('sales.deal.page.health.overdue') + ': ' + kind
  }
  const date = props.nextTask.due_at ? formatDate(props.nextTask.due_at) : ''
  return t('sales.deal.page.health.nextTask', { kind, date })
})

function kindLabel(kind: string): string {
  const map: Record<string, string> = {
    task: t('sales.deal.composer.subtypes.task'),
    call: t('sales.deal.composer.subtypes.call'),
    meeting: t('sales.deal.composer.subtypes.meeting'),
    note: t('sales.deal.composer.note'),
    follow_up: t('sales.deal.composer.subtypes.follow_up'),
  }
  return map[kind] ?? kind
}

function formatDate(iso: string): string {
  const d = new Date(iso)
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })
}
</script>

<style lang="scss" scoped>
.deal-health-chip {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  padding: 2px 8px;
  border-radius: $radius-sm;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  background: rgba(255, 255, 255, 0.1); // brand header overlay — static decorative tint on navy panel
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  color: rgba(255, 255, 255, 0.8); // brand header overlay — static decorative tint on navy panel
  white-space: nowrap;
  overflow: hidden;
  max-width: 200px;
  text-overflow: ellipsis;

  i {
    font-size: $font-size-3xs;
    flex-shrink: 0;
  }

  &--overdue {
    color: var(--p-red-300);
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    background: rgba(239, 68, 68, 0.15); // danger tint on dark brand header — no token for this alpha
  }

  &--no-task {
    color: var(--p-yellow-300);
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    background: rgba(234, 179, 8, 0.12); // warning tint on dark brand header — no token for this alpha
  }
}
</style>
