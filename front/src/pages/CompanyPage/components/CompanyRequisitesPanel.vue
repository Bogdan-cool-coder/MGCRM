<template>
  <InfoPanel
    :title="t('crm.company.sections.requisites')"
    icon="pi-building"
    panel-key="company-requisites"
    :default-collapsed="false"
  >
    <!-- Section: Legal data -->
    <div class="requisites__section">
      <div class="requisites__section-divider">
        <span>{{ t('company.requisites.section.legal') }}</span>
      </div>
      <KeyFactsBlock>
        <KeyFactsItem :label="t('company.page.fields.taxIdLabel')">
          <InlineEditableField
            :model-value="company.tax_id_label"
            field-key="tax_id_label"
            field-type="text"
            placeholder="БИН / ИНН / TIN"
            :saving="isSaving"
            @save="onSave"
          />
        </KeyFactsItem>

        <KeyFactsItem :label="t('company.page.fields.taxId')">
          <InlineEditableField
            :model-value="company.tax_id"
            field-key="tax_id"
            field-type="text"
            :saving="isSaving"
            @save="onSave"
          />
        </KeyFactsItem>

        <KeyFactsItem :label="t('company.page.fields.legalForm')">
          <InlineEditableField
            :model-value="company.legal_form"
            field-key="legal_form"
            field-type="text"
            :saving="isSaving"
            @save="onSave"
          />
        </KeyFactsItem>

        <KeyFactsItem :label="t('company.page.fields.fullLegalForm')">
          <InlineEditableField
            :model-value="company.full_legal_form"
            field-key="full_legal_form"
            field-type="text"
            :saving="isSaving"
            @save="onSave"
          />
        </KeyFactsItem>

        <KeyFactsItem :label="t('company.page.fields.directorPosition')">
          <InlineEditableField
            :model-value="company.director_position"
            field-key="director_position"
            field-type="text"
            :saving="isSaving"
            @save="onSave"
          />
        </KeyFactsItem>
      </KeyFactsBlock>
    </div>

    <!-- Section: Bank -->
    <div class="requisites__section">
      <div class="requisites__section-divider">
        <span>{{ t('company.requisites.section.bank') }}</span>
      </div>
      <KeyFactsBlock>
        <KeyFactsItem :label="t('company.page.fields.bank')">
          <InlineEditableField
            :model-value="company.bank"
            field-key="bank"
            field-type="text"
            :saving="isSaving"
            @save="onSave"
          />
        </KeyFactsItem>

        <KeyFactsItem :label="t('company.page.fields.account')">
          <InlineEditableField
            :model-value="company.account"
            field-key="account"
            field-type="text"
            :saving="isSaving"
            @save="onSave"
          />
        </KeyFactsItem>
      </KeyFactsBlock>
    </div>

    <!-- Section: Contacts & Segmentation -->
    <div class="requisites__section">
      <div class="requisites__section-divider">
        <span>{{ t('company.requisites.section.contacts') }}</span>
      </div>
      <KeyFactsBlock>
        <KeyFactsItem :label="t('company.page.fields.address')">
          <InlineEditableField
            :model-value="company.address"
            field-key="address"
            field-type="textarea"
            :saving="isSaving"
            @save="onSave"
          />
        </KeyFactsItem>

        <KeyFactsItem :label="t('company.page.fields.website')">
          <span class="company-requisites__url-wrap">
            <a
              v-if="company.website"
              :href="company.website"
              target="_blank"
              rel="noopener noreferrer"
              class="company-requisites__url-link"
            >
              {{ truncateUrl(company.website) }}
              <i class="pi pi-external-link company-requisites__url-icon" />
            </a>
            <InlineEditableField
              :model-value="company.website"
              field-key="website"
              field-type="text"
              :saving="isSaving"
              @save="onSave"
            />
          </span>
        </KeyFactsItem>

        <KeyFactsItem :label="t('company.page.fields.phone')">
          <InlineEditableField
            :model-value="company.phone"
            field-key="phone"
            field-type="text"
            :saving="isSaving"
            @save="onSave"
          />
        </KeyFactsItem>

        <KeyFactsItem :label="t('company.page.fields.email')">
          <InlineEditableField
            :model-value="company.email"
            field-key="email"
            field-type="text"
            :saving="isSaving"
            @save="onSave"
          />
        </KeyFactsItem>
      </KeyFactsBlock>
    </div>
  </InfoPanel>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import InlineEditableField from '@/components/crm/InlineEditableField.vue'
import InfoPanel from '@/components/crm/entity/InfoPanel.vue'
import KeyFactsBlock from '@/components/crm/entity/KeyFactsBlock.vue'
import KeyFactsItem from '@/components/crm/entity/KeyFactsItem.vue'
import type { Company } from '@/entities/crm'

defineProps<{
  company: Company
  isSaving: boolean
}>()

const emit = defineEmits<{
  save: [fieldKey: string, value: unknown]
}>()

const { t } = useI18n()

function onSave(fieldKey: string, value: string | number | null) {
  emit('save', fieldKey, value)
}

function truncateUrl(url: string): string {
  try {
    const u = new URL(url)
    return u.hostname + (u.pathname !== '/' ? u.pathname : '')
  } catch {
    return url.slice(0, 40)
  }
}
</script>

<style lang="scss" scoped>
.requisites__section {
  &:not(:first-child) {
    margin-top: $space-4;
  }
}

.requisites__section-divider {
  display: flex;
  align-items: center;
  gap: $space-2;
  margin-bottom: $space-3;

  span {
    font-size: 10px;
    font-weight: $font-weight-bold;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: $surface-400;
    white-space: nowrap;

    .app-dark & {
      color: var(--p-surface-500);
    }
  }

  &::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--p-surface-200);

    .app-dark & {
      background: var(--p-surface-700);
    }
  }
}

.company-requisites__url-wrap {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  width: 100%;
}

.company-requisites__url-link {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-sm;
  color: var(--p-primary-color);
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }
}

.company-requisites__url-icon {
  font-size: 10px;
}
</style>
