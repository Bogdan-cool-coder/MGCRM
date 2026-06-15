<template>
  <DealFieldGroup
    :title="t('sales.deal.info.groups.company')"
    icon="pi-building"
    group-key="company"
  >
    <!-- website -->
    <DealFieldRow
      :label="t('company.page.fields.website')"
      field-key="website"
      :model-value="company.website"
      field-type="text"
      :saving="savingField === 'website'"
      @save="saveCompanyField"
    />

    <!-- address -->
    <DealFieldRow
      :label="t('company.page.fields.address')"
      field-key="address"
      :model-value="company.address"
      field-type="text"
      :saving="savingField === 'address'"
      @save="saveCompanyField"
    />

    <!-- company_type_id -->
    <DealFieldRow
      :label="t('company.page.fields.companyType')"
      field-key="company_type_id"
      :model-value="company.company_type_id"
      field-type="select"
      :options="directoriesStore.activeCompanyTypes as Array<Record<string, unknown>>"
      option-label="name"
      option-value="id"
      :saving="savingField === 'company_type_id'"
      @save="saveCompanyField"
    />

    <!-- phone -->
    <DealFieldRow
      :label="t('company.page.fields.phone')"
      field-key="phone"
      :model-value="company.phone"
      field-type="text"
      :saving="savingField === 'phone'"
      @save="saveCompanyField"
    />

    <!-- email -->
    <DealFieldRow
      :label="t('company.page.fields.email')"
      field-key="email"
      :model-value="company.email"
      field-type="text"
      :saving="savingField === 'email'"
      @save="saveCompanyField"
    />

    <!-- Custom fields scope=company (extra_fields) -->
    <template v-if="companyCustomDefs.length > 0">
      <DealFieldRow
        v-for="def in companyCustomDefs"
        :key="def.code"
        :label="def.label"
        :field-key="def.code"
        :model-value="(company.extra_fields?.[def.code] as string | null | undefined)"
        :field-type="def.field_type === 'url' ? 'url' : 'text'"
        :saving="savingField === `extra_${def.code}`"
        @save="saveExtraField"
      />
    </template>
  </DealFieldGroup>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import DealFieldGroup from './DealFieldGroup.vue'
import DealFieldRow from './DealFieldRow.vue'
import { companiesApi } from '@/api/crm/companies'
import { customFieldsApi } from '@/api/crm/customFields'
import { useDirectoriesStore } from '@/stores/directories'
import { getApiErrorMessage } from '@/utils/errors'
import type { Company } from '@/entities/crm'
import type { CustomFieldDef } from '@/entities/crm'

const props = defineProps<{
  company: Company
}>()

const emit = defineEmits<{
  companyUpdated: [company: Company]
}>()

const { t } = useI18n()
const toast = useToast()
const directoriesStore = useDirectoriesStore()

// Ensure company types are loaded
onMounted(async () => {
  if (!directoriesStore.loaded) {
    await directoriesStore.fetchAll()
  }
  await loadCompanyCustomDefs()
})

// ── Company custom field definitions ──────────────────────────────────────────

const companyCustomDefs = ref<CustomFieldDef[]>([])

async function loadCompanyCustomDefs() {
  try {
    const defs = await customFieldsApi.getDefinitions('company')
    // Filter URL-type and interesting fields only
    companyCustomDefs.value = defs.filter(
      (d) => d.is_active && ['url', 'text'].includes(d.field_type),
    )
  } catch {
    // Non-critical
  }
}

// ── Save company fields ────────────────────────────────────────────────────────

const savingField = ref<string | null>(null)

async function saveCompanyField(fieldKey: string, value: string | number | null) {
  savingField.value = fieldKey
  try {
    const updated = await companiesApi.update(props.company.id, { [fieldKey]: value ?? null })
    emit('companyUpdated', updated)
    toast.add({ severity: 'success', summary: t('common.saved'), life: 2000 })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    savingField.value = null
  }
}

async function saveExtraField(fieldKey: string, value: string | number | null) {
  savingField.value = `extra_${fieldKey}`
  try {
    const extra = { ...(props.company.extra_fields ?? {}), [fieldKey]: value ?? null }
    const updated = await companiesApi.update(props.company.id, { extra_fields: extra })
    emit('companyUpdated', updated)
    toast.add({ severity: 'success', summary: t('common.saved'), life: 2000 })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    savingField.value = null
  }
}
</script>
