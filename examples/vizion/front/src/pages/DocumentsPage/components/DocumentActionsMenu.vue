<template>
  <div class="document-actions-menu" @click.stop>
    <Button
      ref="anchorRef"
      v-tooltip.bottom="t('actionsMenu.title')"
      icon="pi pi-ellipsis-v"
      severity="secondary"
      text
      rounded
      size="small"
      :aria-label="t('actionsMenu.title')"
      :aria-haspopup="true"
      :aria-expanded="popoverOpen"
      @click="togglePopover"
    />

    <Popover ref="popoverRef" @show="popoverOpen = true" @hide="popoverOpen = false">
      <div class="document-actions-menu__panel" :aria-label="t('actionsMenu.title')">
        <div class="document-actions-menu__info" :aria-label="t('actionsMenu.info_section')">
          <div v-if="document.isSystem" class="document-actions-menu__info-row">
            <span class="document-actions-menu__info-label">{{ t('actionsMenu.status') }}</span>
            <Tag :value="t('actionsMenu.status_system')" severity="info" />
          </div>

          <template v-else>
            <div v-if="authorDisplay" class="document-actions-menu__info-row">
              <span class="document-actions-menu__info-label">{{ t('actionsMenu.author') }}</span>
              <span
                class="document-actions-menu__info-value"
                :title="authorTooltip ?? undefined"
              >{{ authorDisplay }}</span>
            </div>

            <div v-if="createdAtDisplay" class="document-actions-menu__info-row">
              <span class="document-actions-menu__info-label">{{ t('actionsMenu.created_at') }}</span>
              <span class="document-actions-menu__info-value">{{ createdAtDisplay }}</span>
            </div>

            <div class="document-actions-menu__info-row">
              <span class="document-actions-menu__info-label">{{ t('actionsMenu.status') }}</span>
              <Tag
                :value="document.isPublished ? t('actionsMenu.status_published') : t('actionsMenu.status_draft')"
                :severity="document.isPublished ? 'success' : 'warn'"
              />
            </div>
          </template>

          <!-- System templates still expose the publish status as a tag, so the
               info block is never empty. -->
          <div
            v-if="document.isSystem && createdAtDisplay"
            class="document-actions-menu__info-row"
          >
            <span class="document-actions-menu__info-label">{{ t('actionsMenu.created_at') }}</span>
            <span class="document-actions-menu__info-value">{{ createdAtDisplay }}</span>
          </div>
        </div>

        <template v-if="hasAnyAction">
          <div class="document-actions-menu__divider" role="separator" />

          <div class="document-actions-menu__actions">
            <Button
              v-if="showEdit"
              :label="t('actionsMenu.edit')"
              icon="pi pi-pencil"
              severity="secondary"
              text
              :disabled="busyAction !== null"
              class="document-actions-menu__action-btn"
              @click="onEdit"
            />

            <Button
              v-if="showPublish"
              :label="t('actionsMenu.publish')"
              icon="pi pi-eye"
              severity="secondary"
              text
              :loading="busyAction === 'publish'"
              :disabled="busyAction !== null && busyAction !== 'publish'"
              class="document-actions-menu__action-btn"
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
              class="document-actions-menu__action-btn"
              @click="onUnpublish"
            />

            <Button
              v-if="showDelete"
              :label="t('actionsMenu.delete')"
              icon="pi pi-trash"
              severity="danger"
              text
              :disabled="busyAction !== null"
              class="document-actions-menu__action-btn document-actions-menu__action-btn--danger"
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
  canDeleteDocument,
  canManageDocuments,
  canManageDocumentPublication,
} from '@/shared/auth/capabilities'
import { getLocalizedText } from '@/utils/localization'
import DeleteConfirmModal from '@/components/modals/DeleteConfirmModal.vue'
import type { DocumentTemplate, DocumentTemplateListItem } from '@/entities/document'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

const vTooltip = Tooltip

/**
 * Accepts either the library list-item projection (DocumentsPage grid) or the
 * full template detail (DocumentPage header). The detail carries `createdAt`
 * (shown as an extra info row when present); both share the metadata the menu
 * needs (`isSystem`, `isPublished`, `userId`, `author`, `name`).
 */
type MenuDocument = DocumentTemplateListItem | DocumentTemplate

interface Props {
  document: MenuDocument
  /**
   * When true (DocumentPage header), the menu offers an "Edit" action that
   * opens the edit modal via `@edit`. The DocumentsPage grid leaves it off —
   * editing happens by navigating into the template.
   */
  editable?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  editable: false,
})

const emit = defineEmits<{
  /** Emitted after a successful publish/unpublish — payload is the fresh template. */
  (_e: 'document-updated', _document: DocumentTemplate): void
  /** Emitted after a successful delete — payload is the deleted template id. */
  (_e: 'document-deleted', _id: number): void
  /** Emitted when the user picks "Edit" — parent opens the edit modal. */
  (_e: 'edit'): void
}>()

const { t } = useLocalI18n({ en, ru })
const { locale } = useI18n()
const { format } = useFormatter()
const { notifySuccess, notifyApiError } = useNotifications()
const { documentService } = useServices()
const userStore = useUserStore()

const popoverRef = ref<InstanceType<typeof Popover> | null>(null)
const anchorRef = ref<InstanceType<typeof Button> | null>(null)
const popoverOpen = ref(false)
const deleteModalVisible = ref(false)

// Local toast lifetime: a sticky toast would overlap the actions button and
// break click-through; same rationale as `ReportActionsMenu`.
const TOAST_LIFE_MS = 3000
const busyAction = ref<'publish' | 'unpublish' | 'delete' | null>(null)

const togglePopover = (event: MouseEvent): void => {
  // Same PrimeVue Popover anchor guard as ReportActionsMenu — drop invalid
  // anchor paths (replayed Escape → re-click) to keep the console clean.
  if (!event.currentTarget) return
  if (!anchorRef.value) return
  popoverRef.value?.toggle(event)
}

const closePopover = (): void => {
  popoverRef.value?.hide()
}

const author = computed(() => props.document.author ?? null)

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

const localizedTitle = computed<string>(() =>
  getLocalizedText(props.document.name, locale.value),
)

// `createdAt` only exists on the full detail shape (DocumentPage). Narrow on the
// property's presence rather than the type so the list-item path stays safe.
const createdAtDisplay = computed<string | null>(() => {
  const raw = (props.document as DocumentTemplate).createdAt
  if (!raw) return null
  const formatted = format(raw, { type: 'datetime' })
  return typeof formatted === 'string' && formatted !== '' ? formatted : null
})

const isSystem = computed<boolean>(() => props.document.isSystem === true)
const isOwner = computed<boolean>(() => {
  const me = userStore.currentUser
  if (!me || props.document.userId == null) return false
  return me.id === props.document.userId
})

const canPublish = computed<boolean>(
  () => !isSystem.value && canManageDocumentPublication(userStore.currentUser?.role),
)

const canDelete = computed<boolean>(() =>
  canDeleteDocument(userStore.currentUser?.role, isOwner.value, isSystem.value),
)

// Edit (DocumentPage header only): analyst+ on a non-system template. System
// templates are read-only for everyone (backend rejects writes).
const canEdit = computed<boolean>(
  () => !isSystem.value && canManageDocuments(userStore.currentUser?.role),
)
const showEdit = computed<boolean>(() => props.editable && canEdit.value)

const showPublish = computed<boolean>(
  () => canPublish.value && props.document.isPublished !== true,
)
const showUnpublish = computed<boolean>(
  () => canPublish.value && props.document.isPublished === true,
)
const showDelete = computed<boolean>(() => canDelete.value)
const hasAnyAction = computed<boolean>(
  () => showEdit.value || showPublish.value || showUnpublish.value || showDelete.value,
)

const onEdit = (): void => {
  if (busyAction.value !== null) return
  emit('edit')
  closePopover()
}

const onPublish = async (): Promise<void> => {
  if (busyAction.value !== null) return
  busyAction.value = 'publish'
  try {
    const updated = await documentService.publishTemplate(props.document.id)
    emit('document-updated', updated)
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
    const updated = await documentService.unpublishTemplate(props.document.id)
    emit('document-updated', updated)
    notifySuccess(t('actionsMenu.toast_unpublished'), undefined, TOAST_LIFE_MS)
    closePopover()
  } catch (error) {
    notifyApiError(error, t('actionsMenu.toast_error'))
  } finally {
    busyAction.value = null
  }
}

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
    await documentService.deleteTemplate(props.document.id)
    notifySuccess(t('actionsMenu.toast_deleted'), undefined, TOAST_LIFE_MS)
    emit('document-deleted', props.document.id)
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
.document-actions-menu {
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
