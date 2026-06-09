<template>
  <div class="docx-config">
    <!-- Source upload / replace -->
    <div class="docx-block">
      <div class="block-head">
        <h3 class="block-title">{{ t('docx.upload.title') }}</h3>
        <Button
          v-tooltip.left="t('docx.catalog.title')"
          icon="pi pi-question-circle"
          severity="secondary"
          text
          rounded
          :aria-label="t('docx.catalog.title')"
          @click="$emit('open-catalog')"
        />
      </div>

      <p class="block-hint">
        <i class="pi pi-file-word" aria-hidden="true" />
        <span v-if="hasSource">{{ t('docx.upload.hasSource') }}</span>
        <span v-else>{{ t('docx.upload.noSource') }}</span>
      </p>

      <FileUpload
        mode="basic"
        :auto="false"
        accept=".docx"
        :max-file-size="MAX_DOCX_SIZE"
        :choose-label="uploading ? t('docx.upload.uploading') : uploadLabel"
        custom-upload
        :disabled="uploading"
        @select="onSelect"
      />
      <small class="upload-meta">{{ t('docx.upload.limit') }}</small>
    </div>

    <!-- Extracted placeholders (read-only, known / unknown markers) -->
    <div v-if="hasSource" class="docx-block">
      <h3 class="block-title">{{ t('docx.placeholders.title') }}</h3>

      <LoadingState v-if="placeholdersLoading && rows.length === 0" />

      <EmptyState
        v-else-if="rows.length === 0"
        :message="t('docx.placeholders.noPlaceholders')"
        icon="pi pi-inbox"
      />

      <template v-else>
        <Message
          v-if="hasUnknown"
          severity="warn"
          :closable="false"
          class="placeholder-warning"
        >
          {{ t('docx.placeholders.unknownWarning') }}
          <strong>{{ unknownTokens.join(', ') }}</strong>
        </Message>

        <ul class="placeholder-list">
          <li
            v-for="row in rows"
            :key="row.token"
            class="placeholder-row"
            :class="{ 'is-unknown': !row.known }"
          >
            <i
              :class="['pi', row.known ? 'pi-check-circle' : 'pi-exclamation-circle', 'placeholder-icon']"
              aria-hidden="true"
            />
            <code class="placeholder-token">{{ '${' + row.token + '}' }}</code>
            <span class="placeholder-status">
              {{ row.known ? t('docx.placeholders.known') : t('docx.placeholders.unknown') }}
            </span>
          </li>
        </ul>

        <small class="placeholder-hint">{{ t('docx.placeholders.hint') }}</small>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import Button from 'primevue/button'
import FileUpload, { type FileUploadSelectEvent } from 'primevue/fileupload'
import Message from 'primevue/message'
import Tooltip from 'primevue/tooltip'
import LoadingState from '@/components/states/LoadingState.vue'
import EmptyState from '@/components/states/EmptyState.vue'
import type { PlaceholderRow } from '../composables/useDocumentDocx'

const vTooltip = Tooltip

/** 10 MB — mirrors the backend `max:10240` (KB) validation. */
const MAX_DOCX_SIZE = 10 * 1024 * 1024

interface Props {
  t: (_key: string) => string
  hasSource: boolean
  uploading: boolean
  placeholdersLoading: boolean
  rows: PlaceholderRow[]
  unknownTokens: string[]
  hasUnknown: boolean
}

const props = defineProps<Props>()

const emit = defineEmits<{
  upload: [file: File]
  'open-catalog': []
}>()

const uploadLabel = computed(() =>
  props.hasSource ? props.t('docx.upload.replace') : props.t('docx.upload.choose'),
)

const onSelect = (event: FileUploadSelectEvent) => {
  const files = Array.isArray(event.files) ? event.files : [event.files]
  const file = files[0] as File | undefined
  if (file) emit('upload', file)
}
</script>

<style lang="scss" scoped>
.docx-config {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  min-height: 0;
  overflow: auto;
}

.docx-block {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;

  .block-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
  }

  .block-title {
    margin: 0;
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
    color: $surface-800;
  }

  .block-hint {
    margin: 0;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-size: $font-size-sm;
    color: $surface-600;
  }

  .upload-meta {
    color: $surface-500;
    font-size: $font-size-xs;
  }
}

.placeholder-warning {
  margin: 0;
}

.placeholder-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.placeholder-row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.3rem 0.5rem;
  border-radius: 4px;
  background: $surface-50;

  .placeholder-icon {
    font-size: 0.85rem;
    color: $primary-color;
    flex-shrink: 0;
  }

  .placeholder-token {
    font-family: monospace;
    font-size: $font-size-sm;
    color: $surface-800;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
    min-width: 0;
  }

  .placeholder-status {
    font-size: $font-size-xs;
    color: $surface-500;
    flex-shrink: 0;
  }

  &.is-unknown {
    .placeholder-icon {
      color: $orange-500;
    }

    .placeholder-token {
      color: $surface-600;
    }
  }
}

.placeholder-hint {
  color: $surface-500;
  font-size: $font-size-xs;
}
</style>
