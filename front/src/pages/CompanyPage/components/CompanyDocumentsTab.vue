<template>
  <div class="company-docs-tab">
    <!-- Header row -->
    <div class="company-docs-tab__header d-flex justify-content-between align-items-center mb-3">
      <span class="fw-semibold text-muted" style="font-size: 0.85rem;">
        {{ t('company.page.tabs.documents') }}
      </span>
      <Button
        icon="pi pi-plus"
        :label="t('documents.create.title')"
        size="small"
        @click="openGenerateDialog"
      />
    </div>

    <!-- Loading -->
    <Skeleton v-if="loading" height="120px" />

    <!-- Table -->
    <DataTable
      v-else
      :value="documents"
      size="small"
      row-hover
      @row-click="onRowClick"
    >
      <Column :header="t('documents.list.filters.status')" style="width: 150px">
        <template #body="{ data }">
          <DocumentStatusTag :status="data.status" />
        </template>
      </Column>
      <Column :header="'№'" style="width: 130px">
        <template #body="{ data }">
          <span :class="!data.number ? 'text-secondary' : 'fw-medium'">
            {{ data.number ?? `#draft-${data.id}` }}
          </span>
        </template>
      </Column>
      <Column :header="t('documents.create.kind')" style="width: 110px">
        <template #body="{ data }">{{ t(`documents.kinds.${data.kind}`, data.kind) }}</template>
      </Column>
      <Column :header="t('common.date', 'Дата')" style="width: 90px">
        <template #body="{ data }">{{ formatDate(data.created_at) }}</template>
      </Column>
      <Column style="width: 100px">
        <template #body="{ data }">
          <div class="d-flex gap-1">
            <Button
              icon="pi pi-external-link"
              text
              severity="secondary"
              size="small"
              :title="t('common.open', 'Открыть')"
              @click.stop="router.push(`/documents/${data.id}`)"
            />
            <Button
              v-if="data.pdf_path"
              icon="pi pi-file-pdf"
              text
              severity="secondary"
              size="small"
              :title="t('documents.card.actions.downloadPdf')"
              @click.stop="downloadPdf(data.id)"
            />
          </div>
        </template>
      </Column>

      <template #empty>
        <div class="company-docs-tab__empty">
          <i class="pi pi-file-edit company-docs-tab__empty-icon" />
          <p class="mt-2 mb-3 text-muted">{{ t('documents.list.empty') }}</p>
          <Button
            icon="pi pi-plus"
            :label="t('documents.create.title')"
            size="small"
            severity="secondary"
            outlined
            @click="openGenerateDialog"
          />
        </div>
      </template>
    </DataTable>

    <!-- Generate dialog -->
    <GenerateDocumentDialog
      v-model="generateDialogOpen"
      :company-id="companyId"
      @created="onCreated"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Skeleton from 'primevue/skeleton'
import DocumentStatusTag from '@/components/shared/DocumentStatusTag.vue'
import GenerateDocumentDialog from '@/pages/DocumentPage/components/GenerateDocumentDialog.vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { documentsApi } from '@/api/documents'
import type { DocumentListItemDto } from '@/entities/document'

const props = defineProps<{
  companyId: number
}>()

const { t } = useI18n()
const router = useRouter()
const generateDialogOpen = ref(false)

const resource = useAsyncResource<DocumentListItemDto[]>(() => [])
const documents = ref<DocumentListItemDto[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  await resource.run(async () => {
    const resp = await documentsApi.getDocuments({ source_company_id: props.companyId, per_page: 50 })
    return resp.data
  })
  documents.value = resource.data.value
  loading.value = false
}

function openGenerateDialog() {
  generateDialogOpen.value = true
}

function onRowClick(event: { data: DocumentListItemDto }) {
  void router.push(`/documents/${event.data.id}`)
}

function downloadPdf(docId: number) {
  window.open(documentsApi.getDownloadPdfUrl(docId), '_blank')
}

function onCreated(docId: number) {
  void router.push(`/documents/${docId}`)
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' })
}

onMounted(() => void load())
</script>

<style lang="scss" scoped>
.company-docs-tab {
  &__header {
    padding: $space-1 0;
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 2.5rem $space-4;
    text-align: center;
  }

  &__empty-icon {
    font-size: 2.5rem;
    color: var(--p-text-muted-color);
    opacity: 0.4;
  }
}
</style>
