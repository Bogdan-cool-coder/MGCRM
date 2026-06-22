<template>
  <div class="contact-deals-tab">
    <!-- TabHead -->
    <div class="contact-deals-tab__head">
      <span class="contact-deals-tab__head-title">{{ t('contact.page.tabs.deals') }}</span>
      <Button
        icon="pi pi-plus"
        :label="t('crm.contact.sections.dealsParticipation')"
        size="small"
        severity="secondary"
        outlined
        disabled
        :title="t('crm.contact.deals.addComingSoon')"
      />
      <!-- TODO B-3: кнопка «Добавить в сделку» — требует POST /api/deals/{id}/contacts -->
    </div>

    <!-- Loading -->
    <div v-if="loading" class="contact-deals-tab__skeleton">
      <Skeleton height="44px" class="mb-2" />
      <Skeleton height="44px" class="mb-2" />
      <Skeleton height="44px" />
    </div>

    <!-- Empty state -->
    <div v-else-if="deals.length === 0" class="contact-deals-tab__empty">
      <i class="pi pi-briefcase contact-deals-tab__empty-icon" />
      <p class="contact-deals-tab__empty-text">{{ t('crm.contact.sections.dealsEmpty') }}</p>
    </div>

    <!-- Full DataTable -->
    <DataTable
      v-else
      :value="deals"
      size="small"
      class="contact-deals-tab__table"
    >
      <Column :header="t('sales.deal.list.columns.title')">
        <template #body="{ data }">
          <RouterLink :to="`/deals/${data.id}`" class="contact-deals-tab__deal-link">
            {{ data.title || `#${data.id}` }}
          </RouterLink>
        </template>
      </Column>

      <Column :header="t('sales.deal.list.columns.stage')" style="width: 160px">
        <template #body="{ data }">
          <Tag
            v-if="data.stage?.name"
            :value="data.stage.name"
            severity="secondary"
            size="small"
            :style="data.stage?.color ? { background: data.stage.color + '22', color: data.stage.color } : {}"
          />
          <span v-else>—</span>
        </template>
      </Column>

      <Column :header="t('sales.deal.list.columns.amount')" style="width: 140px">
        <template #body="{ data }">
          <span class="contact-deals-tab__amount">{{ formatKopecks(data.amount, data.currency) }}</span>
        </template>
      </Column>

      <Column :header="t('sales.deal.list.columns.owner')" style="width: 140px">
        <template #body="{ data }">
          <span class="contact-deals-tab__owner">{{ data.owner?.name ?? '—' }}</span>
        </template>
      </Column>

      <Column :header="t('common.createdAt')" style="width: 110px">
        <template #body="{ data }">
          <span class="contact-deals-tab__date">{{ formatDate(data.created_at) }}</span>
        </template>
      </Column>
    </DataTable>

    <!-- Load more -->
    <div v-if="hasMore" class="contact-deals-tab__load-more">
      <Button
        icon="pi pi-refresh"
        :label="t('common.loadMore')"
        severity="secondary"
        outlined
        size="small"
        :loading="loadingMore"
        @click="emit('loadMore')"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Skeleton from 'primevue/skeleton'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import type { DealDto } from '@/entities/sales'

defineProps<{
  deals: DealDto[]
  loading?: boolean
  loadingMore?: boolean
  hasMore?: boolean
}>()

const emit = defineEmits<{
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
.contact-deals-tab {
  display: flex;
  flex-direction: column;
}

// ── TabHead ──────────────────────────────────────────────────────────────────

.contact-deals-tab__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-3 $space-4;
  border-bottom: 1px solid var(--p-surface-200);

  .app-dark & {
    border-bottom-color: var(--p-surface-600);
  }
}

.contact-deals-tab__head-title {
  font-size: $font-size-xs;
  font-weight: $font-weight-bold;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: $surface-500;
}

// ── States ───────────────────────────────────────────────────────────────────

.contact-deals-tab__skeleton {
  padding: $space-4;
  display: flex;
  flex-direction: column;
}

.contact-deals-tab__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
  text-align: center;
}

.contact-deals-tab__empty-icon {
  font-size: $font-size-icon-xl;
  color: $surface-300;
}

.contact-deals-tab__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

// ── Table ────────────────────────────────────────────────────────────────────

.contact-deals-tab__table {
  border-top: none;
}

.contact-deals-tab__deal-link {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: var(--p-primary-color);
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }
}

.contact-deals-tab__amount {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $primary-900;

  .app-dark & {
    color: var(--p-primary-300);
  }
}

.contact-deals-tab__owner {
  font-size: $font-size-sm;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-300);
  }
}

.contact-deals-tab__date {
  font-size: $font-size-sm;
  color: $surface-500;
}

// ── Load more ────────────────────────────────────────────────────────────────

.contact-deals-tab__load-more {
  display: flex;
  justify-content: center;
  padding: $space-3;
}
</style>
