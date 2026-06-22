<template>
  <div class="contact-deals-panel">
    <!-- Loading -->
    <div v-if="loading" class="contact-deals-panel__skeleton">
      <Skeleton height="32px" class="mb-2" />
      <Skeleton height="32px" class="mb-2" />
      <Skeleton height="32px" />
    </div>

    <!-- 3-column mini-table (spec §4): Сделка · Этап (чип) · Сумма (right, 700, navy) -->
    <template v-else>
      <!-- No table header per spec §4 -->
      <div
        v-for="deal in deals"
        :key="deal.id"
        class="contact-deals-panel__row"
      >
        <RouterLink
          :to="`/deals/${deal.id}`"
          class="contact-deals-panel__deal-name"
        >
          {{ deal.title || `#${deal.id}` }}
        </RouterLink>
        <Tag
          v-if="deal.stage?.name"
          :value="deal.stage.name"
          severity="secondary"
          size="small"
          class="contact-deals-panel__stage"
          :style="deal.stage?.color ? { background: deal.stage.color + '22', color: deal.stage.color } : {}"
        />
        <span v-else class="contact-deals-panel__stage-empty">—</span>
        <span class="contact-deals-panel__amount">{{ formatKopecks(deal.amount, deal.currency) }}</span>
      </div>

      <!-- Empty -->
      <div v-if="deals.length === 0" class="contact-deals-panel__empty">
        <i class="pi pi-briefcase contact-deals-panel__empty-icon" />
        <p class="contact-deals-panel__empty-text">{{ t('crm.contact.sections.dealsEmpty') }}</p>
      </div>

      <!-- Load more -->
      <button
        v-if="hasMore"
        class="contact-deals-panel__load-more"
        :disabled="loadingMore"
        @click="emit('loadMore')"
      >
        <i v-if="loadingMore" class="pi pi-spin pi-spinner" />
        {{ t('common.loadMore') }}
      </button>
    </template>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Tag from 'primevue/tag'
import Skeleton from 'primevue/skeleton'
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
</script>

<style lang="scss" scoped>
.contact-deals-panel {
  display: flex;
  flex-direction: column;
}

.contact-deals-panel__skeleton {
  display: flex;
  flex-direction: column;
  padding: $space-2 0;
}

// ── 3-column mini-table row — spec §4 ─────────────────────────────────────────
// Columns: Сделка (flex:1) · Этап (shrink:0) · Сумма (text-right, navy, 700)

.contact-deals-panel__row {
  display: grid;
  grid-template-columns: 1fr auto auto;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  border-bottom: 1px solid var(--p-surface-100);
  transition: background var(--app-transition-fast);

  &:last-child {
    border-bottom: none;
  }

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-100);
    }
  }

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

.contact-deals-panel__deal-name {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: var(--p-primary-color);
  text-decoration: none;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  &:hover {
    text-decoration: underline;
  }
}

.contact-deals-panel__stage {
  flex-shrink: 0;
}

.contact-deals-panel__stage-empty {
  font-size: $font-size-sm;
  color: $surface-400;
  flex-shrink: 0;
}

// Amount: right-aligned, 700, navy — spec §4
.contact-deals-panel__amount {
  font-size: $font-size-sm;
  font-weight: $font-weight-bold;
  color: $primary-900;
  white-space: nowrap;
  text-align: right;
  flex-shrink: 0;

  .app-dark & {
    color: var(--p-primary-300);
  }
}

// ── Empty ─────────────────────────────────────────────────────────────────────

.contact-deals-panel__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-4;
  text-align: center;
}

.contact-deals-panel__empty-icon {
  font-size: $font-size-2xl;
  color: $surface-300;
}

.contact-deals-panel__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

// ── Load more ─────────────────────────────────────────────────────────────────

.contact-deals-panel__load-more {
  display: flex;
  align-items: center;
  gap: $space-2;
  justify-content: center;
  background: transparent;
  border: none;
  cursor: pointer;
  color: var(--p-primary-color);
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  padding: $space-2;
  transition: opacity var(--app-transition-fast);

  &:hover:not(:disabled) {
    opacity: 0.75;
  }

  &:disabled {
    cursor: not-allowed;
    opacity: 0.5;
  }
}
</style>
