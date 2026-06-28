<template>
  <div class="deals-list">
    <!-- KPI chips above table -->
    <DealsKpiChips :kpi="kpi" :loading="kpiLoading" />

    <DataTable
      :value="deals"
      :loading="loading"
      striped-rows
      lazy
      paginator
      :rows="perPage"
      :rows-per-page-options="[25, 50, 100]"
      :total-records="total"
      data-key="id"
      class="deals-list__table"
      @row-click="onRowClick"
      @page="emit('pageChange', $event)"
    >
      <!-- Empty state -->
      <template #empty>
        <div class="deals-list__empty">
          <template v-if="hasActiveFilters">
            <i class="pi pi-filter-slash deals-list__empty-icon" />
            <p class="deals-list__empty-title">{{ t('sales.deals.page.empty.filtered') }}</p>
            <p class="deals-list__empty-subtitle">{{ t('sales.deals.page.empty.filteredSubtitle') }}</p>
            <Button
              :label="t('sales.deals.page.empty.resetFilters')"
              severity="secondary"
              outlined
              @click="emit('resetFilters')"
            />
          </template>
          <template v-else>
            <i class="pi pi-briefcase deals-list__empty-icon" />
            <p class="deals-list__empty-title">{{ t('sales.deals.page.empty.title') }}</p>
            <p class="deals-list__empty-subtitle">{{ t('sales.deals.page.empty.subtitle') }}</p>
            <Button
              icon="pi pi-plus"
              :label="t('sales.deals.page.create')"
              @click="emit('create')"
            />
          </template>
        </div>
      </template>

      <!-- 1. Название (link) — auto width (flex remaining space) -->
      <Column field="title" style="min-width: 180px">
        <template #header>
          <button class="deals-list__sort-btn" @click="emit('sort', 'name')">
            {{ t('sales.deals.page.columns.title') }}
            <i :class="sortIcon('name')" class="deals-list__sort-icon" />
          </button>
        </template>
        <template #body="{ data }">
          <RouterLink :to="`/deals/${data.id}`" class="deals-list__link" @click.stop>
            {{ data.title }}
          </RouterLink>
        </template>
      </Column>

      <!-- 2. Страна — fixed 90px -->
      <Column style="width: 90px; min-width: 90px; max-width: 90px">
        <template #header>
          <button class="deals-list__sort-btn" @click="emit('sort', 'country')">
            {{ t('sales.deals.page.list.columns.country') }}
            <i :class="sortIcon('country')" class="deals-list__sort-icon" />
          </button>
        </template>
        <template #body="{ data }">
          <span class="deals-list__country">
            {{ countryName(data.country) }}
          </span>
        </template>
      </Column>

      <!-- 3. Сумма — fixed 130px, right-aligned data -->
      <Column style="width: 130px; min-width: 130px; max-width: 130px; text-align: right">
        <template #header>
          <button class="deals-list__sort-btn" @click="emit('sort', 'amount')">
            {{ t('sales.deals.page.columns.amount') }}
            <i :class="sortIcon('amount')" class="deals-list__sort-icon" />
          </button>
        </template>
        <template #body="{ data }">
          <span class="deals-list__amount">{{ formatCurrency(data.amount, data.currency) }}</span>
        </template>
      </Column>

      <!-- 4. Статус (stage chip with tint 22%) — fixed 150px -->
      <Column style="width: 150px; min-width: 150px; max-width: 150px">
        <template #header>
          <button class="deals-list__sort-btn" @click="emit('sort', 'stage')">
            {{ t('sales.deals.page.columns.stage') }}
            <i :class="sortIcon('stage')" class="deals-list__sort-icon" />
          </button>
        </template>
        <template #body="{ data }">
          <span
            v-if="data.stage"
            class="deals-list__stage-chip"
            :style="stageChipStyle(data.stage)"
          >
            <span class="deals-list__stage-dot" :style="{ background: data.stage.color ?? 'var(--p-surface-400)' }" />
            {{ stageIndex(data.stage) }}. {{ data.stage.name }}
          </span>
          <span v-else class="deals-list__stage-chip">—</span>
        </template>
      </Column>

      <!-- 5. В статусе (days in stage, plural) — fixed 110px -->
      <Column style="width: 110px; min-width: 110px; max-width: 110px">
        <template #header>
          <button class="deals-list__sort-btn" @click="emit('sort', 'days_in_stage')">
            {{ t('sales.deals.page.list.columns.inStage') }}
            <i :class="sortIcon('days_in_stage')" class="deals-list__sort-icon" />
          </button>
        </template>
        <template #body="{ data }">
          <span
            class="deals-list__days"
            :class="rottingClass(data)"
          >
            {{ daysInStageText(data) }}
          </span>
        </template>
      </Column>

      <!-- 6. Посл. контакт (freshness) — fixed 130px -->
      <Column style="width: 130px; min-width: 130px; max-width: 130px">
        <template #header>
          <button class="deals-list__sort-btn" @click="emit('sort', 'last_contact')">
            {{ t('sales.deals.page.list.columns.lastContact') }}
            <i :class="sortIcon('last_contact')" class="deals-list__sort-icon" />
          </button>
        </template>
        <template #body="{ data }">
          <span
            class="deals-list__freshness"
            :style="{ color: freshnessColor(data) }"
          >
            {{ freshnessText(data) }}
          </span>
        </template>
      </Column>

      <!-- 7. Задача (custom pills per spec §5.2) — fixed 120px -->
      <Column style="width: 120px; min-width: 120px; max-width: 120px">
        <template #header>
          <!-- Task column: no dedicated server sort key — maps to 'created' as nearest field -->
          <button class="deals-list__sort-btn" @click="emit('sort', 'created')">
            {{ t('sales.deals.page.columns.task') }}
            <i :class="sortIcon('created')" class="deals-list__sort-icon" />
          </button>
        </template>
        <template #body="{ data }">
          <!-- overdue → red pill -->
          <span
            v-if="data.next_task && data.next_task.is_overdue"
            class="deals-list__task-pill deals-list__task-pill--overdue"
          >
            {{ t('sales.deals.page.list.overdueLabel') }}
          </span>
          <!-- ok → icon + date -->
          <span
            v-else-if="data.next_task"
            class="deals-list__task-ok"
          >
            <i :class="taskTypeIcon(data.next_task.type)" class="deals-list__task-icon" />
            {{ taskDateShort(data.next_task.due_at) }}
          </span>
          <!-- no task → orange pill -->
          <span
            v-else
            class="deals-list__task-pill deals-list__task-pill--no-task"
          >
            {{ t('sales.deals.page.card.noTask') }}
          </span>
        </template>
      </Column>

      <!-- 8. Ответственный (avatar 22px + full name) — fixed 150px -->
      <Column style="width: 150px; min-width: 150px; max-width: 150px">
        <template #header>
          <button class="deals-list__sort-btn" @click="emit('sort', 'owner')">
            {{ t('sales.deals.page.columns.owner') }}
            <i :class="sortIcon('owner')" class="deals-list__sort-icon" />
          </button>
        </template>
        <template #body="{ data }">
          <div class="deals-list__owner">
            <span class="deals-list__owner-avatar">{{ ownerInitial(data.owner?.name ?? '') }}</span>
            <span class="deals-list__owner-name">{{ data.owner?.name ?? '—' }}</span>
          </div>
        </template>
      </Column>
    </DataTable>

    <!-- Total count -->
    <div v-if="total > 0" class="deals-list__total">
      {{ t('common.total', { count: total }) ?? `Итого: ${total}` }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRouter, RouterLink } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import { formatCurrency } from '@/utils/currency'
import { useDirectoriesStore } from '@/stores/directories'
import DealsKpiChips from './DealsKpiChips.vue'
import type { DealDto, ActivityType, PipelineStageDto, DealKpiDto } from '@/entities/sales'
import type { DealSortKey, DealSortState } from '../composables/useDealsList'

const props = defineProps<{
  deals: DealDto[]
  loading: boolean
  total: number
  perPage: number
  hasActiveFilters: boolean
  stages: PipelineStageDto[]
  kpi: DealKpiDto
  kpiLoading: boolean
  sortState: DealSortState
}>()

const emit = defineEmits<{
  pageChange: [event: { page: number; rows: number }]
  resetFilters: []
  create: []
  changeStage: [deal: DealDto]
  delete: [deal: DealDto]
  sort: [key: DealSortKey]
}>()

const { t } = useI18n()
const router = useRouter()
const directoriesStore = useDirectoriesStore()

function onRowClick(event: { data: DealDto }) {
  void router.push(`/deals/${event.data.id}`)
}

// ── Country code → display name ────────────────────────────────────────────────
// Resolves an ISO-2 country code (e.g. "kz") to a human-readable name using the
// countries directory already loaded by the page. Falls back to uppercased code.
function countryName(code: string | null | undefined): string {
  if (!code) return '—'
  return directoriesStore.getCountryName(code) || code.toUpperCase()
}

// ── Sort icon helper ────────────────────────────────────────────────────────────

function sortIcon(key: DealSortKey): string {
  if (props.sortState.sortBy !== key) return 'pi pi-sort-alt'
  return props.sortState.sortDir === 'asc' ? 'pi pi-sort-amount-up' : 'pi pi-sort-amount-down'
}

// ── Stage chip style (tint 22%) ────────────────────────────────────────────────

function stageChipStyle(stage: PipelineStageDto): Record<string, string> {
  const color = stage.color
  if (!color) return {}
  return {
    backgroundColor: `color-mix(in srgb, ${color} 22%, var(--mg-surface-card))`,
  }
}

// ── Stage index (1-based) from props.stages ────────────────────────────────────

function stageIndex(stage: PipelineStageDto): number {
  const idx = props.stages.findIndex((s) => s.id === stage.id)
  return idx >= 0 ? idx + 1 : 1
}

// ── Days in stage (с правильным склонением) ────────────────────────────────────

const FALLBACK_WARN_DAYS = 7
const FALLBACK_DANGER_DAYS = 14

function effectiveDays(deal: DealDto): number {
  if (deal.days_in_stage != null) return deal.days_in_stage
  if (!deal.stage_changed_at) return 0
  return Math.floor((Date.now() - new Date(deal.stage_changed_at).getTime()) / 86400000)
}

function daysInStageText(deal: DealDto): string {
  const n = effectiveDays(deal)
  // Russian plural: 1 → день, 2-4 → дня, 5+ → дней
  const mod10 = n % 10
  const mod100 = n % 100
  let key: string
  if (mod100 >= 11 && mod100 <= 19) {
    key = 'sales.deals.page.list.daysInStage_many'
  } else if (mod10 === 1) {
    key = 'sales.deals.page.list.daysInStage_one'
  } else if (mod10 >= 2 && mod10 <= 4) {
    key = 'sales.deals.page.list.daysInStage_few'
  } else {
    key = 'sales.deals.page.list.daysInStage_many'
  }
  return t(key, { n })
}

function rottingClass(deal: DealDto): string {
  const days = effectiveDays(deal)
  const warnDays = deal.stage?.warn_days ?? FALLBACK_WARN_DAYS
  const dangerDays = deal.stage?.danger_days ?? FALLBACK_DANGER_DAYS
  if (days >= dangerDays) return 'deals-list__days--rotting'
  if (days >= warnDays) return 'deals-list__days--warn'
  return ''
}

// ── Freshness (last_contact_at) §5.3 ──────────────────────────────────────────

function freshnessColor(deal: DealDto): string {
  if (!deal.last_contact_at) return 'var(--p-surface-500)'
  const days = Math.floor((Date.now() - new Date(deal.last_contact_at).getTime()) / 86400000)
  const isOverdue = deal.next_task?.is_overdue ?? false
  if (isOverdue || days >= 21) return 'var(--p-red-600)'
  if (days >= 7) return 'var(--p-orange-700)'
  return 'var(--p-green-700)'
}

function freshnessText(deal: DealDto): string {
  if (!deal.last_contact_at) return '—'
  const days = Math.floor((Date.now() - new Date(deal.last_contact_at).getTime()) / 86400000)
  if (days <= 1) return t('sales.deals.page.list.today')
  return t('sales.deals.page.list.daysAgo', { n: days })
}

// ── Owner ──────────────────────────────────────────────────────────────────────

function ownerInitial(name: string): string {
  return name.trim().charAt(0).toUpperCase()
}

// ── Task health helpers ────────────────────────────────────────────────────────

function taskTypeIcon(type: ActivityType): string {
  const map: Record<ActivityType, string> = {
    call: 'pi pi-phone',
    meeting: 'pi pi-calendar',
    task: 'pi pi-check-square',
    note: 'pi pi-file-edit',
    follow_up: 'pi pi-arrow-right-arrow-left',
    presentation: 'pi pi-desktop',
  }
  return map[type] ?? 'pi pi-check-square'
}

function taskDateShort(dueAt: string | null): string {
  if (!dueAt) return ''
  const d = new Date(dueAt)
  const day = String(d.getDate()).padStart(2, '0')
  const month = String(d.getMonth() + 1).padStart(2, '0')
  return `${day}.${month}`
}
</script>

<style lang="scss" scoped>
.deals-list {
  background: transparent;
}

:deep(.p-datatable) {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid var(--p-surface-200);
  box-shadow: $shadow-card;
  overflow: hidden;
}

.deals-list__table {
  width: 100%;
  cursor: pointer;

  // L2: fixed layout so opening the filter panel NEVER reflows column widths
  :deep(table) {
    table-layout: fixed;
    width: 100%;
  }
}

:deep(.p-datatable-thead th) {
  // L1: bigger, bolder, near-black header text
  font-size: $font-size-sm;
  font-weight: $font-weight-bold;
  color: $surface-900;
  background: $surface-card;
  // Remove default PrimeVue header padding so our button fills it
  padding: 0 !important;
  // L1: center header labels over columns
  text-align: center;

  .app-dark & {
    color: var(--p-surface-900); // dark surface-900 = #F9FAFB — near-white on dark bg
  }
}

// Sort button inside each column header
.deals-list__sort-btn {
  // L1: match th font spec + centered
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: $space-1;
  width: 100%;
  padding: $space-2 $space-3;
  background: none;
  border: none;
  cursor: pointer;
  font-size: $font-size-sm;
  font-weight: $font-weight-bold;
  color: $surface-900;
  text-align: center;
  white-space: nowrap;
  transition: color 0.15s;

  &:hover {
    color: $primary-color;

    .app-dark & {
      color: var(--p-primary-300);
    }
  }

  .app-dark & {
    color: var(--p-surface-900);
  }
}

.deals-list__sort-icon {
  font-size: $font-size-2xs;
  flex-shrink: 0;
  color: $surface-400;
  transition: color 0.15s;

  // Active sort icon gets primary color
  .pi-sort-amount-up &,
  .pi-sort-amount-down & {
    color: var(--p-primary-color);
  }

  .app-dark & {
    color: var(--p-surface-500);
  }
}

// Override: when the button contains an active sort icon, highlight icon
:deep(.p-datatable-thead th) .deals-list__sort-btn .pi-sort-amount-up,
:deep(.p-datatable-thead th) .deals-list__sort-btn .pi-sort-amount-down {
  color: var(--p-primary-color);
}

.deals-list__link {
  color: $primary-color;
  font-weight: $font-weight-medium;
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }
}

.deals-list__country {
  font-size: $font-size-xs;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.deals-list__amount {
  font-weight: $font-weight-bold;
  color: $primary-color;
}

// Stage chip (tint 22%)
.deals-list__stage-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px;
  border-radius: $radius-sm;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-800;
  // background set by :style color-mix (uses --mg-surface-card for proper tint base)

  .app-dark & {
    color: var(--p-surface-900); // dark surface-900 = #F9FAFB — readable on color-mix tint bg
  }
}

.deals-list__stage-dot {
  width: 7px;
  height: 7px;
  border-radius: $radius-circle;
  flex-shrink: 0;
}

// Days in stage
.deals-list__days {
  font-size: $font-size-xs;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }

  &--warn {
    color: var(--p-orange-500);
  }

  &--rotting {
    color: $color-danger;
    font-weight: $font-weight-medium;
  }
}

// Freshness (color set inline via :style)
.deals-list__freshness {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
}

// Task column — custom pills per §5.2
.deals-list__task-pill {
  display: inline-flex;
  align-items: center;
  padding: 4px 9px;
  border-radius: $radius-sm;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  white-space: nowrap;

  &--overdue {
    background: var(--p-red-50);
    color: var(--p-red-700);

    .app-dark & {
      background: rgba(200, 50, 50, 0.2);
      color: var(--p-red-300);
    }
  }

  &--no-task {
    background: var(--p-orange-50);
    color: var(--p-orange-900);

    .app-dark & {
      background: rgba(200, 120, 30, 0.2);
      color: var(--p-orange-300);
    }
  }
}

.deals-list__task-ok {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  color: $surface-600;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-300);
  }
}

.deals-list__task-icon {
  font-size: $font-size-xs;
  flex-shrink: 0;
}

// Owner cell
.deals-list__owner {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.deals-list__owner-avatar {
  width: 22px;
  height: 22px;
  border-radius: $radius-circle;
  background: var(--p-primary-color);
  color: $surface-0;
  font-size: $font-size-2xs;
  font-weight: $font-weight-semibold;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.deals-list__owner-name {
  font-size: $font-size-xs;
  color: $surface-600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-300);
  }
}

.deals-list__total {
  padding: $space-2 $space-4;
  font-size: $font-size-sm;
  color: $surface-500;
  text-align: right;
  border-top: 1px solid $surface-100;

  .app-dark & {
    border-top-color: var(--p-surface-700);
  }
}

.deals-list__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
}

.deals-list__empty-icon {
  font-size: $font-size-icon-2xl;
  color: $surface-400;
}

.deals-list__empty-title {
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  margin: 0;
}

.deals-list__empty-subtitle {
  font-size: $font-size-sm;
  color: $surface-400;
  margin: 0;
}
</style>
