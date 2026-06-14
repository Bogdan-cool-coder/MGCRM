<template>
  <Drawer
    v-model:visible="visible"
    position="right"
    style="width: 440px"
    :header="t('onboarding.coursePage.aiTutor.title')"
    :modal="true"
    :dismissable="true"
    class="ai-tutor-drawer"
  >
    <template #header>
      <div class="d-flex align-items-center justify-content-between w-100">
        <div class="d-flex align-items-center gap-2">
          <i class="pi pi-sparkles text-primary" />
          <span class="fw-bold">{{ t('onboarding.coursePage.aiTutor.title') }}</span>
        </div>
        <div class="d-flex align-items-center gap-1">
          <Button
            :label="t('onboarding.coursePage.aiTutor.clearHistory')"
            icon="pi pi-trash"
            severity="danger"
            text
            size="small"
            @click="requestClearHistory()"
          />
          <Button
            icon="pi pi-times"
            severity="secondary"
            text
            size="small"
            :aria-label="t('common.close')"
            @click="visible = false"
          />
        </div>
      </div>
    </template>

    <!-- Lesson subtitle -->
    <p class="ai-tutor-drawer__lesson-title text-muted mb-3">
      {{ lessonTitle }}
    </p>

    <!-- Messages history -->
    <div ref="messagesEl" class="ai-tutor-drawer__messages">
      <!-- Loading history -->
      <div v-if="isLoadingHistory" class="d-flex flex-column gap-2">
        <Skeleton height="60px" class="mb-1" />
        <Skeleton height="80px" class="mb-1" />
      </div>

      <!-- Empty history -->
      <div
        v-else-if="messages.length === 0 && !isSending"
        class="text-center text-muted py-4"
      >
        <i class="pi pi-comments" style="font-size: 2rem; opacity: 0.3" />
        <p class="mt-2 mb-0">{{ t('onboarding.coursePage.aiTutor.emptyHistory') }}</p>
      </div>

      <!-- Messages -->
      <template v-else>
        <div
          v-for="msg in messages"
          :key="msg.id"
          :class="['ai-tutor-drawer__message', `ai-tutor-drawer__message--${msg.role}`]"
        >
          <i v-if="msg.role === 'assistant'" class="pi pi-sparkles ai-tutor-drawer__ai-icon" />
          <div class="ai-tutor-drawer__bubble">
            <p class="ai-tutor-drawer__text mb-1">{{ msg.content }}</p>
            <span class="ai-tutor-drawer__time">
              {{ formatTime(msg.created_at) }}
            </span>
          </div>
        </div>

        <!-- Sending indicator -->
        <div v-if="isSending" class="d-flex align-items-center gap-2 mt-2">
          <ProgressSpinner style="width: 24px; height: 24px" strokeWidth="4" />
          <span class="text-muted" style="font-size: 0.875rem">Думаю...</span>
        </div>
      </template>
    </div>

    <!-- Input area -->
    <div class="ai-tutor-drawer__input-area">
      <Textarea
        v-model="question"
        rows="2"
        auto-resize
        :placeholder="t('onboarding.coursePage.aiTutor.placeholder')"
        class="w-100 mb-2"
        @keydown.enter.ctrl="handleSend"
      />
      <Button
        :label="t('onboarding.coursePage.aiTutor.send')"
        icon="pi pi-send"
        :loading="isSending"
        :disabled="!question?.trim()"
        class="w-100"
        @click="handleSend"
      />
    </div>
  </Drawer>
</template>

<script setup lang="ts">
import { ref, watch, nextTick, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Drawer from 'primevue/drawer'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Textarea from 'primevue/textarea'
import ProgressSpinner from 'primevue/progressspinner'
import { useAiTutor } from '../composables/useAiTutor'

const props = defineProps<{
  lessonId: number
  lessonTitle?: string
}>()

const visible = defineModel<boolean>('visible', { default: false })

const { t } = useI18n()

// Destructure so refs auto-unwrap in template (plain object from composable does NOT auto-unwrap)
const {
  messages,
  question,
  isLoadingHistory,
  isSending,
  loadHistory,
  sendQuestion,
  requestClearHistory,
} = useAiTutor(props.lessonId)

const messagesEl = ref<HTMLElement | null>(null)

onMounted(async () => {
  await loadHistory()
})

watch(
  () => messages.value.length,
  async () => {
    await nextTick()
    if (messagesEl.value) {
      messagesEl.value.scrollTop = messagesEl.value.scrollHeight
    }
  },
)

async function handleSend() {
  await sendQuestion()
}

function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}
</script>

<style lang="scss" scoped>
.ai-tutor-drawer {
  &__lesson-title {
    font-size: 0.8125rem;
  }

  &__messages {
    flex: 1;
    overflow-y: auto;
    padding-bottom: 0.5rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    min-height: 200px;
    max-height: calc(100vh - 300px);
  }

  &__message {
    display: flex;
    gap: 0.5rem;

    &--user {
      flex-direction: row-reverse;

      .ai-tutor-drawer__bubble {
        background: var(--p-primary-50);
        border-radius: 12px 12px 2px 12px;
        text-align: right;
      }
    }

    &--assistant {
      .ai-tutor-drawer__bubble {
        background: var(--p-surface-100);
        border-radius: 12px 12px 12px 2px;
      }
    }
  }

  &__ai-icon {
    font-size: 1rem;
    color: var(--p-primary-color);
    margin-top: 0.25rem;
    flex-shrink: 0;
  }

  &__bubble {
    max-width: 85%;
    padding: 0.625rem 0.75rem;
  }

  &__text {
    font-size: 0.9375rem;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
  }

  &__time {
    font-size: 0.6875rem;
    color: var(--p-surface-400);
  }

  &__input-area {
    padding-top: 0.75rem;
    border-top: 1px solid var(--p-surface-200);
    margin-top: auto;
    position: sticky;
    bottom: 0;
    background: var(--p-card-background);
  }
}
</style>
