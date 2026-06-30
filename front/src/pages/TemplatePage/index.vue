<template>
  <div class="template-page">
    <template v-if="loading">
      <Skeleton height="40px" class="mb-3" />
      <div class="row g-4">
        <div class="col-lg-8">
          <Skeleton height="160px" class="mb-3" />
          <Skeleton height="200px" />
        </div>
        <div class="col-lg-4">
          <Skeleton height="200px" class="mb-3" />
          <Skeleton height="160px" />
        </div>
      </div>
    </template>

    <template v-else-if="loadError || !template">
      <div class="template-page__error">
        <p>{{ t('templates.card.notFound') }}</p>
        <Button :label="t('templates.card.back')" icon="pi pi-arrow-left" text @click="router.back()" />
      </div>
    </template>

    <template v-else>
      <PageHeader :title="template.title" icon="pi pi-file-edit">
        <template #actions>
          <Button
            icon="pi pi-arrow-left"
            :label="t('templates.card.back')"
            severity="secondary"
            text
            @click="router.back()"
          />
          <Button
            icon="pi pi-pencil"
            :label="t('templates.card.edit.title')"
            severity="secondary"
            outlined
            @click="editDialogVisible = true"
          />
        </template>
      </PageHeader>

      <div class="row g-4">
        <div class="col-lg-8">
          <TemplateUploadCard
            :current-version="template.current_version"
            :uploading="uploading"
            class="mb-3"
            @upload="uploadVersion"
          />

          <TemplateAiCheckCard
            :version="latestVersion"
            :rechecking="rechecking"
            :overriding="overrideMutation.isPending.value"
            @recheck="recheckVersion"
            @override="confirmOverride"
          />
        </div>

        <div class="col-lg-4">
          <TemplateVersionsCard
            :versions="versions"
            :loading="loadingVersions"
            class="mb-3"
          />
          <TemplateMetaCard :template="template" />
        </div>
      </div>
    </template>

    <ConfirmDialog />

    <!-- Edit dialog — wired to patchTemplate -->
    <TemplateEditDialog
      v-model="editDialogVisible"
      :template="template ?? null"
      :saving="editSaving"
      @save="saveEdit"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import ConfirmDialog from 'primevue/confirmdialog'
import TemplateUploadCard from './components/TemplateUploadCard.vue'
import TemplateAiCheckCard from './components/TemplateAiCheckCard.vue'
import TemplateVersionsCard from './components/TemplateVersionsCard.vue'
import TemplateMetaCard from './components/TemplateMetaCard.vue'
import TemplateEditDialog from './components/TemplateEditDialog.vue'
import { useTemplatePage } from './composables/useTemplatePage'

const { t } = useI18n()
const {
  router,
  template,
  loading,
  loadError,
  versions,
  loadingVersions,
  latestVersion,
  uploading,
  uploadVersion,
  rechecking,
  recheckVersion,
  overrideMutation,
  confirmOverride,
  editDialogVisible,
  editSaving,
  saveEdit,
} = useTemplatePage()
</script>

<style lang="scss" scoped>
.template-page {
  padding: 0.75rem;

  &__error {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1rem;
    padding: 4rem;
    color: var(--p-text-muted-color);
  }
}
</style>
