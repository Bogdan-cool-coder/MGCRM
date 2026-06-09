<template>
  <div class="column-manager">
    <Button
      ref="anchorRef"
      v-tooltip.bottom="t('columnManager.tooltip')"
      icon="pi pi-th-large"
      severity="secondary"
      :aria-label="t('columnManager.tooltip')"
      :aria-haspopup="true"
      :aria-expanded="popoverOpen"
      @click="togglePopover"
    />

    <Popover ref="popoverRef" @show="popoverOpen = true" @hide="popoverOpen = false">
      <div class="column-manager__panel" :aria-label="t('columnManager.header')">
        <div class="column-manager__header">
          <span class="column-manager__title">{{ t('columnManager.header') }}</span>
          <Button
            v-if="anyCustomised"
            :label="t('columnManager.reset')"
            severity="secondary"
            text
            size="small"
            @click="onReset"
          />
        </div>

        <div class="column-manager__hint">
          {{ t('columnManager.hint') }}
        </div>

        <div class="column-manager__bulk">
          <Checkbox
            :model-value="bulkState.allVisible"
            :indeterminate="bulkState.indeterminate"
            :binary="true"
            @update:model-value="(value) => onBulkVisibilityToggle(value === true)"
          />
          <span class="column-manager__bulk-label">
            {{ t('columnManager.bulkLabel') }}
          </span>
          <span class="column-manager__bulk-count">
            {{ bulkState.visibleCount }}/{{ columns.length }}
          </span>
        </div>

        <draggable
          :model-value="columns"
          item-key="_key"
          :animation="160"
          ghost-class="column-manager__row--ghost"
          chosen-class="column-manager__row--chosen"
          handle=".column-manager__row-handle"
          class="column-manager__list"
          @change="(event: DraggableChangeEvent) => onDragChange(event)"
        >
          <template #item="{ element }">
            <div class="column-manager__row" :data-key="element._key">
              <span
                class="column-manager__row-handle"
                :aria-label="t('columnManager.dragHandle')"
                role="button"
                tabindex="-1"
              >
                <i class="pi pi-bars" aria-hidden="true" />
              </span>
              <Checkbox
                :model-value="!hiddenFields.has(element._key)"
                :binary="true"
                :aria-label="t('columnManager.toggleColumn', { name: element.header })"
                @update:model-value="
                  (value) => onColumnVisibilityToggle(element._key, value === true)
                "
              />
              <span class="column-manager__row-label">{{ element.header }}</span>
            </div>
          </template>
        </draggable>
      </div>
    </Popover>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Popover from 'primevue/popover'
import Tooltip from 'primevue/tooltip'
import draggable from 'vuedraggable'
import { useLocalI18n } from '@/composables/useLocalI18n'
import type { PresentationColumn } from '../composables/useReportPresentation'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

const vTooltip = Tooltip

/**
 * vuedraggable @change event shape (we only consume the discriminated
 * fields we actually use — moved, since the popover is a single flat
 * sortable list and intra-list reorder is the only motion possible).
 */
interface DraggableChangeEvent {
  moved?: { element: PresentationColumn; oldIndex: number; newIndex: number }
}

interface Props {
  /**
   * Flat list of columns in display order. Caller owns deriving this from
   * `displayColumns` (so DnD updates flow through the same composable that
   * persists to the backend). Empty array hides the manager body.
   */
  columns: PresentationColumn[]
  /** Column `_key`s currently hidden by the user. */
  hiddenFields: Set<string>
  /** True when the user has any column-order / visibility customisation. */
  anyCustomised: boolean
}

const props = defineProps<Props>()

const emit = defineEmits<{
  /** Per-column visibility toggle (by column `_key`). */
  (_e: 'toggle-column', _key: string, _visible: boolean): void
  /**
   * The user dropped a column. Fires once per atomic move:
   *   - `dragIndex` — original position in `columns`
   *   - `dropIndex` — new position in `columns`
   */
  (_e: 'reorder', _payload: { dragIndex: number; dropIndex: number }): void
  /** Bulk visibility — show or hide all columns at once. */
  (_e: 'toggle-all', _visible: boolean): void
  /** Reset all customisation back to the report config defaults. */
  (_e: 'reset'): void
}>()

const { t } = useLocalI18n({ en, ru })

const popoverRef = ref<InstanceType<typeof Popover> | null>(null)
const anchorRef = ref<InstanceType<typeof Button> | null>(null)
const popoverOpen = ref(false)

const togglePopover = (event: MouseEvent): void => {
  popoverRef.value?.toggle(event)
}

/**
 * Derive the bulk-toggle checkbox state:
 *   - checked       — every column is visible
 *   - unchecked     — every column is hidden
 *   - indeterminate — mix of visible / hidden columns
 */
const bulkState = computed<{
  allVisible: boolean
  indeterminate: boolean
  visibleCount: number
}>(() => {
  const total = props.columns.length
  if (total === 0) {
    return { allVisible: true, indeterminate: false, visibleCount: 0 }
  }
  let hidden = 0
  for (const col of props.columns) {
    if (props.hiddenFields.has(col._key)) hidden += 1
  }
  const visibleCount = total - hidden
  if (hidden === 0) {
    return { allVisible: true, indeterminate: false, visibleCount }
  }
  if (hidden === total) {
    return { allVisible: false, indeterminate: false, visibleCount }
  }
  return { allVisible: false, indeterminate: true, visibleCount }
})

const onColumnVisibilityToggle = (key: string, visible: boolean): void => {
  emit('toggle-column', key, visible)
}

const onBulkVisibilityToggle = (visible: boolean): void => {
  emit('toggle-all', visible)
}

const onReset = (): void => {
  emit('reset')
}

/**
 * Single sortable zone — vuedraggable emits `moved` for intra-list
 * reorders. We forward the indices to the parent composable, which owns
 * the persistence step.
 */
const onDragChange = (event: DraggableChangeEvent): void => {
  if (!event.moved) return
  const { oldIndex, newIndex } = event.moved
  if (oldIndex === newIndex) return
  emit('reorder', { dragIndex: oldIndex, dropIndex: newIndex })
}
</script>

<style lang="scss" scoped>
.column-manager {
  display: inline-flex;
  flex-shrink: 0;

  &__panel {
    display: flex;
    flex-direction: column;
    min-width: 18rem;
    max-width: 26rem;
    max-height: 30rem;
    gap: 0.5rem;
  }

  &__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid $surface-200;
  }

  &__title {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-800;
  }

  &__hint {
    font-size: $font-size-xs;
    color: $surface-500;
    line-height: 1.4;
  }

  &__bulk {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.35rem 0.25rem;
    border-radius: $radius-sm;
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-800;
    background: $surface-100;
    flex-shrink: 0;
  }

  &__bulk-label {
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
  }

  &__bulk-count {
    // Literal `400` — `_typography.scss` ships only medium / semibold tokens
    // and we want body weight here to contrast with the semibold bulk row.
    font-weight: 400;
    font-size: $font-size-xs;
    color: $surface-500;
    white-space: nowrap;
  }

  &__list {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    overflow-y: auto;
    flex: 1;
    min-height: 0;
    border-radius: $radius-sm;
    border: 1px dashed transparent;
    transition: border-color 0.15s ease, background 0.15s ease;
  }

  &__row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.3rem 0.25rem 0.3rem 0.5rem;
    border-radius: $radius-sm;
    font-size: $font-size-sm;
    color: $surface-700;
    background: $surface-0;

    &:hover {
      background: $surface-50;
    }

    &--ghost {
      // Placeholder shown at the drop position during drag — keep it
      // visible but muted so the user can read the destination.
      opacity: 0.4;
      background: $surface-100;
    }

    &--chosen {
      // The element under the cursor while dragging. Distinct from the
      // ghost — sortable.js lets us style them independently. Inline
      // literal shadow because the project's `_layout.scss` token set only
      // exposes `md` / `lg` and this lift is intentionally subtler than `md`.
      background: $surface-50;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
    }
  }

  &__row-handle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.25rem;
    height: 1.25rem;
    color: $surface-500;
    cursor: grab;
    user-select: none;

    &:active {
      cursor: grabbing;
    }

    .pi {
      font-size: 0.85rem;
    }
  }

  &__row-label {
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
  }
}
</style>
