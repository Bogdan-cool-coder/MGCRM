<template>
  <div class="entity-log-tab">
    <!-- Compact metrics bar -->
    <div v-if="visibleMetrics.length > 0" class="entity-log-tab__metrics">
      <div
        v-for="m in visibleMetrics"
        :key="m.key"
        class="entity-log-tab__metric"
      >
        <span class="entity-log-tab__metric-value">{{ m.metricValue }}</span>
        <span class="entity-log-tab__metric-label">{{ m.label }}</span>
      </div>
    </div>

    <!-- Divider -->
    <div v-if="visibleMetrics.length > 0" class="entity-log-tab__divider" />

    <!-- Log header -->
    <div class="entity-log-tab__header">
      <span class="entity-log-tab__header-title">
        {{ t('crm.log.title') }}
        <span v-if="log.total.value > 0" class="entity-log-tab__count">
          {{ log.total.value }}
        </span>
      </span>
    </div>

    <!-- Loading skeleton -->
    <div v-if="log.loading.value && log.entries.value.length === 0" class="entity-log-tab__skeleton">
      <Skeleton height="44px" class="mb-2" />
      <Skeleton height="44px" class="mb-2" />
      <Skeleton height="44px" class="mb-2" />
      <Skeleton height="44px" />
    </div>

    <!-- Empty state -->
    <div v-else-if="!log.loading.value && log.entries.value.length === 0" class="entity-log-tab__empty">
      <i class="pi pi-history entity-log-tab__empty-icon" />
      <p class="entity-log-tab__empty-title">{{ t('crm.log.empty.title') }}</p>
      <p class="entity-log-tab__empty-hint">{{ t('crm.log.empty.hint') }}</p>
    </div>

    <!-- Log entries list -->
    <div v-else class="entity-log-tab__list">
      <div
        v-for="entry in log.entries.value"
        :key="entry.id"
        class="entity-log-tab__entry"
      >
        <!-- Icon badge -->
        <div class="entity-log-tab__icon-wrap">
          <i :class="['pi', eventIcon(entry.event_type), 'entity-log-tab__icon']" />
        </div>

        <!-- Content -->
        <div class="entity-log-tab__content">
          <div class="entity-log-tab__row">
            <span class="entity-log-tab__actor">
              {{ entry.user?.full_name ?? t('crm.log.system') }}
            </span>
            <span class="entity-log-tab__event-label">
              {{ eventLabel(entry.event_type) }}
            </span>
            <!-- Stage change: old → new -->
            <template v-if="entry.event_type === 'stage_changed' && entry.old_value && entry.new_value">
              <span class="entity-log-tab__stage-old">{{ entry.old_value }}</span>
              <i class="pi pi-arrow-right entity-log-tab__arrow" />
              <span class="entity-log-tab__stage-new">{{ entry.new_value }}</span>
            </template>
            <!-- Field change -->
            <template v-else-if="entry.event_type === 'updated' && entry.description">
              <span class="entity-log-tab__description">{{ entry.description }}</span>
            </template>
          </div>
          <!-- Description for non-field events -->
          <div
            v-if="entry.description && entry.event_type !== 'updated' && entry.event_type !== 'stage_changed'"
            class="entity-log-tab__desc"
          >
            {{ entry.description }}
          </div>
          <div class="entity-log-tab__time">{{ formatDate(entry.created_at) }}</div>
        </div>
      </div>

      <!-- Load more -->
      <div v-if="log.hasMore.value" class="entity-log-tab__load-more">
        <Button
          :label="t('crm.log.loadMore')"
          severity="secondary"
          outlined
          size="small"
          :loading="log.loadingMore.value"
          @click="log.loadMore()"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import { useEntityLogFormat } from './composables/useEntityLogFormat'
import type { UseEntityLogReturn } from '@/composables/crm/useEntityLog'

// ── Props ─────────────────────────────────────────────────────────────────────

export interface LogMetric {
  key: string
  label: string
  /** Renamed from `value` to avoid Vue 3 template ref-unwrapping confusion on property named `value` */
  metricValue: string | number
}

const props = defineProps<{
  log: UseEntityLogReturn
  /** Optional compact stats row shown above the log */
  metrics?: LogMetric[]
}>()

const { t } = useI18n()

// ── Metrics ───────────────────────────────────────────────────────────────────

const visibleMetrics = computed(() => props.metrics ?? [])

// ── Shared log formatting ────────────────────────────────────────────────────

const { eventIcon, eventLabel, formatDate } = useEntityLogFormat()
</script>

<style lang="scss" scoped>
.entity-log-tab {
  display: flex;
  flex-direction: column;
  min-height: 0;

  // ── Metrics bar ────────────────────────────────────────────────────────────

  &__metrics {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: $space-2 $space-4;
    padding: $space-3 $space-3 $space-2;
  }

  &__metric {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 3.5rem;
  }

  &__metric-value {
    font-size: $font-size-lg;
    font-weight: $font-weight-bold;
    color: var(--p-text-color);
    line-height: 1.1;
  }

  &__metric-label {
    font-size: $font-size-xs;
    color: $surface-500;
    text-align: center;
    white-space: nowrap;
  }

  &__divider {
    height: 1px;
    background: var(--p-surface-200);
    margin: 0 $space-3 $space-1;

    .app-dark & {
      background: var(--p-surface-700);
    }
  }

  // ── Header ─────────────────────────────────────────────────────────────────

  &__header {
    display: flex;
    align-items: center;
    gap: $space-2;
    padding: $space-2 $space-3;
  }

  &__header-title {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-600;
    display: flex;
    align-items: center;
    gap: $space-1;

    .app-dark & {
      color: var(--p-surface-400);
    }
  }

  &__count {
    background: var(--p-surface-200);
    border-radius: 999px;
    padding: 1px 6px;
    font-size: $font-size-xs;
    color: $surface-600;
    font-weight: $font-weight-normal;

    .app-dark & {
      background: var(--p-surface-700);
      color: var(--p-surface-300);
    }
  }

  // ── States ─────────────────────────────────────────────────────────────────

  &__skeleton {
    padding: $space-3;
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: $space-8 $space-4;
    gap: $space-2;
    text-align: center;
  }

  &__empty-icon {
    font-size: 2rem;
    color: var(--p-surface-400);
  }

  &__empty-title {
    font-weight: $font-weight-semibold;
    color: $surface-700;
    margin: 0;

    .app-dark & {
      color: var(--p-surface-200);
    }
  }

  &__empty-hint {
    font-size: $font-size-sm;
    color: $surface-500;
    margin: 0;
  }

  // ── Entries list ───────────────────────────────────────────────────────────

  &__list {
    display: flex;
    flex-direction: column;
    padding: 0 $space-3 $space-3;
  }

  &__entry {
    display: flex;
    gap: $space-2;
    padding: $space-2 0;
    border-bottom: 1px solid var(--p-surface-100);
    align-items: flex-start;

    .app-dark & {
      border-bottom-color: var(--p-surface-800);
    }

    &:last-child {
      border-bottom: none;
    }
  }

  // ── Icon badge ─────────────────────────────────────────────────────────────

  &__icon-wrap {
    flex-shrink: 0;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--p-surface-100);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-top: 2px;

    .app-dark & {
      background: var(--p-surface-800);
    }
  }

  &__icon {
    font-size: 0.7rem;
    color: var(--p-primary-color);
  }

  // ── Entry content ──────────────────────────────────────────────────────────

  &__content {
    flex: 1;
    min-width: 0;
  }

  &__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 3px;
    font-size: $font-size-sm;
  }

  &__actor {
    font-weight: $font-weight-semibold;
    color: var(--p-text-color);
    white-space: nowrap;
  }

  &__event-label {
    color: $surface-600;

    .app-dark & {
      color: var(--p-surface-400);
    }
  }

  &__stage-old {
    color: $surface-500;
    text-decoration: line-through;
    font-size: $font-size-xs;
  }

  &__arrow {
    font-size: 0.6rem;
    color: $surface-400;
  }

  &__stage-new {
    color: var(--p-primary-color);
    font-size: $font-size-xs;
    font-weight: $font-weight-semibold;
  }

  &__description {
    color: $surface-600;
    font-size: $font-size-xs;
    word-break: break-word;

    .app-dark & {
      color: var(--p-surface-400);
    }
  }

  &__desc {
    font-size: $font-size-xs;
    color: $surface-500;
    margin-top: 2px;
    word-break: break-word;
  }

  &__time {
    font-size: $font-size-xs;
    color: $surface-400;
    margin-top: 2px;
  }

  // ── Load more ──────────────────────────────────────────────────────────────

  &__load-more {
    display: flex;
    justify-content: center;
    padding-top: $space-3;
  }
}
</style>
