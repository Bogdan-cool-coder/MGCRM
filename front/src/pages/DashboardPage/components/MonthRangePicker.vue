<template>
  <!-- Обзор month picker (Ф8): replaces the named-period Select. A trigger button
       shows the current selection; clicking opens a Popover with a year-navigated
       12-month grid where one OR several months can be toggled. Multi-select drives
       the dashboard `months[]` query; the selection format collapses a contiguous
       run to a range («Май–Июль 2026») and a scattered set to «Июль +2». -->
  <div class="month-range-picker">
    <Button
      type="button"
      severity="secondary"
      outlined
      icon="pi pi-calendar"
      icon-pos="left"
      class="month-range-picker__trigger"
      :aria-label="t('dashboard.monthPicker.aria_open')"
      @click="togglePanel"
    >
      <i class="pi pi-calendar month-range-picker__trigger-icon" aria-hidden="true" />
      <span class="month-range-picker__trigger-label">{{ triggerLabel }}</span>
      <i class="pi pi-chevron-down month-range-picker__trigger-caret" aria-hidden="true" />
    </Button>

    <Popover ref="popoverRef" class="month-range-picker__panel">
      <div class="month-range-picker__body">
        <!-- Year navigation header -->
        <div class="month-range-picker__year-nav">
          <Button
            text
            rounded
            severity="secondary"
            size="small"
            icon="pi pi-chevron-left"
            :aria-label="t('dashboard.monthPicker.prev_year')"
            @click="viewYear -= 1"
          />
          <span class="month-range-picker__year">{{ viewYear }}</span>
          <Button
            text
            rounded
            severity="secondary"
            size="small"
            icon="pi pi-chevron-right"
            :disabled="viewYear >= maxYear"
            :aria-label="t('dashboard.monthPicker.next_year')"
            @click="viewYear += 1"
          />
        </div>

        <!-- 3×4 month grid for the viewed year -->
        <div class="month-range-picker__grid" role="group" :aria-label="String(viewYear)">
          <button
            v-for="cell in monthCells"
            :key="cell.value"
            type="button"
            class="month-range-picker__cell"
            :class="{
              'month-range-picker__cell--selected': cell.selected,
              'month-range-picker__cell--current': cell.isCurrent,
            }"
            :disabled="cell.disabled"
            :aria-pressed="cell.selected"
            @click="toggleMonth(cell.value)"
          >
            {{ cell.short }}
          </button>
        </div>

        <div class="month-range-picker__footer">
          <button
            type="button"
            class="month-range-picker__reset"
            :disabled="isCurrentMonthOnly"
            @click="resetToCurrent"
          >
            {{ t('dashboard.monthPicker.current_month') }}
          </button>
          <span class="month-range-picker__count">{{ selectionSummary }}</span>
        </div>
      </div>
    </Popover>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Popover from 'primevue/popover'
import Button from 'primevue/button'

/** Selected months as sorted "YYYY-MM" strings — mirrors the API `months[]` shape. */
const props = defineProps<{
  modelValue: string[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string[]]
}>()

const { t, locale } = useI18n()

const popoverRef = ref<InstanceType<typeof Popover> | null>(null)

const now = new Date()
const currentMonthKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
const maxYear = now.getFullYear()

// Year shown in the grid — opens on the year of the latest selected month, else current.
const viewYear = ref<number>(deriveInitialYear())

function deriveInitialYear(): number {
  const sorted = [...props.modelValue].sort()
  const last = sorted.length > 0 ? sorted[sorted.length - 1] : undefined
  if (last !== undefined) {
    const y = Number(last.slice(0, 4))
    if (Number.isFinite(y)) return y
  }
  return now.getFullYear()
}

const intlLocale = computed(() => (locale.value === 'en' ? 'en-US' : 'ru-RU'))

/** Localised short month name (e.g. «янв» / «Jan»), capitalised. */
function shortMonth(monthIndex0: number): string {
  const name = new Date(2000, monthIndex0, 1).toLocaleString(intlLocale.value, {
    month: 'short',
  })
  return name.charAt(0).toUpperCase() + name.slice(1).replace('.', '')
}

/** Localised full month name, capitalised. */
function longMonth(monthIndex0: number): string {
  const name = new Date(2000, monthIndex0, 1).toLocaleString(intlLocale.value, {
    month: 'long',
  })
  return name.charAt(0).toUpperCase() + name.slice(1)
}

const selectedSet = computed<Set<string>>(() => new Set(props.modelValue))

interface MonthCell {
  value: string // "YYYY-MM"
  short: string
  selected: boolean
  isCurrent: boolean
  disabled: boolean
}

const monthCells = computed<MonthCell[]>(() =>
  Array.from({ length: 12 }, (_, i) => {
    const value = `${viewYear.value}-${String(i + 1).padStart(2, '0')}`
    // Future months (beyond the current calendar month) carry no data — disable them.
    const disabled = value > currentMonthKey
    return {
      value,
      short: shortMonth(i),
      selected: selectedSet.value.has(value),
      isCurrent: value === currentMonthKey,
      disabled,
    }
  }),
)

const togglePanel = (event: Event): void => {
  // Re-anchor the grid on the latest selection each time the panel opens.
  viewYear.value = deriveInitialYear()
  popoverRef.value?.toggle(event)
}

const toggleMonth = (value: string): void => {
  const next = new Set(props.modelValue)
  if (next.has(value)) {
    next.delete(value)
  } else {
    // Cap at 12 (matches the backend max:12); silently ignore beyond.
    if (next.size >= 12) return
    next.add(value)
  }
  // Never emit an empty selection — a cleared picker would fall back to the
  // named-period default on the backend. Keep at least the current month.
  const arr = [...next].sort()
  emit('update:modelValue', arr.length > 0 ? arr : [currentMonthKey])
}

const resetToCurrent = (): void => {
  emit('update:modelValue', [currentMonthKey])
}

const isCurrentMonthOnly = computed<boolean>(
  () => props.modelValue.length === 1 && props.modelValue[0] === currentMonthKey,
)

/** Are the selected months an unbroken run of consecutive calendar months? */
const isContiguous = computed<boolean>(() => {
  const indices = [...props.modelValue].sort().map(ymToIndex)
  for (let i = 1; i < indices.length; i++) {
    const prev = indices[i - 1] ?? 0
    const cur = indices[i] ?? 0
    if (cur - prev !== 1) return false
  }
  return true
})

/** Split a "YYYY-MM" key into numeric year + 0-based month, strict-safe. */
function partsOf(ym: string): { year: number; monthIndex0: number } {
  const year = Number(ym.slice(0, 4))
  const monthIndex0 = Number(ym.slice(5, 7)) - 1
  return { year, monthIndex0 }
}

/** Absolute month index (year*12 + month) for contiguity math. */
function ymToIndex(ym: string): number {
  const { year, monthIndex0 } = partsOf(ym)
  return year * 12 + monthIndex0
}

/**
 * Trigger label — the readable rendering of the selection:
 *  - single month           → «Июль 2026»
 *  - contiguous run, one yr  → «Май–Июль 2026»
 *  - contiguous run, 2 yrs   → «Ноябрь 2025 – Февраль 2026»
 *  - scattered set           → «Июль +2» (earliest + count of the rest)
 */
const triggerLabel = computed<string>(() => {
  const sorted = [...props.modelValue].sort()
  const firstKey = sorted[0]
  const lastKey = sorted[sorted.length - 1]
  if (firstKey === undefined || lastKey === undefined) {
    return t('dashboard.monthPicker.current_month')
  }

  const first = partsOf(firstKey)
  const last = partsOf(lastKey)

  if (sorted.length === 1) {
    return `${longMonth(first.monthIndex0)} ${first.year}`
  }

  if (isContiguous.value) {
    if (first.year === last.year) {
      // «Май–Июль 2026» (en-dash join, single trailing year)
      return `${longMonth(first.monthIndex0)}–${longMonth(last.monthIndex0)} ${last.year}`
    }
    // «Ноябрь 2025 – Февраль 2026» (spaced dash when years differ)
    return `${longMonth(first.monthIndex0)} ${first.year} – ${longMonth(last.monthIndex0)} ${last.year}`
  }

  // Scattered: earliest month + «+N»
  return t('dashboard.monthPicker.scattered', {
    month: longMonth(first.monthIndex0),
    year: first.year,
    rest: sorted.length - 1,
  })
})

/** In-panel footer summary of how many months are selected. */
const selectionSummary = computed<string>(() =>
  t('dashboard.monthPicker.selected_count', { count: props.modelValue.length }),
)
</script>

<style lang="scss" scoped>
.month-range-picker {
  display: inline-flex;
}

// Trigger mirrors the filter-bar Select chrome (min-width, height, secondary tone).
.month-range-picker__trigger {
  min-width: 180px;
  justify-content: flex-start;
  gap: $space-2;
}

.month-range-picker__trigger-icon {
  font-size: $font-size-sm;
  color: var(--p-text-muted-color);
  flex-shrink: 0;
}

.month-range-picker__trigger-label {
  flex: 1;
  min-width: 0;
  text-align: left;
  font-weight: $font-weight-semibold;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.month-range-picker__trigger-caret {
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);
  flex-shrink: 0;
}

.month-range-picker__body {
  width: 280px;
  max-width: 90vw;
}

.month-range-picker__year-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: $space-2;
}

.month-range-picker__year {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  font-variant-numeric: tabular-nums;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

.month-range-picker__grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: $space-2;
}

.month-range-picker__cell {
  height: 40px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  background: $surface-0;
  color: $surface-800;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  cursor: pointer;
  transition:
    background-color $transition-fast,
    border-color $transition-fast,
    color $transition-fast;

  .app-dark & {
    border-color: var(--p-surface-300);
    background: var(--p-surface-100);
    color: var(--p-surface-700);
  }

  &:hover:not(:disabled):not(.month-range-picker__cell--selected) {
    background: var(--mg-surface-hover);
    border-color: $surface-300;

    .app-dark & {
      border-color: var(--p-surface-400);
    }
  }

  &:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }
}

// Current month gets a subtle ring even when not selected.
.month-range-picker__cell--current:not(.month-range-picker__cell--selected) {
  border-color: var(--p-primary-color);
}

// Selected — filled primary (navy light / blue dark via reactive token).
.month-range-picker__cell--selected {
  background: var(--p-primary-color);
  border-color: var(--p-primary-color);
  color: var(--p-primary-contrast-color);
  font-weight: $font-weight-semibold;

  .app-dark & {
    background: var(--p-primary-color);
    border-color: var(--p-primary-color);
    color: var(--p-primary-contrast-color);
  }
}

.month-range-picker__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: $space-3;
  padding-top: $space-3;
  border-top: 1px solid $surface-200;

  .app-dark & {
    border-top-color: var(--p-surface-300);
  }
}

.month-range-picker__reset {
  border: none;
  background: transparent;
  padding: 0;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: var(--p-primary-color);
  cursor: pointer;

  &:disabled {
    color: $surface-400;
    cursor: default;

    .app-dark & {
      color: var(--p-surface-500);
    }
  }
}

.month-range-picker__count {
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);
}
</style>
