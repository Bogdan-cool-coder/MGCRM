<template>
  <div class="documents-page">
    <PageHeader
      :title="t('documents.list.title')"
      icon="pi pi-file-edit"
    >
      <template #actions>
        <Button
          v-if="canCreate"
          icon="pi pi-plus"
          :label="t('documents.list.create')"
          @click="openCreateDialog"
        />
      </template>
    </PageHeader>

    <!-- Filters -->
    <DocumentsFilterPanel
      v-model="filter"
      :has-active-filters="hasActiveFilters"
      @reset="resetFilters"
    />

    <!-- DataTable -->
    <Card class="documents-page__table-card">
      <template #content>
        <DataTable
          :value="documents"
          :loading="loading"
          row-hover
          class="documents-page__table"
          @row-click="(e) => onRowClick((e.data as DocumentListItemDto).id)"
        >
          <!-- Number -->
          <Column :header="'№'" style="width: 130px">
            <template #body="{ data }">
              <span
                :class="data.number ? 'fw-medium' : 'text-secondary'"
              >
                {{ data.number ?? `#draft-${data.id}` }}
              </span>
            </template>
          </Column>

          <!-- Company -->
          <Column :header="t('documents.list.columns.company', 'Компания')">
            <template #body="{ data }">
              {{ data.source_company?.name ?? '—' }}
            </template>
          </Column>

          <!-- Kind -->
          <Column :header="t('documents.list.filters.kind')" style="width: 110px">
            <template #body="{ data }">
              {{ t(`documents.kinds.${data.kind}`, data.kind) }}
            </template>
          </Column>

          <!-- Product -->
          <Column :header="t('documents.list.filters.product')" style="width: 110px">
            <template #body="{ data }">
              {{ data.product_code ?? '—' }}
            </template>
          </Column>

          <!-- Country -->
          <Column :header="t('documents.list.filters.country')" style="width: 80px">
            <template #body="{ data }">
              {{ data.country_code ?? '—' }}
            </template>
          </Column>

          <!-- Status -->
          <Column :header="t('documents.list.filters.status')" style="width: 160px">
            <template #body="{ data }">
              <DocumentStatusTag :status="data.status" :archived="!!data.archived_at" />
            </template>
          </Column>

          <!-- Author -->
          <Column :header="t('documents.list.filters.author')" style="width: 140px">
            <template #body="{ data }">
              {{ data.author?.full_name ?? '—' }}
            </template>
          </Column>

          <!-- Date -->
          <Column :header="t('documents.list.columns.date', 'Дата')" style="width: 110px">
            <template #body="{ data }">
              {{ formatDate(data.created_at) }}
            </template>
          </Column>

          <!-- Actions -->
          <Column style="width: 80px">
            <template #body="{ data }">
              <Button
                icon="pi pi-ellipsis-v"
                text
                severity="secondary"
                size="small"
                @click.stop="(e) => openRowMenu(e, data)"
              />
            </template>
          </Column>

          <!-- Empty / loading states -->
          <template #empty>
            <div class="documents-page__empty">
              <template v-if="hasActiveFilters">
                <i class="pi pi-filter-slash documents-page__empty-icon" />
                <p>{{ t('documents.list.emptyFiltered') }}</p>
                <Button
                  :label="t('common.reset')"
                  severity="secondary"
                  outlined
                  @click="resetFilters"
                />
              </template>
              <template v-else>
                <i class="pi pi-file-edit documents-page__empty-icon" />
                <p>{{ t('documents.list.empty') }}</p>
                <Button
                  v-if="canCreate"
                  :label="t('documents.list.create')"
                  icon="pi pi-plus"
                  @click="openCreateDialog"
                />
              </template>
            </div>
          </template>
        </DataTable>

        <!-- Paginator -->
        <Paginator
          v-if="total > perPage"
          :rows="perPage"
          :total-records="total"
          :rows-per-page-options="[25, 50, 100]"
          class="mt-2"
          @page="onPageChange"
        />
      </template>
    </Card>

    <!-- Row context menu -->
    <Menu ref="rowMenu" :model="rowMenuItems" popup />

    <!-- Create dialog -->
    <CreateDocumentDialog
      v-model="createDialogVisible"
      @created="onDocumentCreated"
    />

    <ConfirmDialog />
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import Card from 'primevue/card'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Paginator from 'primevue/paginator'
import Menu from 'primevue/menu'
import ConfirmDialog from 'primevue/confirmdialog'
import DocumentStatusTag from '@/components/shared/DocumentStatusTag.vue'
import DocumentsFilterPanel from './components/DocumentsFilterPanel.vue'
import CreateDocumentDialog from './components/CreateDocumentDialog.vue'
import { useDocumentsPage } from './composables/useDocumentsPage'
import type { DocumentListItemDto } from '@/entities/document'

const { t } = useI18n()

const {
  filter,
  documents,
  total,
  loading,
  perPage,
  hasActiveFilters,
  resetFilters,
  onRowClick,
  createDialogVisible,
  openCreateDialog,
  onDocumentCreated,
  archiveDoc,
  canCreate,
  onPageChange,
} = useDocumentsPage()

// ─── Row menu ──────────────────────────────────────────────────────────────
const rowMenu = ref()
const rowMenuItems = ref<{ label: string; icon: string; command: () => void }[]>([])

function openRowMenu(event: Event, doc: DocumentListItemDto) {
  rowMenuItems.value = [
    {
      label: t('common.edit', 'Открыть'),
      icon: 'pi pi-external-link',
      command: () => onRowClick(doc.id),
    },
    {
      label: t('common.archive', 'В архив'),
      icon: 'pi pi-box',
      command: () => archiveDoc(doc),
    },
  ]
  rowMenu.value?.toggle(event)
}

// ─── Date formatter ────────────────────────────────────────────────────────
function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('ru-RU', {
    day: '2-digit',
    month: 'short',
    year: undefined,
  })
}
</script>

<style lang="scss" scoped>
.documents-page {
  padding: $space-3;

  &__table-card {
    :deep(.p-card-body) {
      padding: $space-3;
    }
  }

  &__table {
    cursor: pointer;
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-3;
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    padding: 3rem $space-4; // no exact $space token for 3rem (48px); nearest $space-8 is 32px
    color: var(--p-text-muted-color);
    text-align: center;
  }

  &__empty-icon {
    font-size: $font-size-icon-xl;
    opacity: 0.4;
  }
}
</style>
