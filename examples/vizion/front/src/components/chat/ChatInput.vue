<template>
  <div ref="rootRef" class="chat-input">
    <Textarea
      v-model="content"
      :placeholder="placeholder"
      :disabled="disabled"
      :style="textareaStyle"
      auto-resize
      rows="1"
      class="chat-input__textarea"
      @input="handleInput"
      @keydown="handleKeydown"
    />
    <Button
      icon="pi pi-send"
      :disabled="disabled || !content.trim()"
      class="chat-input__btn"
      @click="handleSubmit"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Textarea from 'primevue/textarea'
import Button from 'primevue/button'
import { useTextareaOverflowLimit } from '@/composables/useTextareaOverflowLimit'

interface Props {
  disabled?: boolean
  placeholder?: string
  /**
   * Optional two-way bound draft text. Enables prefill from the parent (the
   * report-generation modal writes a starter prompt without auto-sending) and
   * lets the parent observe/clear the draft. Omitted by every existing caller,
   * which keeps the input fully self-managed via the local fallback below.
   */
  modelValue?: string
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: undefined,
})

const emit = defineEmits<{
  submit: [content: string]
  'update:modelValue': [value: string]
}>()

const MAX_TEXTAREA_HEIGHT = 160

// `content` is a writable computed proxying the optional `modelValue`. When the
// parent doesn't bind it, we fall back to a local string ref so the input
// stays self-contained (existing usages are unaffected).
const localContent = ref('')
const content = computed<string>({
  get: () => (props.modelValue !== undefined ? props.modelValue : localContent.value),
  set: (value: string) => {
    if (props.modelValue !== undefined) {
      emit('update:modelValue', value)
    } else {
      localContent.value = value
    }
  },
})

const { rootRef, textareaStyle, syncOverflowAfterRender } =
  useTextareaOverflowLimit(MAX_TEXTAREA_HEIGHT)

// Re-sync the overflow clamp when a prefill arrives from the parent (the
// textarea height must be recomputed for multi-line prefills).
watch(
  () => props.modelValue,
  () => {
    syncOverflowAfterRender()
  },
)

const handleSubmit = () => {
  const trimmed = content.value.trim()
  if (!trimmed) return
  emit('submit', trimmed)
  content.value = ''
  syncOverflowAfterRender()
}

const handleInput = () => {
  syncOverflowAfterRender()
}

const handleKeydown = (e: KeyboardEvent) => {
  const isMac = navigator.platform.toUpperCase().includes('MAC')
  const modifier = isMac ? e.metaKey : e.ctrlKey
  if (modifier && e.key === 'Enter') {
    e.preventDefault()
    handleSubmit()
  }
}
</script>

<style lang="scss" scoped>
.chat-input {
  display: flex;
  align-items: flex-end;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  border-top: 1px solid $surface-200;
  background-color: $surface-0;

  &__textarea {
    flex: 1;
    resize: none;
    min-height: 0;
  }

  &__btn {
    flex-shrink: 0;
  }
}
</style>
