<template>
  <div class="dashboard-actions-menu">
    <Button
      ref="anchorRef"
      v-tooltip.bottom="t('actionsMenu.title')"
      icon="pi pi-ellipsis-v"
      severity="secondary"
      :aria-label="t('actionsMenu.title')"
      :aria-haspopup="true"
      :aria-expanded="popoverOpen"
      @click="togglePopover"
    />

    <Popover ref="popoverRef" @show="popoverOpen = true" @hide="popoverOpen = false">
      <div class="dashboard-actions-menu__panel" :aria-label="t('actionsMenu.title')">
        <!-- Info block — always present (read-only metadata). -->
        <div class="dashboard-actions-menu__info" :aria-label="t('actionsMenu.info_section')">
          <div v-if="dashboard.isSystem" class="dashboard-actions-menu__info-row">
            <span class="dashboard-actions-menu__info-label">{{ t('actionsMenu.status') }}</span>
            <Tag :value="t('actionsMenu.status_system')" severity="info" />
          </div>

          <template v-else>
            <div v-if="authorDisplay" class="dashboard-actions-menu__info-row">
              <span class="dashboard-actions-menu__info-label">{{ t('actionsMenu.author') }}</span>
              <span
                class="dashboard-actions-menu__info-value"
                :title="authorTooltip ?? undefined"
              >{{ authorDisplay }}</span>
            </div>

            <div v-if="createdAtDisplay" class="dashboard-actions-menu__info-row">
              <span class="dashboard-actions-menu__info-label">{{ t('actionsMenu.created_at') }}</span>
              <span class="dashboard-actions-menu__info-value">{{ createdAtDisplay }}</span>
            </div>

            <div class="dashboard-actions-menu__info-row">
              <span class="dashboard-actions-menu__info-label">{{ t('actionsMenu.status') }}</span>
              <Tag
                :value="dashboard.isPublished ? t('actionsMenu.status_published') : t('actionsMenu.status_draft')"
                :severity="dashboard.isPublished ? 'success' : 'warn'"
              />
            </div>
          </template>
        </div>

        <!-- Divider + actions list. The "Add widget" item duplicates the
             header "+ Add widget" button for convenience; it is shown to the
             same audience that may edit the dashboard (the page already gates
             this with `editable`). -->
        <template v-if="hasAnyAction">
          <div class="dashboard-actions-menu__divider" role="separator" />

          <div class="dashboard-actions-menu__actions">
            <Button
              v-if="showAddWidget"
              :label="t('actionsMenu.addWidget')"
              icon="pi pi-plus"
              severity="secondary"
              text
              :disabled="busyAction !== null"
              class="dashboard-actions-menu__action-btn"
              @click="onAddWidget"
            />

            <Button
              v-if="showPublish"
              :label="t('actionsMenu.publish')"
              icon="pi pi-eye"
              severity="secondary"
              text
              :loading="busyAction === 'publish'"
              :disabled="busyAction !== null && busyAction !== 'publish'"
              class="dashboard-actions-menu__action-btn"
              @click="onPublish"
            />

            <Button
              v-if="showUnpublish"
              :label="t('actionsMenu.unpublish')"
              icon="pi pi-eye-slash"
              severity="secondary"
              text
              :loading="busyAction === 'unpublish'"
              :disabled="busyAction !== null && busyAction !== 'unpublish'"
              class="dashboard-actions-menu__action-btn"
              @click="onUnpublish"
            />

            <Button
              v-if="showDelete"
              :label="t('actionsMenu.delete')"
              icon="pi pi-trash"
              severity="danger"
              text
              :disabled="busyAction !== null"
              class="dashboard-actions-menu__action-btn dashboard-actions-menu__action-btn--danger"
              @click="onDeleteClick"
            />
          </div>
        </template>
      </div>
    </Popover>

    <DeleteConfirmModal
      v-model:visible="deleteModalVisible"
      :title="t('actionsMenu.delete_confirm_title')"
      :item-name="localizedTitle"
      :warning-text="t('actionsMenu.delete_confirm_message')"
      :loading="busyAction === 'delete'"
      :confirm-label="t('actionsMenu.delete')"
      @cancel="onDeleteCancel"
      @confirm="onDeleteConfirm"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Popover from 'primevue/popover'
import Tag from 'primevue/tag'
import Tooltip from 'primevue/tooltip'
import { useI18n } from 'vue-i18n'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useFormatter } from '@/composables/useFormatter'
import { useNotifications } from '@/composables/useNotifications'
import { useServices } from '@/services'
import { useUserStore } from '@/stores/user'
import {
  canDeleteDashboard,
  canManageDashboardPublication,
} from '@/shared/auth/capabilities'
import { getLocalizedText } from '@/utils/localization'
import DeleteConfirmModal from '@/components/modals/DeleteConfirmModal.vue'
import type { Dashboard, DashboardAuthor } from '@/entities/dashboard'
import en from './locale/en.json'
import ru from './locale/ru.json'

const vTooltip = Tooltip

interface Props {
  /** The full dashboard detail (carries author / created_at / is_published). */
  dashboard: Dashboard
  /** Whether the "Add widget" item is offered (mirrors the header button gate). */
  canAddWidget?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  canAddWidget: false,
})

const emit = defineEmits<{
  /** Toggle the library modal open (same trigger as the header "+ Add widget"). */
  (_e: 'add-widget'): void
  /** Emitted after a successful publish/unpublish — payload is the new flag. */
  (_e: 'published-changed', _isPublished: boolean): void
  /** Emitted after a successful delete — payload is the deleted dashboard id. */
  (_e: 'dashboard-deleted', _id: number): void
}>()

const { t } = useLocalI18n({ en, ru })
const { locale } = useI18n()
const { format } = useFormatter()
const { notifySuccess, notifyApiError } = useNotifications()
const { dashboardService } = useServices()
const userStore = useUserStore()
const router = useRouter()

const popoverRef = ref<InstanceType<typeof Popover> | null>(null)
const anchorRef = ref<InstanceType<typeof Button> | null>(null)
const popoverOpen = ref(false)
const deleteModalVisible = ref(false)

// See ReportActionsMenu: a sticky header toast can physically overlap the `…`
// anchor, so we keep this feature's toasts short-lived (do not change the
// global default).
const TOAST_LIFE_MS = 3000

const busyAction = ref<'publish' | 'unpublish' | 'delete' | null>(null)

const togglePopover = (event: MouseEvent): void => {
  // Guard against a PrimeVue Popover null-anchor race (see ReportActionsMenu).
  if (!event.currentTarget) return
  if (!anchorRef.value) return
  popoverRef.value?.toggle(event)
}

const closePopover = (): void => {
  popoverRef.value?.hide()
}

// ─── Author + created_at display ────────────────────────────────────────────
const author = computed<DashboardAuthor | null>(() => props.dashboard.author ?? null)

const authorDisplay = computed<string | null>(() => {
  const a = author.value
  if (!a) return null
  const trimmedName = (a.name ?? '').trim()
  return trimmedName !== '' ? trimmedName : a.email
})

const authorTooltip = computed<string | null>(() => {
  const a = author.value
  if (!a) return null
  const trimmedName = (a.name ?? '').trim()
  return trimmedName !== '' ? a.email : null
})

const createdAtDisplay = computed<string | null>(() => {
  const raw = props.dashboard.createdAt
  if (!raw) return null
  const formatted = format(raw, { type: 'datetime' })
  return typeof formatted === 'string' && formatted !== '' ? formatted : null
})

const localizedTitle = computed<string>(() =>
  getLocalizedText(props.dashboard.name, locale.value),
)

// ─── Action visibility ──────────────────────────────────────────────────────
const isSystem = computed<boolean>(() => props.dashboard.isSystem === true)
const isOwner = computed<boolean>(() => {
  const me = userStore.currentUser
  if (!me || props.dashboard.userId == null) return false
  return me.id === props.dashboard.userId
})

const canPublish = computed<boolean>(
  () => !isSystem.value && canManageDashboardPublication(userStore.currentUser?.role),
)

const canDelete = computed<boolean>(() =>
  canDeleteDashboard(userStore.currentUser?.role, isOwner.value, isSystem.value),
)

const showAddWidget = computed<boolean>(() => props.canAddWidget === true)
const showPublish = computed<boolean>(() => canPublish.value && props.dashboard.isPublished !== true)
const showUnpublish = computed<boolean>(() => canPublish.value && props.dashboard.isPublished === true)
const showDelete = computed<boolean>(() => canDelete.value)
const hasAnyAction = computed<boolean>(
  () => showAddWidget.value || showPublish.value || showUnpublish.value || showDelete.value,
)

// ─── Add widget ─────────────────────────────────────────────────────────────
const onAddWidget = (): void => {
  emit('add-widget')
  closePopover()
}

// ─── Publish / Unpublish ────────────────────────────────────────────────────
const onPublish = async (): Promise<void> => {
  if (busyAction.value !== null) return
  busyAction.value = 'publish'
  try {
    const updated = await dashboardService.publishDashboard(props.dashboard.id)
    emit('published-changed', updated.isPublished)
    notifySuccess(t('actionsMenu.toast_published'), undefined, TOAST_LIFE_MS)
    closePopover()
  } catch (error) {
    notifyApiError(error, t('actionsMenu.toast_error'))
  } finally {
    busyAction.value = null
  }
}

const onUnpublish = async (): Promise<void> => {
  if (busyAction.value !== null) return
  busyAction.value = 'unpublish'
  try {
    const updated = await dashboardService.unpublishDashboard(props.dashboard.id)
    emit('published-changed', updated.isPublished)
    notifySuccess(t('actionsMenu.toast_unpublished'), undefined, TOAST_LIFE_MS)
    closePopover()
  } catch (error) {
    notifyApiError(error, t('actionsMenu.toast_error'))
  } finally {
    busyAction.value = null
  }
}

// ─── Delete (confirmation + cascade) ────────────────────────────────────────
const onDeleteClick = (): void => {
  if (busyAction.value !== null) return
  deleteModalVisible.value = true
}

const onDeleteCancel = (): void => {
  if (busyAction.value === 'delete') return
  deleteModalVisible.value = false
}

const onDeleteConfirm = async (): Promise<void> => {
  if (busyAction.value !== null) return
  busyAction.value = 'delete'
  try {
    await dashboardService.deleteDashboard(props.dashboard.id)
    notifySuccess(t('actionsMenu.toast_deleted'), undefined, TOAST_LIFE_MS)
    emit('dashboard-deleted', props.dashboard.id)
    deleteModalVisible.value = false
    closePopover()
    void router.push('/dashboards')
  } catch (error) {
    notifyApiError(error, t('actionsMenu.toast_error'))
  } finally {
    busyAction.value = null
  }
}
</script>

<style lang="scss" scoped>
.dashboard-actions-menu {
  display: inline-flex;
  flex-shrink: 0;

  &__panel {
    display: flex;
    flex-direction: column;
    min-width: 16rem;
    max-width: 24rem;
    gap: 0.5rem;
  }

  &__info {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    padding: 0.25rem 0.25rem 0.1rem;
  }

  &__info-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    font-size: $font-size-sm;
    min-height: 1.5rem;
  }

  &__info-label {
    color: $surface-500;
    font-weight: $font-weight-medium;
    flex-shrink: 0;
  }

  &__info-value {
    color: $surface-800;
    font-weight: $font-weight-medium;
    text-align: right;
    overflow-wrap: anywhere;
    min-width: 0;
  }

  &__divider {
    border-top: 1px solid $surface-200;
    margin: 0.25rem 0 0;
    flex-shrink: 0;
  }

  &__actions {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
  }

  &__action-btn {
    justify-content: flex-start;
    width: 100%;

    :deep(.p-button-label) {
      flex: 1;
      text-align: left;
      font-weight: $font-weight-medium;
    }
  }
}
</style>
