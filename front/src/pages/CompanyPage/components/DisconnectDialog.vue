<template>
  <Dialog
    v-model:visible="visible"
    :header="t('crm.company.disconnect.title')"
    modal
    style="width: 520px"
    :closable="!submitting"
  >
    <!-- Hint -->
    <div class="disconnect-dialog__hint">
      <i class="pi pi-info-circle disconnect-dialog__hint-icon" />
      <span>{{ t('crm.company.disconnect.hint') }}</span>
    </div>

    <div class="disconnect-dialog__form">
      <!-- Reason -->
      <div class="disconnect-dialog__field">
        <label class="disconnect-dialog__label">
          {{ t('crm.company.disconnect.reason') }}
          <span class="disconnect-dialog__required">*</span>
        </label>
        <Select
          v-model="form.disconnect_reason_id"
          :options="reasons"
          option-label="name"
          option-value="id"
          :placeholder="t('crm.company.disconnect.reasonPlaceholder')"
          :class="{ 'p-invalid': errors.reason }"
          class="w-full"
        />
        <small v-if="errors.reason" class="p-error">{{ errors.reason }}</small>
      </div>

      <!-- Termination date -->
      <div class="disconnect-dialog__field">
        <label class="disconnect-dialog__label">
          {{ t('crm.company.disconnect.date') }}
          <span class="disconnect-dialog__required">*</span>
        </label>
        <DatePicker
          v-model="terminationDateModel"
          show-icon
          date-format="dd.mm.yy"
          :placeholder="t('crm.company.disconnect.datePlaceholder')"
          :class="{ 'p-invalid': errors.date }"
          class="w-full"
        />
        <small v-if="errors.date" class="p-error">{{ errors.date }}</small>
      </div>

      <!-- Signatory (optional) -->
      <div class="disconnect-dialog__field">
        <label class="disconnect-dialog__label">{{ t('crm.company.disconnect.signatory') }}</label>
        <InputText
          v-model="form.signatory"
          :placeholder="t('crm.company.disconnect.signatoryPlaceholder')"
          class="w-full"
        />
      </div>
    </div>

    <template #footer>
      <Button
        :label="t('common.cancel')"
        severity="secondary"
        text
        :disabled="submitting"
        @click="visible = false"
      />
      <Button
        :label="t('crm.company.disconnect.submit')"
        icon="pi pi-arrow-right"
        icon-pos="right"
        :loading="submitting"
        @click="onSubmit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import InputText from 'primevue/inputtext'
import type { DisconnectReason } from '@/entities/crm'
import { companiesApi } from '@/api/crm/companies'
import type { DocumentDto as Doc } from '@/entities/document'

const props = defineProps<{
  companyId: number
  companyName: string
  reasons: DisconnectReason[]
  /** Pre-fill signatory from company director field */
  signatoryDefault?: string | null
}>()

const emit = defineEmits<{
  (e: 'created', doc: Doc): void
}>()

const visible = defineModel<boolean>({ default: false })

const { t } = useI18n()

const form = ref({
  disconnect_reason_id: null as number | null,
  termination_date: '',
  signatory: props.signatoryDefault ?? '',
})

const terminationDateModel = ref<Date | null>(null)
const errors = ref({ reason: '', date: '' })
const submitting = ref(false)

// Keep termination_date in sync with DatePicker value
watch(terminationDateModel, (d) => {
  if (!d) {
    form.value.termination_date = ''
    return
  }
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  form.value.termination_date = `${y}-${m}-${day}`
})

// Reset when opened
watch(visible, (val) => {
  if (val) {
    form.value.disconnect_reason_id = null
    form.value.termination_date = ''
    form.value.signatory = props.signatoryDefault ?? ''
    terminationDateModel.value = null
    errors.value = { reason: '', date: '' }
  }
})

function validate(): boolean {
  errors.value = { reason: '', date: '' }
  let ok = true
  if (!form.value.disconnect_reason_id) {
    errors.value.reason = t('crm.company.disconnect.reasonRequired')
    ok = false
  }
  if (!form.value.termination_date) {
    errors.value.date = t('crm.company.disconnect.dateRequired')
    ok = false
  }
  return ok
}

async function onSubmit() {
  if (!validate()) return
  submitting.value = true
  try {
    const payload: {
      disconnect_reason_id: number
      termination_date: string
      context?: { custom?: { termination_signatory?: string } }
    } = {
      disconnect_reason_id: form.value.disconnect_reason_id!,
      termination_date: form.value.termination_date,
    }
    if (form.value.signatory) {
      payload.context = { custom: { termination_signatory: form.value.signatory } }
    }
    const doc = await companiesApi.disconnect(props.companyId, payload)
    visible.value = false
    emit('created', doc as unknown as Doc)
  } catch (err: unknown) {
    // 422 validation errors
    const axiosErr = err as { response?: { data?: { errors?: Record<string, string[]> } } }
    const serverErrors = axiosErr?.response?.data?.errors
    if (serverErrors) {
      if (serverErrors['disconnect_reason_id']?.[0]) {
        errors.value.reason = serverErrors['disconnect_reason_id'][0]
      }
      if (serverErrors['termination_date']?.[0]) {
        errors.value.date = serverErrors['termination_date'][0]
      }
    }
  } finally {
    submitting.value = false
  }
}
</script>

<style lang="scss" scoped>
.disconnect-dialog__hint {
  display: flex;
  align-items: flex-start;
  gap: $space-2;
  padding: $space-3 $space-4;
  background: var(--p-yellow-50);
  border: 1px solid var(--p-yellow-200);
  border-radius: $radius-md;
  margin-bottom: $space-4;
  font-size: $font-size-sm;
  color: var(--p-yellow-800);
  line-height: 1.5;

  .app-dark & {
    background: rgba(255, 193, 7, 0.08);
    border-color: rgba(255, 193, 7, 0.25);
    color: var(--p-yellow-300);
  }
}

.disconnect-dialog__hint-icon {
  flex-shrink: 0;
  margin-top: 2px;
  font-size: $font-size-sm;
}

.disconnect-dialog__form {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.disconnect-dialog__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.disconnect-dialog__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.disconnect-dialog__required {
  color: var(--p-red-500);
  margin-left: 2px;
}

.w-full {
  width: 100%;
}
</style>
