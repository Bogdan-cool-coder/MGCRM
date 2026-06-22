<template>
  <div class="company-deals-tab">
    <!-- TabHead -->
    <div class="company-deals-tab__head">
      <span class="company-deals-tab__head-title">{{ t('company.page.tabs.deals') }}</span>
      <Button
        icon="pi pi-plus"
        :label="t('company.page.deals.createDeal')"
        size="small"
        @click="$emit('createDeal')"
      />
    </div>

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

    <!-- Deals table — §6 колонки: Название · Этап · Сумма · Ответственный · Создана -->
    <DataTable
      v-else
      :value="deals"
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

      <Column :header="t('sales.deal.list.columns.amount')" style="width: 150px">
        <template #body="{ data }">
          <span class="company-deals-tab__amount">{{ formatKopecks(data.amount, data.currency) }}</span>
        </template>
      </Column>

      <Column :header="t('sales.deal.list.columns.owner')" style="width: 150px">
        <template #body="{ data }">
          <span class="company-deals-tab__owner">{{ data.owner?.name ?? '—' }}</span>
        </template>
      </Column>

      <Column :header="t('common.createdAt')" style="width: 120px">
        <template #body="{ data }">
          <span class="company-deals-tab__date">{{ formatDate(data.created_at) }}</span>
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
import type { DealDto } from '@/entities/sales'

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

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleDateString('ru-RU', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    })
  } catch {
    return iso
  }
}
</script>

<style lang="scss" scoped>
.company-deals-tab {
  display: flex;
  flex-direction: column;
}

// ── TabHead ──────────────────────────────────────────────────────────────────

.company-deals-tab__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-3 $space-4;
  border-bottom: 1px solid var(--p-surface-200);

  .app-dark & {
    border-bottom-color: var(--p-surface-600);
  }
}

.company-deals-tab__head-title {
  font-size: $font-size-xs;
  font-weight: $font-weight-bold;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: $surface-500;
}

// ── States ───────────────────────────────────────────────────────────────────

.company-deals-tab__skeleton {
  display: flex;
  flex-direction: column;
  padding: $space-4;
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
  font-size: $font-size-icon-2xl;
  color: $surface-300;
}

.company-deals-tab__empty-title {
  font-size: $font-size-base;
  color: $surface-500;
  margin: 0;
}

// ── Table ────────────────────────────────────────────────────────────────────

.company-deals-tab__table {
  border: none;
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
  font-weight: $font-weight-semibold;
  color: $primary-900;

  .app-dark & {
    color: var(--p-primary-300);
  }
}

.company-deals-tab__owner {
  font-size: $font-size-sm;
  color: $surface-600;
}

.company-deals-tab__date {
  font-size: $font-size-sm;
  color: $surface-500;
}

.company-deals-tab__load-more {
  display: flex;
  justify-content: center;
  padding: $space-3;
}
</style>
