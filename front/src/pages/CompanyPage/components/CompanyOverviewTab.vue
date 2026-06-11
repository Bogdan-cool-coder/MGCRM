<template>
  <div class="company-overview">
    <!-- Main info -->
    <Card class="company-overview__card">
      <template #title>{{ t('company.page.sections.main') }}</template>
      <template #content>
        <div class="row g-3">
          <div class="col-md-6">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.name') }} *</label>
              <InlineEditableField
                :model-value="company.name"
                field-key="name"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.legalForm') }}</label>
              <InlineEditableField
                :model-value="company.legal_form"
                field-key="legal_form"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.fullLegalForm') }}</label>
              <InlineEditableField
                :model-value="company.full_legal_form"
                field-key="full_legal_form"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.companyType') }}</label>
              <InlineEditableField
                :model-value="company.company_type_id"
                field-key="company_type_id"
                field-type="select"
                :options="directoriesStore.activeCompanyTypes"
                option-label="name"
                option-value="id"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.industry') }}</label>
              <InlineEditableField
                :model-value="company.industry"
                field-key="industry"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.country') }}</label>
              <InlineEditableField
                :model-value="company.country_code"
                field-key="country_code"
                field-type="geo-country"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.city') }}</label>
              <InlineEditableField
                :model-value="company.city"
                field-key="city"
                field-type="geo-city"
                :country-code="company.country_code"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.phone') }}</label>
              <InlineEditableField
                :model-value="company.phone"
                field-key="phone"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.email') }}</label>
              <InlineEditableField
                :model-value="company.email"
                field-key="email"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.website') }}</label>
              <InlineEditableField
                :model-value="company.website"
                field-key="website"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
        </div>
      </template>
    </Card>

    <!-- Requisites -->
    <Card class="company-overview__card">
      <template #title>{{ t('company.page.sections.requisites') }}</template>
      <template #content>
        <div class="row g-3">
          <div class="col-md-4">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.taxIdLabel') }}</label>
              <InlineEditableField
                :model-value="company.tax_id_label"
                field-key="tax_id_label"
                field-type="text"
                placeholder="БИН / ИНН / TIN"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-8">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.taxId') }}</label>
              <InlineEditableField
                :model-value="company.tax_id"
                field-key="tax_id"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-12">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.address') }}</label>
              <InlineEditableField
                :model-value="company.address"
                field-key="address"
                field-type="textarea"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.bank') }}</label>
              <InlineEditableField
                :model-value="company.bank"
                field-key="bank"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-3">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.bankCodeLabel') }}</label>
              <InlineEditableField
                :model-value="company.bank_code_label"
                field-key="bank_code_label"
                field-type="text"
                placeholder="БИК / SWIFT"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-3">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.bankCode') }}</label>
              <InlineEditableField
                :model-value="company.bank_code"
                field-key="bank_code"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-12">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.account') }}</label>
              <InlineEditableField
                :model-value="company.account"
                field-key="account"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
        </div>
      </template>
    </Card>

    <!-- Signatory -->
    <Card class="company-overview__card">
      <template #title>{{ t('company.page.sections.signatory') }}</template>
      <template #content>
        <div class="row g-3">
          <div class="col-md-6">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.directorPosition') }}</label>
              <InlineEditableField
                :model-value="company.director_position"
                field-key="director_position"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.directorGenitive') }}</label>
              <InlineEditableField
                :model-value="company.director_genitive"
                field-key="director_genitive"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.directorShort') }}</label>
              <InlineEditableField
                :model-value="company.director_short"
                field-key="director_short"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="company-overview__field">
              <label class="company-overview__label">{{ t('company.page.fields.actsBasis') }}</label>
              <InlineEditableField
                :model-value="company.acts_basis"
                field-key="acts_basis"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
        </div>
      </template>
    </Card>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import InlineEditableField from '@/components/crm/InlineEditableField.vue'
import { useDirectoriesStore } from '@/stores/directories'
import type { Company } from '@/entities/crm'

defineProps<{
  company: Company
  isSaving: boolean
}>()

const emit = defineEmits<{
  save: [fieldKey: string, value: unknown]
}>()

const { t } = useI18n()
const directoriesStore = useDirectoriesStore()

function onSave(fieldKey: string, value: string | number | null) {
  emit('save', fieldKey, value)
}
</script>

<style lang="scss" scoped>
.company-overview {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.company-overview__card {
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-card;
}

.company-overview__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.company-overview__label {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
</style>
