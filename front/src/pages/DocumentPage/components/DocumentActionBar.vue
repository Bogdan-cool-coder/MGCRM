<template>
  <div class="document-action-bar d-flex align-items-center flex-wrap gap-2">
    <!-- Back -->
    <Button
      icon="pi pi-arrow-left"
      :label="t('documents.card.back')"
      severity="secondary"
      text
      @click="$emit('back')"
    />

    <div class="d-flex align-items-center gap-2 ms-auto flex-wrap">
      <!-- Generate -->
      <Button
        v-if="showGenerate"
        icon="pi pi-cog"
        :label="generating ? t('documents.card.actions.generating') : t('documents.card.actions.generate')"
        :loading="generating"
        :disabled="!isContextValid"
        @click="$emit('generate')"
      />

      <!-- Download docx -->
      <Button
        v-if="doc.docx_path"
        icon="pi pi-file-word"
        :label="t('documents.card.actions.downloadDocx')"
        severity="secondary"
        outlined
        @click="$emit('downloadDocx')"
      />

      <!-- Download pdf -->
      <Button
        v-if="doc.pdf_path"
        icon="pi pi-file-pdf"
        :label="t('documents.card.actions.downloadPdf')"
        severity="secondary"
        outlined
        @click="$emit('downloadPdf')"
      />

      <!-- Submit / Resubmit -->
      <Button
        v-if="showSubmit"
        icon="pi pi-send"
        :label="t('documents.card.actions.submit')"
        :loading="submitting"
        @click="$emit('submit')"
      />
      <Button
        v-if="showResubmit"
        icon="pi pi-refresh"
        :label="t('documents.card.actions.resubmit')"
        :loading="submitting"
        @click="$emit('submit')"
      />

      <!-- Sign -->
      <Button
        v-if="showSign"
        icon="pi pi-pen-to-square"
        :label="t('documents.card.actions.sign')"
        severity="success"
        :loading="signing"
        @click="$emit('sign')"
      />

      <!-- Unsign -->
      <Button
        v-if="showUnsign"
        icon="pi pi-undo"
        :label="t('documents.card.actions.unsign')"
        severity="danger"
        outlined
        @click="$emit('unsign')"
      />

      <!-- Archive / Unarchive -->
      <Button
        v-if="showUnarchive"
        icon="pi pi-box"
        :label="t('documents.card.actions.unarchive')"
        severity="secondary"
        outlined
        @click="$emit('unarchive')"
      />
      <Button
        v-else-if="showArchive"
        icon="pi pi-box"
        :label="t('documents.card.actions.archive')"
        severity="secondary"
        text
        @click="$emit('archive')"
      />

      <!-- Three dots menu -->
      <Button
        icon="pi pi-ellipsis-v"
        text
        severity="secondary"
        @click="(e) => $emit('openMenu', e)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import type { DocumentDto } from '@/entities/document'

const props = defineProps<{
  doc: DocumentDto
  generating?: boolean
  submitting?: boolean
  signing?: boolean
  isContextValid?: boolean
  hasSignedScan?: boolean
  canUnsign?: boolean
}>()

defineEmits<{
  back: []
  generate: []
  downloadDocx: []
  downloadPdf: []
  submit: []
  sign: []
  unsign: []
  archive: []
  unarchive: []
  openMenu: [event: Event]
}>()

const { t } = useI18n()

const status = computed(() => props.doc.status)

const showGenerate = computed(() =>
  status.value === 'draft' || status.value === 'rejected' || status.value === 'needs_rework',
)

const showSubmit = computed(() =>
  status.value === 'draft' && !!props.doc.docx_path,
)

const showResubmit = computed(() =>
  (status.value === 'rejected' || status.value === 'needs_rework') && !!props.doc.docx_path,
)

const showSign = computed(() =>
  status.value === 'approved' && props.hasSignedScan,
)

const showUnsign = computed(() =>
  status.value === 'signed' && props.canUnsign,
)

const showArchive = computed(() =>
  status.value !== 'in_review' && status.value !== 'submitted' && !props.doc.archived_at,
)

const showUnarchive = computed(() => !!props.doc.archived_at)
</script>

<style lang="scss" scoped>
.document-action-bar {
  padding: 0.5rem 0;
}
</style>
