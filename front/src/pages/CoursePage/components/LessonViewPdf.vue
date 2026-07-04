<template>
  <div class="lesson-view-pdf">
    <div v-if="loading" class="lesson-view-pdf__loading mb-3">
      <ProgressSpinner style="width: 40px; height: 40px" stroke-width="4" />
    </div>

    <div v-else-if="resolvedUrl" class="mb-3">
      <iframe
        :src="resolvedUrl"
        class="lesson-view-pdf__iframe"
        title="PDF Viewer"
      />
    </div>

    <Message v-else severity="warn" :closable="false" class="mb-3">
      {{ t('onboarding.coursePage.pdfUnavailable') }}
    </Message>

    <div v-if="resolvedUrl" class="d-flex gap-2 flex-wrap mb-4">
      <Button
        :label="t('onboarding.coursePage.openPdf')"
        icon="pi pi-external-link"
        severity="secondary"
        outlined
        as="a"
        :href="resolvedUrl"
        target="_blank"
      />
      <Button
        :label="t('onboarding.coursePage.downloadPdf')"
        icon="pi pi-download"
        severity="secondary"
        outlined
        as="a"
        :href="resolvedUrl"
        :download="downloadName"
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
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import { apiClient } from '@/api/client'
import LessonCompleteButton from './LessonCompleteButton.vue'

const props = defineProps<{
  /** Externally-hosted PDF URL (content.url) — iframe-safe, no auth needed. */
  externalUrl: string | null
  /** Authenticated streaming route for disk-stored PDFs (content.path). */
  playerSrc: string | null
  completed?: boolean
  completing?: boolean
}>()

defineEmits<{ complete: [] }>()

const { t } = useI18n()

// Blob object URL for the authenticated streaming route (revoked on teardown).
const blobUrl = ref<string | null>(null)
const loading = ref(false)

// An external URL is embeddable directly; otherwise fall back to the blob.
const resolvedUrl = computed<string | null>(() => props.externalUrl ?? blobUrl.value)
const downloadName = computed(() => 'lesson.pdf')

function revokeBlob(): void {
  if (blobUrl.value) {
    URL.revokeObjectURL(blobUrl.value)
    blobUrl.value = null
  }
}

/**
 * Fetch the authenticated player_src as a Blob (a bare <iframe src> can't carry
 * the Bearer token) and expose it as an object URL. Only runs when there is no
 * direct external URL to embed.
 */
async function loadBlob(): Promise<void> {
  revokeBlob()
  if (props.externalUrl || !props.playerSrc) return
  loading.value = true
  try {
    const res = await apiClient.get<Blob>(props.playerSrc, { responseType: 'blob' })
    const blob = new Blob([res.data], {
      type: String(res.headers['content-type'] ?? 'application/pdf'),
    })
    blobUrl.value = URL.createObjectURL(blob)
  } catch {
    blobUrl.value = null
  } finally {
    loading.value = false
  }
}

watch(
  () => [props.externalUrl, props.playerSrc],
  () => void loadBlob(),
  { immediate: true },
)

onBeforeUnmount(revokeBlob)
</script>

<style lang="scss" scoped>
.lesson-view-pdf {
  &__iframe {
    width: 100%;
    height: 600px;
    border: 1px solid var(--p-surface-200);
    border-radius: $radius-md; // 6px
  }

  &__loading {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 200px;
  }
}
</style>
