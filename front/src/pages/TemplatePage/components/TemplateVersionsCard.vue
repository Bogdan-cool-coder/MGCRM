<template>
  <Card class="template-versions-card">
    <template #title>{{ t('templates.card.versions.title') }}</template>
    <template #content>
      <div v-if="loading">
        <Skeleton height="32px" class="mb-1" v-for="i in 3" :key="i" />
      </div>

      <div v-else-if="versions.length === 0" class="text-secondary">
        {{ t('templates.card.versions.empty') }}
      </div>

      <div v-else class="d-flex flex-column gap-2">
        <div
          v-for="v in versions"
          :key="v.id"
          class="template-versions-card__row"
        >
          <span class="fw-medium">v{{ v.version_number }}</span>
          <span class="text-secondary ms-2">{{ formatDate(v.created_at) }}</span>
          <span class="text-secondary ms-2">{{ v.created_by_name ?? '—' }}</span>
          <Tag
            :severity="aiSeverity(v.ai_check_status)"
            :value="t(`templates.card.aiCheck.statuses.${v.ai_check_status}`, v.ai_check_status)"
            class="ms-auto template-versions-card__ai-tag"
          />
        </div>
      </div>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Tag from 'primevue/tag'
import Skeleton from 'primevue/skeleton'
import type { TemplateVersionDto, AiCheckStatus } from '@/entities/template'

type TagSeverity = 'secondary' | 'info' | 'success' | 'warn' | 'danger' | 'contrast'

defineProps<{
  versions: TemplateVersionDto[]
  loading: boolean
}>()

const { t } = useI18n()

function aiSeverity(status: AiCheckStatus): TagSeverity {
  const map: Record<AiCheckStatus, TagSeverity> = {
    pending: 'warn',
    checking: 'info',
    checked: 'success',
    failed: 'danger',
  }
  return map[status] ?? 'secondary'
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' })
}
</script>

<style lang="scss" scoped>
.template-versions-card {
  &__row {
    display: flex;
    align-items: center;
    font-size: $font-size-sm;
    padding: 0.35rem 0;
    border-bottom: 1px solid var(--p-surface-100);

    &:last-child {
      border-bottom: none;
    }
  }

  &__ai-tag {
    font-size: $font-size-3xs; // snap from 0.7rem (11.2px→10px)
  }
}
</style>
