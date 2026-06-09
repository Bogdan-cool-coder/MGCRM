<template>
  <div ref="containerRef" class="message-list">
    <ChatMessageBubble
      v-for="msg in messages"
      :key="msg.id"
      :message="msg"
      :enable-action-marker="enableActionMarker"
      @action="emit('action', $event)"
    />
  </div>
</template>

<script setup lang="ts">
import { nextTick, onMounted, ref, watch } from 'vue'
import type { ChatMessage } from '@/entities/chat'
import type { ChatActionMarker } from '@/utils/markdown'
import ChatMessageBubble from './ChatMessageBubble.vue'

interface Props {
  messages: ChatMessage[]
  isSending: boolean
  enableActionMarker?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  enableActionMarker: false,
})

const emit = defineEmits<{
  action: [payload: { marker: ChatActionMarker; onComplete: () => void }]
}>()

const containerRef = ref<HTMLElement | null>(null)
const AUTO_SCROLL_THRESHOLD_PX = 80

const scrollToBottom = () => {
  if (containerRef.value) {
    containerRef.value.scrollTop = containerRef.value.scrollHeight
  }
}

const getDistanceToBottom = (): number => {
  if (!containerRef.value) return 0

  return (
    containerRef.value.scrollHeight -
    containerRef.value.scrollTop -
    containerRef.value.clientHeight
  )
}

const isNearBottom = () => getDistanceToBottom() <= AUTO_SCROLL_THRESHOLD_PX

onMounted(() => {
  nextTick(scrollToBottom)
})

watch(
  [
    () => props.messages.length,
    () => props.messages[props.messages.length - 1]?.id ?? null,
    () => props.messages[props.messages.length - 1]?.createdAt ?? null,
  ],
  () => {
    const shouldScroll = isNearBottom()

    if (!shouldScroll) {
      return
    }

    scrollToBottom()
  },
  { flush: 'post' },
)

watch(
  () => props.isSending,
  (isSendingNow, wasSending) => {
    if (!isSendingNow || wasSending) {
      return
    }

    nextTick(scrollToBottom)
  },
  { flush: 'post' },
)
</script>

<style lang="scss" scoped>
.message-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 1rem;
  display: flex;
  flex-direction: column;
  // On the fullscreen /ai-chat page the chat column inherits the entire
  // page width (can be 1600px+ on a wide monitor). A `max-width: 70%` bubble
  // would then be ~1100px — too wide for comfortable reading and far enough
  // to amplify any horizontal overflow from long content. Center a readable
  // column; the MiniChat popover (~420px wide) is already narrower than the
  // cap and is unaffected.
  max-width: 880px;
  width: 100%;
  margin-left: auto;
  margin-right: auto;
}
</style>
