<template>
  <span class="document-status-tag">
    <Tag
      :severity="statusSeverity"
      :icon="statusIcon"
      :value="statusLabel"
      class="document-status-tag__chip"
    />
    <Tag
      v-if="archived"
      severity="secondary"
      icon="pi pi-box"
      :value="t('documents.statuses.archived')"
      class="document-status-tag__chip ms-1"
    />
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import type { ContractStatus } from '@/entities/document'

type TagSeverity = 'secondary' | 'info' | 'success' | 'warn' | 'danger' | 'contrast'

interface StatusConfig {
  severity: TagSeverity
  icon: string
}

const STATUS_CONFIG: Record<ContractStatus, StatusConfig> = {
  draft: { severity: 'secondary', icon: 'pi pi-file' },
  submitted: { severity: 'info', icon: 'pi pi-send' },
  in_review: { severity: 'warn', icon: 'pi pi-clock' },
  needs_rework: { severity: 'warn', icon: 'pi pi-undo' },
  approved: { severity: 'success', icon: 'pi pi-check-circle' },
  rejected: { severity: 'danger', icon: 'pi pi-times-circle' },
  signed: { severity: 'success', icon: 'pi pi-pen-to-square' },
  uploaded: { severity: 'info', icon: 'pi pi-cloud-upload' },
  archived: { severity: 'secondary', icon: 'pi pi-box' },
}

const props = defineProps<{
  status: ContractStatus
  archived?: boolean
}>()

const { t } = useI18n()

const statusSeverity = computed<TagSeverity>(() => STATUS_CONFIG[props.status]?.severity ?? 'secondary')
const statusIcon = computed(() => STATUS_CONFIG[props.status]?.icon ?? 'pi pi-file')
const statusLabel = computed(() => t(`documents.statuses.${props.status}`, props.status))
</script>

<style lang="scss" scoped>
.document-status-tag {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.25rem;

  &__chip {
    white-space: nowrap;
  }
}
</style>
