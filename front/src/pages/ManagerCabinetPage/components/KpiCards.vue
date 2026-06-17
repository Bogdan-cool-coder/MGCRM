<template>
  <!-- Loading skeleton -->
  <template v-if="loading">
    <div class="row g-3">
      <div v-for="n in 4" :key="n" class="col-6 col-md-3">
        <Skeleton height="112px" border-radius="8px" />
      </div>
    </div>
  </template>

  <!-- Empty state -->
  <template v-else-if="!kpi">
    <div class="kpi-cards__empty">
      <i class="pi pi-chart-pie kpi-cards__empty-icon" />
      <p class="kpi-cards__empty-text">{{ t('managerCabinet.kpi.unavailable') }}</p>
    </div>
  </template>

  <!-- Cards -->
  <template v-else>
    <div class="row g-3">
      <!-- Card 1: МК% -->
      <div class="col-6 col-md-3">
        <Card class="kpi-card h-100">
          <template #content>
            <div class="kpi-card__header">
              <i class="pi pi-chart-pie kpi-card__icon" />
              <span class="kpi-card__label">{{ t('managerCabinet.kpi.scorePct') }}</span>
            </div>
            <div
              class="kpi-card__value"
              :style="kpi.personal.has_salary_plan ? { color: scoreColor } : undefined"
            >
              {{ kpi.personal.score_pct }}%
            </div>
            <div class="kpi-card__footer">
              <Tag
                v-if="kpi.personal.has_salary_plan"
                :severity="kpi.personal.score_badge"
                :value="scoreLabel"
                size="small"
              />
              <span v-else class="kpi-card__no-plan">{{ t('managerCabinet.kpi.noPlan') }}</span>
            </div>
          </template>
        </Card>
      </div>

      <!-- Card 2: Личные продажи -->
      <div class="col-6 col-md-3">
        <Card class="kpi-card h-100">
          <template #content>
            <div class="kpi-card__header">
              <i class="pi pi-money-bill kpi-card__icon" />
              <span class="kpi-card__label">{{ t('managerCabinet.kpi.personalSales') }}</span>
            </div>
            <div class="kpi-card__value kpi-card__value--md">
              {{ formatMoney(kpi.personal.income_fact_kopecks) }}
            </div>
            <div class="kpi-card__footer">
              <span v-if="kpi.personal.has_salary_plan" class="kpi-card__sub-text">
                {{ t('managerCabinet.kpi.plan') }}: {{ formatMoney(kpi.personal.income_plan_kopecks) }}
              </span>
              <span v-else class="kpi-card__no-plan">{{ t('managerCabinet.kpi.noPlan') }}</span>
            </div>
          </template>
        </Card>
      </div>

      <!-- Card 3: FTM -->
      <div class="col-6 col-md-3">
        <Card class="kpi-card h-100">
          <template #content>
            <div class="kpi-card__header">
              <i class="pi pi-users kpi-card__icon" />
              <span class="kpi-card__label">{{ t('managerCabinet.kpi.ftm') }}</span>
            </div>
            <div class="kpi-card__value">
              {{ kpi.personal.ftm_count_fact
                + (kpi.personal.ftm_count_plan != null ? '/' + kpi.personal.ftm_count_plan : '') }}
            </div>
            <div class="kpi-card__footer">
              <span v-if="kpi.personal.has_salary_plan" class="kpi-card__sub-text">
                {{ t('managerCabinet.kpi.ftmLabel') }}
              </span>
              <span v-else class="kpi-card__no-plan">{{ t('managerCabinet.kpi.noPlan') }}</span>
            </div>
          </template>
        </Card>
      </div>

      <!-- Card 4: Ранг -->
      <div class="col-6 col-md-3">
        <Card class="kpi-card h-100">
          <template #content>
            <div class="kpi-card__header">
              <i class="pi pi-trophy kpi-card__icon" />
              <span class="kpi-card__label">{{ t('managerCabinet.kpi.rank') }}</span>
            </div>
            <div class="kpi-card__value kpi-card__value--md">
              #{{ kpi.team.rank }} {{ t('managerCabinet.team.of') }} {{ kpi.team.size }}
            </div>
            <div class="kpi-card__footer">
              <span class="kpi-card__sub-text">
                {{ t('managerCabinet.team.avgLabel') }}: {{ kpi.team.avg_pct }}%
              </span>
            </div>
          </template>
        </Card>
      </div>
    </div>
  </template>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import type { KpiResponse } from '@/entities/managerCabinet'
import { formatMoney } from '@/utils/chartFormatters'

const props = defineProps<{
  kpi: KpiResponse | null
  loading: boolean
}>()

const { t } = useI18n()

const scoreColor = computed<string>(() => {
  if (!props.kpi) return ''
  const badge = props.kpi.personal.score_badge
  if (badge === 'success') return 'var(--p-green-500)'
  if (badge === 'warning') return 'var(--p-orange-500)'
  return 'var(--p-red-500)'
})

const scoreLabel = computed<string>(() => {
  if (!props.kpi) return ''
  const badge = props.kpi.personal.score_badge
  if (badge === 'success') return t('managerCabinet.kpi.excellent')
  if (badge === 'warning') return t('managerCabinet.kpi.good')
  return t('managerCabinet.kpi.needsWork')
})
</script>

<style lang="scss" scoped>
.kpi-card {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  height: 100%;

  :deep(.p-card-body) {
    padding: $space-4;
    height: 100%;
  }

  :deep(.p-card-content) {
    padding: 0;
    height: 100%;
    display: flex;
    flex-direction: column;
    gap: $space-2;
  }
}

.kpi-card__header {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.kpi-card__icon {
  font-size: 20px;
  color: $primary-color;
  flex-shrink: 0;
}

.kpi-card__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  line-height: $line-height-tight;
}

.kpi-card__value {
  font-size: 2rem;
  font-weight: $font-weight-bold;
  color: $surface-900;
  line-height: 1;
}

.kpi-card__value--md {
  font-size: 1.5rem;
}

.kpi-card__footer {
  margin-top: auto;
}

.kpi-card__sub-text {
  font-size: $font-size-sm;
  color: $surface-600;
}

.kpi-card__no-plan {
  font-size: $font-size-sm;
  color: $surface-400;
}

.kpi-cards__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: $space-8;
  gap: $space-3;
  min-height: 112px;
}

.kpi-cards__empty-icon {
  font-size: 2.5rem;
  color: $surface-400;
}

.kpi-cards__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}
</style>
