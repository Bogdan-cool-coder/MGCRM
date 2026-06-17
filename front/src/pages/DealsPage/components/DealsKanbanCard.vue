<template>
  <div
    class="kanban-card"
    :class="[
      healthClass,
      { 'kanban-card--overdue': health === 'overdue' },
      { 'kanban-card--dragging': isDragging },
      { 'kanban-card--selected': isSelected },
    ]"
    @click="onClick"
  >
    <!-- Bulk checkbox -->
    <div v-if="bulkMode" class="kanban-card__checkbox" @click.stop>
      <Checkbox :model-value="isSelected" binary @update:model-value="onSelectToggle" />
    </div>

    <!-- Body -->
    <div class="kanban-card__body">
      <!-- Title -->
      <div
        class="kanban-card__title"
        :title="card.title"
        @dblclick.stop="startEdit"
      >
        <template v-if="!isEditing">{{ card.title }}</template>
        <InputText
          v-else
          v-model="editTitle"
          class="kanban-card__title-input"
          @blur="submitEdit"
          @keydown="onEditKeydown"
          @click.stop
        />
      </div>

      <!-- Amount + product chip -->
      <div class="kanban-card__amount-row">
        <span class="kanban-card__amount">{{ formatCurrency(card.amount, card.currency) }}</span>
        <span v-if="card.primary_product" class="kanban-card__product-chip" :title="card.primary_product.name">
          <i class="pi pi-box kanban-card__product-icon" />
          <span class="kanban-card__product-name">{{ card.primary_product.name }}</span>
        </span>
      </div>

      <!-- Manager row -->
      <div class="kanban-card__meta-row">
        <span class="kanban-card__owner">
          <span class="kanban-card__avatar">{{ ownerInitial }}</span>
          <span class="kanban-card__owner-name">{{ shortName(card.owner.name) }}</span>
        </span>
        <span
          class="kanban-card__days"
          :class="rottingClass"
        >
          <i class="pi pi-clock kanban-card__days-icon" />
          {{ t('sales.deals.page.card.daysInWork', { n: effectiveDaysInStage }) }}
        </span>
        <button
          class="kanban-card__quick-add"
          :title="t('activity.quickAdd.tooltip')"
          type="button"
          @click.stop="onQuickAdd"
        >
          <i class="pi pi-plus" />
        </button>
      </div>
    </div>

    <!-- Health bar (bottom strip) -->
    <div class="kanban-card__health-strip" :class="healthStripClass">
      <!-- ok: has task, on time -->
      <template v-if="health === 'ok' && card.next_task">
        <i :class="['kanban-card__task-icon', taskTypeIcon(card.next_task.type)]" />
        <span class="kanban-card__task-text">{{ formatTaskDate(card.next_task.due_at) }}</span>
      </template>
      <!-- no-task -->
      <template v-else-if="health === 'no-task'">
        <span class="kanban-card__task-text kanban-card__task-text--muted">
          {{ t('sales.deals.page.card.noTask') }}
        </span>
        <button class="kanban-card__schedule-btn" type="button" @click.stop="onScheduleTask">
          {{ t('sales.deals.page.card.scheduleTask') }}
        </button>
      </template>
      <!-- overdue -->
      <template v-else-if="health === 'overdue' && card.next_task">
        <i :class="['kanban-card__task-icon', 'kanban-card__task-icon--danger', taskTypeIcon(card.next_task.type)]" />
        <span class="kanban-card__task-text kanban-card__task-text--danger">
          {{ t('sales.deals.page.card.overdue', { type: taskTypeLabel(card.next_task.type), when: overdueWhen }) }}
        </span>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import InputText from 'primevue/inputtext'
import Checkbox from 'primevue/checkbox'
import { formatCurrency } from '@/utils/currency'
import { useActivityStore } from '@/stores/activityStore'
import { useSalesStore } from '@/stores/salesStore'
import type { DealCardDto, ActivityType, PipelineStageDto } from '@/entities/sales'

const props = defineProps<{
  card: DealCardDto
  isDragging?: boolean
  stage?: PipelineStageDto | null
}>()

const emit = defineEmits<{
  titleChange: [cardId: number, title: string]
}>()

const { t } = useI18n()
const router = useRouter()
const activityStore = useActivityStore()
const salesStore = useSalesStore()

// ── Bulk mode ──────────────────────────────────────────────────────────────────

const bulkMode = computed(() => salesStore.bulkMode)
const isSelected = computed(() => salesStore.bulkSelection.includes(props.card.id))

function onSelectToggle() {
  salesStore.toggleBulkItem(props.card.id)
}

// ── Inline edit ────────────────────────────────────────────────────────────────

const isEditing = ref(false)
const editTitle = ref('')

function startEdit() {
  isEditing.value = true
  editTitle.value = props.card.title
}

function submitEdit() {
  const trimmed = editTitle.value.trim()
  if (trimmed && trimmed !== props.card.title) {
    emit('titleChange', props.card.id, trimmed)
  }
  isEditing.value = false
}

function cancelEdit() {
  isEditing.value = false
}

function onEditKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter') {
    event.preventDefault()
    submitEdit()
  } else if (event.key === 'Escape') {
    event.preventDefault()
    cancelEdit()
  }
}

// ── Rotting ────────────────────────────────────────────────────────────────────

const FALLBACK_WARN_DAYS = 7
const FALLBACK_DANGER_DAYS = 14

const effectiveDaysInStage = computed(() => {
  if (props.card.days_in_stage != null) return props.card.days_in_stage
  if (!props.card.stage_changed_at) return 0
  return Math.floor((Date.now() - new Date(props.card.stage_changed_at).getTime()) / 86400000)
})

const warnDays = computed(() => props.stage?.warn_days ?? FALLBACK_WARN_DAYS)
const dangerDays = computed(() => props.stage?.danger_days ?? FALLBACK_DANGER_DAYS)

const rottingClass = computed(() => {
  const d = effectiveDaysInStage.value
  if (d >= dangerDays.value) return 'kanban-card__days--rotting'
  if (d >= Math.floor(warnDays.value * 0.7)) return 'kanban-card__days--warn'
  return ''
})

// ── Health signal ──────────────────────────────────────────────────────────────

type Health = 'ok' | 'no-task' | 'overdue'

const health = computed((): Health => {
  const task = props.card.next_task
  if (!task) return 'no-task'
  if (task.is_overdue) return 'overdue'
  return 'ok'
})

const healthClass = computed(() => {
  switch (health.value) {
    case 'no-task': return 'kanban-card--no-task'
    case 'overdue': return 'kanban-card--overdue-health'
    default: return ''
  }
})

const healthStripClass = computed(() => {
  switch (health.value) {
    case 'no-task': return 'kanban-card__health-strip--warning'
    case 'overdue': return 'kanban-card__health-strip--danger'
    default: return 'kanban-card__health-strip--neutral'
  }
})

// ── Task type helpers ──────────────────────────────────────────────────────────

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

// ── Date formatting ────────────────────────────────────────────────────────────

function formatTaskDate(dueAt: string | null): string {
  if (!dueAt) return ''
  const d = new Date(dueAt)
  const now = new Date()
  const todayStr = now.toDateString()
  const tomorrow = new Date(now)
  tomorrow.setDate(tomorrow.getDate() + 1)
  const hhmm = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`

  if (d.toDateString() === todayStr) {
    return t('sales.deals.page.card.today', { time: hhmm })
  }
  if (d.toDateString() === tomorrow.toDateString()) {
    return t('sales.deals.page.card.tomorrow', { time: hhmm })
  }
  const day = String(d.getDate()).padStart(2, '0')
  const month = String(d.getMonth() + 1).padStart(2, '0')
  if (d.getFullYear() === now.getFullYear()) {
    return `${day}.${month} ${hhmm}`
  }
  const yy = String(d.getFullYear()).slice(-2)
  return `${day}.${month}.${yy}`
}

const overdueWhen = computed(() => {
  const task = props.card.next_task
  if (!task?.due_at) return ''
  const d = new Date(task.due_at)
  const diffDays = Math.floor((Date.now() - d.getTime()) / 86400000)
  return t('sales.deals.page.card.overdueWhen', diffDays)
})

// ── Actions ────────────────────────────────────────────────────────────────────

function onClick() {
  if (!isEditing.value && !bulkMode.value) {
    void router.push(`/deals/${props.card.id}`)
  } else if (bulkMode.value) {
    salesStore.toggleBulkItem(props.card.id)
  }
}

function onQuickAdd() {
  activityStore.openQuickAdd(props.card.id)
}

function onScheduleTask() {
  activityStore.openQuickAdd(props.card.id)
}

// ── Utils ──────────────────────────────────────────────────────────────────────

function shortName(name: string): string {
  const parts = name.trim().split(' ')
  if (parts.length === 1) return parts[0] ?? name
  const first = parts[0] ?? ''
  const secondInitial = parts[1]?.charAt(0).toUpperCase() ?? ''
  return `${first} ${secondInitial}.`
}

const ownerInitial = computed(() => {
  const name = props.card.owner.name.trim()
  return name.charAt(0).toUpperCase()
})
</script>

<style lang="scss" scoped>
.kanban-card {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  cursor: pointer;
  transition: box-shadow var(--app-transition-fast), background-color var(--app-transition-fast);
  user-select: none;
  overflow: hidden;
  position: relative;

  &:hover {
    background: var(--p-surface-50);
    box-shadow: var(--p-card-shadow);
  }

  :global(.app-dark) & {
    background: var(--p-surface-900);
    border-color: var(--p-surface-700);

    &:hover {
      background: var(--p-surface-800);
    }
  }

  // Health: no-task — yellow left inset border
  &--no-task {
    box-shadow: inset 4px 0 0 $color-warning;
  }

  // Health: overdue — red left inset border + full border
  &--overdue-health {
    box-shadow: inset 4px 0 0 $color-danger;
    border-color: $color-danger;

    :global(.app-dark) & {
      border-color: $color-danger;
    }
  }

  // Dragging
  &--dragging {
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
    opacity: 0.95;
  }

  // Bulk selected
  &--selected {
    border-color: var(--p-primary-color);
    border-width: 2px;
  }
}

.kanban-card__checkbox {
  position: absolute;
  top: $space-2;
  left: $space-2;
  z-index: 2;
}

.kanban-card__body {
  padding: $space-3;
}

.kanban-card__title {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-800;
  margin-bottom: $space-2;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-height: 20px;

  :global(.app-dark) & {
    color: var(--p-surface-50);
  }
}

.kanban-card__title-input {
  width: 100%;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  padding: 2px 4px;
}

// Amount + product chip row
.kanban-card__amount-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  margin-bottom: $space-2;
  min-width: 0;
}

.kanban-card__amount {
  font-size: $font-size-xs;
  font-weight: $font-weight-bold;
  color: $primary-color;
  white-space: nowrap;
  flex-shrink: 0;
}

.kanban-card__product-chip {
  display: flex;
  align-items: center;
  gap: 3px;
  background: var(--p-surface-100);
  border-radius: $radius-sm;
  padding: 1px 6px;
  font-size: 11px;
  color: $surface-600;
  min-width: 0;
  overflow: hidden;

  :global(.app-dark) & {
    background: var(--p-surface-700);
    color: var(--p-surface-300);
  }
}

.kanban-card__product-icon {
  font-size: 10px;
  flex-shrink: 0;
}

.kanban-card__product-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 100px;
}

// Manager row
.kanban-card__meta-row {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.kanban-card__owner {
  display: flex;
  align-items: center;
  gap: $space-1;
  flex: 1;
  min-width: 0;
  overflow: hidden;
}

.kanban-card__avatar {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: var(--p-primary-color);
  color: #fff;
  font-size: 10px;
  font-weight: $font-weight-semibold;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.kanban-card__owner-name {
  font-size: $font-size-xs;
  color: $surface-500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 80px;
}

// Days in stage / rotting
.kanban-card__days {
  display: flex;
  align-items: center;
  gap: 2px;
  font-size: $font-size-xs;
  color: $surface-500;
  white-space: nowrap;
  flex-shrink: 0;

  &--warn {
    color: var(--p-orange-500);
  }

  &--rotting {
    color: $color-danger;

    .kanban-card__days-icon {
      color: $color-danger;
    }
  }
}

.kanban-card__days-icon {
  font-size: 11px;
}

// Quick add button
.kanban-card__quick-add {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: $radius-sm;
  border: none;
  background: transparent;
  color: $surface-400;
  cursor: pointer;
  opacity: 0;
  transition: opacity var(--app-transition-fast), background-color var(--app-transition-fast), color var(--app-transition-fast);
  flex-shrink: 0;
  padding: 0;

  i {
    font-size: 11px;
  }

  &:hover {
    background: var(--p-surface-hover);
    color: $primary-color;
  }
}

.kanban-card:hover .kanban-card__quick-add {
  opacity: 1;
}

// ─── Health strip (bottom bar) ────────────────────────────────────────────────

.kanban-card__health-strip {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  border-top: 1px solid $surface-200;
  font-size: 11px;
  min-height: 28px;

  :global(.app-dark) & {
    border-top-color: var(--p-surface-700);
  }

  &--neutral {
    background: var(--p-surface-50);

    :global(.app-dark) & {
      background: var(--p-surface-800);
    }
  }

  &--warning {
    background: $color-warning-bg;

    :global(.app-dark) & {
      background: rgba(255, 179, 138, 0.15);
    }
  }

  &--danger {
    background: $color-danger-bg;

    :global(.app-dark) & {
      background: rgba(255, 90, 68, 0.15);
    }
  }
}

.kanban-card__task-icon {
  font-size: 11px;
  color: $surface-500;
  flex-shrink: 0;

  &--danger {
    color: $color-danger;
  }
}

.kanban-card__task-text {
  color: $surface-600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex: 1;

  :global(.app-dark) & {
    color: var(--p-surface-300);
  }

  &--muted {
    color: $color-warning-text;

    :global(.app-dark) & {
      color: $color-warning;
    }
  }

  &--danger {
    color: $color-danger-text;
    font-weight: $font-weight-medium;

    :global(.app-dark) & {
      color: $color-danger;
    }
  }
}

.kanban-card__schedule-btn {
  margin-left: auto;
  border: none;
  background: transparent;
  color: $primary-color;
  cursor: pointer;
  font-size: 11px;
  white-space: nowrap;
  padding: 0;

  &:hover {
    text-decoration: underline;
  }
}

// Ghost (drag placeholder)
:global(.kanban-card--ghost) {
  opacity: 0.4;
  background: var(--p-surface-100);
}
</style>
