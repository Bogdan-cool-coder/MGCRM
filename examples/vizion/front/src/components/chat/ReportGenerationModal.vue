<template>
  <Dialog
    :visible="modalStore.isOpen"
    modal
    :header="headerTitle"
    :closable="true"
    :dismissable-mask="false"
    class="report-gen-modal"
    :pt="{
      root: { class: 'report-gen-modal__root' },
      content: { class: 'report-gen-modal__content' },
    }"
    :breakpoints="{ '960px': '92vw' }"
    :style="{ width: '720px' }"
    @update:visible="handleVisibleChange"
    @hide="handleHide"
  >
    <div class="report-gen-modal__body">
      <LoadingState v-if="isLoading" />

      <EmptyState
        v-else-if="isPreview"
        icon="pi pi-sparkles"
        :message="previewMessage"
      />

      <EmptyState
        v-else-if="!currentChat || messages.length === 0"
        icon="pi pi-sparkles"
        :message="previewMessage"
      />

      <ChatMessageList
        v-else
        :messages="messages"
        :is-sending="isSending"
      />
    </div>

    <template #footer>
      <div class="report-gen-modal__footer">
        <Button
          v-if="showOpenReportCta"
          class="report-gen-modal__cta"
          icon="pi pi-external-link"
          :label="t('reportGenerationModal.openReport')"
          severity="success"
          @click="openCreatedReport"
        />

        <ChatInput
          v-model="inputValue"
          class="report-gen-modal__input"
          :disabled="isSending || isLoading"
          :placeholder="t('reportGenerationModal.placeholder')"
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
import { useReportGenerationModalChat } from './composables/useReportGenerationModalChat'
import EmptyState from '@/components/states/EmptyState.vue'
import LoadingState from '@/components/states/LoadingState.vue'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useReportGenerationModalStore } from '@/stores/reportGenerationModal'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t } = useLocalI18n({ en, ru })
const modalStore = useReportGenerationModalStore()
const route = useRoute()
const router = useRouter()

const chat = useReportGenerationModalChat()
// Destructure refs for idiomatic template access (template auto-unwrap only
// applies to top-level setup-return keys).
const { isPreview, currentChat, messages, isLoading, isSending, createdReportId, inputValue } = chat

const headerTitle = computed(() =>
  modalStore.mode === 'edit'
    ? t('reportGenerationModal.title.edit')
    : t('reportGenerationModal.title.create'),
)

const previewMessage = computed(() =>
  modalStore.mode === 'edit'
    ? t('reportGenerationModal.previewEdit')
    : t('reportGenerationModal.previewCreate'),
)

/**
 * The report id of the page we're currently on (if any). When the modal opens
 * in edit-mode on `/reports/:id`, the "Open report" CTA is redundant — the SPA
 * refetch (driven by `signalReportUpdated`) updates the visible report in
 * place. So we hide the CTA when its target equals the route's report.
 */
const currentRouteReportId = computed<number | null>(() => {
  if (route.name !== 'ReportDetail') return null
  const raw = route.params.id
  const id = Number(Array.isArray(raw) ? raw[0] : raw)
  return Number.isFinite(id) && id > 0 ? id : null
})

const showOpenReportCta = computed(
  () =>
    createdReportId.value !== null &&
    createdReportId.value !== currentRouteReportId.value,
)

const handleSubmit = async (content: string) => {
  await chat.sendMessage(content)
}

const openCreatedReport = () => {
  const id = createdReportId.value
  if (id === null) return
  modalStore.close()
  void router.push(`/reports/${id}`)
}

const handleVisibleChange = (value: boolean) => {
  if (!value) modalStore.close()
}

/**
 * Fires after the Dialog has fully closed. Tear down the chat state and clear
 * the store's transient opts so a subsequent open starts clean. The active SSE
 * subscription is stopped, but the background AI turn keeps running (matches
 * the mini-chat "closing never blocks the stream" policy).
 */
const handleHide = () => {
  chat.reset()
  modalStore.resetOptions()
}

/**
 * Drive the chat lifecycle off the store's open flag. Initializing here (rather
 * than in the Dialog's `@show`) keeps the single source of truth in the store —
 * any trigger that flips `isOpen` to true gets the chat initialized for the
 * mode/ids it set alongside it.
 */
watch(
  () => modalStore.isOpen,
  (open) => {
    if (open) void chat.init()
  },
)
</script>

<style lang="scss" scoped>
// NOTE: PrimeVue Dialog teleports to <body>, so the panel sizing lives on the
// `:pt` root/content classes targeted via `:deep`. The body/footer flex layout
// below is scoped to the slotted content, which renders inside the teleported
// content node — `:deep` from a scoped block reaches it because the slot
// content carries this component's data-v attribute.
:deep(.report-gen-modal__root) {
  max-height: 88vh;
}

:deep(.report-gen-modal__content) {
  display: flex;
  flex-direction: column;
  padding: 0;
  min-height: 0;
}

.report-gen-modal__body {
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

.report-gen-modal__footer {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  width: 100%;
}

.report-gen-modal__cta {
  align-self: flex-start;
}

.report-gen-modal__input {
  // Override ChatInput's own top-border/padding — inside the dialog footer it
  // sits flush, the dialog already supplies separation.
  border-top: none;
  padding: 0;
}
</style>
