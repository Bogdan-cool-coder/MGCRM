<template>
  <div class="entity-mini-timeline">
    <!-- Header -->
    <div class="entity-mini-timeline__header">
      <span class="entity-mini-timeline__title">
        <i class="pi pi-history entity-mini-timeline__title-icon" />
        {{ t('crm.entity.miniTimeline.title') }}
      </span>
      <button
        v-if="onGoToLog"
        type="button"
        class="entity-mini-timeline__link"
        @click="onGoToLog()"
      >
        {{ t('crm.entity.miniTimeline.goToLog') }}
        <i class="pi pi-arrow-right entity-mini-timeline__link-icon" />
      </button>
    </div>

    <!-- Loading -->
    <div v-if="log.loading.value && log.entries.value.length === 0" class="entity-mini-timeline__skeleton">
      <Skeleton v-for="i in (maxItems ?? 5)" :key="i" height="14px" class="entity-mini-timeline__skeleton-row" />
    </div>

    <!-- Empty -->
    <div v-else-if="!log.loading.value && visibleEntries.length === 0" class="entity-mini-timeline__empty">
      <p class="entity-mini-timeline__empty-text">{{ t('crm.entity.miniTimeline.empty') }}</p>
    </div>

    <!-- Entries -->
    <div v-else class="entity-mini-timeline__list">
      <div
        v-for="entry in visibleEntries"
        :key="entry.id"
        class="entity-mini-timeline__row"
      >
        <span class="entity-mini-timeline__dot" aria-hidden="true" />
        <div class="entity-mini-timeline__content">
          <span class="entity-mini-timeline__actor">
            {{ entry.user?.full_name ?? t('crm.log.system') }}
          </span>
          <span class="entity-mini-timeline__event">{{ eventLabel(entry.event_type) }}</span>
          <span v-if="entry.description" class="entity-mini-timeline__desc">
            {{ truncateDesc(entry.description) }}
          </span>
        </div>
        <span class="entity-mini-timeline__time">{{ relativeDate(entry.created_at) }}</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Skeleton from 'primevue/skeleton'
import { useEntityLogFormat } from './composables/useEntityLogFormat'
import type { UseEntityLogReturn } from '@/composables/crm/useEntityLog'

const props = withDefaults(
  defineProps<{
    log: UseEntityLogReturn
    maxItems?: number
    onGoToLog?: () => void
  }>(),
  {
    maxItems: 5,
    onGoToLog: undefined,
  },
)

const { t } = useI18n()
const { eventLabel, relativeDate } = useEntityLogFormat()

const visibleEntries = computed(() => props.log.entries.value.slice(0, props.maxItems))

function truncateDesc(desc: string): string {
  return desc.length > 60 ? desc.slice(0, 60) + '…' : desc
}
</script>

<style lang="scss" scoped>
.entity-mini-timeline {
  display: flex;
  flex-direction: column;
  gap: 0;
}

.entity-mini-timeline__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: $space-2;
}

.entity-mini-timeline__title {
  display: flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.entity-mini-timeline__title-icon {
  font-size: 12px;
  color: $surface-400;
}

.entity-mini-timeline__link {
  display: flex;
  align-items: center;
  gap: 4px;
  background: transparent;
  border: none;
  cursor: pointer;
  font-size: $font-size-xs;
  color: var(--p-primary-color);
  font-weight: $font-weight-medium;
  padding: 0;
  transition: opacity var(--app-transition-fast);

  &:hover {
    opacity: 0.75;
  }
}

.entity-mini-timeline__link-icon {
  font-size: 9px;
}

.entity-mini-timeline__skeleton {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.entity-mini-timeline__skeleton-row {
  width: 100%;
}

.entity-mini-timeline__empty {
  padding: $space-3 0;
}

.entity-mini-timeline__empty-text {
  font-size: $font-size-sm;
  color: $surface-400;
  margin: 0;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.entity-mini-timeline__list {
  display: flex;
  flex-direction: column;
}

.entity-mini-timeline__row {
  display: flex;
  align-items: baseline;
  gap: $space-2;
  padding: $space-1 $space-2;
  border-radius: $radius-sm;
  transition: background var(--app-transition-fast);

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-800);
    }
  }
}

.entity-mini-timeline__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--p-primary-color);
  flex-shrink: 0;
  margin-top: 5px;
}

.entity-mini-timeline__content {
  flex: 1;
  min-width: 0;
  font-size: 12px;
  color: var(--p-text-color);
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
  align-items: baseline;
  line-height: 1.4;
}

.entity-mini-timeline__actor {
  font-weight: $font-weight-semibold;
  white-space: nowrap;
}

.entity-mini-timeline__event {
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.entity-mini-timeline__desc {
  color: $surface-500;
  font-style: italic;
  font-size: 11px;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.entity-mini-timeline__time {
  font-size: 11px;
  color: $surface-400;
  white-space: nowrap;
  flex-shrink: 0;

  .app-dark & {
    color: var(--p-surface-500);
  }
}
</style>
