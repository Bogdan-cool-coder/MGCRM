<template>
  <div class="document-page">
    <!-- Skeleton loading -->
    <template v-if="loading">
      <Skeleton height="40px" class="mb-3" />
      <div class="row g-4">
        <div class="col-lg-8">
          <Skeleton height="200px" class="mb-3" />
          <Skeleton height="120px" />
        </div>
        <div class="col-lg-4">
          <Skeleton height="160px" class="mb-3" />
          <Skeleton height="120px" />
        </div>
      </div>
    </template>

    <!-- Error / not found -->
    <template v-else-if="loadError || !document">
      <div class="document-page__error">
        <i class="pi pi-exclamation-triangle document-page__error-icon" />
        <p>{{ t('documents.card.notFound') }}</p>
        <Button
          icon="pi pi-arrow-left"
          :label="t('documents.card.backToList')"
          severity="secondary"
          outlined
          @click="router.push('/documents')"
        />
      </div>
    </template>

    <!-- Main content -->
    <template v-else>
      <!-- Page Header -->
      <PageHeader
        :title="documentTitle"
        icon="pi pi-file-edit"
      >
        <template #actions>
          <DocumentStatusTag :status="document.status" :archived="!!document.archived_at" />
        </template>
      </PageHeader>

      <!-- Action bar -->
      <div class="document-page__action-bar mb-3">
        <DocumentActionBar
          :doc="document"
          :generating="generating"
          :submitting="submitting"
          :signing="signing"
          :is-context-valid="isContextValid"
          :has-signed-scan="hasSignedScan"
          :can-unsign="isAuthorOrPrivileged"
          @back="router.back()"
          @generate="generateDoc"
          @download-docx="downloadDocx"
          @download-pdf="downloadPdf"
          @submit="submitForApproval"
          @sign="signDoc"
          @unsign="unsignDoc"
          @archive="archiveDoc"
          @unarchive="unarchiveDoc"
          @open-menu="(e) => docMenu?.toggle(e)"
        />
        <Menu ref="docMenu" :model="docMenuItems" popup />
      </div>

      <!-- Layout -->
      <div class="row g-4">
        <!-- Left: Tabs -->
        <div class="col-lg-8">
          <Card class="document-page__tabs-card">
            <template #content>
              <Tabs v-model:value="activeTab">
                <TabList>
                  <Tab value="context">{{ t('documents.card.tabs.context') }}</Tab>
                  <Tab value="items">{{ t('documents.card.tabs.items') }}</Tab>
                  <Tab value="revisions">{{ t('documents.card.tabs.revisions') }}</Tab>
                  <Tab value="remarks">
                    {{ t('documents.card.tabs.remarks') }}
                    <Badge
                      v-if="unresolvedRemarksCount > 0"
                      :value="unresolvedRemarksCount"
                      severity="danger"
                      class="ms-1"
                    />
                  </Tab>
                  <Tab value="attachments">{{ t('documents.card.tabs.attachments') }}</Tab>
                </TabList>

                <TabPanels>
                  <!-- Context -->
                  <TabPanel value="context">
                    <DocumentContextTab
                      :product-code="document.product_code"
                      :country-code="document.country_code"
                      :initial-context="document.context"
                      :can-edit="canEdit"
                      :autosave-state="autosaveState"
                      @context-change="triggerAutosave"
                    />
                  </TabPanel>

                  <!-- Items -->
                  <TabPanel value="items">
                    <DocumentItemsTab
                      :doc-id="docId"
                      :can-edit="canEdit"
                      :initial-subtotal="document.subtotal"
                      :initial-discount-pct="document.discount_pct"
                      :initial-discount-amount="document.discount_amount"
                      :initial-total="document.total"
                      :initial-currency="document.currency"
                    />
                  </TabPanel>

                  <!-- Revisions -->
                  <TabPanel value="revisions">
                    <DocumentRevisionsTab :doc-id="docId" />
                  </TabPanel>

                  <!-- Remarks -->
                  <TabPanel value="remarks">
                    <DocumentRemarksTab
                      ref="remarksTabRef"
                      :doc-id="docId"
                      :attempt="document.attempt ?? 0"
                      :can-resolve="isAuthorOrPrivileged"
                      @resolved="unresolvedRemarksCount = Math.max(0, unresolvedRemarksCount - 1)"
                    />
                  </TabPanel>

                  <!-- Attachments -->
                  <TabPanel value="attachments">
                    <DocumentAttachmentsTab
                      :doc-id="docId"
                      :can-edit="canEdit"
                      :status="document.status"
                      @has-scan-change="hasSignedScan = $event"
                    />
                  </TabPanel>
                </TabPanels>
              </Tabs>
            </template>
          </Card>
        </div>

        <!-- Right: Approval + Meta -->
        <div class="col-lg-4">
          <ApprovalPanel
            :approval="approval"
            :loading="loadingApproval"
            :deciding="decideMutation.isPending.value"
            class="mb-3"
            @approve="approve"
            @open-decide="openDecideDialog"
          />

          <DocumentMetaCard :doc="document" />
        </div>
      </div>
    </template>

    <!-- Decide dialog -->
    <DecideDialog
      v-model="decideDialogVisible"
      :loading="decideMutation.isPending.value"
      :required="true"
      @confirm="confirmDecide"
    />

    <ConfirmDialog />
    <Toast />
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import Card from 'primevue/card'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import Badge from 'primevue/badge'
import Menu from 'primevue/menu'
import ConfirmDialog from 'primevue/confirmdialog'
import Toast from 'primevue/toast'

import DocumentStatusTag from '@/components/shared/DocumentStatusTag.vue'
import DecideDialog from '@/components/shared/DecideDialog.vue'

import DocumentActionBar from './components/DocumentActionBar.vue'
import DocumentContextTab from './components/DocumentContextTab.vue'
import DocumentItemsTab from './components/DocumentItemsTab.vue'
import DocumentRevisionsTab from './components/DocumentRevisionsTab.vue'
import DocumentRemarksTab from './components/DocumentRemarksTab.vue'
import DocumentAttachmentsTab from './components/DocumentAttachmentsTab.vue'
import ApprovalPanel from './components/ApprovalPanel.vue'
import DocumentMetaCard from './components/DocumentMetaCard.vue'

import { useDocumentPage } from './composables/useDocumentPage'
import { useDocumentApproval } from './composables/useDocumentApproval'

const { t } = useI18n()

const {
  router,
  docId,
  document,
  loading,
  loadError,
  autosaveState,
  triggerAutosave,
  generating,
  generateDoc,
  submitting,
  submitForApproval,
  signing,
  signDoc,
  unsignDoc,
  archiveDoc,
  unarchiveDoc,
  decideMutation,
  decideDialogVisible,
  openDecideDialog,
  approve,
  confirmDecide,
  downloadDocx,
  downloadPdf,
  isAuthorOrPrivileged,
  canEdit,
  hasSignedScan,
} = useDocumentPage()

// Approval polling
const docStatus = computed(() => document.value?.status)
const { approval, loadingApproval } = useDocumentApproval(docId, docStatus)

// ─── State ────────────────────────────────────────────────────────────────
const activeTab = ref('context')
const unresolvedRemarksCount = ref(0)
const remarksTabRef = ref()
const docMenu = ref()
const isContextValid = computed(() => true) // simplified — check required fields in context

const documentTitle = computed(() => {
  if (!document.value) return ''
  const num = document.value.number ?? `#draft-${document.value.id}`
  const company = document.value.source_company?.name ?? ''
  return company ? `${num} — ${company}` : num
})

// ─── Dot menu items ────────────────────────────────────────────────────────
const docMenuItems = computed(() => [
  {
    label: t('documents.card.actions.duplicate'),
    icon: 'pi pi-copy',
    command: () => {
      /* duplicate handled in backend/future */
    },
  },
])
</script>

<style lang="scss" scoped>
.document-page {
  padding: 0.75rem;

  &__action-bar {
    // spacer
  }

  &__tabs-card {
    :deep(.p-card-body) {
      padding: 0.75rem;
    }
  }

  &__error {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1rem;
    padding: 4rem 1rem;
    color: var(--p-text-muted-color);
    text-align: center;

    &-icon {
      font-size: 3rem;
      opacity: 0.4;
    }
  }
}
</style>
