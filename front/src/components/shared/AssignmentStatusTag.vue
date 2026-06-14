<template>
  <Tag
    :severity="config.severity"
    :icon="config.icon"
    :value="t(`onboarding.assignments.statuses.${status}`)"
    class="assignment-status-tag"
  />
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import type { AssignmentStatus } from '@/entities/assignment'

type TagSeverity = 'secondary' | 'info' | 'success' | 'warn' | 'danger' | 'contrast'

interface StatusConfig {
  severity: TagSeverity
  icon: string
}

const STATUS_CONFIG: Record<AssignmentStatus, StatusConfig> = {
  pending: { severity: 'secondary', icon: 'pi pi-clock' },
  in_progress: { severity: 'info', icon: 'pi pi-spinner pi-spin' },
  completed: { severity: 'success', icon: 'pi pi-check-circle' },
  overdue: { severity: 'danger', icon: 'pi pi-exclamation-triangle' },
  archived: { severity: 'secondary', icon: 'pi pi-box' },
}

const props = defineProps<{
  status: AssignmentStatus
}>()

const { t } = useI18n()

const config = computed<StatusConfig>(
  () => STATUS_CONFIG[props.status] ?? { severity: 'secondary', icon: 'pi pi-clock' },
)
</script>

<style lang="scss" scoped>
.assignment-status-tag {
  white-space: nowrap;
}
</style>
