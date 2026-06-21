<template>
  <div class="contact-deals-panel">
    <!-- Loading -->
    <div v-if="loading" class="contact-deals-panel__skeleton">
      <Skeleton height="40px" class="mb-2" />
      <Skeleton height="40px" class="mb-2" />
      <Skeleton height="40px" />
    </div>

    <!-- List -->
    <template v-else>
      <div
        v-for="deal in deals"
        :key="deal.id"
        class="contact-deals-panel__item"
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
          class="contact-deals-panel__stage-tag"
        />
        <Tag
          v-if="deal.status"
          :value="dealStatusLabel(deal.status)"
          :severity="dealStatusSeverity(deal.status)"
          size="small"
        />
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

type DealStatus = 'active' | 'won' | 'lost' | 'archived'

function dealStatusLabel(status: DealStatus | string): string {
  const map: Record<string, string> = {
    active: t('sales.deal.status.active'),
    won: t('sales.deal.status.won'),
    lost: t('sales.deal.status.lost'),
    archived: t('sales.deal.status.archived'),
  }
  return map[status] ?? status
}

function dealStatusSeverity(status: DealStatus | string): 'success' | 'danger' | 'secondary' | 'info' {
  const map: Record<string, 'success' | 'danger' | 'secondary' | 'info'> = {
    active: 'info',
    won: 'success',
    lost: 'danger',
    archived: 'secondary',
  }
  return map[status] ?? 'secondary'
}
</script>

<style lang="scss" scoped>
.contact-deals-panel {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.contact-deals-panel__skeleton {
  display: flex;
  flex-direction: column;
}

.contact-deals-panel__item {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 0;
  border-bottom: 1px solid var(--p-surface-100);
  flex-wrap: wrap;

  .app-dark & {
    border-bottom-color: var(--p-surface-800);
  }

  &:last-child {
    border-bottom: none;
  }
}

.contact-deals-panel__deal-name {
  flex: 1;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: var(--p-primary-color);
  text-decoration: none;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  &:hover {
    text-decoration: underline;
  }
}

.contact-deals-panel__stage-tag {
  flex-shrink: 0;
}

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
