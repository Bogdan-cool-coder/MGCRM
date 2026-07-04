<template>
  <div class="documents-page">
    <!-- Toolbar (canon) -->
    <DocumentsToolbar
      :total="total"
      :filter-count="activeFilterCount"
      :can-create="canCreate"
      @open-filter="onOpenFilter"
      @create="openCreateDialog"
    />

    <!-- Filter panel (Popover behind trigger) -->
    <DocumentsFilterPanel
      ref="filterPanelRef"
      v-model="filter"
      :has-active-filters="hasActiveFilters"
      @reset="resetFilters"
    />

    <!-- DataTable -->
    <div class="documents-page__card">
      <DataTable
        :value="documents"
        :loading="loading"
        striped-rows
        row-hover
        class="documents-page__table"
        @row-click="(e) => onRowClick((e.data as DocumentListItemDto).id)"
      >
        <!-- Number -->
        <Column :header="'№'" style="width: 130px">
          <template #body="{ data }">
            <span
              :class="data.number ? 'documents-page__number--set' : 'documents-page__number--draft'"
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
        class="documents-page__paginator"
        @page="onPageChange"
      />
      <div v-if="total > 0" class="documents-page__total">
        {{ t('common.paginator.showing', { from: shownFrom, to: shownTo, total }) }}
      </div>
    </div>

    <!-- Row context menu -->
    <Menu ref="rowMenu" :model="rowMenuItems" popup />

    <!-- Create dialog -->
    <CreateDocumentDialog
      v-model="createDialogVisible"
      @created="onDocumentCreated"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Paginator from 'primevue/paginator'
import Menu from 'primevue/menu'
import DocumentStatusTag from '@/components/shared/DocumentStatusTag.vue'
import DocumentsToolbar from './components/DocumentsToolbar.vue'
import DocumentsFilterPanel from './components/DocumentsFilterPanel.vue'
import CreateDocumentDialog from './components/CreateDocumentDialog.vue'
import { useDocumentsPage } from './composables/useDocumentsPage'
import type { DocumentListItemDto } from '@/entities/document'

const { t } = useI18n()

const {
  filter,
  page,
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

// ─── Filter trigger + badge ──────────────────────────────────────────────────
const filterPanelRef = ref<InstanceType<typeof DocumentsFilterPanel> | null>(null)

function onOpenFilter(event: Event) {
  filterPanelRef.value?.toggle(event)
}

// Number of active filters shown as the orange badge on the trigger. Derived from
// the same fields as `hasActiveFilters` in the composable (client-only, no logic change).
const activeFilterCount = computed(() => {
  const f = filter.value
  let n = 0
  if (f.status) n += 1
  if (f.kind) n += 1
  if (f.product_code) n += 1
  if (f.country_code) n += 1
  if (f.author_user_id) n += 1
  if (f.search) n += 1
  if (f.archived) n += 1
  return n
})

// ─── Paginator counter «Показано X из Y» ─────────────────────────────────────
const shownFrom = computed(() => (total.value === 0 ? 0 : (page.value - 1) * perPage + 1))
const shownTo = computed(() => Math.min(page.value * perPage, total.value))

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

  &__card {
    background: $surface-card;
    border-radius: $radius-lg;
    border: 1px solid $surface-200;
    box-shadow: $shadow-card;
    overflow: hidden;

    .app-dark & {
      border-color: var(--p-surface-200);
    }
  }

  &__table {
    cursor: pointer;

    :deep(th) {
      text-transform: uppercase;
      font-size: $font-size-xs;
      letter-spacing: 0.03em;
    }
  }

  &__number {
    &--set {
      font-weight: $font-weight-medium;
    }

    &--draft {
      color: var(--p-text-muted-color);
    }
  }

  &__paginator {
    border-top: 1px solid $surface-200;

    .app-dark & {
      border-top-color: var(--p-surface-200);
    }
  }

  &__total {
    padding: $space-2 $space-4;
    font-size: $font-size-sm;
    color: $surface-500;
    text-align: right;

    .app-dark & {
      color: var(--p-surface-400);
    }
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-3;
    padding: $space-8;
    min-height: 260px;
    justify-content: center;
    color: var(--p-text-muted-color);
    text-align: center;
  }

  &__empty-icon {
    font-size: $font-size-icon-xl;
    opacity: 0.4;
  }
}
</style>
