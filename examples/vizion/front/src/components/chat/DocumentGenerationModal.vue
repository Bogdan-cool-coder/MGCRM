<template>
  <Dialog
    :visible="modalStore.isOpen"
    modal
    :header="headerTitle"
    :closable="true"
    :dismissable-mask="false"
    class="document-gen-modal"
    :pt="{
      root: { class: 'document-gen-modal__root' },
      content: { class: 'document-gen-modal__content' },
    }"
    :breakpoints="{ '960px': '92vw' }"
    :style="{ width: '720px' }"
    @update:visible="handleVisibleChange"
    @hide="handleHide"
  >
    <div class="document-gen-modal__body">
      <LoadingState v-if="isLoading" />

      <EmptyState
        v-else-if="isPreview || !currentChat || messages.length === 0"
        icon="pi pi-sparkles"
        :message="previewMessage"
      />

      <ChatMessageList v-else :messages="messages" :is-sending="isSending" />
    </div>

    <template #footer>
      <div class="document-gen-modal__footer">
        <div v-if="showDoneCta" class="document-gen-modal__cta-row">
          <Button
            v-if="showOpenButton"
            class="document-gen-modal__cta"
            icon="pi pi-file-edit"
            :label="t('documentGenerationModal.openDocument')"
            severity="success"
            @click="openCreatedDocument"
          />
          <Button
            class="document-gen-modal__cta"
            icon="pi pi-check"
            :label="t('documentGenerationModal.done')"
            severity="secondary"
            outlined
            @click="finishAndClose"
          />
        </div>

        <ChatInput
          v-model="inputValue"
          class="document-gen-modal__input"
          :disabled="isSending || isLoading"
          :placeholder="t('documentGenerationModal.placeholder')"
          @submit="handleSubmit"
        />
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import ChatInput from './ChatInput.vue'
import ChatMessageList from './ChatMessageList.vue'
import { useDocumentGenerationModalChat } from './composables/useDocumentGenerationModalChat'
import EmptyState from '@/components/states/EmptyState.vue'
import LoadingState from '@/components/states/LoadingState.vue'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useDocumentGenerationModalStore } from '@/stores/documentGenerationModal'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t } = useLocalI18n({ en, ru })
const modalStore = useDocumentGenerationModalStore()
const route = useRoute()
const router = useRouter()

const chat = useDocumentGenerationModalChat()
const {
  isPreview,
  currentChat,
  messages,
  isLoading,
  isSending,
  createdDocumentId,
  inputValue,
} = chat

const headerTitle = computed(() =>
  modalStore.mode === 'edit'
    ? t('documentGenerationModal.title.edit')
    : t('documentGenerationModal.title.create'),
)

const previewMessage = computed(() =>
  modalStore.mode === 'edit'
    ? t('documentGenerationModal.previewEdit')
    : t('documentGenerationModal.previewCreate'),
)

/**
 * The template id of the page we're currently on (if any). When the modal opens
 * in edit-mode on `/documents/:id`, the "Open template" CTA is redundant — the
 * open document page refetches in place (driven by `signalDocumentUpdated`). So
 * we hide it when its target equals the route's document.
 */
const currentRouteDocumentId = computed<number | null>(() => {
  if (route.name !== 'Document') return null
  const raw = route.params.id
  const id = Number(Array.isArray(raw) ? raw[0] : raw)
  return Number.isFinite(id) && id > 0 ? id : null
})

/**
 * "Open template" / "Done" CTAs — shown once the AI created/updated a document
 * template in this session. The chat is the source of truth
 * (`createdDocumentId`). The "open" button is hidden when we're already on the
 * edited template's page (the page refetches in place).
 */
const showDoneCta = computed(() => createdDocumentId.value !== null)
const showOpenButton = computed(
  () =>
    createdDocumentId.value !== null &&
    createdDocumentId.value !== currentRouteDocumentId.value,
)

const handleSubmit = async (content: string) => {
  await chat.sendMessage(content)
}

const openCreatedDocument = () => {
  const id = createdDocumentId.value
  if (id === null) return
  modalStore.close()
  void router.push(`/documents/${id}`)
}

const finishAndClose = () => {
  modalStore.close()
}

const handleVisibleChange = (value: boolean) => {
  if (!value) modalStore.close()
}

const handleHide = () => {
  chat.reset()
  modalStore.resetOptions()
}

watch(
  () => modalStore.isOpen,
  (open) => {
    if (open) void chat.init()
  },
)
</script>

<style lang="scss" scoped>
:deep(.document-gen-modal__root) {
  max-height: 88vh;
}

:deep(.document-gen-modal__content) {
  display: flex;
  flex-direction: column;
  padding: 0;
  min-height: 0;
}

.document-gen-modal__body {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
  height: min(56vh, 560px);
  overflow: hidden;

  :deep(.empty-state) {
    flex: 1;
    border: none;
    background: transparent;
    border-radius: 0;
    padding: $space-4;
  }

  :deep(.loading-state) {
    flex: 1;
  }

  :deep(.message-list) {
    max-width: none;
  }
}

.document-gen-modal__footer {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  width: 100%;
}

.document-gen-modal__cta-row {
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
}

.document-gen-modal__cta {
  align-self: flex-start;
}

.document-gen-modal__input {
  border-top: none;
  padding: 0;
}
</style>
