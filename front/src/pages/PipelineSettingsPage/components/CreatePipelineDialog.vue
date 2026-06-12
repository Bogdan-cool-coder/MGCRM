<template>
  <Dialog
    v-model:visible="visible"
    :header="t('sales.pipelineEditor.createPipelineDialog.title')"
    modal
    style="width: 440px"
    :closable="!saving"
  >
    <div class="create-pipeline-dialog">
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
        :label="t('sales.pipelineEditor.createPipelineDialog.save')"
        icon="pi pi-check"
        :loading="saving"
        severity="primary"
        @click="submit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Button from 'primevue/button'

const props = defineProps<{
  visible: boolean
  saving?: boolean
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  create: [name: string]
}>()

const { t } = useI18n()

const form = ref({ name: '' })
const errors = ref({ name: '' })

watch(
  () => props.visible,
  (v) => {
    if (v) {
      form.value = { name: '' }
      errors.value = { name: '' }
    }
  },
)

const visible = ref(props.visible)

watch(
  () => props.visible,
  (v) => {
    visible.value = v
  },
)

watch(visible, (v) => {
  emit('update:visible', v)
})

function validate(): boolean {
  errors.value.name = ''
  if (!form.value.name.trim()) {
    errors.value.name = t('errors.validation')
    return false
  }
  return true
}

function submit() {
  if (!validate()) return
  emit('create', form.value.name.trim())
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

  &__info {
    margin: 0;
  }
}
</style>
