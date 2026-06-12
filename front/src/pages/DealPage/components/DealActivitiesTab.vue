<template>
  <div class="deal-activities">
    <!-- Toolbar -->
    <div class="deal-activities__toolbar">
      <Button
        icon="pi pi-plus"
        :label="t('activity.actions.add')"
        severity="primary"
        size="small"
        @click="openCreate"
      />
    </div>

    <!-- Loading skeleton -->
    <div v-if="loading && activities.length === 0" class="deal-activities__skeleton">
      <Skeleton height="60px" class="mb-2" />
      <Skeleton height="60px" class="mb-2" />
      <Skeleton height="60px" />
    </div>

    <!-- Empty state -->
    <div v-else-if="!loading && activities.length === 0" class="deal-activities__empty">
      <i class="pi pi-calendar-plus deal-activities__empty-icon" />
      <p class="deal-activities__empty-text">{{ t('activity.timeline.emptyDeal') }}</p>
      <Button
        icon="pi pi-plus"
        :label="t('activity.timeline.emptyCtaDeal')"
        severity="secondary"
        outlined
        size="small"
        @click="openCreate"
      />
    </div>

    <!-- Timeline -->
    <div v-else class="deal-activities__timeline">
      <div
        v-for="activity in activities"
        :key="activity.id"
        class="deal-activities__item"
        :class="{ 'deal-activities__item--done': activity.is_closed }"
      >
        <!-- Timeline dot -->
        <div
          class="deal-activities__dot"
          :class="{
            'deal-activities__dot--done': activity.status === 'done',
            'deal-activities__dot--overdue': activity.is_overdue && !activity.is_closed,
          }"
        />

        <!-- Content -->
        <div class="deal-activities__content">
          <div class="deal-activities__row">
            <!-- Kind icon + title -->
            <div class="deal-activities__title-wrap">
              <i :class="kindIcon(activity.kind)" class="deal-activities__kind-icon" />
              <span
                class="deal-activities__title"
                :class="{ 'deal-activities__title--done': activity.status === 'done' }"
              >
                <s v-if="activity.status === 'done'">{{ activity.title }}</s>
                <template v-else>{{ activity.title }}</template>
              </span>
              <Tag
                v-if="activity.is_pinned"
                icon="pi pi-bookmark-fill"
                severity="secondary"
                size="small"
                class="ms-1"
              />
            </div>

            <!-- Status tag -->
            <Tag
              :severity="statusSeverity(activity.status)"
              :value="t(`activity.statuses.${activity.status}`)"
              size="small"
            />
          </div>

          <!-- Meta -->
          <div class="deal-activities__meta">
            <span
              class="deal-activities__due"
              :class="{ 'deal-activities__due--overdue': activity.is_overdue && !activity.is_closed }"
            >
              <template v-if="activity.due_at">{{ formatDueDate(activity.due_at) }}</template>
              <template v-else>—</template>
            </span>
            <Tag
              v-if="activity.is_overdue && !activity.is_closed"
              severity="danger"
              :value="t('activity.timeline.overdueBadge')"
              size="small"
            />
            <span v-if="activity.responsible" class="deal-activities__responsible">
              · {{ activity.responsible.full_name }}
            </span>
            <span class="deal-activities__priority-wrap">
              · <Tag
                :severity="prioritySeverity(activity.priority)"
                :value="t(`activity.priorities.${activity.priority}`)"
                size="small"
              />
            </span>
          </div>

          <!-- Actions -->
          <div class="deal-activities__actions">
            <Button
              v-if="!activity.is_closed && activity.status !== 'done'"
              icon="pi pi-check"
              :label="t('activity.actions.complete')"
              severity="success"
              size="small"
              outlined
              :loading="completingId === activity.id"
              @click="onComplete(activity)"
            />
            <Button
              v-if="activity.status === 'done'"
              icon="pi pi-refresh"
              :label="t('activity.actions.reopen')"
              severity="secondary"
              size="small"
              text
              :loading="reopeningId === activity.id"
              @click="onReopen(activity)"
            />
            <Button
              icon="pi pi-pencil"
              :label="t('activity.actions.edit')"
              severity="secondary"
              size="small"
              text
              @click="onEdit(activity)"
            />
            <Button
              icon="pi pi-ellipsis-v"
              severity="secondary"
              size="small"
              text
              @click="toggleMenu($event, activity)"
            />
          </div>
        </div>
      </div>

      <!-- Load more -->
      <div v-if="hasMore" class="deal-activities__load-more">
        <Button
          :label="t('activity.timeline.loadMore')"
          severity="secondary"
          outlined
          size="small"
          :loading="loading"
          @click="$emit('load-more')"
        />
      </div>
    </div>

    <!-- Context menu -->
    <Menu ref="menuRef" :model="menuItems" popup />

    <!-- Form dialog -->
    <ActivityFormDialog
      v-model="formDialogOpen"
      :activity-id="editingActivityId"
      :target-type="editingActivityId ? undefined : 'deal'"
      :target-id="editingActivityId ? undefined : dealId"
      :allowed-kinds="allowedKinds"
      @created="onActivityCreated"
      @updated="onActivityUpdated"
    />

    <!-- Meeting report dialog -->
    <MeetingReportDialog
      v-if="meetingReportOpen"
      v-model:visible="meetingReportOpen"
      :activity-id="meetingActivityId ?? 0"
      :deal-id="dealId"
      @saved="meetingReportOpen = false"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Skeleton from 'primevue/skeleton'
import Menu from 'primevue/menu'
import ActivityFormDialog from '@/components/ActivityFormDialog.vue'
import MeetingReportDialog from '@/components/MeetingReportDialog.vue'
import { kindIcon, statusSeverity, prioritySeverity, formatDueDate } from '@/utils/activity'
import type { ActivityDto, ActivityKind } from '@/entities/activity'

defineProps<{
  dealId: number
  activities: ActivityDto[]
  loading: boolean
  hasMore: boolean
  allowedKinds?: ActivityKind[]
}>()

const emit = defineEmits<{
  'load-more': []
  'complete': [activity: ActivityDto]
  'reopen': [activity: ActivityDto]
  'remove': [activity: ActivityDto]
  'updated': [activity: ActivityDto]
  'created': [activity: ActivityDto]
}>()

const { t } = useI18n()
const toast = useToast()
const confirm = useConfirm()

const completingId = ref<number | null>(null)
const reopeningId = ref<number | null>(null)
const formDialogOpen = ref(false)
const editingActivityId = ref<number | null>(null)
const meetingReportOpen = ref(false)
const meetingActivityId = ref<number | null>(null)
const menuRef = ref<InstanceType<typeof Menu> | null>(null)
const menuActivity = ref<ActivityDto | null>(null)

const menuItems = computed(() => {
  const a = menuActivity.value
  if (!a) return []
  const items = [
    {
      label: t('activity.actions.edit'),
      icon: 'pi pi-pencil',
      command: () => onEdit(a),
    },
    {
      label: a.is_pinned ? t('activity.actions.unpin') : t('activity.actions.pin'),
      icon: a.is_pinned ? 'pi pi-bookmark' : 'pi pi-bookmark-fill',
      command: () => onPin(a),
    },
  ]
  if (a.kind === 'meeting') {
    items.push({
      label: t('activity.meetingReport.dialogTitle'),
      icon: 'pi pi-file-edit',
      command: () => onMeetingReport(a),
    })
  }
  items.push({
    label: t('activity.actions.delete'),
    icon: 'pi pi-trash',
    command: () => onDelete(a),
  })
  return items
})

function toggleMenu(event: MouseEvent, activity: ActivityDto) {
  menuActivity.value = activity
  menuRef.value?.toggle(event)
}

function openCreate() {
  editingActivityId.value = null
  formDialogOpen.value = true
}

function onEdit(activity: ActivityDto) {
  editingActivityId.value = activity.id
  formDialogOpen.value = true
}

async function onComplete(activity: ActivityDto) {
  completingId.value = activity.id
  emit('complete', activity)
  completingId.value = null
}

async function onReopen(activity: ActivityDto) {
  reopeningId.value = activity.id
  emit('reopen', activity)
  reopeningId.value = null
}

function onPin(activity: ActivityDto) {
  emit('updated', { ...activity, is_pinned: !activity.is_pinned })
}

function onMeetingReport(activity: ActivityDto) {
  meetingActivityId.value = activity.id
  meetingReportOpen.value = true
}

function onDelete(activity: ActivityDto) {
  confirm.require({
    header: t('activity.actions.deleteConfirmHeader'),
    message: t('activity.actions.deleteConfirmBody'),
    acceptLabel: t('activity.actions.deleteConfirmAccept'),
    rejectLabel: t('activity.actions.deleteConfirmReject'),
    acceptClass: 'p-button-danger',
    accept: () => emit('remove', activity),
  })
}

function onActivityCreated(activity: ActivityDto) {
  emit('created', activity)
  toast.add({ severity: 'success', summary: t('activity.form.successCreate'), life: 3000 })
}

function onActivityUpdated(activity: ActivityDto) {
  emit('updated', activity)
  toast.add({ severity: 'success', summary: t('activity.form.successUpdate'), life: 3000 })
}
</script>

<style lang="scss" scoped>
.deal-activities {
  display: flex;
  flex-direction: column;
  gap: $space-3;
}

.deal-activities__toolbar {
  display: flex;
  justify-content: flex-end;
}

.deal-activities__skeleton {
  display: flex;
  flex-direction: column;
}

.deal-activities__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-6;
  text-align: center;
}

.deal-activities__empty-icon {
  font-size: 3rem;
  color: $surface-400;
}

.deal-activities__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

// Timeline
.deal-activities__timeline {
  position: relative;
  padding-left: $space-6;

  &::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 8px;
    bottom: 8px;
    width: 2px;
    background: $surface-200;

    :global(.app-dark) & {
      background: var(--p-surface-700);
    }
  }
}

.deal-activities__item {
  position: relative;
  padding-bottom: $space-4;

  &:last-child {
    padding-bottom: 0;
  }

  &--done {
    opacity: 0.7;
  }
}

.deal-activities__dot {
  position: absolute;
  left: calc(-1 * $space-6 + 4px);
  top: 4px;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: $primary-color;
  border: 2px solid $surface-card;

  :global(.app-dark) & {
    border-color: var(--p-card-background);
  }

  &--done {
    background: var(--p-green-500);
  }

  &--overdue {
    background: var(--p-red-500);
  }
}

.deal-activities__content {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  padding: $space-3;

  :global(.app-dark) & {
    border-color: var(--p-surface-700);
  }
}

.deal-activities__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-2;
  flex-wrap: wrap;
}

.deal-activities__title-wrap {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex: 1;
  min-width: 0;
}

.deal-activities__kind-icon {
  color: $surface-500;
  font-size: $font-size-sm;
  flex-shrink: 0;
}

.deal-activities__title {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-800;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;

  :global(.app-dark) & {
    color: var(--p-surface-100);
  }

  &--done {
    color: $surface-400;

    :global(.app-dark) & {
      color: var(--p-surface-500);
    }
  }
}

.deal-activities__meta {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
  font-size: $font-size-xs;
}

.deal-activities__due {
  color: $surface-400;

  &--overdue {
    color: var(--p-red-500);
    font-weight: $font-weight-medium;
  }
}

.deal-activities__responsible {
  color: $surface-500;
}

.deal-activities__priority-wrap {
  display: flex;
  align-items: center;
  gap: $space-1;
  color: $surface-400;
}

.deal-activities__actions {
  display: flex;
  align-items: center;
  gap: $space-1;
  margin-top: $space-1;
  flex-wrap: wrap;
}

.deal-activities__load-more {
  display: flex;
  justify-content: center;
  padding-top: $space-4;
}
</style>
