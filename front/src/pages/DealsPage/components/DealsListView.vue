<template>
  <div class="deals-list">
    <!-- KPI chips above table -->
    <DealsKpiChips :deals="deals" />

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
      sort-icon="pi pi-sort-alt"
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

      <!-- 1. Название (link) -->
      <Column field="title" :header="t('sales.deals.page.columns.title')" sortable>
        <template #body="{ data }">
          <RouterLink :to="`/deals/${data.id}`" class="deals-list__link" @click.stop>
            {{ data.title }}
          </RouterLink>
        </template>
      </Column>

      <!-- 2. Страна -->
      <Column :header="t('sales.deals.page.list.columns.country')" style="width: 80px">
        <template #body="{ data }">
          <span class="deals-list__country">
            {{ data.country ? data.country.toUpperCase() : '—' }}
          </span>
        </template>
      </Column>

      <!-- 3. Сумма -->
      <Column :header="t('sales.deals.page.columns.amount')" sortable style="text-align: right; width: 120px">
        <template #body="{ data }">
          <span class="deals-list__amount">{{ formatCurrency(data.amount, data.currency) }}</span>
        </template>
      </Column>

      <!-- 4. Статус (stage chip with tint) -->
      <Column :header="t('sales.deals.page.columns.stage')">
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

      <!-- 5. В статусе (days in stage) -->
      <Column :header="t('sales.deals.page.list.columns.inStage')" style="width: 100px">
        <template #body="{ data }">
          <span
            class="deals-list__days"
            :class="rottingClass(data)"
          >
            {{ daysInStageText(data) }}
          </span>
        </template>
      </Column>

      <!-- 6. Посл. контакт (freshness) -->
      <Column :header="t('sales.deals.page.list.columns.lastContact')" style="width: 120px">
        <template #body="{ data }">
          <span
            class="deals-list__freshness"
            :style="{ color: freshnessColor(data) }"
          >
            {{ freshnessText(data) }}
          </span>
        </template>
      </Column>

      <!-- 7. Задача -->
      <Column :header="t('sales.deals.page.columns.task')">
        <template #body="{ data }">
          <Tag
            v-if="data.next_task && !data.next_task.is_overdue"
            severity="secondary"
            :value="taskTagLabel(data.next_task)"
            :icon="taskTypeIcon(data.next_task.type)"
          />
          <Tag
            v-else-if="data.next_task && data.next_task.is_overdue"
            severity="danger"
            :value="t('sales.deals.page.card.overdue', { type: taskTypeLabel(data.next_task.type), when: '' }).trim()"
          />
          <Tag
            v-else
            severity="warn"
            :value="t('sales.deals.page.card.noTask')"
          />
        </template>
      </Column>

      <!-- 8. Ответственный (avatar + name) -->
      <Column field="owner.name" :header="t('sales.deals.page.columns.owner')" style="width: 140px">
        <template #body="{ data }">
          <div class="deals-list__owner">
            <span class="deals-list__owner-avatar">{{ ownerInitial(data.owner.name) }}</span>
            <span class="deals-list__owner-name">{{ data.owner.name }}</span>
          </div>
        </template>
      </Column>

      <!-- 9. Действия (kebab menu) -->
      <Column style="width: 44px; padding: 0 6px" :header="''">
        <template #body="{ data }">
          <button
            type="button"
            class="deals-list__row-menu-btn"
            :title="t('common.actions')"
            @click.stop="openMenu($event, data)"
          >
            <i class="pi pi-ellipsis-v" />
          </button>
        </template>
      </Column>
    </DataTable>

    <!-- Row action menu -->
    <Menu ref="rowMenu" :model="rowMenuItems" popup />

    <!-- Total count -->
    <div v-if="total > 0" class="deals-list__total">
      {{ t('common.total', { count: total }) ?? `Итого: ${total}` }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, RouterLink } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import Tag from 'primevue/tag'
import { formatCurrency } from '@/utils/currency'
import DealsKpiChips from './DealsKpiChips.vue'
import type { DealDto, ActivityType, PipelineStageDto } from '@/entities/sales'

const props = defineProps<{
  deals: DealDto[]
  loading: boolean
  total: number
  perPage: number
  hasActiveFilters: boolean
  stages: PipelineStageDto[]
}>()

const emit = defineEmits<{
  pageChange: [event: { page: number; rows: number }]
  resetFilters: []
  create: []
  changeStage: [deal: DealDto]
  delete: [deal: DealDto]
}>()

const { t } = useI18n()
const router = useRouter()

const rowMenu = ref<InstanceType<typeof Menu> | null>(null)
const activeDeal = ref<DealDto | null>(null)

const rowMenuItems = ref([
  {
    label: t('sales.deals.page.actions.edit'),
    icon: 'pi pi-pencil',
    command: () => {
      if (activeDeal.value) void router.push(`/deals/${activeDeal.value.id}`)
    },
  },
  {
    label: t('sales.deals.page.actions.changeStage'),
    icon: 'pi pi-arrows-h',
    command: () => {
      if (activeDeal.value) emit('changeStage', activeDeal.value)
    },
  },
  { separator: true },
  {
    label: t('sales.deals.page.actions.delete'),
    icon: 'pi pi-trash',
    class: 'text-danger',
    command: () => {
      if (activeDeal.value) emit('delete', activeDeal.value)
    },
  },
])

function openMenu(event: MouseEvent, deal: DealDto) {
  activeDeal.value = deal
  rowMenu.value?.toggle(event)
}

function onRowClick(event: { data: DealDto }) {
  void router.push(`/deals/${event.data.id}`)
}

// ── Stage chip style (tint 22%) ────────────────────────────────────────────────

function stageChipStyle(stage: PipelineStageDto): Record<string, string> {
  const color = stage.color
  if (!color) return {}
  return {
    backgroundColor: `color-mix(in srgb, ${color} 22%, var(--p-surface-card))`,
  }
}

// ── Stage index (1-based) from props.stages ────────────────────────────────────

function stageIndex(stage: PipelineStageDto): number {
  const idx = props.stages.findIndex((s) => s.id === stage.id)
  return idx >= 0 ? idx + 1 : 1
}

// ── Days in stage ──────────────────────────────────────────────────────────────

const FALLBACK_WARN_DAYS = 7
const FALLBACK_DANGER_DAYS = 14

function effectiveDays(deal: DealDto): number {
  if (deal.days_in_stage != null) return deal.days_in_stage
  if (!deal.stage_changed_at) return 0
  return Math.floor((Date.now() - new Date(deal.stage_changed_at).getTime()) / 86400000)
}

function daysInStageText(deal: DealDto): string {
  const d = effectiveDays(deal)
  return t('sales.deals.page.list.daysAgo', { n: d })
}

function rottingClass(deal: DealDto): string {
  const days = effectiveDays(deal)
  const warnDays = deal.stage?.warn_days ?? FALLBACK_WARN_DAYS
  const dangerDays = deal.stage?.danger_days ?? FALLBACK_DANGER_DAYS
  if (days >= dangerDays) return 'deals-list__days--rotting'
  if (days >= Math.floor(warnDays * 0.7)) return 'deals-list__days--warn'
  return ''
}

// ── Freshness (last_contact_at) ────────────────────────────────────────────────

function freshnessColor(deal: DealDto): string {
  if (!deal.last_contact_at) return 'var(--p-surface-400)'
  const days = Math.floor((Date.now() - new Date(deal.last_contact_at).getTime()) / 86400000)
  const isOverdue = deal.next_task?.is_overdue ?? false
  if (isOverdue || days >= 21) return 'var(--p-red-600)'
  if (days >= 7) return 'var(--p-orange-600)'
  return 'var(--p-green-600)'
}

function freshnessText(deal: DealDto): string {
  if (!deal.last_contact_at) return '—'
  const days = Math.floor((Date.now() - new Date(deal.last_contact_at).getTime()) / 86400000)
  if (days <= 0) return t('sales.deals.page.list.today')
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

function taskTypeLabel(type: ActivityType): string {
  return t(`sales.deals.page.taskTypes.${type}`)
}

function taskTagLabel(nextTask: { type: ActivityType; due_at: string | null }): string {
  if (!nextTask.due_at) return taskTypeLabel(nextTask.type)
  const d = new Date(nextTask.due_at)
  const day = String(d.getDate()).padStart(2, '0')
  const month = String(d.getMonth() + 1).padStart(2, '0')
  return `${taskTypeLabel(nextTask.type)} · ${day}.${month}`
}

// ── Country display ────────────────────────────────────────────────────────────
</script>

<style lang="scss" scoped>
.deals-list {
  background: transparent;
}

// KPI chips wrapper handled by DealsKpiChips itself

.deals-list__table-wrap {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid var(--p-surface-200);
  box-shadow: $shadow-card;
  overflow: hidden;
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
}

:deep(.p-datatable-thead th) {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }
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
  font-weight: $font-weight-medium;
  letter-spacing: 0.03em;
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
  // background set by :style color-mix

  .app-dark & {
    color: var(--p-surface-100);
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

// Row kebab action button
.deals-list__row-menu-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: none;
  background: transparent;
  border-radius: $radius-sm;
  color: $surface-400;
  cursor: pointer;
  transition: background var(--app-transition-fast), color var(--app-transition-fast);

  i {
    font-size: $font-size-sm;
  }

  &:hover {
    background: var(--p-surface-100);
    color: $surface-700;

    .app-dark & {
      background: var(--p-surface-100);
      color: var(--p-surface-200);
    }
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
