<template>
  <Card class="template-upload-card">
    <template #title>{{ t('templates.card.upload.title') }}</template>
    <template #content>
      <p v-if="currentVersion != null" class="text-secondary mb-3">
        {{ t('templates.card.upload.current', { v: `v${currentVersion.version_number}` }) }}
      </p>
      <!-- Upload button hidden for read-only roles (TemplatePolicy::uploadVersion = contracts.approve) -->
      <FileUpload
        v-if="!readonly"
        mode="basic"
        accept=".docx"
        :max-file-size="20 * 1024 * 1024"
        :choose-label="currentVersion != null ? t('templates.card.upload.replace') : t('templates.card.upload.btn')"
        :auto="false"
        custom-upload
        :disabled="uploading"
        @select="onSelect"
      />
      <p v-if="!readonly" class="text-secondary mt-2 mb-0 template-upload-card__hint">
        {{ t('templates.card.upload.limit') }}
      </p>
      <p v-if="readonly && currentVersion == null" class="text-secondary mb-0 template-upload-card__hint">
        {{ t('templates.card.upload.noVersion', '—') }}
      </p>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import FileUpload from 'primevue/fileupload'
import type { TemplateVersionDto } from '@/entities/template'

withDefaults(defineProps<{
  currentVersion: TemplateVersionDto | null
  uploading: boolean
  readonly?: boolean
}>(), { readonly: false })

const emit = defineEmits<{
  upload: [file: File]
}>()

const { t } = useI18n()

function onSelect(event: { files: File[] }) {
  const file = event.files[0]
  if (file) emit('upload', file)
}
</script>

<style lang="scss" scoped>
.template-upload-card__hint {
  font-size: $font-size-2xs; // snap from 0.8rem (12.8px)
}
</style>
