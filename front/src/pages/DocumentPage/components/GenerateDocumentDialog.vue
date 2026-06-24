<template>
  <Dialog
    v-model:visible="visible"
    :header="t('documents.create.title')"
    modal
    style="width: 36rem"
  >
    <div class="gen-dialog">
      <!-- Kind -->
      <div class="mb-3">
        <label class="gen-dialog__label">{{ t('documents.create.kind') }} *</label>
        <SelectButton
          v-model="form.kind"
          :options="kindOptions"
          option-label="label"
          option-value="value"
          class="mt-1"
        />
      </div>

      <!-- Template -->
      <div class="mb-3">
        <label class="gen-dialog__label">{{ t('documents.create.template') }} *</label>
        <Select
          v-model="form.template_id"
          :options="templateOptions"
          option-label="label"
          option-value="value"
          :loading="loadingTemplates"
          :placeholder="t('documents.create.template')"
          class="w-100 mt-1"
          :invalid="!!errors.template_id"
        />
        <Message
          v-if="!loadingTemplates && templateOptions.length === 0"
          severity="warn"
          :closable="false"
          class="mt-1"
        >
          {{ t('documents.create.noTemplates') }}
        </Message>
        <small v-if="errors.template_id" class="p-error">{{ errors.template_id }}</small>
      </div>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" text @click="visible = false" />
      <Button
        :label="t('documents.create.submit')"
        icon="pi pi-arrow-right"
        :loading="creating"
        :disabled="!form.template_id"
        @click="submit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import { templatesApi } from '@/api/templates'
import { documentsApi } from '@/api/documents'
import type { DocumentKind } from '@/entities/document'
import type { GenerateFromContextResponse } from '@/api/documents'

const props = defineProps<{
  modelValue: boolean
  dealId?: number | null
  companyId?: number | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  created: [docId: number]
}>()

const { t } = useI18n()
const toast = useToast()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const kindOptions = computed(() => [
  { label: t('documents.kinds.contract'), value: 'contract' as DocumentKind },
  { label: t('documents.kinds.invoice'), value: 'invoice' as DocumentKind },
  { label: t('documents.kinds.act'), value: 'act' as DocumentKind },
  { label: t('documents.kinds.reconciliation'), value: 'reconciliation' as DocumentKind },
])

interface FormState {
  kind: DocumentKind
  template_id: number | null
}

const form = ref<FormState>({ kind: 'contract', template_id: null })
const errors = ref<Record<string, string>>({})
const creating = ref(false)
const loadingTemplates = ref(false)
const templateOptions = ref<{ label: string; value: number }[]>([])

async function loadTemplates() {
  loadingTemplates.value = true
  templateOptions.value = []
  form.value.template_id = null
  try {
    const templates = await templatesApi.getTemplates({ kind: form.value.kind })
    templateOptions.value = templates.map((tpl) => ({
      label: `${tpl.title} (v${tpl.current_version ?? 1})`,
      value: tpl.id,
    }))
  } catch {
    // non-critical
  } finally {
    loadingTemplates.value = false
  }
}

watch(() => form.value.kind, () => void loadTemplates())
watch(() => props.modelValue, (open) => {
  if (open) {
    form.value = { kind: 'contract', template_id: null }
    errors.value = {}
    void loadTemplates()
  }
})

async function submit() {
  errors.value = {}
  if (!form.value.template_id) {
    errors.value.template_id = t('errors.required', 'Обязательное поле')
    return
  }
  creating.value = true
  try {
    let result: GenerateFromContextResponse
    if (props.dealId) {
      result = await documentsApi.generateFromDeal(props.dealId, {
        kind: form.value.kind,
        template_id: form.value.template_id,
      })
    } else if (props.companyId) {
      result = await documentsApi.generateFromCompany(props.companyId, {
        kind: form.value.kind,
        template_id: form.value.template_id,
      })
    } else {
      return
    }
    visible.value = false
    emit('created', result.document_id)
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  } finally {
    creating.value = false
  }
}
</script>

<style lang="scss" scoped>
.gen-dialog {
  padding: $space-2 0;
  display: flex;
  flex-direction: column;
  gap: $space-3;

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
    margin-bottom: 0.25rem;
  }
}
</style>
