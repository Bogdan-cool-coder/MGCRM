<template>
  <div class="inbox-draft-editor">
    <!-- Toolbar -->
    <div class="inbox-draft-editor__toolbar">
      <Button
        v-if="showBack"
        icon="pi pi-chevron-left"
        :label="t('inbox.reading.backToList')"
        severity="secondary"
        text
        size="small"
        class="inbox-draft-editor__back"
        @click="emit('back')"
      />
      <span class="inbox-draft-editor__icon">
        <i class="pi pi-file-edit" />
      </span>
      <div class="inbox-draft-editor__title">
        {{ isNew ? t('inbox.drafts.newTitle') : t('inbox.drafts.editTitle') }}
      </div>
      <Button
        icon="pi pi-trash"
        :label="t('inbox.drafts.delete')"
        severity="danger"
        outlined
        size="small"
        class="inbox-draft-editor__delete"
        @click="emit('delete')"
      />
      <Button
        icon="pi pi-check"
        :label="t('inbox.drafts.save')"
        severity="primary"
        size="small"
        :loading="savePending"
        :disabled="!dirty"
        class="inbox-draft-editor__save"
        @click="emit('save')"
      />
    </div>

    <!-- Editor body -->
    <div class="inbox-draft-editor__body">
      <div v-if="relatedMessageId" class="inbox-draft-editor__related">
        <i class="pi pi-reply" />
        {{ t('inbox.drafts.relatedTo', { id: relatedMessageId }) }}
      </div>

      <div class="inbox-draft-editor__field">
        <label class="inbox-draft-editor__label" for="draft-subject">
          {{ t('inbox.drafts.subjectLabel') }}
        </label>
        <InputText
          id="draft-subject"
          :model-value="subject"
          :placeholder="t('inbox.drafts.subjectPlaceholder')"
          class="inbox-draft-editor__input"
          @update:model-value="onSubject"
        />
      </div>

      <div class="inbox-draft-editor__field inbox-draft-editor__field--grow">
        <label class="inbox-draft-editor__label" for="draft-body">
          {{ t('inbox.drafts.bodyLabel') }}
        </label>
        <Textarea
          id="draft-body"
          :model-value="body"
          :placeholder="t('inbox.drafts.bodyPlaceholder')"
          auto-resize
          rows="10"
          class="inbox-draft-editor__textarea"
          @update:model-value="onBody"
        />
      </div>

      <p class="inbox-draft-editor__hint">
        <i class="pi pi-info-circle" />
        {{ t('inbox.drafts.noSendHint') }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'

defineProps<{
  subject: string
  body: string
  relatedMessageId: number | null
  /** true = creating a fresh draft (not yet persisted). */
  isNew: boolean
  dirty: boolean
  savePending: boolean
  showBack?: boolean
}>()

const emit = defineEmits<{
  'update:subject': [value: string]
  'update:body': [value: string]
  save: []
  delete: []
  back: []
}>()

const { t } = useI18n()

function onSubject(value: string | undefined) {
  emit('update:subject', value ?? '')
}

function onBody(value: string | undefined) {
  emit('update:body', value ?? '')
}
</script>

<style lang="scss" scoped>
.inbox-draft-editor {
  height: 100%;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

// ── Toolbar ─────────────────────────────────────────────────────────────────
.inbox-draft-editor__toolbar {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-3 $space-5;
  border-bottom: 1px solid $surface-200;
  flex-shrink: 0;

  .app-dark & {
    border-bottom-color: var(--p-surface-200);
  }
}

.inbox-draft-editor__back {
  flex-shrink: 0;
}

.inbox-draft-editor__icon {
  width: 34px;
  height: 34px;
  flex-shrink: 0;
  border-radius: $radius-circle;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: color-mix(in srgb, var(--p-primary-color) 14%, var(--mg-surface-card));
  color: var(--p-primary-color);

  .pi {
    font-size: $font-size-sm;
  }
}

.inbox-draft-editor__title {
  flex: 1;
  min-width: 0;
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
}

.inbox-draft-editor__delete,
.inbox-draft-editor__save {
  flex-shrink: 0;
}

// ── Body ────────────────────────────────────────────────────────────────────
.inbox-draft-editor__body {
  flex: 1;
  overflow-y: auto;
  padding: $space-4 $space-5;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: $space-4;
  scrollbar-width: none;

  &::-webkit-scrollbar {
    display: none;
  }
}

.inbox-draft-editor__related {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  align-self: flex-start;
  padding: $space-1 $space-3;
  border-radius: $radius-pill;
  background: $primary-50;
  color: $primary-color;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;

  .app-dark & {
    background: var(--p-surface-200);
    color: var(--p-primary-color);
  }

  .pi {
    font-size: $font-size-xs;
  }
}

.inbox-draft-editor__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;

  &--grow {
    flex: 1;
    min-height: 0;
  }
}

.inbox-draft-editor__label {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
}

.inbox-draft-editor__input,
.inbox-draft-editor__textarea {
  width: 100%;
}

.inbox-draft-editor__textarea {
  resize: vertical;
  min-height: 180px;
}

.inbox-draft-editor__hint {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  margin: 0;
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);

  .pi {
    font-size: $font-size-xs;
  }
}
</style>
