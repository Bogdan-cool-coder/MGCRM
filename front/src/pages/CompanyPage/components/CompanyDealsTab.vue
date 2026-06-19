<template>
  <div class="company-deals-tab">
    <!-- Loading skeleton -->
    <div v-if="loading" class="company-deals-tab__skeleton">
      <Skeleton height="48px" class="mb-2" />
      <Skeleton height="48px" class="mb-2" />
      <Skeleton height="48px" />
    </div>

    <!-- Empty state -->
    <div v-else-if="deals.length === 0" class="company-deals-tab__empty">
      <i class="pi pi-briefcase company-deals-tab__empty-icon" />
      <p class="company-deals-tab__empty-title">{{ t('company.page.deals.empty') }}</p>
      <Button
        icon="pi pi-plus"
        :label="t('company.page.deals.createDeal')"
        severity="secondary"
        outlined
        @click="$emit('createDeal')"
      />
    </div>

    <!-- Deals table -->
    <DataTable
      v-else
      :value="deals"
      striped-rows
      size="small"
      class="company-deals-tab__table"
    >
      <Column :header="t('sales.deal.list.columns.title')">
        <template #body="{ data }">
          <RouterLink :to="`/deals/${data.id}`" class="company-deals-tab__deal-link">
            {{ data.title }}
          </RouterLink>
        </template>
      </Column>

      <Column :header="t('sales.deal.list.columns.stage')" style="width: 180px">
        <template #body="{ data }">
          <Tag
            :value="data.stage.name"
            severity="secondary"
            size="small"
            :style="data.stage.color ? { background: data.stage.color + '22', color: data.stage.color } : {}"
          />
        </template>
      </Column>

      <Column :header="t('sales.deal.list.columns.amount')" style="width: 150px" class="text-right">
        <template #body="{ data }">
          <span class="company-deals-tab__amount">{{ formatKopecks(data.amount, data.currency) }}</span>
        </template>
      </Column>

      <Column :header="t('sales.deal.list.columns.status')" style="width: 110px">
        <template #body="{ data }">
          <Tag
            :value="t(`sales.deal.statuses.${data.status}`)"
            :severity="statusSeverity(data.status)"
            size="small"
          />
        </template>
      </Column>

      <Column :header="t('sales.deal.list.columns.owner')" style="width: 150px">
        <template #body="{ data }">
          <span class="company-deals-tab__owner">{{ data.owner?.name ?? '—' }}</span>
        </template>
      </Column>

      <Column style="width: 60px">
        <template #body="{ data }">
          <Button
            icon="pi pi-external-link"
            text
            severity="secondary"
            size="small"
            @click="$router.push(`/deals/${data.id}`)"
          />
        </template>
      </Column>
    </DataTable>

    <!-- Pagination -->
    <div v-if="hasMore" class="company-deals-tab__load-more">
      <Button
        icon="pi pi-refresh"
        :label="t('common.loadMore')"
        severity="secondary"
        outlined
        size="small"
        :loading="loading"
        @click="$emit('loadMore')"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import type { DealDto, DealStatus } from '@/entities/sales'

defineProps<{
  deals: DealDto[]
  loading: boolean
  hasMore: boolean
}>()

defineEmits<{
  createDeal: []
  loadMore: []
}>()

const { t } = useI18n()

function formatKopecks(kopecks: number, currency: string): string {
  const units = Math.round(kopecks / 100)
  try {
    return new Intl.NumberFormat('ru-RU', {
      style: 'currency',
      currency,
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(units)
  } catch {
    return `${units.toLocaleString('ru-RU')} ${currency}`
  }
}

function statusSeverity(status: DealStatus): 'success' | 'danger' | 'secondary' {
  if (status === 'won') return 'success'
  if (status === 'lost') return 'danger'
  return 'secondary'
}
</script>

<style lang="scss" scoped>
.company-deals-tab {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.company-deals-tab__skeleton {
  display: flex;
  flex-direction: column;
}

.company-deals-tab__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
  text-align: center;
}

.company-deals-tab__empty-icon {
  font-size: 3rem;
  color: $surface-300;
}

.company-deals-tab__empty-title {
  font-size: $font-size-base;
  color: $surface-500;
  margin: 0;
}

.company-deals-tab__table {
  border: 1px solid $surface-200;
  border-radius: $radius-md;
}

.company-deals-tab__deal-link {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: var(--p-primary-color);
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }
}

.company-deals-tab__amount {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.company-deals-tab__owner {
  font-size: $font-size-sm;
  color: $surface-600;
}

.company-deals-tab__load-more {
  display: flex;
  justify-content: center;
  padding-top: $space-2;
}
</style>
