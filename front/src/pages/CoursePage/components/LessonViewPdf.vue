<template>
  <div class="lesson-view-pdf">
    <div v-if="pdfUrl" class="mb-3">
      <iframe
        :src="pdfUrl"
        class="lesson-view-pdf__iframe"
        title="PDF Viewer"
      />
    </div>
    <Message v-else severity="warn" :closable="false" class="mb-3">
      PDF недоступен
    </Message>

    <div class="d-flex gap-2 flex-wrap mb-4">
      <Button
        v-if="pdfUrl"
        :label="t('onboarding.coursePage.openPdf')"
        icon="pi pi-external-link"
        severity="secondary"
        outlined
        as="a"
        :href="pdfUrl"
        target="_blank"
      />
      <Button
        v-if="pdfUrl"
        :label="t('onboarding.coursePage.downloadPdf')"
        icon="pi pi-download"
        severity="secondary"
        outlined
        as="a"
        :href="pdfUrl"
        download
      />
    </div>

    <LessonCompleteButton
      :completed="completed"
      :loading="completing"
      @complete="$emit('complete')"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import LessonCompleteButton from './LessonCompleteButton.vue'

defineProps<{
  pdfUrl: string | null
  completed?: boolean
  completing?: boolean
}>()

defineEmits<{ complete: [] }>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.lesson-view-pdf {
  &__iframe {
    width: 100%;
    height: 600px;
    border: 1px solid var(--p-surface-200);
    border-radius: 6px;
  }
}
</style>
