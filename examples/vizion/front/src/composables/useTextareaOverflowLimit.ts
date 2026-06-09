import { computed, nextTick, onMounted, ref } from 'vue'

export const useTextareaOverflowLimit = (maxHeight: number) => {
  const rootRef = ref<HTMLElement | null>(null)
  const overflowY = ref<'hidden' | 'auto'>('hidden')

  const getTextareaElement = () => rootRef.value?.querySelector('textarea') ?? null

  const syncOverflow = () => {
    const textarea = getTextareaElement()
    if (!textarea) return

    overflowY.value = textarea.scrollHeight > maxHeight ? 'auto' : 'hidden'
  }

  const syncOverflowAfterRender = () => {
    void nextTick(syncOverflow)
  }

  const textareaStyle = computed(() => ({
    maxHeight: `${maxHeight}px`,
    overflowY: overflowY.value,
  }))

  onMounted(() => {
    syncOverflow()
  })

  return {
    rootRef,
    textareaStyle,
    syncOverflow,
    syncOverflowAfterRender,
  }
}
