<template>
  <div class="documents-page">
    <div class="documents-card">
      <div class="documents-header">
        <div class="documents-heading">
          <h1 class="documents-title">{{ t('title') }}</h1>
        </div>

        <div class="documents-actions">
          <SelectButton
            :model-value="typeFilter"
            :options="typeOptions"
            option-label="label"
            option-value="value"
            :allow-empty="false"
            :aria-label="t('filter.label')"
            @update:model-value="onTypeFilterChange"
          />

          <Button
            v-if="canManage"
            icon="pi pi-plus"
            :label="t('create.button')"
            @click="openCreateDialog"
          />
        </div>
      </div>

      <div class="documents-content">
        <LoadingState v-if="loading" />

        <template v-else-if="hasAny">
          <DocumentSection
            v-if="systemTemplates.length > 0"
            :title="t('sections.system')"
            :items="systemTemplates"
            @open="openDocument"
          />
          <DocumentSection
            v-if="publishedTemplates.length > 0"
            :title="t('sections.published')"
            :items="publishedTemplates"
            @open="openDocument"
          />
          <DocumentSection
            v-if="personalTemplates.length > 0"
            :title="t('sections.personal')"
            :items="personalTemplates"
            @open="openDocument"
          />
        </template>

        <EmptyState v-else :message="t('empty')" />
      </div>
    </div>

    <CreateDocumentDialog
      v-model:visible="createDialogVisible"
      @created="goToCreatedDocument"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import SelectButton from 'primevue/selectbutton'
import Button from 'primevue/button'
import LoadingState from '@/components/states/LoadingState.vue'
import EmptyState from '@/components/states/EmptyState.vue'
import DocumentSection from './components/DocumentSection.vue'
import CreateDocumentDialog from './components/CreateDocumentDialog.vue'
import {
  useDocumentsPage,
} from './composables/useDocumentsPage'
import type { DocumentTypeFilter } from './composables/useDocumentsPageData'

const {
  t,
  loading,
  hasAny,
  typeFilter,
  setTypeFilter,
  systemTemplates,
  publishedTemplates,
  personalTemplates,
  openDocument,
  canManage,
  createDialogVisible,
  openCreateDialog,
  goToCreatedDocument,
} = useDocumentsPage()

const typeOptions = computed<{ label: string; value: DocumentTypeFilter }[]>(() => [
  { label: t('filter.all'), value: 'all' },
  { label: t('filter.html'), value: 'html' },
  { label: t('filter.docx'), value: 'docx' },
])

const onTypeFilterChange = (value: DocumentTypeFilter) => {
  setTypeFilter(value)
}
</script>

<style lang="scss" scoped>
.documents-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  padding: 0.75rem;

  .documents-card {
    background: $surface-0;
    border-radius: $card-border-radius;
    padding: 1rem;
    box-shadow: $shadow-md;
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow: hidden;

    .documents-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1rem;
      flex-shrink: 0;

      .documents-heading {
        display: flex;
        align-items: center;
        gap: 0.25rem;

        .documents-title {
          margin: 0;
          font-size: $font-size-2xl;
          font-weight: $font-weight-semibold;
          color: $surface-900;
        }
      }

      .documents-actions {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
      }
    }

    .documents-content {
      margin-top: 1rem;
      padding-top: 1rem;
      border-top: 1px solid $surface-200;
      flex: 1;
      min-height: 0;
      overflow: auto;
      display: flex;
      flex-direction: column;
      gap: 1.5rem;
    }
  }
}
</style>
