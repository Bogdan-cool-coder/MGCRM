<template>
  <div class="widget-grid">
    <div
      v-for="entry in renderList"
      :key="entry.i"
      class="widget-grid__cell"
      :class="{ 'widget-grid__cell--hidden': edit && !entry.visible }"
      :style="cellStyle(entry.i)"
    >
      <!-- Edit chrome: reorder + visibility controls -->
      <div v-if="edit" class="widget-grid__chrome">
        <span class="widget-grid__chrome-label">{{ labelFor(entry.i) }}</span>
        <div class="widget-grid__chrome-actions">
          <button
            type="button"
            class="widget-grid__chrome-btn"
            :disabled="isFirst(entry.i)"
            :aria-label="t('dashboard.layout.moveUp')"
            :title="t('dashboard.layout.moveUp')"
            @click="emit('move-up', entry.i)"
          >
            <i class="pi pi-arrow-up" />
          </button>
          <button
            type="button"
            class="widget-grid__chrome-btn"
            :disabled="isLast(entry.i)"
            :aria-label="t('dashboard.layout.moveDown')"
            :title="t('dashboard.layout.moveDown')"
            @click="emit('move-down', entry.i)"
          >
            <i class="pi pi-arrow-down" />
          </button>
          <button
            type="button"
            class="widget-grid__chrome-btn widget-grid__chrome-btn--toggle"
            :class="{ 'widget-grid__chrome-btn--off': !entry.visible }"
            :aria-label="entry.visible ? t('dashboard.layout.hide') : t('dashboard.layout.show')"
            :title="entry.visible ? t('dashboard.layout.hide') : t('dashboard.layout.show')"
            @click="emit('toggle-visible', entry.i)"
          >
            <i :class="entry.visible ? 'pi pi-eye' : 'pi pi-eye-slash'" />
          </button>
        </div>
      </div>

      <!-- Widget body — dimmed & non-interactive when hidden in edit mode -->
      <div
        class="widget-grid__body"
        :class="{ 'widget-grid__body--muted': edit && !entry.visible }"
      >
        <slot :name="entry.i" />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { WIDGET_SPAN, type LayoutEntry, type WidgetId } from '../composables/useDashboardLayout'

const { t } = useI18n()

const props = defineProps<{
  /** Layout entries already sorted by `order`. */
  ordered: LayoutEntry[]
  edit: boolean
  isFirst: (id: WidgetId) => boolean
  isLast: (id: WidgetId) => boolean
}>()

const emit = defineEmits<{
  'move-up': [id: WidgetId]
  'move-down': [id: WidgetId]
  'toggle-visible': [id: WidgetId]
}>()

// In view mode hidden widgets are dropped entirely; in edit mode they are shown
// dimmed so the user can re-enable them.
const renderList = computed<LayoutEntry[]>(() =>
  props.edit ? props.ordered : props.ordered.filter((e) => e.visible),
)

const cellStyle = (id: WidgetId): Record<string, string> => ({
  gridColumn: `span ${WIDGET_SPAN[id]}`,
})

const LABEL_KEY: Record<WidgetId, string> = {
  'kpi-active': 'dashboard.statusGroups.active',
  'kpi-won': 'dashboard.statusGroups.won',
  'kpi-lost': 'dashboard.statusGroups.lost',
  'kpi-total': 'dashboard.statusGroups.total',
  funnel: 'dashboard.funnel.title',
  forecast: 'dashboard.forecast.title',
  top: 'dashboard.topBar.title',
  notask: 'dashboard.dealsWithoutTasks.title',
}

const labelFor = (id: WidgetId): string => t(LABEL_KEY[id])
</script>

<style lang="scss" scoped>
.widget-grid {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap: $space-4;
  align-items: stretch;
}

.widget-grid__cell {
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.widget-grid__cell--hidden {
  // Dashed placeholder frame for a hidden widget in edit mode.
  border: 1px dashed $surface-300;
  border-radius: $radius-lg;
  padding: $space-2;
}

.widget-grid__chrome {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-1 $space-2;
  margin-bottom: $space-2;
  // Reactive primary tint over the card surface (color-mix on --p-primary-color):
  // the static $primary-50/$primary-200 stayed a pale light plaque on navy.
  background: color-mix(in srgb, var(--p-primary-color) 8%, var(--p-surface-card));
  border: 1px solid color-mix(in srgb, var(--p-primary-color) 24%, var(--p-surface-card));
  border-radius: $radius-md;
}

.widget-grid__chrome-label {
  flex: 1;
  min-width: 0;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  // Brand accent reads on both themes from the reactive PrimeVue token.
  color: var(--p-primary-color);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.widget-grid__chrome-actions {
  display: flex;
  align-items: center;
  gap: $space-1;
  flex-shrink: 0;
}

.widget-grid__chrome-btn {
  width: 26px;
  height: 26px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid $surface-300;
  border-radius: $radius-sm;
  background: $surface-card;
  color: $surface-600;
  cursor: pointer;
  font-size: $font-size-xs;
  transition: all var(--app-transition-fast);

  &:hover:not(:disabled) {
    border-color: var(--p-primary-color);
    color: var(--p-primary-color);
  }

  &:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }

  &--toggle.widget-grid__chrome-btn--off {
    color: $status-warning-text;
    border-color: $status-warning-text;
  }
}

.widget-grid__body {
  flex: 1;
  min-height: 0;
}

.widget-grid__body--muted {
  opacity: 0.45;
  pointer-events: none;
}

// ─── Responsive collapse ─────────────────────────────────────────────────────
// Below lg the 12-col grid gets cramped; drop to a single column so widgets
// stack (matches the previous Bootstrap col-12 behaviour on narrow viewports).
@media (max-width: 991.98px) {
  .widget-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .widget-grid__cell {
    grid-column: span 1 !important;
  }
}
</style>
