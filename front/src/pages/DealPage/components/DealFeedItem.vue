<template>
  <div
    class="feed-item"
    :class="{
      'feed-item--done': isActivity && item.activity?.is_closed,
      'feed-item--deal-created': item.type === 'deal_created',
      'feed-item--system': isSystem,
      'feed-item--highlight': isHighlighted,
    }"
    :data-feed-id="item.id"
    :data-feed-type="item.type"
    :data-activity-kind="item.activity?.kind ?? null"
    :data-ka="itemKeyActionType"
  >
    <!-- Timeline dot — 22px for system, 26px for activity (spec §7.2) -->
    <div
      class="feed-item__dot"
      :class="[
        `feed-item__dot--${dotSize}`,
        {
          'feed-item__dot--primary': dotVariant === 'primary',
          'feed-item__dot--green': dotVariant === 'green',
          'feed-item__dot--red': dotVariant === 'red',
          'feed-item__dot--surface': dotVariant === 'surface',
          'feed-item__dot--kind': !!kindAccentColor,
        },
      ]"
      :style="kindAccentColor ? { '--kind-color': kindAccentColor } : {}"
    >
      <i :class="['pi', itemIcon]" class="feed-item__dot-icon" />
    </div>

    <!-- Content — system events: no card; activities: card with kind tint border -->
    <div
      class="feed-item__card"
      :class="{
        'feed-item__card--no-bg': isSystem,
        'feed-item__card--kind': isActivity && !!kindAccentColor,
      }"
      :style="isActivity && kindAccentColor ? { '--kind-color': kindAccentColor } : {}"
    >
      <!-- ─── system events: ONE LINE per spec §11 ───────────────────────────── -->
      <template v-if="isSystem">
        <div class="feed-item__system-line">
          <span v-if="item.actor" class="feed-item__sys-actor">{{ item.actor.full_name }},</span>
          <span class="feed-item__sys-date">{{ formatSystemDate(item.timestamp) }}</span>
          <span class="feed-item__sys-verb">{{ systemVerb }}</span>
          <!-- stage change -->
          <template v-if="item.type === 'stage_change'">
            <s v-if="item.fromStage" class="feed-item__sys-old">{{ item.fromStage.name }}</s>
            <i class="pi pi-arrow-right feed-item__sys-arrow" />
            <span class="feed-item__sys-new">{{ item.toStage?.name ?? '—' }}</span>
          </template>
          <!-- field changes -->
          <template v-else-if="item.type === 'field_change'">
            <span
              v-for="(change, i) in (item.fieldChanges ?? [])"
              :key="i"
              class="feed-item__sys-change"
            >
              <template v-if="i > 0">&nbsp;·&nbsp;</template>
              {{ change.field }}:
              <s v-if="change.old_value" class="feed-item__sys-old">{{ change.old_value }}</s>
              <i class="pi pi-arrow-right feed-item__sys-arrow" />
              <span class="feed-item__sys-new">{{ change.new_value ?? '—' }}</span>
            </span>
          </template>
          <!-- payment fixed -->
          <template v-else-if="item.type === 'payment_fixed'">
            <span v-if="paymentFixedLabel" class="feed-item__sys-new">{{ paymentFixedLabel }}</span>
          </template>
          <span class="feed-item__sys-time">{{ formatTime(item.timestamp) }}</span>
        </div>
      </template>

      <!-- ─── activity (note / task / call / meeting / follow_up / presentation) -->
      <template v-else-if="isActivity && item.activity">
        <div class="feed-item__header">
          <div class="feed-item__title-wrap">
            <span
              class="feed-item__activity-title"
              :class="{ 'feed-item__activity-title--done': item.activity.status === 'done' }"
            >
              <s v-if="item.activity.status === 'done'">{{ item.activity.title }}</s>
              <template v-else>{{ item.activity.title }}</template>
            </span>
          </div>
          <div class="feed-item__header-right">
            <Tag
              v-if="item.activity?.kind !== 'note'"
              :severity="statusSeverity(item.activity.status)"
              :value="t(`activity.statuses.${item.activity.status}`)"
              size="small"
            />
            <span class="feed-item__time">{{ formatTime(item.timestamp) }}</span>
          </div>
        </div>

        <!-- Meta row: due date · time · responsible — all in ONE row per spec §11 -->
        <div v-if="item.activity.due_at || item.activity.responsible" class="feed-item__meta-row">
          <span
            v-if="item.activity.due_at"
            class="feed-item__due"
            :class="{
              'feed-item__due--overdue': item.activity.is_overdue && !item.activity.is_closed,
            }"
          >
            <i class="pi pi-calendar feed-item__meta-icon" />
            {{ formatDueDate(item.activity.due_at) }}
          </span>
          <span v-if="item.activity.responsible" class="feed-item__responsible">
            <i class="pi pi-user feed-item__meta-icon" />
            {{ item.activity.responsible.full_name }}
          </span>
        </div>

        <!-- Body preview for note -->
        <p v-if="item.activity.body" class="feed-item__body">
          {{ item.activity.body }}
        </p>

        <!-- Author (bottom, 11px muted) -->
        <div v-if="item.actor" class="feed-item__author">
          {{ item.actor.full_name }}
        </div>

        <!-- Actions (hover-only) -->
        <div class="feed-item__actions">
          <Button
            v-if="item.activity.status === 'done' && item.activity.kind !== 'note'"
            icon="pi pi-refresh"
            :label="t('activity.actions.reopen')"
            severity="secondary"
            size="small"
            text
            :loading="reopeningId === item.activity.id"
            class="feed-item__hover-btn"
            @click="onReopen"
          />
          <Button
            v-if="item.activity.kind !== 'note'"
            icon="pi pi-pencil"
            severity="secondary"
            size="small"
            text
            class="feed-item__hover-btn"
            @click="onEdit"
          />
          <Button
            icon="pi pi-ellipsis-v"
            severity="secondary"
            size="small"
            text
            class="feed-item__hover-btn"
            @click="toggleMenu"
          />
        </div>
      </template>
    </div>
  </div>

  <!-- Context menu -->
  <Menu
    v-if="isActivity"
    ref="menuRef"
    :model="menuItems"
    popup
  />

  <!-- Activity edit dialog — only mounted when open (saves N idle Dialog subtrees) -->
  <ActivityFormDialog
    v-if="isActivity && formDialogOpen"
    v-model="formDialogOpen"
    :activity-id="editingActivityId"
    :initial-activity="item.activity ?? null"
    :target-type="undefined"
    :target-id="undefined"
    @updated="onActivityUpdated"
  />

  <!-- Meeting report dialog -->
  <MeetingReportDialog
    v-if="meetingReportOpen && item.activity"
    v-model:visible="meetingReportOpen"
    :activity-id="item.activity.id"
    :deal-id="dealId"
    :pipeline-id="pipelineId ?? null"
    @saved="meetingReportOpen = false"
  />
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Menu from 'primevue/menu'
import ActivityFormDialog from '@/components/ActivityFormDialog.vue'
import MeetingReportDialog from '@/components/MeetingReportDialog.vue'
import { statusSeverity, formatDueDate } from '@/utils/activity'
import { formatCurrency } from '@/utils/currency'
import type { FeedItem } from '../composables/useDealFeed'
import type { ActivityDto } from '@/entities/activity'

// ─── Kind accent colours — spec §11 ──────────────────────────────────────────
// call=#2A6FDB  meeting=#1F8A5B  follow_up/presentation=#E8A317  contract/task=#172747

const KIND_META: Record<string, { color: string; icon: string }> = {
  call:         { color: '#2A6FDB', icon: 'pi-phone' },
  meeting:      { color: '#1F8A5B', icon: 'pi-calendar' },
  follow_up:    { color: '#E8A317', icon: 'pi-file-edit' },
  presentation: { color: '#E8A317', icon: 'pi-desktop' },
  task:         { color: '#172747', icon: 'pi-check-square' },
  note:         { color: '', icon: 'pi-file' },
}

// ─── Props / emits ────────────────────────────────────────────────────────────

const props = defineProps<{
  item: FeedItem
  dealId: number
  /** Deal's pipeline id — forwarded to the meeting-report dialog for per-pipeline questions */
  pipelineId?: number | null
  completingId: number | null
  reopeningId: number | null
  /** True when the key-actions bar triggered a scroll-to on this item */
  isHighlighted?: boolean
}>()

const emit = defineEmits<{
  reopen: [id: number]
  remove: [id: number]
  updated: [activity: ActivityDto]
  pin: [id: number, isPinned: boolean]
}>()

// ─── Setup ────────────────────────────────────────────────────────────────────

const { t } = useI18n()
const toast = useToast()
const confirm = useConfirm()

const menuRef = ref<InstanceType<typeof Menu> | null>(null)
const formDialogOpen = ref(false)
const editingActivityId = ref<number | null>(null)
const meetingReportOpen = ref(false)

// ─── Computed ─────────────────────────────────────────────────────────────────

const isActivity = computed(
  (): boolean =>
    props.item.type === 'note' ||
    props.item.type === 'task' ||
    props.item.type === 'call' ||
    props.item.type === 'meeting' ||
    props.item.type === 'follow_up' ||
    props.item.type === 'presentation',
)

const isSystem = computed(
  (): boolean =>
    props.item.type === 'stage_change' ||
    props.item.type === 'field_change' ||
    props.item.type === 'deal_created' ||
    props.item.type === 'payment_fixed',
)

/** Dot size: 22px for system events, 26px for activities (spec §7.2) */
const dotSize = computed((): 'sm' | 'lg' => (isSystem.value ? 'sm' : 'lg'))

/** Accent color for kind-tinted dot / card border */
const kindAccentColor = computed((): string | null => {
  if (!isActivity.value) return null
  const kind = props.item.activity?.kind
  if (!kind) return null
  return KIND_META[kind]?.color || null
})

const dotVariant = computed((): 'primary' | 'green' | 'red' | 'surface' | 'kind' => {
  if (props.item.type === 'deal_created') return 'green'
  if (props.item.type === 'stage_change') return 'primary'
  if (props.item.type === 'field_change') return 'surface'
  if (props.item.type === 'payment_fixed') return 'green'
  if (kindAccentColor.value) return 'kind'
  // fallback activity
  const a = props.item.activity
  if (!a) return 'primary'
  if (a.status === 'done') return 'green'
  if (a.is_overdue && !a.is_closed) return 'red'
  return 'primary'
})

const itemIcon = computed((): string => {
  const kind = props.item.activity?.kind
  if (kind && KIND_META[kind]) return KIND_META[kind].icon
  switch (props.item.type) {
    case 'stage_change': return 'pi-flag'
    case 'deal_created': return 'pi-plus-circle'
    case 'field_change': return 'pi-pencil'
    case 'payment_fixed': return 'pi-wallet'
    default: return 'pi-circle'
  }
})

/**
 * data-ka attribute for quick-search scroll (spec §7.1/§11).
 * Maps activity kind to the corresponding KeyActionType chip type.
 */
const itemKeyActionType = computed((): string | null => {
  if (!isActivity.value) return null
  const kind = props.item.activity?.kind
  if (!kind) return null
  const map: Partial<Record<string, string>> = {
    presentation: 'last_presentation',
    call: 'last_touch',
    follow_up: 'last_touch',
    meeting: 'last_event',
  }
  return map[kind] ?? null
})

/** Verb for system event ONE-LINE format */
const systemVerb = computed((): string => {
  switch (props.item.type) {
    case 'deal_created': return t('sales.deal.feed.events.dealCreatedVerb', 'создал сделку')
    case 'stage_change': return t('sales.deal.feed.events.stageChangedVerb', 'изменил стадию')
    case 'field_change': return t('sales.deal.feed.events.fieldsChangedVerb', 'изменил')
    case 'payment_fixed': return t('sales.deal.feed.events.paymentFixedVerb', 'зафиксировал оплату')
    default: return ''
  }
})

/** Formatted payment amount string for payment_fixed items */
const paymentFixedLabel = computed((): string => {
  const pf = props.item.paymentFixed
  if (!pf) return ''
  const parts: string[] = []
  if (pf.amount != null && pf.currency) {
    parts.push(formatCurrency(pf.amount, pf.currency))
  } else if (pf.amount != null) {
    parts.push(formatCurrency(pf.amount, 'RUB'))
  }
  if (pf.paid_at) {
    parts.push(t('sales.deal.feed.events.paymentFixedOn', { date: formatPaymentDate(pf.paid_at) }))
  }
  return parts.join(' ')
})

const menuItems = computed(() => {
  const a = props.item.activity
  if (!a) return []

  const items: Array<{ label: string; icon: string; command: () => void }> = [
    {
      label: t('activity.actions.edit'),
      icon: 'pi pi-pencil',
      command: () => onEdit(),
    },
    {
      label: a.is_pinned ? t('activity.actions.unpin') : t('activity.actions.pin'),
      icon: a.is_pinned ? 'pi pi-bookmark' : 'pi pi-bookmark-fill',
      command: () => emit('pin', a.id, !a.is_pinned),
    },
  ]

  if (a.kind === 'meeting') {
    items.push({
      label: t('activity.meetingReport.dialogTitle'),
      icon: 'pi pi-file-edit',
      command: () => {
        meetingReportOpen.value = true
      },
    })
  }

  items.push({
    label: t('activity.actions.delete'),
    icon: 'pi pi-trash',
    command: () => onDelete(),
  })

  return items
})

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatTime(iso: string): string {
  const d = new Date(iso)
  return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

/**
 * System date: «18 июн, 15:40» — matches spec §11 format
 * «{Автор}, {дата}, {время} {действие}»
 */
function formatSystemDate(iso: string): string {
  const d = new Date(iso)
  const datePart = d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })
  const timePart = d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
  return `${datePart}, ${timePart}`
}

/**
 * Payment date: «27 июн 2026» (day + short-month + year, no time).
 * Handles both ISO datetime strings and bare date strings (YYYY-MM-DD).
 */
function formatPaymentDate(iso: string): string {
  const d = new Date(iso)
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' })
}

// ─── Handlers ─────────────────────────────────────────────────────────────────

function toggleMenu(event: MouseEvent) {
  menuRef.value?.toggle(event)
}

function onEdit() {
  if (props.item.activity) {
    editingActivityId.value = props.item.activity.id
    formDialogOpen.value = true
  }
}

function onReopen() {
  if (props.item.activity) {
    emit('reopen', props.item.activity.id)
  }
}

function onDelete() {
  if (!props.item.activity) return
  const id = props.item.activity.id
  confirm.require({
    header: t('activity.actions.deleteConfirmHeader'),
    message: t('activity.actions.deleteConfirmBody'),
    acceptLabel: t('activity.actions.deleteConfirmAccept'),
    rejectLabel: t('activity.actions.deleteConfirmReject'),
    acceptClass: 'p-button-danger',
    accept: () => emit('remove', id),
  })
}

function onActivityUpdated(activity: ActivityDto) {
  emit('updated', activity)
  toast.add({ severity: 'success', summary: t('activity.form.successUpdate'), life: 3000 })
}
</script>

<style lang="scss" scoped>
.feed-item {
  display: flex;
  gap: $space-3;
  position: relative;
  padding-bottom: $space-4;

  &:last-child {
    padding-bottom: 0;
  }

  &--done {
    opacity: 0.72;
  }
}

// ─── Dot ──────────────────────────────────────────────────────────────────────
// 22px for system events, 26px for activities (spec §7.2)

.feed-item__dot {
  flex-shrink: 0;
  border-radius: $radius-circle;
  border: 2px solid var(--p-card-background);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 2px;
  position: relative;
  z-index: 1;

  // System events: 22px, --c-hover background
  &--sm {
    width: 22px;
    height: 22px;
    background: var(--p-surface-100);
    color: $surface-500;

    .app-dark & {
      background: var(--p-surface-200);
      color: var(--p-surface-400);
    }
  }

  // Activity cards: 26px
  &--lg {
    width: 26px;
    height: 26px;
  }

  &--primary {
    background: var(--p-primary-400);
    color: $sidebar-text-active;
  }

  &--green {
    background: var(--p-green-500);
    color: $sidebar-text-active;

    .app-dark & {
      background: var(--p-green-400);
      color: var(--p-surface-900);
    }
  }

  &--red {
    background: var(--p-red-500);
    color: $sidebar-text-active;
  }

  &--surface {
    background: var(--p-surface-300);
    color: var(--p-surface-600);
  }

  // Kind-colored dot (spec §11: light bg + icon in kind color)
  &--kind {
    // color-mix: kind-color 16% over card bg
    background: color-mix(in srgb, var(--kind-color) 16%, var(--p-card-background));
    color: var(--kind-color);
  }
}

.feed-item__dot-icon {
  font-size: $font-size-xs;
}

// ─── Card ─────────────────────────────────────────────────────────────────────

.feed-item__card {
  flex: 1;
  min-width: 0;
  background: var(--p-card-background);
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  padding: $space-3;
  display: flex;
  flex-direction: column;
  gap: $space-1;

  .app-dark & {
    border-color: var(--p-surface-700);
  }

  // System events: no background, no border (spec §7.2)
  &--no-bg {
    background: transparent;
    border: none;
    padding: $space-1 0;
    box-shadow: none;
  }

  // Kind-tinted left border (spec §11)
  &--kind {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    border-left: 2px solid var(--kind-color);
  }
}

// ─── System event: ONE LINE (spec §11) ────────────────────────────────────────
// «{Автор}, {дата}, {время} {действие} ~~старое~~ → новое» + time right

.feed-item__system-line {
  display: flex;
  align-items: baseline;
  gap: 4px;
  flex-wrap: wrap;
  font-size: $font-size-xs;
  color: $surface-500;
  line-height: $line-height-tight;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.feed-item__sys-actor {
  font-weight: $font-weight-semibold;
  color: $surface-700;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.feed-item__sys-date {
  white-space: nowrap;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.feed-item__sys-verb {
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.feed-item__sys-change {
  display: inline-flex;
  align-items: baseline;
  gap: 3px;
  flex-wrap: wrap;
}

.feed-item__sys-old {
  color: $surface-400;
  text-decoration: line-through;
}

.feed-item__sys-arrow {
  font-size: $font-size-3xs;
  color: $surface-400;
}

.feed-item__sys-new {
  color: $surface-700;
  font-weight: $font-weight-medium;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.feed-item__sys-time {
  margin-left: auto;
  white-space: nowrap;
  color: $surface-400;
  font-size: $font-size-2xs;
}

// ─── Activity card header ─────────────────────────────────────────────────────

.feed-item__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: $space-2;
  flex-wrap: wrap;
}

.feed-item__header-right {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-shrink: 0;
}

.feed-item__time {
  font-size: $font-size-xs;
  color: $surface-400;
  white-space: nowrap;
}

.feed-item__title-wrap {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex: 1;
  min-width: 0;
}

.feed-item__activity-title {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-800;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;

  .app-dark & {
    color: var(--p-text-color);
  }

  &--done {
    color: $surface-400;

    .app-dark & {
      color: var(--p-surface-500);
    }
  }
}

// ─── Meta row: date · time · responsible in ONE row (spec §11) ────────────────

.feed-item__meta-row {
  display: flex;
  align-items: center;
  gap: $space-3;
  flex-wrap: wrap;
  font-size: $font-size-xs;
}

.feed-item__meta-icon {
  font-size: $font-size-3xs;
  margin-right: 3px;
  opacity: 0.7;
}

.feed-item__due {
  color: $surface-400;
  display: inline-flex;
  align-items: center;

  &--overdue {
    color: var(--p-red-500);
    font-weight: $font-weight-medium;
  }
}

.feed-item__responsible {
  color: $surface-500;
  display: inline-flex;
  align-items: center;
}

.feed-item__body {
  font-size: $font-size-sm;
  color: $surface-600;
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;

  .app-dark & {
    color: var(--p-surface-300);
  }
}

.feed-item__author {
  font-size: $font-size-2xs;
  color: $surface-400;
  margin-top: $space-1;
}

.feed-item__actions {
  display: flex;
  align-items: center;
  gap: $space-1;
  margin-top: $space-1;
  flex-wrap: wrap;
}

.feed-item__hover-btn {
  opacity: 0;
  transition: opacity 0.15s;
}

.feed-item__card:hover .feed-item__hover-btn {
  opacity: 1;
}

// ─── Key-actions bar highlight — spec §7.1 ────────────────────────────────────
// border + ring flash for ~2s

@keyframes feed-item-flash {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  0%   { border-color: var(--p-primary-color); box-shadow: 0 0 0 $space-1 color-mix(in srgb, var(--p-primary-color) 18%, transparent); }
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  60%  { border-color: var(--p-primary-color); box-shadow: 0 0 0 2px color-mix(in srgb, var(--p-primary-color) 10%, transparent); }
  100% { border-color: var(--p-surface-200); box-shadow: none; }
}

.feed-item--highlight {
  .feed-item__card {
    animation: feed-item-flash 1.8s ease-out forwards;
  }
}
</style>
