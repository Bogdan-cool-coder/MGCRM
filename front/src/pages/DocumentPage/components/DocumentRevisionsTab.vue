<template>
  <div class="revisions-tab">
    <div v-if="loading">
      <Skeleton height="40px" class="mb-2" v-for="i in 3" :key="i" />
    </div>

    <DataTable
      v-else
      :value="revisions"
      size="small"
      row-hover
    >
      <Column :header="t('documents.revisions.version')" style="width: 80px">
        <template #body="{ data }">v{{ data.version }}</template>
      </Column>
      <Column :header="t('documents.approval.attempt')" style="width: 100px">
        <template #body="{ data }">
          #{{ data.attempt }}
        </template>
      </Column>
      <Column :header="t('documents.revisions.author')">
        <template #body="{ data }">{{ data.created_by_name ?? '—' }}</template>
      </Column>
      <Column :header="t('documents.list.columns.date')" style="width: 110px">
        <template #body="{ data }">{{ formatDate(data.created_at) }}</template>
      </Column>
      <Column :header="t('documents.revisions.note')">
        <template #body="{ data }">{{ data.note ?? '—' }}</template>
      </Column>
      <Column header="Скачать" style="width: 120px">
        <template #body="{ data }">
          <span class="d-flex gap-1">
            <Button
              v-if="data.docx_path"
              icon="pi pi-file-word"
              text
              severity="secondary"
              size="small"
              :title="t('documents.card.actions.downloadDocx')"
              @click="downloadDocx(data.document_id)"
            />
            <Button
              v-if="data.pdf_path"
              icon="pi pi-file-pdf"
              text
              severity="secondary"
              size="small"
              :title="t('documents.card.actions.downloadPdf')"
              @click="downloadPdf(data.document_id)"
            />
          </span>
        </template>
      </Column>

      <template #empty>
        <div class="revisions-tab__empty">
          <i class="pi pi-history" />
          <span>{{ t('documents.revisions.empty') }}</span>
        </div>
      </template>
    </DataTable>
  </div>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { documentsApi } from '@/api/documents'
import type { DocumentRevisionDto } from '@/entities/document'

const props = defineProps<{ docId: number }>()
const { t } = useI18n()

const resource = useAsyncResource<DocumentRevisionDto[]>(() => [])
const revisions = computed(() => resource.data.value)
const loading = computed(() => resource.loading.value)

watch(() => props.docId, () => {
  void resource.run(() => documentsApi.getDocumentRevisions(props.docId))
}, { immediate: true })

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' })
}

/** Download via the served API endpoint (not raw storage path). */
function downloadDocx(documentId: number | undefined) {
  if (!documentId) return
  window.open(`/api/documents/${documentId}/download/docx`, '_blank')
}

function downloadPdf(documentId: number | undefined) {
  if (!documentId) return
  window.open(`/api/documents/${documentId}/download/pdf`, '_blank')
}
</script>

<style lang="scss" scoped>
.revisions-tab {
  &__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 1.5rem;
    color: var(--p-text-muted-color);
  }
}
</style>
