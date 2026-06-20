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
  >
    <!-- Timeline dot -->
    <div
      class="feed-item__dot"
      :class="{
        'feed-item__dot--primary': dotColor === 'primary',
        'feed-item__dot--green': dotColor === 'green',
        'feed-item__dot--red': dotColor === 'red',
        'feed-item__dot--surface': dotColor === 'surface',
      }"
    >
      <i :class="['pi', itemIcon]" class="feed-item__dot-icon" />
    </div>

    <!-- Content card -->
    <div class="feed-item__card">
      <!-- ─── stage_change ─────────────────────────────────────────────────── -->
      <template v-if="item.type === 'stage_change'">
        <div class="feed-item__header">
          <span class="feed-item__event-title">{{ t('sales.deal.feed.events.stageChanged') }}</span>
          <span class="feed-item__time">{{ formatTime(item.timestamp) }}</span>
        </div>
        <div class="feed-item__stage-change">
          <span class="feed-item__stage-from">{{ item.fromStage?.name ?? '—' }}</span>
          <i class="pi pi-arrow-right feed-item__stage-arrow" />
          <span class="feed-item__stage-to">{{ item.toStage?.name ?? '—' }}</span>
        </div>
        <div v-if="item.actor" class="feed-item__meta">
          {{ item.actor.full_name }}
        </div>
      </template>

      <!-- ─── deal_created ────────────────────────────────────────────────── -->
      <template v-else-if="item.type === 'deal_created'">
        <div class="feed-item__header">
          <span class="feed-item__event-title">{{ t('sales.deal.feed.events.dealCreated') }}</span>
          <span class="feed-item__time">{{ formatTime(item.timestamp) }}</span>
        </div>
        <div v-if="item.actor" class="feed-item__meta">
          {{ item.actor.full_name }}
        </div>
      </template>

      <!-- ─── field_change ────────────────────────────────────────────────── -->
      <template v-else-if="item.type === 'field_change'">
        <div class="feed-item__header">
          <span class="feed-item__event-title">{{ t('sales.deal.feed.events.fieldsChanged') }}</span>
          <span class="feed-item__time">{{ formatTime(item.timestamp) }}</span>
        </div>
        <div v-if="item.actor" class="feed-item__meta">
          {{ item.actor.full_name }}
        </div>
        <div class="feed-item__field-changes">
          <div
            v-for="(change, i) in visibleChanges"
            :key="i"
            class="feed-item__field-row"
          >
            <span class="feed-item__field-name">{{ change.field }}:</span>
            <span class="feed-item__field-old">{{ change.old_value ?? '—' }}</span>
            <i class="pi pi-arrow-right feed-item__field-arrow" />
            <span class="feed-item__field-new">{{ change.new_value ?? '—' }}</span>
          </div>
          <button
            v-if="hiddenChangesCount > 0 && !changesExpanded"
            type="button"
            class="feed-item__expand-btn"
            @click="changesExpanded = true"
          >
            <i class="pi pi-chevron-right" />
            {{ t('sales.deal.feed.events.moreChanges', { n: hiddenChangesCount }) }}
          </button>
        </div>
      </template>

      <!-- ─── activity (note / task / call / meeting) ─────────────────────── -->
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
            <Tag
              v-if="item.activity.is_pinned"
              icon="pi pi-bookmark-fill"
              severity="secondary"
              size="small"
              class="ms-1"
            />
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

        <!-- Meta row -->
        <div class="feed-item__meta">
          <span
            v-if="item.activity.due_at"
            class="feed-item__due"
            :class="{
              'feed-item__due--overdue': item.activity.is_overdue && !item.activity.is_closed,
            }"
          >
            {{ formatDueDate(item.activity.due_at) }}
          </span>
          <Tag
            v-if="item.activity.is_overdue && !item.activity.is_closed"
            severity="danger"
            :value="t('activity.timeline.overdueBadge')"
            size="small"
          />
          <span v-if="item.activity.responsible" class="feed-item__responsible">
            <i class="pi pi-user feed-item__responsible-icon" />
            {{ item.activity.responsible.full_name }}
          </span>
          <Tag
            v-if="item.activity?.kind !== 'note' && item.activity.priority"
            :severity="prioritySeverity(item.activity.priority)"
            :value="t(`activity.priorities.${item.activity.priority}`)"
            size="small"
          />
        </div>

        <!-- Body preview for note -->
        <p v-if="item.activity.body && item.activity?.kind === 'note'" class="feed-item__body">
          {{ item.activity.body }}
        </p>

        <!-- Actions (hover-only; no "Complete" btn — open tasks live above composer) -->
        <div class="feed-item__actions">
          <!-- Reopen: shown when task was completed (done) -->
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
          <!-- Edit -->
          <Button
            v-if="item.activity.kind !== 'note'"
            icon="pi pi-pencil"
            severity="secondary"
            size="small"
            text
            class="feed-item__hover-btn"
            @click="onEdit"
          />
          <!-- More menu (pin / delete) -->
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

  <!-- Context menu (outside card, teleported by PrimeVue) -->
  <Menu
    v-if="isActivity"
    ref="menuRef"
    :model="menuItems"
    popup
  />

  <!-- Activity edit dialog -->
  <ActivityFormDialog
    v-if="isActivity"
    v-model="formDialogOpen"
    :activity-id="editingActivityId"
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
import { statusSeverity, prioritySeverity, formatDueDate } from '@/utils/activity'
import type { FeedItem } from '../composables/useDealFeed'
import type { ActivityDto } from '@/entities/activity'

// ─── Props / emits ────────────────────────────────────────────────────────────

const props = defineProps<{
  item: FeedItem
  dealId: number
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
const changesExpanded = ref(false)

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
    props.item.type === 'deal_created',
)

const dotColor = computed((): 'primary' | 'green' | 'red' | 'surface' => {
  if (props.item.type === 'deal_created') return 'green'
  if (props.item.type === 'stage_change') return 'primary'
  if (props.item.type === 'field_change') return 'surface'
  // activity
  const a = props.item.activity
  if (!a) return 'primary'
  if (a.status === 'done') return 'green'
  if (a.is_overdue && !a.is_closed) return 'red'
  return 'primary'
})

const itemIcon = computed((): string => {
  switch (props.item.type) {
    case 'stage_change': return 'pi-flag'
    case 'deal_created': return 'pi-plus-circle'
    case 'field_change': return 'pi-pencil'
    case 'note': return 'pi-file'
    case 'task': return 'pi-check-square'
    case 'call': return 'pi-phone'
    case 'meeting': return 'pi-users'
    case 'follow_up': return 'pi-reply'
    case 'presentation': return 'pi-desktop'
    default: return 'pi-circle'
  }
})

const CHANGES_INITIAL = 1

const visibleChanges = computed(() => {
  const changes = props.item.fieldChanges ?? []
  if (changesExpanded.value) return changes
  return changes.slice(0, CHANGES_INITIAL)
})

const hiddenChangesCount = computed(() => {
  const changes = props.item.fieldChanges ?? []
  if (changesExpanded.value) return 0
  return Math.max(0, changes.length - CHANGES_INITIAL)
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
    opacity: 0.7;
  }
}

.feed-item__dot {
  flex-shrink: 0;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 2px solid var(--p-card-background);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 2px;
  position: relative;
  z-index: 1;

  &--primary {
    background: var(--p-primary-400);
    color: #fff;
  }

  &--green {
    background: var(--p-green-500);
    color: #fff;
  }

  &--red {
    background: var(--p-red-500);
    color: #fff;
  }

  &--surface {
    background: var(--p-surface-400);
    color: #fff;
  }
}

.feed-item__dot-icon {
  font-size: 12px;
}

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
}

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

.feed-item__event-title {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.feed-item__time {
  font-size: $font-size-xs;
  color: $surface-400;
  white-space: nowrap;
}

.feed-item__stage-change {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
  font-size: $font-size-sm;
}

.feed-item__stage-from {
  color: $surface-500;
  text-decoration: line-through;
}

.feed-item__stage-arrow {
  color: $surface-400;
  font-size: $font-size-xs;
}

.feed-item__stage-to {
  color: var(--p-primary-color);
  font-weight: $font-weight-medium;
}

.feed-item__meta {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
  font-size: $font-size-xs;
  color: $surface-500;
}

.feed-item__field-changes {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  margin-top: $space-1;
}

.feed-item__field-row {
  display: flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  flex-wrap: wrap;
}

.feed-item__field-name {
  font-weight: $font-weight-medium;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-300);
  }
}

.feed-item__field-old {
  color: $surface-400;
  text-decoration: line-through;
}

.feed-item__field-arrow {
  font-size: 10px;
  color: $surface-400;
}

.feed-item__field-new {
  color: $surface-800;
  font-weight: $font-weight-medium;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.feed-item__expand-btn {
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  font-size: $font-size-xs;
  color: var(--p-primary-color);
  display: flex;
  align-items: center;
  gap: $space-1;
  margin-top: $space-1;

  &:hover {
    text-decoration: underline;
  }

  i {
    font-size: 10px;
  }
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

  // Dark mode: use semantic text token to avoid invisible text on dark card bg
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

.feed-item__due {
  color: $surface-400;

  &--overdue {
    color: var(--p-red-500);
    font-weight: $font-weight-medium;
  }
}

.feed-item__responsible {
  color: $surface-500;
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

.feed-item__complete-btn {
  // always visible — no opacity change
}

.feed-item__responsible-icon {
  font-size: 10px;
  margin-right: 2px;
}

// System events: transparent card
.feed-item--system {
  .feed-item__card {
    background: transparent;
    border: none;
    padding: $space-1 $space-2;
    box-shadow: none;
  }
}

// Key-actions bar highlight — flash animation
@keyframes feed-item-flash {
  0%   { background-color: rgba(var(--p-primary-color-rgb, 23, 39, 71), 0.18); }
  60%  { background-color: rgba(var(--p-primary-color-rgb, 23, 39, 71), 0.12); }
  100% { background-color: transparent; }
}

.feed-item--highlight {
  .feed-item__card {
    animation: feed-item-flash 1.6s ease-out forwards;
  }
}
</style>
