<template>
  <div class="entity-log-tab">
    <!-- spec §6: 2-column grid with border dividers — label 11px muted, value 15px/700 (A1) -->
    <div v-if="visibleMetrics.length > 0" class="entity-log-tab__metrics-grid">
      <div
        v-for="(m, idx) in visibleMetrics"
        :key="m.key"
        class="entity-log-tab__metric-cell"
        :class="{
          'entity-log-tab__metric-cell--right': idx % 2 === 0,
          'entity-log-tab__metric-cell--bottom': idx < visibleMetrics.length - 2,
        }"
      >
        <span class="entity-log-tab__metric-label">{{ m.label }}</span>
        <span class="entity-log-tab__metric-value">{{ m.metricValue }}</span>
      </div>
    </div>

    <!-- Log header: «История действий» (A1) -->
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
      <Skeleton height="20px" class="mb-2" />
      <Skeleton height="20px" class="mb-2" />
      <Skeleton height="20px" class="mb-2" />
      <Skeleton height="20px" />
    </div>

    <!-- Empty state -->
    <div v-else-if="!log.loading.value && log.entries.value.length === 0" class="entity-log-tab__empty">
      <i class="pi pi-history entity-log-tab__empty-icon" />
      <p class="entity-log-tab__empty-title">{{ t('crm.log.empty.title') }}</p>
      <p class="entity-log-tab__empty-hint">{{ t('crm.log.empty.hint') }}</p>
    </div>

    <!-- Log entries — plain grey text, no card backing (A1) -->
    <div v-else class="entity-log-tab__list">
      <div
        v-for="entry in log.entries.value"
        :key="entry.id"
        class="entity-log-tab__line"
      >
        <span class="entity-log-tab__line-body">
          <!-- actor -->
          <span class="entity-log-tab__actor">{{ entry.user?.full_name ?? t('crm.log.system') }}</span>
          <!-- event label -->
          <span class="entity-log-tab__sep"> — </span>
          <span class="entity-log-tab__event">{{ eventLabel(entry.action) }}</span>
          <!-- old → new (strikethrough old) -->
          <template v-if="entry.action === 'stage_changed' && entry.old_value && entry.new_value">
            <span class="entity-log-tab__sep"> </span>
            <span class="entity-log-tab__old">{{ entry.old_value }}</span>
            <i class="pi pi-arrow-right entity-log-tab__arrow" />
            <span class="entity-log-tab__new">{{ entry.new_value }}</span>
          </template>
          <template v-else-if="entry.old_value && entry.new_value">
            <span class="entity-log-tab__sep"> </span>
            <span class="entity-log-tab__old">{{ entry.old_value }}</span>
            <i class="pi pi-arrow-right entity-log-tab__arrow" />
            <span class="entity-log-tab__new">{{ entry.new_value }}</span>
          </template>
          <template v-else-if="entry.description">
            <span class="entity-log-tab__sep">: </span>
            <span class="entity-log-tab__desc-inline">{{ entry.description }}</span>
          </template>
        </span>
        <span class="entity-log-tab__time">{{ formatDate(entry.created_at) }}</span>
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
  /** Renamed from `value` to avoid Vue 3 template ref-unwrapping confusion */
  metricValue: string | number
}

const props = defineProps<{
  log: UseEntityLogReturn
  /** Optional metrics shown above the log in a 2-col grid */
  metrics?: LogMetric[]
}>()

const { t } = useI18n()

// ── Metrics ───────────────────────────────────────────────────────────────────

const visibleMetrics = computed(() => props.metrics ?? [])

// ── Log formatting ────────────────────────────────────────────────────────────

const { eventLabel, formatDate } = useEntityLogFormat()
</script>

<style lang="scss" scoped>
.entity-log-tab {
  display: flex;
  flex-direction: column;
  min-height: 0;
}

// ── Metrics grid — spec §6: 2-col, border dividers (A1) ──────────────────────

.entity-log-tab__metrics-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  border-bottom: 1px solid var(--p-surface-200);
  margin-bottom: $space-2;

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

.entity-log-tab__metric-cell {
  padding: $space-3 $space-3 $space-2;
  display: flex;
  flex-direction: column;
  gap: $space-1;

  // Left column cells get right border
  &--right {
    border-right: 1px solid var(--p-surface-200);

    .app-dark & {
      border-right-color: var(--p-surface-700);
    }
  }

  // All cells except bottom row get bottom border
  &--bottom {
    border-bottom: 1px solid var(--p-surface-200);

    .app-dark & {
      border-bottom-color: var(--p-surface-700);
    }
  }
}

// label: 11px muted (A1)
.entity-log-tab__metric-label {
  font-size: $font-size-2xs;
  color: $surface-500;
  line-height: $line-height-tight;
}

// value: 15px bold (A1)
.entity-log-tab__metric-value {
  font-size: $font-size-md;
  font-weight: $font-weight-bold;
  color: var(--p-text-color);
  line-height: 1.1;
}

// ── Header ────────────────────────────────────────────────────────────────────

.entity-log-tab__header {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
}

.entity-log-tab__header-title {
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

.entity-log-tab__count {
  background: var(--p-surface-200);
  border-radius: $radius-pill;
  padding: 1px 6px;
  font-size: $font-size-xs;
  color: $surface-600;
  font-weight: $font-weight-normal;

  .app-dark & {
    background: var(--p-surface-700);
    color: var(--p-surface-300);
  }
}

// ── States ────────────────────────────────────────────────────────────────────

.entity-log-tab__skeleton {
  padding: $space-3;
}

.entity-log-tab__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: $space-8 $space-4;
  gap: $space-2;
  text-align: center;
}

.entity-log-tab__empty-icon {
  font-size: $font-size-icon-lg;
  color: var(--p-surface-400);
}

.entity-log-tab__empty-title {
  font-weight: $font-weight-semibold;
  color: $surface-700;
  margin: 0;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.entity-log-tab__empty-hint {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

// ── Log list — plain grey text rows, no card backing (A1) ────────────────────

.entity-log-tab__list {
  display: flex;
  flex-direction: column;
  padding: 0 $space-3 $space-3;
  gap: $space-1;
}

// single-line flex row: body (flex:1) + time (right)
.entity-log-tab__line {
  display: flex;
  align-items: baseline;
  gap: $space-2;
  font-size: $font-size-xs;
  color: $surface-600;
  line-height: $line-height-normal;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.entity-log-tab__line-body {
  flex: 1;
  min-width: 0;
  white-space: normal;
  word-break: break-word;
}

.entity-log-tab__actor {
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
}

.entity-log-tab__sep {
  color: $surface-400;
}

.entity-log-tab__event {
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// strikethrough old value (A1)
.entity-log-tab__old {
  text-decoration: line-through;
  color: $surface-400;
}

.entity-log-tab__arrow {
  font-size: $font-size-3xs;
  color: $surface-400;
  margin: 0 2px;
}

// bold new value (A1)
.entity-log-tab__new {
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
}

.entity-log-tab__desc-inline {
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.entity-log-tab__time {
  flex-shrink: 0;
  font-size: $font-size-xs;
  color: $surface-400;
  white-space: nowrap;
}

// ── Load more ─────────────────────────────────────────────────────────────────

.entity-log-tab__load-more {
  display: flex;
  justify-content: center;
  padding-top: $space-3;
}
</style>
