<template>
  <div class="lesson-view-text">
    <div
      class="lesson-text-content"
      v-html="renderedContent"
    />
    <div class="lesson-view-text__actions mt-4">
      <LessonCompleteButton
        :completed="completed"
        :loading="completing"
        @complete="$emit('complete')"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { marked } from 'marked'
import DOMPurify from 'dompurify'
import LessonCompleteButton from './LessonCompleteButton.vue'

const props = defineProps<{
  content: string | null
  completed?: boolean
  completing?: boolean
}>()

defineEmits<{ complete: [] }>()

const renderedContent = computed(() => {
  if (!props.content) return ''
  // Normalize double-escaped newlines (backend may store \\n as literal backslash-n)
  const normalized = props.content.replace(/\\n/g, '\n')
  // marked.parse can return Promise<string> in newer versions — call synchronously
  const result = marked.parse(normalized, { async: false })
  const html = typeof result === 'string' ? result : ''
  return DOMPurify.sanitize(html)
})
</script>

<style lang="scss" scoped>
.lesson-view-text {
  max-width: 720px;
}

.lesson-text-content {
  line-height: 1.7;
  font-size: $font-size-sm; // snap from 0.9375rem (15px→14px)
  color: var(--p-text-color);

  :deep(h1),
  :deep(h2),
  :deep(h3),
  :deep(h4) {
    margin-top: 1.5rem;
    margin-bottom: 0.75rem;
    font-weight: 700;
    line-height: 1.3;
    color: var(--p-text-color);
  }

  :deep(p) {
    margin-bottom: 1rem;
  }

  :deep(ul),
  :deep(ol) {
    margin-bottom: 1rem;
    padding-left: 1.5rem;
  }

  :deep(li) {
    margin-bottom: 0.25rem;
  }

  :deep(code) {
    background: var(--p-surface-100);
    border-radius: $radius-sm; // 4px
    padding: 0.1em 0.4em;
    font-size: $font-size-sm; // 0.875rem (was em-relative, now rem-based token)
  }

  :deep(pre) {
    background: var(--p-surface-100);
    border-radius: $radius-md; // 6px
    padding: 1rem;
    overflow-x: auto;
    margin-bottom: 1rem;

    code {
      background: transparent;
      padding: 0;
    }
  }

  // Callout blocks — O-2 (blockquote SCSS only, no type parsing)
  :deep(blockquote) {
    border-left: 4px solid var(--p-primary-color);
    background: var(--p-surface-100);
    padding: 0.75rem 1rem;
    margin: 1rem 0;
    border-radius: $radius-sm; // 4px
    color: var(--p-text-color);

    p:last-child {
      margin-bottom: 0;
    }
  }

  :deep(table) {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1rem;

    th,
    td {
      padding: 0.5rem 0.75rem;
      border: 1px solid var(--p-surface-200);
      text-align: left;
    }

    th {
      background: var(--p-surface-100);
      font-weight: 600;
    }
  }

  :deep(hr) {
    border: none;
    border-top: 1px solid var(--p-surface-200);
    margin: 1.5rem 0;
  }
}
</style>
