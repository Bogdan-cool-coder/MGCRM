<template>
  <DealFieldGroup
    ref="fieldGroupRef"
    :title="t('sales.deal.info.groups.company')"
    icon="pi-building"
    group-key="company"
    :accent="true"
    :default-collapsed="defaultCollapsed"
  >
    <!-- Телефон -->
    <DealFieldRow
      :label="t('company.page.fields.phone')"
      field-key="phone"
      :model-value="company.phone"
      field-type="text"
      :saving="savingField === 'phone'"
      @save="saveCompanyField"
    />

    <!-- Email -->
    <DealFieldRow
      :label="t('company.page.fields.email')"
      field-key="email"
      :model-value="company.email"
      field-type="text"
      :saving="savingField === 'email'"
      @save="saveCompanyField"
    />

    <!-- Сайт -->
    <DealFieldRow
      :label="t('company.page.fields.website')"
      field-key="website"
      :model-value="company.website"
      field-type="text"
      :saving="savingField === 'website'"
      @save="saveCompanyField"
    />

    <!-- Адрес -->
    <DealFieldRow
      :label="t('company.page.fields.address')"
      field-key="address"
      :model-value="company.address"
      field-type="text"
      :saving="savingField === 'address'"
      @save="saveCompanyField"
    />

    <!-- Тип компании -->
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
  </DealFieldGroup>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import DealFieldGroup from './DealFieldGroup.vue'
import DealFieldRow from './DealFieldRow.vue'
import { companiesApi } from '@/api/crm/companies'
import { useDirectoriesStore } from '@/stores/directories'
import { getApiErrorMessage } from '@/utils/errors'
import type { Company } from '@/entities/crm'

const props = withDefaults(
  defineProps<{
    company: Company
    defaultCollapsed?: boolean
  }>(),
  { defaultCollapsed: true },
)

const emit = defineEmits<{
  companyUpdated: [company: Company]
}>()

const { t } = useI18n()
const toast = useToast()

const fieldGroupRef = ref<InstanceType<typeof DealFieldGroup> | null>(null)
const directoriesStore = useDirectoriesStore()

function collapse() { fieldGroupRef.value?.collapse?.() }
function expand() { fieldGroupRef.value?.expand?.() }
defineExpose({ collapse, expand })

onMounted(async () => {
  if (!directoriesStore.loaded) {
    await directoriesStore.fetchAll()
  }
})

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
</script>
