<template>
  <div class="document-page">
    <LoadingState v-if="templateLoading && !template" />

    <EmptyState v-else-if="!template" :message="t('errors.notFound')" />

    <div v-else class="document-card">
      <!-- Header: title + actions menu (info / publish / delete / edit) -->
      <div class="document-header">
        <div class="document-heading">
          <h1 class="document-title">{{ templateName }}</h1>
        </div>

        <DocumentActionsMenu
          v-if="template"
          :document="template"
          editable
          @edit="openEditModal"
          @document-updated="onTemplateUpdated"
          @document-deleted="onTemplateDeleted"
        />
      </div>

      <!-- ─── HTML flow ─────────────────────────────────────────────────── -->
      <div v-if="isHtml" class="document-body">
        <aside class="document-form">
          <ProposalSelectors
            :t="t"
            :selected-estate-sell-id="selectedEstateSellId"
            :object-options="displayedObjectOptions"
            :objects-loading="objectsLoading"
            :objects-loaded-once="objectsLoadedOnce"
            :promotion-options="promotionOptions"
            :promotions-loading="promotionsLoading"
            :selected-promotion-id="selectedPromotionId"
            :has-promotion="selectedPromotion !== null"
            :discount="discount"
            :discount-min="discountMin"
            :discount-max="discountMax"
            :is-percent-discount="isPercentDiscount"
            :can-manage-promotions="canOpenPromotionSettings"
            @object-show="onObjectDropdownShow"
            @object-filter="onObjectFilter"
            @object-select="onObjectSelect"
            @promotion-select="onPromotionSelect"
            @discount-input="onDiscountInput"
            @slider-input="onSliderInput"
            @open-promotions="openPromotionSettings"
          />

          <div class="form-actions">
            <Button
              :label="isGenerating ? t('generation.generating') : t('generation.export')"
              icon="pi pi-file-pdf"
              :loading="isGenerating"
              :disabled="isGenerating"
              @click="generate"
            />
            <Button
              v-if="phase === 'ready'"
              :label="t('generation.downloadAgain')"
              icon="pi pi-download"
              severity="secondary"
              outlined
              @click="() => downloadAgain('pdf')"
            />
          </div>

          <Message v-if="phase === 'error'" severity="error" :closable="false">
            {{ t('generation.error') }}
          </Message>
          <Message v-else-if="phase === 'ready'" severity="success" :closable="false">
            {{ t('generation.ready') }}
          </Message>
        </aside>

        <!-- Live HTML preview -->
        <section class="document-preview">
          <div class="preview-toolbar">
            <span class="preview-title">{{ t('preview.title') }}</span>
            <ProgressSpinner v-if="previewLoading" class="preview-spinner" stroke-width="4" />
          </div>

          <div class="preview-frame-wrap">
            <LoadingState v-if="!previewLoadedOnce && previewLoading" />
            <iframe
              v-else
              class="preview-frame"
              :srcdoc="previewHtml"
              sandbox=""
              :title="t('preview.title')"
            ></iframe>
          </div>
        </section>
      </div>

      <!-- ─── Word (docx) flow ──────────────────────────────────────────── -->
      <div v-else-if="isDocx" class="document-body document-body--docx">
        <!-- Manage side: upload + placeholder mapping (analyst+ / non-system). -->
        <aside v-if="canManageDocuments" class="document-form">
          <!-- AI auto-mapping: reads the docx and proposes a field mapping. -->
          <div v-if="hasSource" class="docx-ai">
            <Button
              :label="aiRunning ? t('docx.ai.running') : t('docx.ai.autoMap')"
              icon="pi pi-sparkles"
              severity="help"
              outlined
              size="small"
              :loading="aiRunning"
              :disabled="aiRunning || savingMapping"
              @click="proposeFields"
            />
            <small class="docx-ai__hint">{{ t('docx.ai.autoMapHint') }}</small>

            <DocumentFieldsProposedPanel
              v-if="aiHasProposals"
              :proposals="aiProposals"
              :t="t"
              :label-for-key="labelForKey"
              :disabled="savingMapping"
              @accept="acceptProposal"
              @dismiss="dismissProposal"
              @accept-all="acceptAllProposals"
              @dismiss-all="clearProposals"
            />
          </div>

          <DocxConfigPanel
            :t="t"
            :has-source="hasSource"
            :uploading="uploading"
            :placeholders-loading="placeholdersLoading"
            :rows="placeholderRows"
            :unknown-tokens="unknownTokens"
            :has-unknown="hasUnknown"
            @upload="uploadSource"
            @open-catalog="openCatalogModal"
          />
        </aside>

        <!-- Generate side: object + discount + DOCX / PDF download. -->
        <section class="docx-generate">
          <div v-if="!canManageDocuments || hasSource">
            <ProposalSelectors
              :t="t"
              :selected-estate-sell-id="selectedEstateSellId"
              :object-options="displayedObjectOptions"
              :objects-loading="objectsLoading"
              :objects-loaded-once="objectsLoadedOnce"
              :promotion-options="promotionOptions"
              :promotions-loading="promotionsLoading"
              :selected-promotion-id="selectedPromotionId"
              :has-promotion="selectedPromotion !== null"
              :discount="discount"
              :discount-min="discountMin"
              :discount-max="discountMax"
              :is-percent-discount="isPercentDiscount"
              :can-manage-promotions="canOpenPromotionSettings"
              @object-show="onObjectDropdownShow"
              @object-filter="onObjectFilter"
              @object-select="onObjectSelect"
              @promotion-select="onPromotionSelect"
              @discount-input="onDiscountInput"
              @slider-input="onSliderInput"
              @open-promotions="openPromotionSettings"
            />

            <div class="form-actions">
              <Button
                :label="isGenerating ? t('generation.generating') : t('docx.generate')"
                icon="pi pi-cog"
                :loading="isGenerating"
                :disabled="isGenerating"
                @click="generate"
              />
            </div>

            <div v-if="phase === 'ready'" class="docx-downloads">
              <Button
                :label="t('docx.downloadDocx')"
                icon="pi pi-file-word"
                severity="secondary"
                outlined
                @click="() => downloadAgain('docx')"
              />
              <Button
                :label="t('docx.downloadPdf')"
                icon="pi pi-file-pdf"
                severity="secondary"
                outlined
                @click="() => downloadAgain('pdf')"
              />
            </div>

            <Message v-if="phase === 'error'" severity="error" :closable="false">
              {{ t('generation.error') }}
            </Message>
            <Message v-else-if="phase === 'ready'" severity="success" :closable="false">
              {{ t('docx.ready') }}
            </Message>
          </div>

          <!-- No source yet and the viewer can't upload → nothing to consume. -->
          <EmptyState
            v-else
            :message="t('docx.notReady')"
            icon="pi pi-file-word"
          />
        </section>

        <FieldCatalogModal
          :visible="catalogModalVisible"
          :loading="catalogLoading"
          :catalog="fieldCatalog"
          :t="t"
          :locale="String(locale)"
          @update:visible="onCatalogVisibleChange"
        />
      </div>

      <!-- Unknown type fallback -->
      <div v-else class="document-unsupported">
        <EmptyState :message="t('errors.unsupportedType')" icon="pi pi-file" />
      </div>

      <!-- Edit modal (manual by default; "Edit with AI" hands off to the
           global generation modal in edit-mode). -->
      <DocumentEditModal
        v-model:visible="editModalVisible"
        :template="template"
        :t="t"
        :locale="String(locale)"
        @saved="onTemplateEdited"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import Button from 'primevue/button'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import LoadingState from '@/components/states/LoadingState.vue'
import EmptyState from '@/components/states/EmptyState.vue'
import ProposalSelectors from './components/ProposalSelectors.vue'
import DocxConfigPanel from './components/DocxConfigPanel.vue'
import FieldCatalogModal from './components/FieldCatalogModal.vue'
import DocumentFieldsProposedPanel from './components/DocumentFieldsProposedPanel.vue'
import DocumentEditModal from './components/DocumentEditModal.vue'
import DocumentActionsMenu from '@/pages/DocumentsPage/components/DocumentActionsMenu.vue'
import { useDocumentPage } from './composables/useDocumentPage'

const {
  t,
  locale,
  template,
  templateLoading,
  templateName,
  isHtml,
  isDocx,
  canManageDocuments,
  // object picker
  selectedEstateSellId,
  displayedObjectOptions,
  objectsLoading,
  objectsLoadedOnce,
  onObjectDropdownShow,
  onObjectFilter,
  onObjectSelect,
  // promotions / discount
  promotionsLoading,
  promotionOptions,
  selectedPromotionId,
  selectedPromotion,
  discount,
  discountMin,
  discountMax,
  isPercentDiscount,
  onPromotionSelect,
  onDiscountInput,
  onSliderInput,
  // html preview
  previewHtml,
  previewLoading,
  previewLoadedOnce,
  // docx
  hasSource,
  uploading,
  uploadSource,
  catalogLoading,
  placeholdersLoading,
  placeholderRows,
  unknownTokens,
  hasUnknown,
  fieldCatalog,
  labelForKey,
  savingMapping,
  catalogModalVisible,
  openCatalogModal,
  closeCatalogModal,
  // AI auto-mapping (docx)
  aiProposals,
  aiHasProposals,
  aiRunning,
  proposeFields,
  dismissProposal,
  clearProposals,
  acceptProposal,
  acceptAllProposals,
  // actions
  phase,
  isGenerating,
  canOpenPromotionSettings,
  generate,
  downloadAgain,
  openPromotionSettings,
  // actions menu + edit modal
  editModalVisible,
  openEditModal,
  onTemplateUpdated,
  onTemplateEdited,
  onTemplateDeleted,
} = useDocumentPage()

const onCatalogVisibleChange = (value: boolean) => {
  if (!value) closeCatalogModal()
}
</script>

<style lang="scss" scoped>
.document-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  padding: 0.75rem;

  .document-card {
    background: $surface-0;
    border-radius: $card-border-radius;
    padding: 1rem;
    box-shadow: $shadow-md;
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow: hidden;
  }

  .document-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-shrink: 0;

    .document-title {
      margin: 0;
      font-size: $font-size-2xl;
      font-weight: $font-weight-semibold;
      color: $surface-900;
    }
  }

  .document-unsupported {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .document-body {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid $surface-200;
    flex: 1;
    min-height: 0;
    display: grid;
    grid-template-columns: minmax(280px, 360px) 1fr;
    gap: 1.5rem;

    @media (max-width: 992px) {
      grid-template-columns: 1fr;
    }

    &--docx {
      // Docx generate side is narrower than the HTML preview iframe.
      grid-template-columns: minmax(320px, 420px) 1fr;

      @media (max-width: 992px) {
        grid-template-columns: 1fr;
      }
    }
  }

  .document-form {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    min-height: 0;
    overflow: auto;
  }

  .docx-ai {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    align-items: flex-start;

    .docx-ai__hint {
      font-size: $font-size-xs;
      color: $surface-500;
    }

    :deep(.fields-proposed-panel) {
      width: 100%;
      margin-top: 0.5rem;
    }
  }

  .form-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 1.25rem;
  }

  .docx-generate {
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: auto;

    .docx-downloads {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-top: 1rem;
    }

    :deep(.p-message) {
      margin-top: 1rem;
    }
  }

  .document-preview {
    display: flex;
    flex-direction: column;
    min-height: 0;

    .preview-toolbar {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      margin-bottom: 0.5rem;
      flex-shrink: 0;

      .preview-title {
        font-weight: $font-weight-semibold;
        font-size: $font-size-sm;
        color: $surface-700;
      }

      .preview-spinner {
        width: 1.1rem;
        height: 1.1rem;
      }
    }

    .preview-frame-wrap {
      flex: 1;
      min-height: 0;
      border: 1px solid $surface-200;
      border-radius: $card-border-radius;
      overflow: hidden;
      background: $surface-100;

      .preview-frame {
        width: 100%;
        height: 100%;
        border: none;
        background: #fff;
      }
    }
  }
}
</style>
