<template>
  <div class="deals-list">
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

      <!-- # -->
      <Column field="id" :header="t('sales.deals.page.columns.id')" style="width: 60px" />

      <!-- Title -->
      <Column field="title" :header="t('sales.deals.page.columns.title')" sortable>
        <template #body="{ data }">
          <RouterLink :to="`/deals/${data.id}`" class="deals-list__link" @click.stop>
            {{ data.title }}
          </RouterLink>
        </template>
      </Column>

      <!-- Company -->
      <Column field="company.name" :header="t('sales.deals.page.columns.company')" sortable>
        <template #body="{ data }">
          <RouterLink
            :to="`/companies/${data.company.id}`"
            class="deals-list__link"
            @click.stop
          >
            {{ data.company.name }}
          </RouterLink>
        </template>
      </Column>

      <!-- Stage -->
      <Column :header="t('sales.deals.page.columns.stage')">
        <template #body="{ data }">
          <span
            class="deals-list__stage-tag"
            :style="{
              backgroundColor: data.stage?.color ?? 'var(--p-surface-400)',
              color: '#ffffff',
            }"
          >
            {{ data.stage?.name ?? '—' }}
          </span>
        </template>
      </Column>

      <!-- Task health -->
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

      <!-- Days in stage -->
      <Column :header="t('sales.deals.page.columns.daysInStage')" style="width: 100px">
        <template #body="{ data }">
          <span
            class="deals-list__days"
            :class="rottingClass(data)"
          >
            {{ effectiveDays(data) }}
          </span>
        </template>
      </Column>

      <!-- Amount -->
      <Column :header="t('sales.deals.page.columns.amount')" sortable style="text-align: right">
        <template #body="{ data }">
          <span class="deals-list__amount">{{ formatCurrency(data.amount, data.currency) }}</span>
        </template>
      </Column>

      <!-- Owner -->
      <Column field="owner.name" :header="t('sales.deals.page.columns.owner')" />

      <!-- Created -->
      <Column field="created_at" :header="t('sales.deals.page.columns.createdAt')" sortable>
        <template #body="{ data }">
          {{ formatDate(data.created_at) }}
        </template>
      </Column>

      <!-- Actions -->
      <Column :header="t('sales.deals.page.columns.actions')" style="width: 50px" :frozen="true" align-frozen="right">
        <template #body="{ data }">
          <Button
            icon="pi pi-ellipsis-v"
            text
            severity="secondary"
            size="small"
            @click.stop="openMenu($event, data)"
          />
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
import type { DealDto, ActivityType } from '@/entities/sales'

defineProps<{
  deals: DealDto[]
  loading: boolean
  total: number
  perPage: number
  hasActiveFilters: boolean
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
  {
    separator: true,
  },
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

function formatDate(dateStr: string | null): string {
  if (!dateStr) return '—'
  const d = new Date(dateStr)
  const day = String(d.getDate()).padStart(2, '0')
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const year = d.getFullYear()
  return `${day}.${month}.${year}`
}

// ── Task health helpers ────────────────────────────────────────────────────────

function taskTypeIcon(type: ActivityType): string {
  const map: Record<ActivityType, string> = {
    call: 'pi pi-phone',
    meeting: 'pi pi-calendar',
    task: 'pi pi-check-square',
    note: 'pi pi-file-edit',
    follow_up: 'pi pi-arrow-right-arrow-left',
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

// ── Rotting helpers ────────────────────────────────────────────────────────────

function effectiveDays(deal: DealDto & { days_in_stage?: number | null }): string {
  const days = deal.days_in_stage != null
    ? deal.days_in_stage
    : deal.stage_changed_at
      ? Math.floor((Date.now() - new Date(deal.stage_changed_at).getTime()) / 86400000)
      : 0
  return String(days)
}

const FALLBACK_WARN_DAYS = 7
const FALLBACK_DANGER_DAYS = 14

function rottingClass(deal: DealDto & { days_in_stage?: number | null }): string {
  const days = deal.days_in_stage != null
    ? deal.days_in_stage
    : deal.stage_changed_at
      ? Math.floor((Date.now() - new Date(deal.stage_changed_at).getTime()) / 86400000)
      : 0
  const warnDays = deal.stage?.warn_days ?? FALLBACK_WARN_DAYS
  const dangerDays = deal.stage?.danger_days ?? FALLBACK_DANGER_DAYS
  if (days >= dangerDays) return 'deals-list__days--rotting'
  if (days >= Math.floor(warnDays * 0.7)) return 'deals-list__days--warn'
  return ''
}
</script>

<style lang="scss" scoped>
.deals-list {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  overflow: hidden;

  :global(.app-dark) & {
    border-color: var(--p-surface-700);
  }
}

.deals-list__table {
  width: 100%;
  cursor: pointer;
}

.deals-list__link {
  color: $primary-color;
  text-decoration: none;
  &:hover {
    text-decoration: underline;
  }
}

.deals-list__stage-tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: $radius-sm;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  white-space: nowrap;
}

.deals-list__amount {
  font-weight: $font-weight-semibold;
  color: $primary-color;
}

.deals-list__total {
  padding: $space-2 $space-4;
  font-size: $font-size-sm;
  color: $surface-500;
  text-align: right;
  border-top: 1px solid $surface-100;

  :global(.app-dark) & {
    border-top-color: var(--p-surface-700);
  }
}

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

.deals-list__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
}

.deals-list__empty-icon {
  font-size: 3rem;
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
