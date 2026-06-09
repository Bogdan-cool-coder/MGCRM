<template>
  <div class="report-detail-page">
    <LoadingState v-if="loading" />

    <div v-else-if="report" class="report-card">
      <div class="report-body">
        <!-- Header: back + title + view-mode toggle + filter controls -->
        <div class="report-header">
          <div class="header-left">
            <Button icon="pi pi-arrow-left" :label="t('back')" @click="goBack" />
            <div class="report-title-block">
              <h1 class="report-title">{{ localizedReportTitle }}</h1>
              <!-- Compact summary of the key active filters (date range +
                   single entity values) shown under the title, outside the
                   filter panel. Closes #10a / #11. -->
              <div v-if="headerFilterSummary.length > 0" class="report-header-filters">
                <span
                  v-for="(chip, idx) in headerFilterSummary"
                  :key="`${chip.field}:${idx}`"
                  class="report-header-filters__chip"
                >
                  <span v-if="chip.label" class="report-header-filters__label">{{ chip.label }}:</span>
                  <span class="report-header-filters__value">{{ chip.value }}</span>
                </span>
              </div>
            </div>

            <!-- Primary filter: the report's one "everyday" filter surfaced as
                 an interactive widget right of the title. Renders only when
                 `config.primary_filter` names a field present in
                 `filters_available`; otherwise the header is unchanged. Shares
                 state with the big filter panel via `localFilters[field]`. -->
            <ReportHeaderPrimaryFilter
              v-if="primaryFilterField && primaryFilterConfig"
              :field="primaryFilterField"
              :config="primaryFilterConfig"
              :model-value="getFilterValue(primaryFilterField)"
              class="report-header-primary"
              @apply="onPrimaryFilterApply"
              @update:selected-label="onPrimaryFilterSelectedLabel"
            />
          </div>

          <div class="header-right">
            <div
              v-if="showPagination"
              ref="headerPaginationRef"
              :class="['header-pagination', { 'is-stacked': headerPaginationStacked }]"
            >
              <div ref="headerPaginationControlRef" class="header-pagination__control">
                <Paginator
                  :total-records="report.meta?.total || 0"
                  :rows="report.meta?.per_page || currentRowsPerPage || 100"
                  :first="
                    ((report.meta?.page || 1) - 1) *
                    (report.meta?.per_page || currentRowsPerPage || 100)
                  "
                  :rows-per-page-options="[25, 50, 100, 200]"
                  @page="onPageChange"
                />
              </div>
              <span ref="headerPaginationInfoRef" class="pagination-info">
                {{
                  t('paginationInfo', {
                    page: report.meta?.page || 1,
                    lastPage: report.meta?.last_page || 1,
                  })
                }}
              </span>
            </div>

            <ColumnManagerPopover
              v-if="tableColumns.length > 0"
              :columns="columnOrder.displayColumns.value"
              :hidden-fields="columnOrder.hiddenFields.value"
              :any-customised="columnOrder.isCustomised.value"
              @toggle-column="onColumnVisibilityToggle"
              @toggle-all="onBulkVisibilityToggle"
              @reorder="onColumnManagerReorder"
              @reset="onColumnManagerReset"
            />

            <div v-if="hasFilters" class="filter-toggle-wrap">
              <!-- The filter button is the same in both states (count > 0 / = 0);
                   only the OverlayBadge wrapper changes. Kept as one source <Button>
                   to avoid prop drift between branches. -->
              <component
                :is="activeFiltersCount > 0 ? OverlayBadge : 'div'"
                :value="activeFiltersCount > 0 ? activeFiltersCount : undefined"
                :severity="activeFiltersCount > 0 ? 'danger' : undefined"
              >
                <Button
                  v-tooltip.bottom="filterCollapsed ? t('filters') : t('collapse')"
                  :icon="filterCollapsed ? 'pi pi-filter' : 'pi pi-filter-fill'"
                  severity="secondary"
                  :aria-label="filterCollapsed ? t('filters') : t('collapse')"
                  @click="toggleFilter"
                />
              </component>
            </div>

            <ReportActionsMenu
              v-if="report && canSeeActionsMenu"
              :report="report"
              @report-updated="onReportUpdated"
              @report-deleted="onReportDeleted"
            />
          </div>
        </div>

        <!-- Expanded filters -->
        <div v-show="hasFilters && !filterCollapsed" class="filter-inline">
          <div class="filter-fields">
            <div
              v-for="(filterConfig, field) in effectiveFiltersAvailable"
              :key="field"
              class="filter-field"
            >
              <component
                :is="getFilterComponent(filterConfig.type)"
                :field="field"
                :config="filterConfig"
                :model-value="getFilterValue(field)"
                @update:model-value="updateFilterValue(field, $event)"
                @update:selected-label="
                  (_value: string | null, label: string | null) =>
                    setAsyncSelectLabel(field, label)
                "
              />
            </div>
          </div>
          <div class="filter-buttons">
            <Button
              v-if="hasActiveFilters"
              icon="pi pi-refresh"
              :label="t('common.reset')"
              severity="secondary"
              text
              size="small"
              @click="resetFilters"
            />
            <Button
              icon="pi pi-check"
              :label="t('common.apply')"
              size="small"
              @click="applyFilters"
              :loading="loading"
            />
          </div>
        </div>

        <!-- Divider line -->
        <hr v-show="hasFilters" class="filter-divider" />

        <div class="table-mode">
          <div class="data-section">
              <!-- Flat report table — the report page always renders a flat table.
                   payment_schedule footer styles are applied via global selectors on
                   `.ps-footer-*` classes (rendered inside the #footer slot below).
                   See assets/styles/_payment-schedule-footer.scss — no parent class flag
                   on DataTable is needed (PrimeVue v4 does not reliably forward `class`
                   to a useful root node for our selector). -->
              <DataTable
                v-if="formattedTableData && formattedTableData.length > 0"
                :key="`${tableStateKey}-${columnReorderKey}`"
                :value="formattedTableData"
                stripedRows
                tableStyle="min-width: 50rem"
                lazy
                :loading="loading"
                :sortField="currentSort?.field"
                :sortOrder="currentSort ? (currentSort.direction === 'asc' ? 1 : -1) : undefined"
                :reorderableColumns="reorderableColumnsEnabled"
                @sort="onSortChange"
                @column-reorder="onColumnReorder"
              >
                <!--
                  Single-row header (2026-05-21 column-manager iteration).
                  The previous two-level <ColumnGroup type="header"> rendered a
                  decorative parent row above the actual column headers, which
                  contributed visual noise (e.g. `Поступление | Объект | Контрагент`
                  on the daily-receipts report). Column grouping is now a popup-only
                  concept driving the column manager — the table itself just shows
                  one header row per column.
                -->
                <Column
                  v-for="(col, colIndex) in visibleTableColumns"
                  :key="`${col._key}:${colIndex}:${col.sortable ? 'sortable' : 'plain'}`"
                  :field="col.field"
                  v-bind="col.sortable ? { sortable: true } : {}"
                  :body-class="col.is_crm_id ? 'crm-id-cell-td' : undefined"
                >
                  <!--
                    Custom header: label + optional `?` icon with description
                    tooltip (см. DEVELOPMENT_PLAN_CAPITALDATA §5).
                  -->
                  <template #header>
                    <span class="column-header-label">{{ col.header }}</span>
                    <button
                      v-if="col.description"
                      v-tooltip.top="col.description"
                      type="button"
                      class="column-header-tooltip-icon"
                      :aria-label="col.description"
                      @click.stop
                    >
                      <i class="pi pi-question-circle" aria-hidden="true" />
                    </button>
                  </template>
                  <template
                    v-if="isPaymentScheduleColumn(col) || col.type === 'link' || col.truncate === 'first_word' || col.badge"
                    #body="{ data: rowData, index: rowIndex }"
                  >
                    <template v-if="isPaymentScheduleColumn(col)">
                      <PaymentScheduleCell
                        v-if="tableData[rowIndex]?.[col.field] != null"
                        :value="tableData[rowIndex][col.field] as unknown as PaymentScheduleValue"
                        :cell-id="`${col.field}-${rowIndex}`"
                      />
                    </template>
                    <div
                      v-else
                      :class="[
                        col.badge ? 'badge-cell' : undefined,
                        col.is_crm_id ? 'crm-id-cell-wrapper' : undefined,
                      ]"
                    >
                      <template v-if="col.type === 'link'">
                        <template v-for="ref in [getLinkRefByKey(col._key, rowIndex)]" :key="rowIndex">
                          <!-- CRM-id column: the WHOLE cell is the link
                               target — clicking anywhere on the cell (including
                               empty zones / corners) opens the CRM object in a
                               new tab. The link itself is stretched to fill the
                               <td> (position:absolute; inset:0), so
                               elementFromPoint returns the <a> everywhere, not a
                               shrink-wrapped sub-region. The small external-link
                               icon sits inline after the value as a visual
                               affordance.
                               When no href could be resolved we render a plain
                               span (no link). -->
                          <template v-if="col.is_crm_id">
                            <template v-if="ref.label && ref.href">
                              <!-- In-flow height spacer: the visible anchor is
                                   position:absolute (out of flow), so this
                                   hidden copy of the value gives the <td> a
                                   natural height even when no sibling cell is
                                   taller. Never collapses the row. -->
                              <span class="crm-id-cell-spacer" aria-hidden="true">{{ ref.label }}</span>
                              <a
                                v-tooltip.top="t('openInCrm')"
                                :href="ref.href"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="crm-id-cell crm-id-cell--link"
                                :aria-label="t('openInCrm')"
                              >
                                <span class="crm-id-cell__value">{{ ref.label }}</span>
                                <i class="crm-id-cell__icon pi pi-external-link" aria-hidden="true" />
                              </a>
                            </template>
                            <span v-else-if="ref.label" class="crm-id-cell">
                              <span class="crm-id-cell__value">{{ ref.label }}</span>
                            </span>
                          </template>
                          <template v-else-if="ref.label">
                            <a
                              v-if="ref.href"
                              :href="ref.href"
                              target="_blank"
                              rel="noopener noreferrer"
                              :class="['link-cell-label', { 'link-cell-label--fallback': ref.isFallback }]"
                            >{{ ref.label }}</a>
                            <span
                              v-else
                              :class="['link-cell-label', { 'link-cell-label--fallback': ref.isFallback }]"
                            >{{ ref.label }}</span>
                          </template>
                        </template>
                      </template>
                      <template v-else-if="col.truncate === 'first_word'">
                        <span
                          v-if="rowData[col.field] != null && String(rowData[col.field]).trim() !== ''"
                          v-tooltip="String(rowData[col.field])"
                        >{{ String(rowData[col.field]).split(/\s+/)[0] }}</span>
                      </template>
                      <template v-else>
                        <span>{{ rowData[col.field] }}</span>
                      </template>
                      <Badge
                        v-if="col.badge && getFlatBadge(rowIndex, col.field)"
                        :severity="getFlatBadge(rowIndex, col.field)!.severity"
                        :value="getFlatBadge(rowIndex, col.field)!.label"
                        class="badge-cell__badge"
                      />
                    </div>
                  </template>
                </Column>
                <ColumnGroup v-if="formattedTotalsRow" type="footer">
                  <Row>
                    <Column
                      v-for="cell in visibleFooterCells"
                      :key="cell._key"
                      :footer="cell.isPaymentSchedule ? undefined : getFooterLabel(cell)"
                    >
                      <template v-if="cell.isPaymentSchedule" #footer>
                        <div class="ps-footer-row">
                          <div v-if="cell.paidTotal != null" class="ps-footer-pair">
                            <div class="ps-footer-label">{{ t('paymentSchedule.footer.paidLabel') }}</div>
                            <div class="ps-footer-value">{{ format(cell.paidTotal, { type: 'money' }) }}</div>
                          </div>
                          <div v-if="cell.dueTotal != null" class="ps-footer-pair">
                            <div class="ps-footer-label">{{ t('paymentSchedule.footer.dueLabel') }}</div>
                            <div class="ps-footer-value">{{ format(cell.dueTotal, { type: 'money' }) }}</div>
                          </div>
                        </div>
                      </template>
                    </Column>
                  </Row>
                </ColumnGroup>
              </DataTable>

              <EmptyState v-else :message="t('emptyData')" />
            </div>
        </div>
      </div>
    </div>

    <NotFoundState v-else :title="t('notFoundTitle')" :description="t('notFoundDescription')">
      <template #action>
        <Button :label="t('backToList')" @click="goBack" class="mt-3" />
      </template>
    </NotFoundState>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue'
import Button from 'primevue/button'
import Badge from 'primevue/badge'
import OverlayBadge from 'primevue/overlaybadge'
import Tooltip from 'primevue/tooltip'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import ColumnGroup from 'primevue/columngroup'
import Row from 'primevue/row'
import Paginator from 'primevue/paginator'
// ColumnGroup + Row are still imported above — they remain in use for the
// table footer (totals row). We dropped the two-level *header* group only
// (see "Single-row header" comment in the template).
import LoadingState from '@/components/states/LoadingState.vue'
import NotFoundState from '@/components/states/NotFoundState.vue'
import EmptyState from '@/components/states/EmptyState.vue'
import type { ReportFilterType, ReportFilterValue } from '@/entities/report'
import AsyncSelectFilter from '@/components/filters/AsyncSelectFilter.vue'
import DateRangeFilter from '@/components/filters/DateRangeFilter.vue'
import SelectFilter from '@/components/filters/SelectFilter.vue'
import TextFilter from '@/components/filters/TextFilter.vue'
import NumberRangeFilter from '@/components/filters/NumberRangeFilter.vue'
import { useCompaniesStore } from '@/stores/companies'
import { useReportContextStore } from '@/stores/reportContext'
import { useUserStore } from '@/stores/user'
import { canSeeReportActionsMenu } from '@/shared/auth/capabilities'
import { useReportGenerationModalStore } from '@/stores/reportGenerationModal'
import { useReportPage } from './composables/useReportPage'
import { isPaymentScheduleColumn, useReportPresentation } from './composables/useReportPresentation'
import type { FooterCell, PresentationColumn } from './composables/useReportPresentation'
import { useColumnOrder } from './composables/useColumnOrder'
import ColumnManagerPopover from './components/ColumnManagerPopover.vue'
import ReportActionsMenu from './components/ReportActionsMenu.vue'
import ReportHeaderPrimaryFilter from './components/ReportHeaderPrimaryFilter.vue'
import type { Report } from '@/entities/report'
import { useFormatter } from '@/composables/useFormatter'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { getLocalizedText } from '@/utils/localization'
import PaymentScheduleCell from './components/PaymentScheduleCell.vue'
import type { PaymentScheduleValue } from './components/PaymentScheduleCell.vue'
import en from './locale/en.json'
import ru from './locale/ru.json'

const vTooltip = Tooltip

const companiesStore = useCompaniesStore()
const reportContextStore = useReportContextStore()
const currentCompany = computed(() => companiesStore.getCurrentCompany)
const crmUrl = computed(() => currentCompany.value?.crm_url ?? null)
const headerPaginationRef = ref<HTMLElement | null>(null)
const headerPaginationControlRef = ref<HTMLElement | null>(null)
const headerPaginationInfoRef = ref<HTMLElement | null>(null)
const headerPaginationStacked = ref(false)
const { t, locale } = useLocalI18n({ en, ru })
const { format } = useFormatter()
const {
  report,
  loading,
  currentRowsPerPage,
  currentFilters,
  currentSort,
  filterCollapsed,
  hasFilters,
  effectiveFiltersAvailable,
  hasActiveFilters,
  activeFiltersCount,
  showPagination,
  primaryFilterField,
  primaryFilterConfig,
  asyncSelectLabels,
  getFilterValue,
  updateFilterValue,
  setAsyncSelectLabel,
  toggleFilter,
  applyFilters,
  applyPrimaryFilter,
  resetFilters,
  onPageChange,
  onSortChange,
  goBack,
  fetchReport,
} = useReportPage(t('errors.networkError'))

const modalStore = useReportGenerationModalStore()

const {
  tableColumns,
  tableData,
  formattedTableData,
  formattedTotalsRow,
  footerCells,
  tableStateKey,
  linkRefs,
  resolveBadge,
} = useReportPresentation(report, crmUrl)

const reportId = computed(() => (report.value ? report.value.id : 0))

const localizedReportTitle = computed(() =>
  report.value ? getLocalizedText(report.value.title, locale.value) : '',
)

// ─── Header filter summary (#10a / #11) ──────────────────────────────────
// Compact, read-only summary of the *key* active filters rendered under the
// report title (outside the filter panel). We surface:
//   - date_range filters → "С dd.mm.yyyy ПО dd.mm.yyyy" (one chip, no label)
//   - single entity filters (select / async_select single / text) → "label: value"
// Multi-value filters and number ranges are intentionally omitted — they are
// not "key" identifiers and would clutter the header.
type HeaderFilterChip = { field: string; label: string | null; value: string }

/** Resolve a stored date-range bound (ISO or relative token) to dd.mm.yyyy. */
const formatHeaderDate = (raw: string | null | undefined): string => {
  if (raw == null || raw === '') return ''
  // Relative tokens stored by DateRangeFilter ("today", "-90 days") — resolve
  // to a concrete date the same way the filter does, then format.
  if (raw === 'today') return String(format(new Date().toISOString(), { type: 'date' }))
  const daysMatch = /^(-?\d+)\s*days?$/.exec(raw.trim())
  if (daysMatch) {
    const days = Number(daysMatch[1])
    const d = new Date()
    d.setDate(d.getDate() + days)
    return String(format(d.toISOString(), { type: 'date' }))
  }
  return String(format(raw, { type: 'date' }))
}

/** Resolve a select/multiselect option value to its localized label. */
const resolveSelectLabel = (
  config: { options?: { value: string | number; label: unknown }[] },
  value: string | number,
): string => {
  const option = config.options?.find((o) => String(o.value) === String(value))
  if (!option) return String(value)
  const { label } = option
  if (typeof label === 'object' && label !== null) {
    return getLocalizedText(label as Record<string, string>, locale.value)
  }
  return String(label)
}

const headerFilterSummary = computed<HeaderFilterChip[]>(() => {
  const filters = currentFilters.value
  const available = effectiveFiltersAvailable.value
  const chips: HeaderFilterChip[] = []

  // The primary filter is rendered as its own interactive widget in the
  // header (see ReportHeaderPrimaryFilter), so skip it here to avoid showing
  // the same filter both as a live control and a static chip.
  const primaryField = primaryFilterField.value

  for (const [field, rawValue] of Object.entries(filters)) {
    if (field === primaryField) continue
    const config = available[field]
    if (!config || rawValue == null) continue

    const configLabel = config.label
      ? getLocalizedText(config.label, locale.value)
      : null

    if (config.type === 'date_range') {
      const range = rawValue as { from?: string | null; to?: string | null }
      const from = formatHeaderDate(range.from)
      const to = formatHeaderDate(range.to)
      let value = ''
      if (from && to) value = t('headerFilters.dateRange', { from, to })
      else if (from) value = t('headerFilters.dateFrom', { from })
      else if (to) value = t('headerFilters.dateTo', { to })
      if (value) chips.push({ field, label: null, value })
      continue
    }

    // Single entity value (scalar) — select / async_select / text.
    if (typeof rawValue === 'string' || typeof rawValue === 'number') {
      if (rawValue === '') continue
      let value: string
      if (config.type === 'select') {
        value = resolveSelectLabel(config, rawValue)
      } else if (config.type === 'async_select') {
        // The applied filter only holds an opaque id — the human-readable
        // label is cached in `asyncSelectLabels` (surfaced by AsyncSelectFilter
        // via `update:selectedLabel`, #11). Fall back to the raw id only if the
        // label hasn't been resolved yet (e.g. restored filter before the
        // dropdown was opened).
        value = asyncSelectLabels.value[field] ?? String(rawValue)
      } else {
        // text — the raw value is itself human-readable.
        value = String(rawValue)
      }
      chips.push({ field, label: configLabel, value })
    }
    // Arrays (multiselect) and number ranges are deliberately skipped.
  }

  return chips
})

// Bridge the header primary-filter widget's `apply` into the shared
// apply-single-filter action (debounce already handled inside the widget for
// text / number; immediate for select / date). Async-select labels surfaced
// by the widget are forwarded into the same `asyncSelectLabels` cache the
// panel + summary use, so the displayed contractor name stays consistent.
const onPrimaryFilterApply = (field: string, value: ReportFilterValue): void => {
  void applyPrimaryFilter(field, value)
}

const onPrimaryFilterSelectedLabel = (
  field: string,
  _value: string | null,
  label: string | null,
): void => {
  setAsyncSelectLabel(field, label)
}

const userStore = useUserStore()

// Viewer never sees the `…` actions menu (not even the read-only info block).
// Everyone else does; the finer per-action gating lives inside the menu.
const canSeeActionsMenu = computed(() =>
  canSeeReportActionsMenu(userStore.currentUser?.role),
)

// SPA refetch trigger: when the "edit with AI" modal finishes an AI turn that
// touched THIS report, it bumps `reportUpdatedTick` (and stamps the report id).
// We refetch in-place so the table reflects the AI's changes without a full
// reload. Keyed off the tick (not the id) so back-to-back edits of the same
// report each fire; the id guard keeps unrelated reports from refetching.
watch(
  () => modalStore.reportUpdatedTick,
  () => {
    if (modalStore.lastUpdatedReportId === reportId.value) {
      void fetchReport()
    }
  },
)

// ─── Column ordering + per-column visibility (DnD, 2026-05-21) ─────────────
// `displayColumns` is the user-curated order. `useColumnOrder` accepts a
// `disableWhen` flag (used to historically suppress DnD on grouped reports);
// the grouped report view was removed, so it's always enabled here.
const columnOrderDisabled = ref(false)
const columnOrder = useColumnOrder(reportId, tableColumns, columnOrderDisabled)

/**
 * Final list of columns rendered in the table — applies the user's order
 * via `columnOrder.displayColumns` then filters out per-column hidden
 * fields. Column groups were removed 2026-05-21; the column manager popup
 * now renders a single flat list.
 */
const visibleTableColumns = computed<PresentationColumn[]>(() => {
  const hidden = columnOrder.hiddenFields.value
  if (hidden.size === 0) return columnOrder.displayColumns.value
  return columnOrder.displayColumns.value.filter((col) => !hidden.has(col._key))
})

// PrimeVue DataTable `@column-reorder` callback. Translates drag/drop indices
// into our composable's `applyReorder` (which handles the group-inheritance
// logic and persists to localStorage). Indices passed by PrimeVue refer to
// the currently-rendered <Column> order, which matches `visibleTableColumns`
// (= ordered AND visibility-filtered). We translate those indices back to
// indices in the full `displayColumns` array so hiding a column doesn't
// scramble the underlying order.
//
// After the reorder we bump `columnReorderKey` to force PrimeVue to remount
// the table — it mutates its own internal `columns` array during drag-drop,
// which would otherwise desync from our reactive `displayColumns` (we're the
// source of truth; PrimeVue's mutation is fire-and-forget).
const columnReorderKey = ref(0)
const onColumnReorder = (event: { dragIndex: number; dropIndex: number }): void => {
  const visible = visibleTableColumns.value
  const fromCol = visible[event.dragIndex]
  const toCol = visible[event.dropIndex]
  if (!fromCol || !toCol) return
  const full = columnOrder.displayColumns.value
  const fromIndex = full.findIndex((col) => col._key === fromCol._key)
  const toIndex = full.findIndex((col) => col._key === toCol._key)
  if (fromIndex === -1 || toIndex === -1) return
  columnOrder.applyReorder(fromIndex, toIndex)
  columnReorderKey.value += 1
}

// DnD is offered on reports with no payment_schedule complications for now
// (the special PS cell renderer attaches absolutely-positioned footer labels
// that don't survive PrimeVue's drag overlay clone cleanly). Most reports
// don't carry payment_schedule, so the default is "DnD on".
const reorderableColumnsEnabled = computed<boolean>(() => {
  return columnOrder.displayColumns.value.every((col) => !isPaymentScheduleColumn(col))
})

// ─── Column manager popover (2026-05-21) ────────────────────────────────────
// Popover renders a flat sortable list of all columns (column groups were
// dropped from the product on 2026-05-21). Reorder + visibility writes
// flow through `columnOrder` (which persists via `useReportPreferences`).
const onColumnVisibilityToggle = (key: string, visible: boolean): void => {
  columnOrder.setColumnVisibility(key, visible)
}

const onBulkVisibilityToggle = (visible: boolean): void => {
  // Bulk show/hide. We don't try to coalesce these into a single PUT — the
  // shared preference store debounces writes, so N calls inside the same
  // tick become one PUT regardless.
  for (const col of columnOrder.displayColumns.value) {
    columnOrder.setColumnVisibility(col._key, visible)
  }
}

const onColumnManagerReorder = (payload: {
  dragIndex: number
  dropIndex: number
}): void => {
  columnOrder.applyReorder(payload.dragIndex, payload.dropIndex)
  // Bump the remount key so PrimeVue picks up the new order and doesn't
  // hold onto its internally-mutated column array (same reasoning as the
  // table-side `onColumnReorder` handler).
  columnReorderKey.value += 1
}

const onColumnManagerReset = (): void => {
  columnOrder.reset()
}

/**
 * Mapping from a visible-column's `_key` to its original index in
 * `tableColumns`. `linkRefs` is keyed by the original index (stable across
 * hide/show), so we look up the original slot when rendering links. Keyed by
 * `_key` (not `field`) because several columns can share a `field` — a
 * `field`-keyed map collapses them and the wrong link refs (the last column's)
 * would render for all of them.
 */
const originalColIndexByKey = computed<Map<string, number>>(() => {
  const map = new Map<string, number>()
  tableColumns.value.forEach((col, idx) => {
    map.set(col._key, idx)
  })
  return map
})

const getLinkRefByKey = (key: string, rowIndex: number) => {
  const idx = originalColIndexByKey.value.get(key) ?? -1
  if (idx === -1) return { href: null, label: '', isFallback: false }
  return getLinkRef(idx, rowIndex)
}

/**
 * Footer cells filtered + reordered to match the currently-visible table
 * columns (so the totals row aligns with the body columns after user
 * reorder / hide). When the user has not customised anything this is
 * effectively a no-op (`visibleTableColumns` already equals `tableColumns`).
 */
const visibleFooterCells = computed<FooterCell[]>(() => {
  const visibleKeys = visibleTableColumns.value.map((col) => col._key)
  if (visibleKeys.length === 0) return []

  // Map footer cells by `_key` for O(1) lookup, then walk visibleKeys to
  // reorder. Keyed by `_key` (not `field`) so duplicate-`field` columns each
  // get their own footer cell instead of collapsing onto the last one. Cells
  // without a corresponding footer entry are skipped (a column with no totals
  // contribution simply doesn't show one).
  const byKey = new Map(footerCells.value.map((cell) => [cell._key, cell]))
  const filtered: FooterCell[] = []
  for (const key of visibleKeys) {
    const cell = byKey.get(key)
    if (cell) filtered.push(cell)
  }
  if (filtered.length === 0) return filtered

  // Re-flag the first visible cell as isTotalsLabel if the original
  // totals-label-bearing cell isn't first anymore (column reorder / hide).
  const hadTotalsLabel = filtered.some((cell) => cell.isTotalsLabel)
  if (!hadTotalsLabel) {
    const first = filtered[0]
    if (first && (first.footer ?? '') === '' && !first.isPaymentSchedule) {
      filtered[0] = { ...first, isTotalsLabel: true }
    }
  }
  return filtered
})

/**
 * Returns the footer cell text for a given FooterCell entry.
 * payment_schedule columns are rendered via a #footer slot — this function is not called for them.
 * For all other columns, returns cell.footer (or the "Итого"/"Total" label for the first cell).
 */
const getFooterLabel = (cell: FooterCell): string => {
  return cell.isTotalsLabel ? t('totals') : cell.footer
}

const getLinkRef = (colIndex: number, rowIndex: number) =>
  linkRefs.value[colIndex]?.[rowIndex] ?? { href: null, label: '' }

// Badge helper for table rows
const getFlatBadge = (rowIndex: number, field: string) => {
  const row = tableData.value[rowIndex]
  if (!row) return null
  return resolveBadge(row as Record<string, unknown>, field)
}

let paginationResizeObserver: ResizeObserver | null = null

const updateHeaderPaginationLayout = () => {
  const container = headerPaginationRef.value
  const control = headerPaginationControlRef.value
  const info = headerPaginationInfoRef.value

  if (!container || !control || !info) {
    headerPaginationStacked.value = false
    return
  }

  if (window.innerWidth >= 1100) {
    headerPaginationStacked.value = false
    return
  }

  const containerWidth = container.clientWidth
  const requiredWidth = control.scrollWidth + info.scrollWidth + 12

  headerPaginationStacked.value = requiredWidth > containerWidth
}

const getFilterComponent = (type: ReportFilterType): Component | null => {
  switch (type) {
    case 'async_select':
      return AsyncSelectFilter
    case 'date_range':
      return DateRangeFilter
    case 'multiselect':
    case 'select':
      return SelectFilter
    case 'text':
      return TextFilter
    case 'number_range':
      return NumberRangeFilter
    default:
      return null
  }
}

watch(
  [
    showPagination,
    () => report.value?.meta?.page,
    () => report.value?.meta?.last_page,
    () => report.value?.meta?.per_page,
  ],
  async () => {
    await nextTick()
    updateHeaderPaginationLayout()
  },
  { immediate: true },
)

watch(headerPaginationRef, (element) => {
  if (!paginationResizeObserver) {
    return
  }

  paginationResizeObserver.disconnect()

  if (element) {
    paginationResizeObserver.observe(element)
  }
})

// ─── Actions menu handlers (publish / unpublish / delete) ────────────────
// `report` here is the page-level `ReportItem` (mapped via `mapReportToItem`
// inside the service). On publish/unpublish we only swap the few fields the
// backend can flip — keeping `rows / columns / meta / config` intact so we
// don't have to re-paginate or re-apply filters. On delete we navigate back
// to the list via `goBack` (which `useReportPageActions` already wires to
// `/reports`).
const onReportUpdated = (updated: Report): void => {
  if (!report.value) return
  // In-place mutation so dependent computeds (e.g. presentation, filters)
  // see the change without losing the loaded rows / pagination.
  report.value.is_published = updated.is_published
  report.value.updated_at = updated.updated_at
  // Author + created_at don't change on publish/unpublish — but pass them
  // through anyway in case the backend ever starts touching them.
  if (updated.author !== undefined) {
    report.value.author = updated.author
  }
  if (updated.created_at !== undefined) {
    report.value.created_at = updated.created_at
  }
}

const onReportDeleted = (_id: number): void => {
  // Same destination as the back button (see `useReportPageActions.goBack`).
  void goBack()
}

// ─── Toolbox top-offset override (P2 cleanup, 2026-05-21) ────────────────
// The fixed-position Toolbox lives in the top-right corner. On ReportPage
// there is a header strip in that same area (.report-header — back button,
// title, pagination, view-toggle, filter button), so the expanded Toolbox
// panel would horizontally overlap those controls. We drop the Toolbox
// below the header via the CSS custom properties `--toolbox-top-offset` /
// `--toolbox-top-offset-mobile` (read in Toolbox.vue as `top: var(...)`).
//
// The Toolbox is `position: fixed` AND a sibling of <main> in DefaultLayout,
// so a scoped style on `.report-detail-page` cannot reach it through the
// scoped-CSS cascade. Custom properties do cascade through the DOM tree —
// but Toolbox is *outside* this page's subtree. The clean workaround is
// to set the variables on `document.documentElement` from a lifecycle
// hook (scoped to ReportPage mount/unmount lifetime). Other pages don't
// touch these vars, so they fall back to the defaults (1rem / 0.5rem).
const applyToolboxOffsetForReportPage = (): void => {
  document.documentElement.style.setProperty('--toolbox-top-offset', '5rem')
  document.documentElement.style.setProperty('--toolbox-top-offset-mobile', '5rem')
}

const resetToolboxOffset = (): void => {
  document.documentElement.style.removeProperty('--toolbox-top-offset')
  document.documentElement.style.removeProperty('--toolbox-top-offset-mobile')
}

onMounted(() => {
  applyToolboxOffsetForReportPage()

  if (typeof ResizeObserver === 'undefined') {
    return
  }

  paginationResizeObserver = new ResizeObserver(() => {
    updateHeaderPaginationLayout()
  })

  if (headerPaginationRef.value) {
    paginationResizeObserver.observe(headerPaginationRef.value)
  }
})

// Publish the current report into the global Pinia store so out-of-page
// surfaces (Toolbox mini-chat widget) can auto-inject context into the first
// chat message. Cleared on unmount and when the report id changes — the
// pre-update value keeps no longer-mounted reports from leaking into chats
// opened on /reports or /ai-chat.
watch(
  [report, currentFilters, locale],
  () => {
    if (!report.value) {
      reportContextStore.clear()
      return
    }
    reportContextStore.set({
      reportId: report.value.id,
      title: localizedReportTitle.value || null,
      config: report.value.config ?? null,
      filtersApplied: currentFilters.value,
    })
  },
  { deep: true, immediate: true },
)

onBeforeUnmount(() => {
  paginationResizeObserver?.disconnect()
  paginationResizeObserver = null
  reportContextStore.clear()
  resetToolboxOffset()
})
</script>

<style lang="scss" scoped>
.report-detail-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  padding: 0.75rem;

  .report-card {
    background: $surface-0;
    border-radius: $card-border-radius;
    padding: 1rem;
    box-shadow: $shadow-md;
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow: hidden;

    .report-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1rem;
      flex-shrink: 0;

      .header-left {
        display: flex;
        align-items: center;
        gap: 1rem;
        min-width: 0;
        flex: 1;

        :deep(.p-button) {
          flex-shrink: 0;
          white-space: nowrap;
        }

        .report-title-block {
          display: flex;
          flex-direction: column;
          gap: 0.15rem;
          min-width: 0;
        }

        .report-title {
          margin: 0;
          font-size: $font-size-2xl;
          font-weight: $font-weight-semibold;
          color: $surface-900;
          min-width: 0;
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
        }

        .report-header-filters {
          display: flex;
          flex-wrap: wrap;
          align-items: center;
          gap: 0.4rem;
          min-width: 0;

          &__chip {
            display: inline-flex;
            align-items: baseline;
            gap: 0.25rem;
            padding: 0.1rem 0.5rem;
            border-radius: $radius-sm;
            background: $surface-100;
            font-size: $font-size-sm;
            color: $surface-700;
            white-space: nowrap;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
          }

          &__label {
            color: $surface-500;
          }

          &__value {
            font-weight: $font-weight-semibold;
          }
        }

        .report-header-primary {
          flex: 0 1 auto;
          min-width: 0;
        }
      }

      .header-right {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
        justify-content: flex-end;

        .header-pagination {
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 0.75rem;
          flex-wrap: nowrap;

          :deep(.p-paginator) {
            padding: 0;
            background: transparent;
            border: none;
            flex-wrap: nowrap;
            flex-shrink: 0;
          }

          &__control {
            flex-shrink: 0;
          }

          .pagination-info {
            font-size: $font-size-sm;
            color: $surface-600;
            white-space: nowrap;
          }

          &.is-stacked {
            flex-wrap: wrap;

            .pagination-info {
              flex-basis: 100%;
              text-align: center;
            }
          }
        }

        :deep(.p-button) {
          flex-shrink: 0;
          white-space: nowrap;
        }

        .filter-toggle-wrap {
          flex-shrink: 0;
        }
      }

      @media (max-width: 1100px) {
        .header-left,
        .header-right {
          flex: 0 0 100%;
          width: 100%;
        }

        .header-right {
          justify-content: flex-start;
          align-items: center;
        }
      }

      @media (max-width: 767px) {
        .header-right {
          .filter-toggle-wrap {
            flex-shrink: 0;
          }
        }
      }
    }

    .filter-inline {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      margin-top: 0.75rem;
      flex-shrink: 0;

      .filter-fields {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;

        .filter-field {
          min-width: 200px;
          flex: 1;
        }
      }

      .filter-buttons {
        display: flex;
        gap: 0.5rem;
      }
    }

    .filter-divider {
      border: none;
      border-top: 1px solid $surface-200;
      margin: 1rem 0;
      flex-shrink: 0;
    }

    .report-body {
      flex: 1;
      min-height: 0;
      display: flex;
      flex-direction: column;
      gap: 0;

      .table-mode {
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;
        margin-top: 1rem;
      }

      .data-section {
        padding: 0.5rem 0 0;
        flex: 1;
        min-height: 0;
        display: flex;
        flex-direction: column;

        // Multi-line link labels (label_lines config): preserve \n as line breaks.
        // Applied to both <a> and <span> inside link-type cells.
        // Link-type cells: brand primary colour instead of browser default blue.
        :deep(a.link-cell-label) {
          white-space: pre-line;
          color: var(--app-action-primary-bg);
          text-decoration: none;

          &:hover {
            color: var(--app-action-primary-hover);
            text-decoration: underline;
          }
        }

        :deep(span.link-cell-label) {
          white-space: pre-line;
        }

        // Fallback label: muted colour + italic to visually distinguish from real values.
        :deep(.link-cell-label--fallback) {
          color: var(--app-text-muted);
          font-style: italic;
        }

        // Badge-cell layout: badge inline to the right of the date value.
        // Spacing is enforced via an explicit margin on the badge rather than
        // a flex `gap` — PrimeVue's Badge resets margins and the gap did not
        // reliably hold, so the badge ("Nд") was rendering flush against the
        // date value (e.g. "31.12.2023874д").
        :deep(.badge-cell) {
          display: inline-flex;
          flex-direction: row;
          align-items: center;
        }

        :deep(.badge-cell__badge) {
          margin-left: 0.35rem;
        }

        // CRM-id cell: the WHOLE cell (<td>) is the clickable link target (#4).
        //
        // Approach = stretch the real <a> to fill the entire <td>. The previous
        // ::after-overlay-on-inline-flex attempt left dead corners (QA: a click
        // in empty zones / corners hit the bare <td>, not the link). Instead of
        // a pseudo-element, the <a> itself is the hit target:
        //   1. <td> (crm-id-cell-td) is the positioning context and has its cell
        //      padding zeroed, so the anchor reaches the literal corners.
        //   2. the wrapper <div> is collapsed (display:contents) so it does not
        //      sit between the <td> and the <a> as an intermediate box.
        //   3. the <a> is `position:absolute; inset:0` → it physically fills the
        //      td. elementFromPoint anywhere inside the cell returns the <a>.
        // inset:0 is strictly inside this td, so it never overlaps neighbouring
        // cells/rows. The cell padding is re-applied inside the anchor so the ID
        // text keeps its normal inset. When no href resolves we render a plain
        // (non-link) span, which keeps the normal cell padding via the fallback.
        :deep(.crm-id-cell-td) {
          // `position: relative` is REQUIRED: it makes this <td> the containing
          // block for the absolutely-positioned anchor inside
          // (`.crm-id-cell--link`, inset:0). Without it, `inset:0` resolves
          // against the nearest positioned ancestor (the table / scroll
          // wrapper) and the anchor physically stretches across the whole
          // table — including into the sticky-header zone — so the ID value
          // paints over the "ID объекта" header on vertical scroll. With
          // `position: relative` the anchor is scoped strictly to this cell.
          position: relative;
          // IMPORTANT: do NOT add an explicit `z-index` here. A positioned
          // <td> with `z-index: 0` (or any integer) creates a NEW stacking
          // context, and its absolutely-positioned child anchor then paints
          // as part of that context. In a scrolling table that promoted layer
          // could land above the sticky header. By leaving `z-index: auto`,
          // the <td> does not create a stacking context, the anchor paints in
          // normal document order, and the sticky header — which IS positioned
          // with a high z-index — always paints on top. The previous
          // `z-index: 0` attempt did not fix the bleed; removing the
          // competing stacking context plus raising the header z-index does.
          // Zero the body-cell padding so the stretched anchor can reach the
          // corners; padding is re-applied inside the anchor (and the in-flow
          // spacer) below. The spacer establishes the cell height.
          padding: 0 !important;
        }

        :deep(.crm-id-cell-wrapper) {
          // Collapse the wrapper so the spacer + <a> become direct layout
          // children of the <td> — the anchor's inset:0 then resolves against
          // the td itself, and the spacer drives the td height in normal flow.
          display: contents;
        }

        // In-flow, invisible, non-interactive copy of the ID value. It occupies
        // the exact same padded box as the stretched anchor, so the <td> always
        // has a real height to fill — the absolutely-positioned anchor can never
        // collapse the row, even with no taller sibling cell.
        :deep(.crm-id-cell-spacer) {
          display: block;
          padding: 0.5rem 0.75rem;
          line-height: 1.2;
          visibility: hidden;
          pointer-events: none;
          user-select: none;
        }

        :deep(.crm-id-cell__value) {
          line-height: 1.2;
        }

        // Plain (non-link) fallback span: no anchor, so restore the normal cell
        // padding that crm-id-cell-td removed, keeping the ID aligned with other
        // columns.
        :deep(.crm-id-cell:not(.crm-id-cell--link)) {
          display: block;
          padding: 0.5rem 0.75rem;
        }

        // Link variant: a block-level anchor stretched to fill the td. We
        // inherit the body text colour (the ID reads as a normal value, not a
        // blue link) and only tint the small icon — keeping the affordance
        // subtle while the full cell is clickable.
        :deep(.crm-id-cell--link) {
          // Physically fill the entire <td> — this is the click hit target.
          position: absolute;
          inset: 0;
          display: flex;
          // Vertically center the value + icon so the cell content lines up with
          // the other (vertical-align:middle) cells in the same row, even when a
          // sibling cell wraps to multiple lines and makes the row taller.
          align-items: center;
          // Small inline gap between the ID value and the trailing external-link
          // icon (the icon now sits inline next to the value, not pinned).
          gap: 0.3rem;
          // Re-apply the cell padding that crm-id-cell-td removed so the ID text
          // keeps its normal inset; the padding is part of the clickable area.
          padding: 0.5rem 0.75rem;
          color: inherit;
          text-decoration: none;
          cursor: pointer;
          transition: color 0.15s ease;

          // External-link icon: inline visual marker right after the value,
          // vertically centered with it. pointer-events:none so it never blocks
          // the click that the surrounding anchor already handles.
          .crm-id-cell__icon {
            flex: 0 0 auto;
            color: var(--app-action-primary-bg);
            font-size: 0.7rem;
            line-height: 1;
            transition: color 0.15s ease;
            pointer-events: none;
          }

          &:hover {
            color: var(--app-action-primary-hover);
            text-decoration: underline;

            .crm-id-cell__icon {
              color: var(--app-action-primary-hover);
            }
          }

          &:focus-visible {
            outline: 2px solid var(--app-action-primary-bg);
            outline-offset: -2px;
          }
        }

        :deep(.p-datatable) {
          flex: 1;
          min-height: 0;
          display: flex;
          flex-direction: column;

          .p-datatable-wrapper {
            flex: 1;
            min-height: 0;
            overflow: auto;
          }

          .p-datatable-thead > tr > th {
            position: sticky;
            top: 0;
            // Sticky header must paint ABOVE every tbody cell — including the
            // CRM-id link column, whose anchor is `position: absolute`. The
            // robust contract is: header z-index strictly greater than any
            // positioned element that can appear in the body. We use 11 (well
            // clear of the tfoot's z-index:1 and any incidental body
            // stacking) so the header always wins, and we make sure the body
            // crm-id <td> does NOT create a competing stacking context (see
            // `.crm-id-cell-td` — it keeps `z-index: auto`). z-index:3 alone
            // was not enough because the <td>'s former `z-index:0` promoted
            // the anchor into a layer that could paint over the header.
            z-index: 11;
            // Opaque background is what actually hides the scrolling rows.
            // $surface-50 resolves to #f9fafb (fully opaque) — kept explicit
            // as a hard stop so a future translucent surface token can never
            // let body rows show through the header.
            background: $surface-50;
            font-weight: $font-weight-semibold;
            color: $surface-700;
          }

          // Column-header `?` tooltip button (см. DEVELOPMENT_PLAN_CAPITALDATA §5).
          // Rendered as a real `<button type="button">` with an inner `<i>`
          // icon so screen-readers announce it and keyboard focus works
          // (a bare `<i>` with `v-tooltip` had hover-only behaviour and
          // could not receive focus). The button strips native chrome
          // (no background/border/padding) and inherits the same muted-
          // gray look the icon had previously. A11Y bits:
          //   - `type="button"` to prevent accidental form submit
          //   - `aria-label` = full description text (set in template)
          //   - `:focus-visible` outline for keyboard users
          //   - PrimeVue Tooltip directive shows on focus too, so the
          //     hint appears on Tab-through as well as hover.
          .column-header-tooltip-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-left: 0.35rem;
            padding: 0;
            border: 0;
            background: transparent;
            color: $surface-500;
            cursor: help;
            font-size: 0.85rem;
            line-height: 1;
            vertical-align: middle;
            border-radius: 2px;
            transition: color 0.15s ease;

            .pi {
              font-size: inherit;
              color: inherit;
            }

            &:hover,
            &:focus,
            &:focus-visible {
              color: $surface-700;
            }

            &:focus-visible {
              outline: 2px solid var(--app-action-primary-bg);
              outline-offset: 1px;
            }
          }

          .column-header-label {
            // No layout overrides — preserves PrimeVue's default header label
            // typography. Kept as a hook in case future iterations want to
            // reflow the label / icon vertically on narrow viewports.
          }

          // Reorderable-column affordance (PROJECT.md §column DnD, 2026-05-21).
          // PrimeVue applies `data-p-reorderable-column="true"` on every <th>
          // that can be dragged. We hint discoverability via `cursor: grab`
          // on hover (PrimeVue's own cursor only kicks in once the drag has
          // started). Sort icons keep their pointer cursor because clicking
          // them is the more common interaction; reorder is a deliberate
          // drag gesture and the grab-cursor only appears outside the sort
          // icon hit area.
          .p-datatable-thead > tr > th[data-p-reorderable-column="true"] {
            cursor: grab;

            &:active {
              cursor: grabbing;
            }

            .p-sortable-column-icon,
            .column-header-tooltip-icon {
              cursor: pointer;
            }
          }

          .p-datatable-tbody > tr:nth-child(odd) {
            background: $surface-50;
          }

          .p-datatable-tbody > tr:nth-child(even) {
            background: $surface-0;
          }

          .p-datatable-tfoot > tr > td {
            position: sticky;
            bottom: 0;
            z-index: 1;
            background: $surface-100;
            font-weight: $font-weight-semibold;
            color: $surface-800;
            border-top: 1px solid $surface-200;
          }
        }

        // payment_schedule footer styles (.ps-footer-row, .ps-footer-pair,
        // .ps-footer-label, .ps-footer-value) live in
        // src/assets/styles/_payment-schedule-footer.scss — global selector-only,
        // no parent-class flag on DataTable (PrimeVue v4 does not reliably forward
        // `class` to the root for our selector). See comment there for full reasoning.
      }
    }
  }
}
</style>
