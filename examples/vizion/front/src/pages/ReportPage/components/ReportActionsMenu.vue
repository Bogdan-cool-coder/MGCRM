<template>
  <div class="report-actions-menu">
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
      <div class="report-actions-menu__panel" :aria-label="t('actionsMenu.title')">
        <!-- Info block — always present, even for viewer (read-only metadata). -->
        <div class="report-actions-menu__info" :aria-label="t('actionsMenu.info_section')">
          <div v-if="report.is_system" class="report-actions-menu__info-row">
            <span class="report-actions-menu__info-label">{{ t('actionsMenu.status') }}</span>
            <Tag :value="t('actionsMenu.status_system')" severity="info" />
          </div>

          <template v-else>
            <div v-if="authorDisplay" class="report-actions-menu__info-row">
              <span class="report-actions-menu__info-label">{{ t('actionsMenu.author') }}</span>
              <span
                class="report-actions-menu__info-value"
                :title="authorTooltip ?? undefined"
              >{{ authorDisplay }}</span>
            </div>

            <div v-if="createdAtDisplay" class="report-actions-menu__info-row">
              <span class="report-actions-menu__info-label">{{ t('actionsMenu.created_at') }}</span>
              <span class="report-actions-menu__info-value">{{ createdAtDisplay }}</span>
            </div>

            <div class="report-actions-menu__info-row">
              <span class="report-actions-menu__info-label">{{ t('actionsMenu.status') }}</span>
              <Tag
                :value="report.is_published ? t('actionsMenu.status_published') : t('actionsMenu.status_draft')"
                :severity="report.is_published ? 'success' : 'warn'"
              />
            </div>
          </template>
        </div>

        <!-- Divider + actions list. Hidden entirely for system reports and
             for users with no available actions (viewer on someone else's
             custom report). -->
        <template v-if="hasAnyAction">
          <div class="report-actions-menu__divider" role="separator" />

          <div class="report-actions-menu__actions">
            <Button
              v-if="showEditWithAi"
              :label="t('actionsMenu.editWithAi')"
              icon="pi pi-sparkles"
              severity="secondary"
              text
              :disabled="busyAction !== null"
              class="report-actions-menu__action-btn"
              @click="onEditWithAi"
            />

            <Button
              v-if="showPublish"
              :label="t('actionsMenu.publish')"
              icon="pi pi-eye"
              severity="secondary"
              text
              :loading="busyAction === 'publish'"
              :disabled="busyAction !== null && busyAction !== 'publish'"
              class="report-actions-menu__action-btn"
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
              class="report-actions-menu__action-btn"
              @click="onUnpublish"
            />

            <Button
              v-if="showDelete"
              :label="t('actionsMenu.delete')"
              icon="pi pi-trash"
              severity="danger"
              text
              :disabled="busyAction !== null"
              class="report-actions-menu__action-btn report-actions-menu__action-btn--danger"
              @click="onDeleteClick"
            />
          </div>
        </template>
      </div>
    </Popover>

    <!-- Delete confirmation. Re-uses the shared `DeleteConfirmModal` (Dialog
         shell) so we inherit existing accessibility / breakpoint behaviour.
         The shared modal renders "Вы уверены, что хотите удалить <itemName>?"
         from its own locale keys; `warningText` overrides the default
         "это действие нельзя отменить" line with our chat-cascade note. -->
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
import { useReportGenerationModalStore } from '@/stores/reportGenerationModal'
import {
  canDeleteReport,
  canEditReportWithAI,
  canManageReportPublication,
} from '@/shared/auth/capabilities'
import { getLocalizedText } from '@/utils/localization'
import DeleteConfirmModal from '@/components/modals/DeleteConfirmModal.vue'
import type { ReportAuthorDto } from '@/api/types/reports'
import type { Report, ReportItem } from '@/entities/report'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

const vTooltip = Tooltip

/**
 * The report shape we accept here is the page-level `ReportItem` projection
 * (which is what `useReportPageData` exposes). It carries the metadata we
 * need (`is_system`, `is_published`, `user_id`, `author`, `created_at`,
 * `title`). Marked as `Readonly` to make it visually obvious the menu does
 * not mutate the report — server returns the updated DTO and the parent
 * forwards it via `report-updated`.
 */
interface Props {
  report: ReportItem
}

const props = defineProps<Props>()

const emit = defineEmits<{
  /** Emitted after a successful publish/unpublish — payload is the fresh DTO. */
  (_e: 'report-updated', _report: Report): void
  /** Emitted after a successful delete — payload is the deleted report id. */
  (_e: 'report-deleted', _id: number): void
}>()

const { t } = useLocalI18n({ en, ru })
const { locale } = useI18n()
const { format } = useFormatter()
const { notifySuccess, notifyApiError } = useNotifications()
const { reportService } = useServices()
const userStore = useUserStore()
const modalStore = useReportGenerationModalStore()

const popoverRef = ref<InstanceType<typeof Popover> | null>(null)
const anchorRef = ref<InstanceType<typeof Button> | null>(null)
const popoverOpen = ref(false)
const deleteModalVisible = ref(false)

/**
 * Local toast lifetime for this feature.
 *
 * Why explicit: `useNotifications.notifySuccess` defaults `life` to `undefined`,
 * which spreads onto PrimeVue Toast and disables auto-dismiss (toast becomes
 * sticky for ~10s+). Since the actions menu lives in the page header next to a
 * `top-right` toast, a sticky toast physically overlaps the `…` button — Playwright
 * `click()` then fails with "subtree intercepts pointer events". We pass 3000 ms
 * explicitly only for this feature's toasts (do NOT change the global default).
 */
const TOAST_LIFE_MS = 3000
/**
 * Tracks which action is currently in-flight. Allows us to:
 *   - show a spinner only on the active action's button
 *   - disable the other actions until the request resolves
 *   - keep the popover open during the request (so the user sees the
 *     spinner before it auto-hides on success)
 */
const busyAction = ref<'publish' | 'unpublish' | 'delete' | null>(null)

const togglePopover = (event: MouseEvent): void => {
  // Guard against a PrimeVue Popover race: if Escape fires immediately before
  // a programmatic re-click, the previous leave-transition can still be
  // tearing down (overlay DOM = null) while `onEnter` runs for the new open,
  // producing:
  //   TypeError: Cannot read properties of null (reading 'offsetHeight')
  //     at alignOverlay → onEnter (primevue Popover internals)
  // The handler is harmless to UX but pollutes the console. We require both
  // a live event target and a mounted anchor button before forwarding the
  // toggle — that's enough to drop the invalid-anchor path without changing
  // the happy-path click behaviour.
  if (!event.currentTarget) return
  if (!anchorRef.value) return
  popoverRef.value?.toggle(event)
}

const closePopover = (): void => {
  popoverRef.value?.hide()
}

// ─── Author + created_at display ────────────────────────────────────────────
// Author shows `name` with a `title` tooltip carrying the email; if the user
// has no name set (rare but possible) we fall back to the email as the
// visible label so the line is not empty.
const author = computed<ReportAuthorDto | null>(() => props.report.author ?? null)

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
  // Only surface the email as a tooltip when it is different from the
  // visible label (i.e. when we are showing the name). Showing
  // `email title=email` would be visual noise.
  return trimmedName !== '' ? a.email : null
})

const createdAtDisplay = computed<string | null>(() => {
  const raw = props.report.created_at
  if (!raw) return null
  // `format(_, { type: 'datetime' })` uses the active-company timezone +
  // current locale for both date and HH:mm parts — same helper the report
  // table cells use, so display stays consistent across the page.
  const formatted = format(raw, { type: 'datetime' })
  return typeof formatted === 'string' && formatted !== '' ? formatted : null
})

const localizedTitle = computed<string>(() =>
  getLocalizedText(props.report.title, locale.value),
)

// ─── Action visibility ──────────────────────────────────────────────────────
const isSystem = computed<boolean>(() => props.report.is_system === true)
const isOwner = computed<boolean>(() => {
  const me = userStore.currentUser
  if (!me || props.report.user_id == null) return false
  return me.id === props.report.user_id
})

const canPublish = computed<boolean>(() =>
  !isSystem.value && canManageReportPublication(userStore.currentUser?.role),
)

const canDelete = computed<boolean>(() =>
  canDeleteReport(userStore.currentUser?.role, isOwner.value, isSystem.value),
)

// Edit-with-AI: owner of a custom report, and only when the report has a
// `report_generation` chat to resume. System reports (user_id = null) and
// older reports without a pinned chat don't qualify.
const reportChatId = computed<number | null>(() => props.report.chatId ?? null)
const showEditWithAi = computed<boolean>(
  () => canEditReportWithAI(isOwner.value, isSystem.value) && reportChatId.value != null,
)

const showPublish = computed<boolean>(() => canPublish.value && props.report.is_published !== true)
const showUnpublish = computed<boolean>(() => canPublish.value && props.report.is_published === true)
const showDelete = computed<boolean>(() => canDelete.value)
const hasAnyAction = computed<boolean>(
  () => showEditWithAi.value || showPublish.value || showUnpublish.value || showDelete.value,
)

// ─── Edit with AI ─────────────────────────────────────────────────────────
// Opens the global report-generation modal in edit-mode, resuming the
// report's existing `report_generation` chat. The open report page watches
// `modalStore.reportUpdatedTick` and refetches itself when the AI turn settles.
const onEditWithAi = (): void => {
  const chatId = reportChatId.value
  if (chatId == null) return
  modalStore.open({ mode: 'edit', reportId: props.report.id, chatId })
  closePopover()
}

// ─── Publish / Unpublish ────────────────────────────────────────────────────
const onPublish = async (): Promise<void> => {
  if (busyAction.value !== null) return
  busyAction.value = 'publish'
  try {
    const updated = await reportService.publishReport(props.report.id)
    emit('report-updated', updated)
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
    const updated = await reportService.unpublishReport(props.report.id)
    emit('report-updated', updated)
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
  // Block cancel while the request is in flight — same UX as the rest of
  // the project's DeleteConfirmModal callers (avoid double-fire on slow nets).
  if (busyAction.value === 'delete') return
  deleteModalVisible.value = false
}

const onDeleteConfirm = async (): Promise<void> => {
  if (busyAction.value !== null) return
  busyAction.value = 'delete'
  try {
    await reportService.deleteReport(props.report.id)
    notifySuccess(t('actionsMenu.toast_deleted'), undefined, TOAST_LIFE_MS)
    emit('report-deleted', props.report.id)
    deleteModalVisible.value = false
    closePopover()
  } catch (error) {
    notifyApiError(error, t('actionsMenu.toast_error'))
  } finally {
    busyAction.value = null
  }
}
</script>

<style lang="scss" scoped>
.report-actions-menu {
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
    // Stretch text-buttons to the full popover width so the icon+label
    // sit flush-left like a typical action menu (PrimeVue Button defaults
    // to inline width).
    justify-content: flex-start;
    width: 100%;

    :deep(.p-button-label) {
      flex: 1;
      text-align: left;
      font-weight: $font-weight-medium;
    }

    &--danger {
      // PrimeVue `severity="danger" text` renders the icon and label in
      // the danger token by default — we just lean on that. Keep this
      // class as a hook in case future iterations want a stronger hover
      // background (currently no override needed).
    }
  }
}
</style>
