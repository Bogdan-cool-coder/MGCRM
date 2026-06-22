<template>
  <div class="deal-tab-stats">
    <div class="row g-3">
      <!-- Days in deal -->
      <div class="col-6">
        <Card class="deal-tab-stats__card">
          <template #content>
            <div class="deal-tab-stats__value">{{ daysInDeal }}</div>
            <div class="deal-tab-stats__label">{{ t('sales.deal.stats.daysInDeal') }}</div>
          </template>
        </Card>
      </div>

      <!-- Days in stage -->
      <div class="col-6">
        <Card class="deal-tab-stats__card">
          <template #content>
            <div class="deal-tab-stats__value">{{ daysInStage }}</div>
            <div class="deal-tab-stats__label">{{ t('sales.deal.stats.daysInStage') }}</div>
          </template>
        </Card>
      </div>

      <!-- Activities total -->
      <div class="col-6">
        <Card class="deal-tab-stats__card">
          <template #content>
            <div class="deal-tab-stats__value">{{ activitiesCount }}</div>
            <div class="deal-tab-stats__label">{{ t('sales.deal.stats.activities') }}</div>
          </template>
        </Card>
      </div>

      <!-- Stage changes -->
      <div class="col-6">
        <Card class="deal-tab-stats__card">
          <template #content>
            <div class="deal-tab-stats__value">{{ stageChangesCount }}</div>
            <div class="deal-tab-stats__label">{{ t('sales.deal.stats.stageChanges') }}</div>
          </template>
        </Card>
      </div>

      <!-- Documents count -->
      <div class="col-6">
        <Card class="deal-tab-stats__card">
          <template #content>
            <div class="deal-tab-stats__value">{{ documentsCount }}</div>
            <div class="deal-tab-stats__label">{{ t('sales.deal.stats.documents') }}</div>
          </template>
        </Card>
      </div>

      <!-- Last activity -->
      <div class="col-6">
        <Card class="deal-tab-stats__card">
          <template #content>
            <div class="deal-tab-stats__value deal-tab-stats__value--sm">
              {{ lastActivityLabel }}
            </div>
            <div class="deal-tab-stats__label">{{ t('sales.deal.stats.lastActivity') }}</div>
          </template>
        </Card>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import type { DealDto, DealStageHistoryDto } from '@/entities/sales'
import type { ActivityDto } from '@/entities/activity'

const props = defineProps<{
  deal: DealDto
  history: DealStageHistoryDto[]
  activities: ActivityDto[]
  documentsCount: number
  /** daysInStage pre-computed by the parent (same logic as DealInfoHeader) */
  daysInStage: number
}>()

const { t } = useI18n()

const daysInDeal = computed((): number => {
  const diff = Date.now() - new Date(props.deal.created_at).getTime()
  return Math.max(0, Math.floor(diff / (1000 * 60 * 60 * 24)))
})

const activitiesCount = computed(() => props.activities.length)

const stageChangesCount = computed(() => props.history.length)

const lastActivityLabel = computed((): string => {
  const last = props.activities[0]
  if (!last) return '—'
  // Activities are expected sorted desc by due_at / created_at
  const dateRef = last.due_at ?? last.created_at
  const diffDays = Math.floor(
    (Date.now() - new Date(dateRef).getTime()) / (1000 * 60 * 60 * 24),
  )
  const kindLabel = t(`activity.kinds.${last.kind}`, last.kind)
  return `${kindLabel} · ${t('sales.deal.stats.daysAgo', { n: diffDays })}`
})
</script>

<style lang="scss" scoped>
.deal-tab-stats {
  padding: $space-3;

  &__card {
    text-align: center;
    height: 100%;
  }

  &__value {
    font-size: $font-size-icon-lg;
    font-weight: $font-weight-bold;
    color: var(--p-text-color);
    line-height: 1.1;
    margin-bottom: $space-1;

    &--sm {
      font-size: $font-size-base;
    }
  }

  &__label {
    font-size: $font-size-sm;
    color: $surface-500;
  }
}
</style>
