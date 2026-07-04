<template>
  <Drawer
    v-model:visible="visible"
    position="right"
    style="width: 440px"
    :modal="true"
    :dismissable="true"
    :show-close-icon="false"
    class="ai-tutor-drawer"
  >
    <template #header>
      <div class="ai-tutor-drawer__header d-flex align-items-center justify-content-between w-full">
        <div class="ai-tutor-drawer__header-brand d-flex align-items-center">
          <i class="pi pi-sparkles ai-tutor-drawer__brand-icon" />
          <span class="ai-tutor-drawer__brand-title">{{ t('onboarding.coursePage.aiTutor.title') }}</span>
        </div>
        <div class="ai-tutor-drawer__header-actions d-flex align-items-center">
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
    <p class="ai-tutor-drawer__lesson-title ai-tutor-drawer__muted mb-3">
      {{ lessonTitle }}
    </p>

    <!-- Messages history -->
    <div ref="messagesEl" class="ai-tutor-drawer__messages">
      <!-- Loading history -->
      <div v-if="isLoadingHistory" class="ai-tutor-drawer__skeletons d-flex flex-column">
        <Skeleton height="60px" class="mb-1" />
        <Skeleton height="80px" class="mb-1" />
      </div>

      <!-- Empty history -->
      <div
        v-else-if="messages.length === 0 && !isSending"
        class="ai-tutor-drawer__empty ai-tutor-drawer__muted"
      >
        <i class="pi pi-comments ai-tutor-drawer__empty-icon" style="opacity: 0.3" />
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
        <div v-if="isSending" class="ai-tutor-drawer__sending d-flex align-items-center mt-2">
          <ProgressSpinner style="width: 24px; height: 24px" strokeWidth="4" />
          <span class="ai-tutor-drawer__muted ai-tutor-drawer__thinking-text">{{ t('onboarding.coursePage.aiTutor.thinking') }}</span>
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
        class="w-full mb-2"
        @keydown.enter.ctrl="handleSend"
      />
      <Button
        :label="t('onboarding.coursePage.aiTutor.send')"
        icon="pi pi-send"
        :loading="isSending"
        :disabled="!question?.trim()"
        class="w-full"
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
// Local width util (full-Bootstrap .w-100 is absent from the grid-only bundle).
.w-full {
  width: 100%;
}

.ai-tutor-drawer {
  // Theme-reactive muted text — replaces dead full-Bootstrap .text-muted.
  &__muted {
    color: var(--p-text-muted-color);
  }

  &__header {
    gap: $space-2;
  }

  &__header-brand {
    gap: $space-2;
  }

  &__header-actions {
    gap: $space-1;
  }

  &__brand-icon {
    color: var(--p-primary-color);
  }

  &__brand-title {
    font-weight: $font-weight-bold;
  }

  &__skeletons {
    gap: $space-2;
  }

  &__empty {
    text-align: center;
    padding-block: $space-4;
  }

  &__sending {
    gap: $space-2;
  }

  &__lesson-title {
    font-size: $font-size-sm; // snap from 0.8125rem (15px→14px)
  }

  &__empty-icon {
    font-size: $font-size-icon-lg; // 2rem
  }

  &__thinking-text {
    font-size: $font-size-sm; // 0.875rem
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
        // --p-primary-50 is a STATIC near-white palette step → light-on-light user
        // bubble in dark. A color-mix accent tint over the surface reads both themes.
        background: color-mix(in srgb, var(--p-primary-color) 14%, var(--p-card-background));
        border-radius: $radius-xl $radius-xl $radius-2xs $radius-xl;
        text-align: right;
      }
    }

    &--assistant {
      .ai-tutor-drawer__bubble {
        background: var(--p-surface-100);
        border-radius: $radius-xl $radius-xl $radius-xl $radius-2xs;
      }
    }
  }

  &__ai-icon {
    font-size: $font-size-md; // 1rem
    color: var(--p-primary-color);
    margin-top: 0.25rem;
    flex-shrink: 0;
  }

  &__bubble {
    max-width: 85%;
    padding: 0.625rem 0.75rem;
  }

  &__text {
    font-size: $font-size-sm; // snap from 0.9375rem (15px→14px)
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
  }

  &__time {
    font-size: $font-size-2xs; // 0.6875rem = 11px
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
