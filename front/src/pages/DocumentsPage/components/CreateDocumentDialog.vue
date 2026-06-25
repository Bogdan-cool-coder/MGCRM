<template>
  <Dialog
    v-model:visible="visible"
    :header="t('documents.create.title')"
    modal
    :style="{ width: '36rem' }"
    :draggable="false"
    class="create-document-dialog"
  >
    <div class="create-document-dialog__body">
      <!-- Kind -->
      <div class="mb-3">
        <label class="create-document-dialog__label">
          {{ t('documents.create.kind') }} <span class="text-danger">*</span>
        </label>
        <SelectButton
          v-model="form.kind"
          :options="kindOptions"
          option-label="label"
          option-value="value"
          :allow-empty="false"
          class="mt-1 w-100"
        />
      </div>

      <!-- Company -->
      <div class="mb-3">
        <label class="create-document-dialog__label">
          {{ t('documents.create.company') }} <span class="text-danger">*</span>
        </label>
        <AutoComplete
          v-model="companySuggestion"
          :suggestions="companySuggestions"
          option-label="name"
          :placeholder="t('documents.create.company')"
          force-selection
          class="w-100 mt-1"
          :class="{ 'p-invalid': errors.company }"
          @complete="searchCompanies"
          @option-select="onCompanySelect"
        />
        <small v-if="errors.company" class="p-error">{{ errors.company }}</small>
      </div>

      <!-- Product -->
      <div class="mb-3">
        <label class="create-document-dialog__label">
          {{ t('documents.create.product') }} <span class="text-danger">*</span>
        </label>
        <Select
          v-model="form.product_code"
          :options="productOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('documents.create.product')"
          class="w-100 mt-1"
          :class="{ 'p-invalid': errors.product }"
        />
        <small v-if="errors.product" class="p-error">{{ errors.product }}</small>
      </div>

      <!-- Country -->
      <div class="mb-3">
        <label class="create-document-dialog__label">
          {{ t('documents.create.country') }} <span class="text-danger">*</span>
        </label>
        <Select
          v-model="form.country_code"
          :options="countryOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('documents.create.country')"
          class="w-100 mt-1"
          :class="{ 'p-invalid': errors.country }"
        />
        <small v-if="errors.country" class="p-error">{{ errors.country }}</small>
      </div>

      <!-- Template -->
      <div class="mb-3">
        <label class="create-document-dialog__label">
          {{ t('documents.create.template') }} <span class="text-danger">*</span>
        </label>
        <Select
          v-model="form.template_id"
          :options="templateOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('documents.create.template')"
          :disabled="!form.product_code || !form.country_code || loadingTemplates"
          :loading="loadingTemplates"
          class="w-100 mt-1"
          :class="{ 'p-invalid': errors.template }"
        />
        <Message
          v-if="form.product_code && form.country_code && !loadingTemplates && templateOptions.length === 0"
          severity="warn"
          class="mt-1"
          :closable="false"
        >
          {{ t('documents.create.noTemplates') }}
        </Message>
        <small v-if="errors.template" class="p-error">{{ errors.template }}</small>
      </div>

      <!-- Title (optional) -->
      <div class="mb-3">
        <label class="create-document-dialog__label">
          {{ t('documents.create.title_field') }}
        </label>
        <InputText
          v-model="form.title"
          :placeholder="t('documents.create.title_field')"
          class="w-100 mt-1"
        />
      </div>
    </div>

    <template #footer>
      <Button
        :label="t('documents.create.cancel')"
        severity="secondary"
        text
        @click="cancel"
      />
      <Button
        :label="t('documents.create.submit')"
        :loading="createMutation.isPending.value"
        icon="pi pi-arrow-right"
        icon-pos="right"
        @click="submit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import AutoComplete from 'primevue/autocomplete'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import { documentsApi } from '@/api/documents'
import { templatesApi } from '@/api/templates'
import { apiClient } from '@/api/client'
import { useMutation } from '@/composables/async/useMutation'
import type { DocumentKind, CreateDocumentPayload } from '@/entities/document'

const props = defineProps<{
  modelValue: boolean
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

// ─── Form state ───────────────────────────────────────────────────────────────
const form = ref<{
  kind: DocumentKind
  source_company_id: number | null
  product_code: string | null
  country_code: string | null
  template_id: number | null
  title: string
}>({
  kind: 'contract',
  source_company_id: null,
  product_code: null,
  country_code: null,
  template_id: null,
  title: '',
})

const errors = ref<Record<string, string>>({})

// AutoComplete company
interface CompanyOption { id: number; name: string }
const companySuggestion = ref<CompanyOption | string | null>(null)
const companySuggestions = ref<CompanyOption[]>([])

async function searchCompanies(event: { query: string }) {
  try {
    const resp = await apiClient.get<{ data: CompanyOption[] }>('/api/companies', {
      params: { search: event.query, per_page: 20 },
    })
    companySuggestions.value = resp.data.data
  } catch {
    companySuggestions.value = []
  }
}

function onCompanySelect(event: { value: CompanyOption }) {
  form.value.source_company_id = event.value.id
}

// ─── Static options ───────────────────────────────────────────────────────────
const kindOptions = computed(() => [
  { label: t('documents.kinds.contract'), value: 'contract' as DocumentKind },
  { label: t('documents.kinds.invoice'), value: 'invoice' as DocumentKind },
  { label: t('documents.kinds.act'), value: 'act' as DocumentKind },
  { label: t('documents.kinds.reconciliation'), value: 'reconciliation' as DocumentKind },
])

const productOptions = [
  { label: 'MacroCRM', value: 'macrocrm' },
  { label: 'MacroSales', value: 'macrosales' },
  { label: 'MacroERP', value: 'macroerp' },
]

const countryOptions = [
  { label: 'KZ — Казахстан', value: 'KZ' },
  { label: 'UZ — Узбекистан', value: 'UZ' },
  { label: 'RU — Россия', value: 'RU' },
  { label: 'KG — Кыргызстан', value: 'KG' },
]

// ─── Templates (dynamic by product + country) ─────────────────────────────────
const loadingTemplates = ref(false)
const templateOptions = ref<{ label: string; value: number }[]>([])

watch(
  [() => form.value.product_code, () => form.value.country_code],
  async ([product, country]) => {
    if (!product || !country) {
      templateOptions.value = []
      form.value.template_id = null
      return
    }
    loadingTemplates.value = true
    try {
      const templates = await templatesApi.getTemplates({
        kind: 'docx',
        product_code: product,
        country_code: country,
      })
      templateOptions.value = templates.map((tpl) => ({
        label: `${tpl.code} — ${tpl.title}`,
        value: tpl.id,
      }))
      if (templateOptions.value.length === 1) {
        form.value.template_id = templateOptions.value[0]!.value
      } else {
        form.value.template_id = null
      }
    } catch {
      templateOptions.value = []
    } finally {
      loadingTemplates.value = false
    }
  },
)

// ─── Submit ───────────────────────────────────────────────────────────────────
const createMutation = useMutation<number>()

function validate(): boolean {
  const e: Record<string, string> = {}
  if (!form.value.source_company_id) e.company = t('common.required', 'Обязательное поле')
  if (!form.value.product_code) e.product = t('common.required', 'Обязательное поле')
  if (!form.value.country_code) e.country = t('common.required', 'Обязательное поле')
  if (!form.value.template_id) e.template = t('common.required', 'Обязательное поле')
  errors.value = e
  return Object.keys(e).length === 0
}

async function submit() {
  if (!validate()) return

  await createMutation.run(
    async () => {
      const payload: CreateDocumentPayload = {
        kind: form.value.kind,
        source_company_id: form.value.source_company_id!,
        product_code: form.value.product_code!,
        country_code: form.value.country_code!,
        template_id: form.value.template_id!,
        title: form.value.title || null,
      }
      const doc = await documentsApi.createDocument(payload)
      return doc.id
    },
    {
      onSuccess: (docId) => {
        toast.add({
          severity: 'success',
          summary: t('documents.create.title'),
          life: 3000,
        })
        emit('created', docId)
      },
      onError: () => {
        toast.add({
          severity: 'error',
          summary: t('errors.unknown', 'Ошибка'),
          life: 3000,
        })
      },
    },
  )
}

function cancel() {
  visible.value = false
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      form.value = {
        kind: 'contract',
        source_company_id: null,
        product_code: null,
        country_code: null,
        template_id: null,
        title: '',
      }
      errors.value = {}
      companySuggestion.value = null
    }
  },
)
</script>

<style lang="scss" scoped>
.create-document-dialog {
  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }
}
</style>
