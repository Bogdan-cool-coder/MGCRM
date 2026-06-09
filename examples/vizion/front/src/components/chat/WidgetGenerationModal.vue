<template>
  <Dialog
    :visible="modalStore.isOpen"
    modal
    :header="headerTitle"
    :closable="true"
    :dismissable-mask="false"
    class="widget-gen-modal"
    :pt="{
      root: { class: 'widget-gen-modal__root' },
      content: { class: 'widget-gen-modal__content' },
    }"
    :breakpoints="{ '960px': '92vw' }"
    :style="{ width: '720px' }"
    @update:visible="handleVisibleChange"
    @hide="handleHide"
  >
    <div class="widget-gen-modal__body">
      <LoadingState v-if="isLoading" />

      <WidgetVariantsPanel
        v-else-if="showVariants"
        class="widget-gen-modal__variants"
        :variants="variants"
        :disabled="isSelectingVariant"
        @pick="handleVariantPick"
      />

      <WidgetPromptPresets
        v-else-if="showPresets"
        class="widget-gen-modal__presets"
        @pick="applyPreset"
      />

      <EmptyState
        v-else-if="isPreview || !currentChat || messages.length === 0"
        icon="pi pi-sparkles"
        :message="previewMessage"
      />

      <ChatMessageList v-else :messages="messages" :is-sending="isSending" />
    </div>

    <template #footer>
      <div class="widget-gen-modal__footer">
        <Button
          v-if="showAddToDashboardCta"
          class="widget-gen-modal__cta"
          icon="pi pi-plus"
          :label="t('widgetGenerationModal.addToDashboard')"
          severity="success"
          :loading="isAttaching"
          :disabled="isAttaching"
          @click="addCreatedWidgetToDashboard"
        />

        <div v-else-if="showDoneCta" class="widget-gen-modal__cta-row">
          <Button
            class="widget-gen-modal__cta"
            icon="pi pi-check"
            :label="t('widgetGenerationModal.done')"
            severity="success"
            @click="finishAndClose"
          />

          <Button
            v-if="showGoToDashboardsCta"
            class="widget-gen-modal__cta"
            icon="pi pi-th-large"
            :label="t('widgetGenerationModal.goToDashboards')"
            severity="secondary"
            outlined
            @click="goToDashboards"
          />
        </div>

        <ChatInput
          v-model="inputValue"
          class="widget-gen-modal__input"
          :disabled="isSending || isLoading"
          :placeholder="t('widgetGenerationModal.placeholder')"
          @submit="handleSubmit"
        />
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import ChatInput from './ChatInput.vue'
import ChatMessageList from './ChatMessageList.vue'
import WidgetPromptPresets from './WidgetPromptPresets.vue'
import WidgetVariantsPanel from './WidgetVariantsPanel.vue'
import { useWidgetGenerationModalChat } from './composables/useWidgetGenerationModalChat'
import EmptyState from '@/components/states/EmptyState.vue'
import LoadingState from '@/components/states/LoadingState.vue'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useNotifications } from '@/composables/useNotifications'
import { useServices } from '@/services'
import { useWidgetGenerationModalStore } from '@/stores/widgetGenerationModal'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t } = useLocalI18n({ en, ru })
const { notifyApiError, notifySuccess } = useNotifications()
const { dashboardService } = useServices()
const modalStore = useWidgetGenerationModalStore()
const route = useRoute()
const router = useRouter()

const chat = useWidgetGenerationModalChat()
const {
  isPreview,
  currentChat,
  messages,
  isLoading,
  isSending,
  createdWidgetId,
  inputValue,
  variants,
  isSelectingVariant,
} = chat

const isAttaching = ref(false)

const headerTitle = computed(() =>
  modalStore.mode === 'edit'
    ? t('widgetGenerationModal.title.edit')
    : t('widgetGenerationModal.title.create'),
)

const previewMessage = computed(() =>
  modalStore.mode === 'edit'
    ? t('widgetGenerationModal.previewEdit')
    : t('widgetGenerationModal.previewCreate'),
)

/**
 * Starter-prompt chips replace the bare preview message in create-mode while the
 * chat is still empty. Edit-mode keeps the plain "describe what to change" hint
 * (the presets are creation phrases). Hidden once the first message exists.
 */
const showPresets = computed(
  () =>
    modalStore.mode === 'create' &&
    (isPreview.value || !currentChat.value) &&
    messages.value.length === 0,
)

/**
 * Variant-picker takes over the body once the AI proposes 2–4 variants (the
 * `widget_variants` event populated `variants`). Shown above the message list:
 * the user picks one, which sends "Create variant N" and clears the panel.
 */
const showVariants = computed(() => variants.value.length > 0)

/** Drops a preset phrase into the input without sending — user edits/sends. */
const applyPreset = (phrase: string) => {
  inputValue.value = phrase
}

const handleVariantPick = async (index: number) => {
  await chat.selectVariant(index)
}

/**
 * "Add to dashboard" CTA — shown only when (a) a widget was created in this
 * session, (b) the modal was opened from a dashboard (`dashboardId` set) and
 * (c) we're in create-mode (editing an already-placed widget shouldn't re-attach).
 */
const showAddToDashboardCta = computed(
  () =>
    createdWidgetId.value !== null &&
    modalStore.mode === 'create' &&
    modalStore.dashboardId !== null,
)

/**
 * "Done" CTA — shown when a widget was created/updated but there's no dashboard
 * to attach to (e.g. created from the library outside a dashboard, or edit-mode).
 */
const showDoneCta = computed(
  () => createdWidgetId.value !== null && !showAddToDashboardCta.value,
)

/**
 * "Go to dashboards" CTA — sits next to "Done" in the completed state. Shown only
 * when the user is NOT already on a specific dashboard's page (`DashboardDetail`,
 * `/dashboards/:id`): if the modal was opened from a dashboard the user is already
 * there, so the shortcut would be pointless. Evaluated against the live route at
 * the moment the completed state renders.
 */
const showGoToDashboardsCta = computed(
  () => showDoneCta.value && route.name !== 'DashboardDetail',
)

const handleSubmit = async (content: string) => {
  await chat.sendMessage(content)
}

/** Close the modal and navigate to the dashboards list. */
const goToDashboards = () => {
  modalStore.close()
  void router.push({ name: 'Dashboards' })
}

const addCreatedWidgetToDashboard = async () => {
  const widgetId = createdWidgetId.value
  const dashboardId = modalStore.dashboardId
  if (widgetId === null || dashboardId === null || isAttaching.value) return

  isAttaching.value = true
  try {
    await dashboardService.attachWidget(dashboardId, { widget_id: widgetId })
    notifySuccess(t('widgetGenerationModal.addedToDashboard'), t('common.success'))
    // Signal so the dashboard page refetches its widget list / data.
    modalStore.signalWidgetUpdated(widgetId)
    modalStore.close()
  } catch (err) {
    notifyApiError(err, t('widgetGenerationModal.addFailed'), t('common.error'))
  } finally {
    isAttaching.value = false
  }
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
:deep(.widget-gen-modal__root) {
  max-height: 88vh;
}

:deep(.widget-gen-modal__content) {
  display: flex;
  flex-direction: column;
  padding: 0;
  min-height: 0;
}

.widget-gen-modal__body {
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

  .widget-gen-modal__presets {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    overflow-y: auto;
  }

  .widget-gen-modal__variants {
    flex: 1;
    min-height: 0;
  }

  :deep(.loading-state) {
    flex: 1;
  }

  :deep(.message-list) {
    max-width: none;
  }
}

.widget-gen-modal__footer {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  width: 100%;
}

.widget-gen-modal__cta {
  align-self: flex-start;
}

.widget-gen-modal__cta-row {
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
}

.widget-gen-modal__input {
  border-top: none;
  padding: 0;
}
</style>
