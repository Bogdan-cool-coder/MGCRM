<template>
  <Dialog
    v-model:visible="visible"
    :header="t('sales.pipelineEditor.createPipelineDialog.title')"
    modal
    style="width: 460px"
    :closable="!saving"
  >
    <div class="create-pipeline-dialog">
      <!-- Mode toggle: empty | from template -->
      <SelectButton
        v-model="mode"
        :options="modeOptions"
        option-label="label"
        option-value="value"
        :allow-empty="false"
        class="create-pipeline-dialog__mode-toggle"
      />

      <!-- ── EMPTY mode ── -->
      <template v-if="mode === 'empty'">
        <div class="create-pipeline-dialog__field">
          <label class="create-pipeline-dialog__label">
            {{ t('sales.pipelineEditor.createPipelineDialog.nameLabel') }}
            <span class="req">*</span>
          </label>
          <InputText
            v-model="form.name"
            class="w-full"
            :placeholder="t('sales.pipelineEditor.createPipelineDialog.namePlaceholder')"
            :class="{ 'p-invalid': errors.name }"
            :disabled="saving"
            @keydown.enter="submit"
          />
          <small v-if="errors.name" class="p-error">{{ errors.name }}</small>
        </div>

        <Message severity="info" :closable="false" class="create-pipeline-dialog__info">
          {{ t('sales.pipelineEditor.createPipelineDialog.autoSeedInfo') }}
        </Message>
      </template>

      <!-- ── FROM TEMPLATE mode ── -->
      <template v-else>
        <div class="create-pipeline-dialog__field">
          <label class="create-pipeline-dialog__label">
            {{ t('sales.pipelineEditor.createPipelineDialog.templateLabel') }}
            <span class="req">*</span>
          </label>
          <Select
            v-model="templateId"
            :options="pipelines"
            option-label="name"
            option-value="id"
            :placeholder="t('sales.pipelineEditor.createPipelineDialog.templatePlaceholder')"
            :class="{ 'p-invalid': errors.template }"
            :disabled="saving || pipelines.length === 0"
            class="w-full"
            filter
          />
          <small v-if="errors.template" class="p-error">{{ errors.template }}</small>
          <small v-if="pipelines.length === 0" class="create-pipeline-dialog__hint">
            {{ t('sales.pipelineEditor.createPipelineDialog.templateEmpty') }}
          </small>
        </div>

        <Message severity="info" :closable="false" class="create-pipeline-dialog__info">
          {{ t('sales.pipelineEditor.createPipelineDialog.templateInfo') }}
        </Message>
      </template>
    </div>

    <template #footer>
      <Button
        :label="t('sales.pipelineEditor.createPipelineDialog.cancel')"
        severity="secondary"
        text
        :disabled="saving"
        @click="cancel"
      />
      <Button
        :label="submitLabel"
        icon="pi pi-check"
        :loading="saving"
        severity="primary"
        @click="submit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Message from 'primevue/message'
import Button from 'primevue/button'
import type { PipelineDto } from '@/entities/sales'

type CreateMode = 'empty' | 'from_template'

const props = defineProps<{
  visible: boolean
  saving?: boolean
  /** Existing pipelines to offer as templates */
  pipelines: PipelineDto[]
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  /** Empty-pipeline creation: emit name */
  create: [name: string]
  /** Clone an existing pipeline by id */
  duplicate: [sourceId: number]
}>()

const { t } = useI18n()

// ─── Mode ─────────────────────────────────────────────────────────────────────

const mode = ref<CreateMode>('empty')

const modeOptions = computed(() => [
  { label: t('sales.pipelineEditor.createPipelineDialog.modeEmpty'), value: 'empty' },
  { label: t('sales.pipelineEditor.createPipelineDialog.modeTemplate'), value: 'from_template' },
])

// ─── Form state ───────────────────────────────────────────────────────────────

const form = ref({ name: '' })
const errors = ref({ name: '', template: '' })
const templateId = ref<number | null>(null)

const submitLabel = computed(() =>
  mode.value === 'from_template'
    ? t('sales.pipelineEditor.createPipelineDialog.saveDuplicate')
    : t('sales.pipelineEditor.createPipelineDialog.save'),
)

// ─── Visibility sync ──────────────────────────────────────────────────────────

const visible = ref(props.visible)

watch(
  () => props.visible,
  (v) => {
    visible.value = v
    if (v) {
      // Reset on open
      mode.value = 'empty'
      form.value = { name: '' }
      errors.value = { name: '', template: '' }
      templateId.value = null
    }
  },
)

watch(visible, (v) => {
  emit('update:visible', v)
})

// Clear errors when mode changes
watch(mode, () => {
  errors.value = { name: '', template: '' }
})

// ─── Submit ───────────────────────────────────────────────────────────────────

function validate(): boolean {
  errors.value = { name: '', template: '' }
  if (mode.value === 'empty') {
    if (!form.value.name.trim()) {
      errors.value.name = t('errors.validation')
      return false
    }
  } else {
    if (!templateId.value) {
      errors.value.template = t('errors.validation')
      return false
    }
  }
  return true
}

function submit() {
  if (!validate()) return
  if (mode.value === 'empty') {
    emit('create', form.value.name.trim())
  } else if (templateId.value !== null) {
    emit('duplicate', templateId.value)
  }
}

function cancel() {
  emit('update:visible', false)
}
</script>

<style lang="scss" scoped>
.create-pipeline-dialog {
  display: flex;
  flex-direction: column;
  gap: $space-4;

  &__mode-toggle {
    align-self: flex-start;
    // Compact — same width as the field below
    width: 100%;

    :deep(.p-selectbutton) {
      width: 100%;
    }

    :deep(.p-togglebutton) {
      flex: 1;
    }
  }

  &__field {
    display: flex;
    flex-direction: column;
    gap: $space-1;
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);

    .req {
      color: var(--p-red-500);
      margin-left: 2px;
    }
  }

  &__hint {
    color: var(--p-text-muted-color);
    font-size: $font-size-xs;
  }

  &__info {
    margin: 0;
  }
}
</style>
