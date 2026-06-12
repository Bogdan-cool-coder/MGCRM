<template>
  <div class="deal-history">
    <h3 class="deal-history__title">{{ t('sales.deal.page.history.sectionTitle') }}</h3>

    <!-- Empty -->
    <div v-if="history.length === 0" class="deal-history__empty">
      <i class="pi pi-clock deal-history__empty-icon" />
      <p class="deal-history__empty-title">{{ t('sales.deal.page.history.empty.title') }}</p>
    </div>

    <!-- Timeline -->
    <div v-else class="deal-history__timeline">
      <div
        v-for="entry in history"
        :key="entry.id"
        class="deal-history__entry"
      >
        <div class="deal-history__dot" />
        <div class="deal-history__content">
          <div class="deal-history__entry-header">
            <span class="deal-history__date">{{ formatDateTime(entry.created_at) }}</span>
            <span v-if="entry.user" class="deal-history__user">{{ entry.user.name }}</span>
          </div>
          <div class="deal-history__transition">
            <span v-if="entry.from_stage" class="deal-history__from">
              {{ t('sales.deal.page.history.from') }}: {{ entry.from_stage.name }}
            </span>
            <span v-else class="deal-history__from deal-history__from--created">
              {{ t('sales.deal.page.history.created') }}
            </span>
            <i class="pi pi-arrow-right deal-history__arrow" />
            <DealStageTag :stage="entry.to_stage" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import DealStageTag from './DealStageTag.vue'
import type { DealStageHistoryDto } from '@/entities/sales'

defineProps<{
  history: DealStageHistoryDto[]
}>()

const { t } = useI18n()

function formatDateTime(dateStr: string): string {
  const d = new Date(dateStr)
  return d.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>

<style lang="scss" scoped>
.deal-history {
  display: flex;
  flex-direction: column;
  gap: $space-3;
}

.deal-history__title {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  margin: 0;
}

.deal-history__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-6;
  text-align: center;
}

.deal-history__empty-icon {
  font-size: 2rem;
  color: $surface-400;
}

.deal-history__empty-title {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

.deal-history__timeline {
  display: flex;
  flex-direction: column;
  gap: 0;
  position: relative;
  padding-left: $space-6;

  &::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 8px;
    bottom: 8px;
    width: 2px;
    background: $surface-200;

    :global(.app-dark) & {
      background: var(--p-surface-700);
    }
  }
}

.deal-history__entry {
  position: relative;
  padding-bottom: $space-4;

  &:last-child {
    padding-bottom: 0;
  }
}

.deal-history__dot {
  position: absolute;
  left: calc(-1 * $space-6 + 4px);
  top: 4px;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: $primary-color;
  border: 2px solid $surface-card;
}

.deal-history__content {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.deal-history__entry-header {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.deal-history__date {
  font-size: $font-size-xs;
  color: $surface-400;
}

.deal-history__user {
  font-size: $font-size-xs;
  color: $surface-500;
  font-weight: $font-weight-medium;
}

.deal-history__transition {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
}

.deal-history__from {
  font-size: $font-size-sm;
  color: $surface-500;

  &--created {
    font-style: italic;
    color: $surface-400;
  }
}

.deal-history__arrow {
  font-size: $font-size-xs;
  color: $surface-400;
}
</style>
