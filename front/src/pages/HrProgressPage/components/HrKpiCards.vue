<template>
  <div class="row g-4 mb-4">
    <div class="col-6 col-lg-3">
      <template v-if="loading">
        <Skeleton height="80px" />
      </template>
      <Card v-else class="hr-kpi-card">
        <template #content>
          <div class="hr-kpi-card__inner">
            <i class="pi pi-users hr-kpi-card__icon" />
            <div>
              <div class="hr-kpi-card__value">{{ summary?.total ?? 0 }}</div>
              <div class="hr-kpi-card__label">{{ t('onboarding.hrProgress.kpi.total') }}</div>
            </div>
          </div>
        </template>
      </Card>
    </div>
    <div class="col-6 col-lg-3">
      <template v-if="loading">
        <Skeleton height="80px" />
      </template>
      <Card v-else class="hr-kpi-card">
        <template #content>
          <div class="hr-kpi-card__inner">
            <i class="pi pi-check-circle hr-kpi-card__icon hr-kpi-card__icon--success" />
            <div>
              <div class="hr-kpi-card__value hr-kpi-card__value--success">{{ summary?.completed ?? 0 }}</div>
              <div class="hr-kpi-card__label">{{ t('onboarding.hrProgress.kpi.completed') }}</div>
            </div>
          </div>
        </template>
      </Card>
    </div>
    <div class="col-6 col-lg-3">
      <template v-if="loading">
        <Skeleton height="80px" />
      </template>
      <Card v-else class="hr-kpi-card">
        <template #content>
          <div class="hr-kpi-card__inner">
            <i class="pi pi-spinner hr-kpi-card__icon hr-kpi-card__icon--info" />
            <div>
              <div class="hr-kpi-card__value hr-kpi-card__value--info">{{ summary?.in_progress ?? 0 }}</div>
              <div class="hr-kpi-card__label">{{ t('onboarding.hrProgress.kpi.inProgress') }}</div>
            </div>
          </div>
        </template>
      </Card>
    </div>
    <div class="col-6 col-lg-3">
      <template v-if="loading">
        <Skeleton height="80px" />
      </template>
      <Card v-else class="hr-kpi-card">
        <template #content>
          <div class="hr-kpi-card__inner">
            <i class="pi pi-exclamation-triangle hr-kpi-card__icon hr-kpi-card__icon--danger" />
            <div>
              <div class="hr-kpi-card__value hr-kpi-card__value--danger">{{ summary?.overdue ?? 0 }}</div>
              <div class="hr-kpi-card__label">{{ t('onboarding.hrProgress.kpi.overdue') }}</div>
            </div>
          </div>
        </template>
      </Card>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Skeleton from 'primevue/skeleton'
import type { HrProgressSummary } from '@/api/onboardingAdmin'

defineProps<{
  summary: HrProgressSummary | null
  loading: boolean
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.hr-kpi-card {
  height: 100%;

  :deep(.p-card-body) {
    padding: $space-4;
  }

  &__inner {
    display: flex;
    align-items: center;
    gap: $space-3;
  }

  &__icon {
    font-size: 2rem;
    color: var(--p-surface-400);
    flex-shrink: 0;

    &--success { color: var(--p-green-500); }
    &--info    { color: var(--p-blue-500); }
    &--danger  { color: var(--p-red-500); }
  }

  &__value {
    font-size: 2rem;
    font-weight: $font-weight-bold;
    line-height: 1;
    color: var(--p-surface-700);

    &--success { color: var(--p-green-600); }
    &--info    { color: var(--p-blue-600); }
    &--danger  { color: var(--p-red-600); }
  }

  &__label {
    font-size: $font-size-sm;
    color: var(--p-surface-500);
    margin-top: $space-1;
  }
}
</style>
