<template>
  <Card class="doc-meta-card">
    <template #content>
      <dl class="doc-meta-card__list">
        <div class="doc-meta-card__item">
          <dt>{{ t('documents.card.meta.created') }}</dt>
          <dd>{{ formatDate(doc.created_at) }}</dd>
        </div>
        <div class="doc-meta-card__item">
          <dt>{{ t('documents.card.meta.author') }}</dt>
          <dd>{{ doc.author?.full_name ?? '—' }}</dd>
        </div>
        <div v-if="doc.template_version" class="doc-meta-card__item">
          <dt>{{ t('documents.card.meta.template') }}</dt>
          <dd>{{ templateVersionLabel(doc.template_version) }}</dd>
        </div>
        <div v-if="doc.total != null" class="doc-meta-card__item">
          <dt>{{ t('documents.card.meta.amount') }}</dt>
          <dd class="fw-medium">
            {{ formatMoney(doc.total, 'ru', doc.currency ?? 'KZT') }}
          </dd>
        </div>
        <div v-if="doc.discount_pct != null && Number(doc.discount_pct) > 0" class="doc-meta-card__item">
          <dt>{{ t('documents.card.meta.discount') }}</dt>
          <dd class="text-danger">
            {{ doc.discount_pct }}% = -{{ formatMoney(doc.discount_amount ?? 0) }}
          </dd>
        </div>
      </dl>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import { formatMoney } from '@/utils/chartFormatters'
import type { DocumentDto } from '@/entities/document'

defineProps<{ doc: DocumentDto }>()

const { t } = useI18n()

function templateVersionLabel(
  v: string | { id: number; code: string; version_number: number } | null,
): string {
  if (!v) return '—'
  if (typeof v === 'object') return `${v.code} v${v.version_number}`
  return v
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('ru-RU', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
  })
}
</script>

<style lang="scss" scoped>
.doc-meta-card {
  &__list {
    margin: 0;
  }

  &__item {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 0.35rem 0;
    border-bottom: 1px solid var(--p-surface-100);
    font-size: $font-size-sm;

    &:last-child {
      border-bottom: none;
    }

    dt {
      color: var(--p-text-muted-color);
      font-weight: normal;
      white-space: nowrap;
    }

    dd {
      margin: 0;
      text-align: right;
      color: var(--p-text-color);
    }
  }
}
</style>
