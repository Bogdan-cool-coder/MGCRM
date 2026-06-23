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

const { t, locale } = useI18n()
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
  if (d >= warnDays.value) return 'kanban-card__days--warn'
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
    presentation: 'pi pi-desktop',
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
  const n = Math.max(1, Math.floor((Date.now() - d.getTime()) / 86400000))
  // Select plural form in JS to avoid vue-i18n lazy-compile crash on '|' in locale strings
  let formKey: string
  if (locale.value === 'ru') {
    const mod10 = n % 10
    const mod100 = n % 100
    if (mod100 >= 11 && mod100 <= 19) formKey = 'overdueWhen_many'
    else if (mod10 === 1) formKey = 'overdueWhen_one'
    else if (mod10 >= 2 && mod10 <= 4) formKey = 'overdueWhen_few'
    else formKey = 'overdueWhen_many'
  } else {
    formKey = n === 1 ? 'overdueWhen_one' : 'overdueWhen_many'
  }
  return t(`sales.deals.page.card.${formKey}`, { n })
})

// ── Actions ────────────────────────────────────────────────────────────────────

function onClick() {
  if (!isEditing.value && !bulkMode.value) {
    void router.push(`/deals/${props.card.id}`)
  } else if (bulkMode.value) {
    salesStore.toggleBulkItem(props.card.id)
  }
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

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);

    &:hover {
      background: var(--p-surface-50);
    }
  }

  // Health: no-task — yellow left inset border
  &--no-task {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    box-shadow: inset 4px 0 0 $color-warning; // health signal inset border uses $color-warning token
  }

  // Health: overdue — red left inset border + full border
  &--overdue-health {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    box-shadow: inset 4px 0 0 $color-danger; // health signal inset border uses $color-danger token
    border-color: $color-danger;
  }

  // Dragging
  &--dragging {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    box-shadow: $shadow-dragging;
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
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 11px 12px; // spec §4.2 exact: 11px top/bottom (no token for 11px), 12px = $space-3
}

.kanban-card__title {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 13px; // spec §4.2 = 13px/600; $font-size-sm = 12.25px (no exact token for 13px)
  font-weight: $font-weight-semibold;
  color: $surface-800;
  margin-bottom: $space-2;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-height: 20px;

  .app-dark & {
    color: var(--p-text-color);
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

  .app-dark & {
    color: var(--p-primary-300); // #172747 is low-contrast on #444547 in dark; use primary-300
  }
}

.kanban-card__product-chip {
  display: flex;
  align-items: center;
  gap: 3px;
  background: var(--p-surface-100);
  border-radius: $radius-sm;
  padding: 1px 6px;
  font-size: $font-size-2xs; // snap from 11px
  color: $surface-600;
  min-width: 0;
  overflow: hidden;

  .app-dark & {
    background: var(--p-surface-200);
    color: var(--p-surface-300);
  }
}

.kanban-card__product-icon {
  font-size: $font-size-3xs; // snap from 10px
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
  border-radius: $radius-circle;
  background: var(--p-primary-color);
  color: $sidebar-text-active;
  font-size: $font-size-3xs; // snap from 10px
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
    color: $orange-700; // --mg-orange-700 per spec §4.2 ≥7 days
  }

  &--rotting {
    color: $color-danger;

    .kanban-card__days-icon {
      color: $color-danger;
    }
  }
}

.kanban-card__days-icon {
  font-size: $font-size-2xs; // snap from 11px
}


// ─── Health strip (bottom bar) ────────────────────────────────────────────────

.kanban-card__health-strip {
  display: flex;
  align-items: center;
  gap: $space-2;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 7px 12px; // spec §4.2 exact: 7px top/bottom (no token), 12px = $space-3
  border-top: 1px solid var(--p-surface-200);
  font-size: $font-size-2xs; // snap from 11px
  min-height: 28px;

  &--neutral {
    background: var(--p-surface-50); // --c-hover light = surface-50; dark = surface-100 via PrimeVue

    .app-dark & {
      background: var(--p-surface-100);
    }
  }

  &--warning {
    background: $orange-50; // --mg-orange-50 = $orange-50 (#fff9f5)

    .app-dark & {
      background: var(--p-surface-100); // dark: same hover tone to preserve readability
    }
  }

  &--danger {
    background: $red-50; // --mg-red-50 = $red-50 (#fff5f4)

    .app-dark & {
      background: var(--p-surface-100);
    }
  }
}

.kanban-card__task-icon {
  font-size: $font-size-2xs; // snap from 11px
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

  .app-dark & {
    color: var(--p-surface-300);
  }

  &--muted {
    color: $orange-900; // --mg-orange-900 per spec §4.2 no-task strip

    .app-dark & {
      color: var(--p-orange-500);
    }
  }

  &--danger {
    color: $red-700; // --mg-red-700 per spec §4.2 overdue strip
    font-weight: $font-weight-medium;

    .app-dark & {
      color: var(--p-red-400);
    }
  }
}

.kanban-card__schedule-btn {
  margin-left: auto;
  border: none;
  background: transparent;
  color: $primary-color;
  cursor: pointer;
  font-size: $font-size-2xs; // snap from 11px
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
