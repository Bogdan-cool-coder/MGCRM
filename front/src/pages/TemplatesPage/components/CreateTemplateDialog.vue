<template>
  <Dialog
    v-model:visible="visible"
    :header="t('templates.create.title')"
    :style="{ width: '500px' }"
    modal
    :draggable="false"
    @hide="resetForm"
  >
    <div class="create-template-dialog__hint mb-3">
      <i class="pi pi-info-circle" />
      {{ t('templates.create.hint') }}
    </div>

    <div class="d-flex flex-column gap-3">
      <!-- Code -->
      <div>
        <label class="create-template-dialog__label">
          {{ t('templates.create.fields.code') }}
          <span class="create-template-dialog__required">*</span>
        </label>
        <InputText
          v-model="form.code"
          class="w-100 mt-1"
          :class="{ 'p-invalid': !!fieldErrors.code }"
          :placeholder="t('templates.create.fields.codePlaceholder')"
          @input="clearFieldError('code')"
        />
        <small v-if="fieldErrors.code" class="p-error">{{ fieldErrors.code }}</small>
        <small v-else class="text-secondary">{{ t('templates.create.fields.codeHint') }}</small>
      </div>

      <!-- Kind -->
      <div>
        <label class="create-template-dialog__label">
          {{ t('templates.create.fields.kind') }}
          <span class="create-template-dialog__required">*</span>
        </label>
        <Select
          v-model="form.kind"
          :options="kindOptions"
          option-label="label"
          option-value="value"
          class="w-100 mt-1"
          :class="{ 'p-invalid': !!fieldErrors.kind }"
          :placeholder="t('templates.create.fields.kindPlaceholder')"
          @change="clearFieldError('kind')"
        />
        <small v-if="fieldErrors.kind" class="p-error">{{ fieldErrors.kind }}</small>
      </div>

      <!-- Title -->
      <div>
        <label class="create-template-dialog__label">
          {{ t('templates.create.fields.title') }}
          <span class="create-template-dialog__required">*</span>
        </label>
        <InputText
          v-model="form.title"
          class="w-100 mt-1"
          :class="{ 'p-invalid': !!fieldErrors.title }"
          @input="clearFieldError('title')"
        />
        <small v-if="fieldErrors.title" class="p-error">{{ fieldErrors.title }}</small>
      </div>

      <!-- Scopes (optional) -->
      <div>
        <label class="create-template-dialog__label">{{ t('templates.card.meta.products') }}</label>
        <InputText
          v-model="productCodesRaw"
          class="w-100 mt-1"
          :placeholder="t('templates.card.edit.placeholderCodes', 'macrocrm, macrosales (через запятую)')"
        />
        <small class="text-secondary">{{ t('templates.card.edit.codesHint') }}</small>
      </div>

      <div>
        <label class="create-template-dialog__label">{{ t('templates.card.meta.countries') }}</label>
        <InputText
          v-model="countryCodesRaw"
          class="w-100 mt-1"
          :placeholder="t('templates.card.edit.placeholderCodes', 'kz, uz')"
        />
        <small class="text-secondary">{{ t('templates.card.edit.codesHint') }}</small>
      </div>
    </div>

    <template #footer>
      <div class="d-flex gap-2 justify-content-end">
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          text
          :disabled="isPending"
          @click="visible = false"
        />
        <Button
          :label="t('templates.create.submit')"
          :loading="isPending"
          @click="submit"
        />
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import { useMutation } from '@/composables/async/useMutation'
import { templatesApi } from '@/api/templates'
import { getValidationErrors } from '@/utils/errors'
import type { TemplateDto, TemplateKind } from '@/entities/template'

const CODE_RE = /^[a-z][a-z0-9_]*$/

const emit = defineEmits<{
  created: [template: TemplateDto]
}>()

const { t } = useI18n()
const router = useRouter()
const toast = useToast()

// ─── Visibility ───────────────────────────────────────────────────────────────

const visible = ref(false)

function open() {
  visible.value = true
}

defineExpose({ open })

// ─── Form state ───────────────────────────────────────────────────────────────

const form = ref({
  code: '',
  kind: null as TemplateKind | null,
  title: '',
})
const productCodesRaw = ref('')
const countryCodesRaw = ref('')
const fieldErrors = ref<Record<string, string>>({})

const kindOptions = computed<{ label: string; value: TemplateKind }[]>(() => [
  { label: t('documents.kinds.docx', 'DOCX'), value: 'docx' },
  { label: t('documents.kinds.yaml', 'YAML'), value: 'yaml' },
  { label: t('documents.kinds.text', 'Текст'), value: 'text' },
])

function clearFieldError(field: string) {
  if (fieldErrors.value[field]) {
    const next = { ...fieldErrors.value }
    delete next[field]
    fieldErrors.value = next
  }
}

function parseCodes(raw: string): string[] {
  return raw
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
}

function resetForm() {
  form.value = { code: '', kind: null, title: '' }
  productCodesRaw.value = ''
  countryCodesRaw.value = ''
  fieldErrors.value = {}
}

// ─── Client-side validation ───────────────────────────────────────────────────

function validate(): boolean {
  const errs: Record<string, string> = {}

  if (!form.value.code.trim()) {
    errs['code'] = t('templates.create.validation.codeRequired')
  } else if (!CODE_RE.test(form.value.code.trim())) {
    errs['code'] = t('templates.create.validation.codeFormat')
  }

  if (!form.value.kind) {
    errs['kind'] = t('templates.create.validation.kindRequired')
  }

  if (!form.value.title.trim()) {
    errs['title'] = t('templates.create.validation.titleRequired')
  }

  fieldErrors.value = errs
  return Object.keys(errs).length === 0
}

// ─── Mutation ─────────────────────────────────────────────────────────────────

const { isPending, run } = useMutation<TemplateDto>()

async function submit() {
  if (!validate()) return

  try {
    const created = await run(() =>
      templatesApi.createTemplate({
        code: form.value.code.trim(),
        kind: form.value.kind!,
        title: form.value.title.trim(),
        product_codes: parseCodes(productCodesRaw.value),
        country_codes: parseCodes(countryCodesRaw.value),
      }),
    )

    visible.value = false
    emit('created', created)
    toast.add({
      severity: 'success',
      summary: t('templates.create.successToast'),
      life: 3000,
    })
    await router.push({ name: 'TemplateDetail', params: { id: created.id } })
  } catch (err: unknown) {
    const apiErrors = getValidationErrors(err)
    if (apiErrors && Object.keys(apiErrors).length > 0) {
      if (apiErrors['code']) {
        apiErrors['code'] = t('templates.card.edit.codeDuplicate')
      }
      fieldErrors.value = apiErrors
    } else {
      toast.add({
        severity: 'error',
        summary: t('errors.unknown', 'Ошибка'),
        life: 3000,
      })
    }
  }
}
</script>

<style lang="scss" scoped>
.create-template-dialog {
  &__hint {
    display: flex;
    align-items: flex-start;
    gap: $space-2;
    padding: $space-2 $space-3;
    border-radius: $radius-md;
    background: var(--p-surface-50);
    color: var(--p-text-muted-color);
    font-size: $font-size-sm;

    .app-dark & {
      background: var(--p-surface-100);
    }

    i {
      flex-shrink: 0;
      margin-top: 2px;
    }
  }

  &__label {
    display: block;
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
  }

  &__required {
    color: var(--p-red-500);
    margin-left: $space-1;
  }
}
</style>
