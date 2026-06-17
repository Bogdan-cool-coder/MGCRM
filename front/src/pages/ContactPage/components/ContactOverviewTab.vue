<template>
  <div class="contact-overview">
    <Card class="contact-overview__card">
      <template #title>{{ t('company.page.sections.main') }}</template>
      <template #content>
        <div class="row g-3">
          <div class="col-md-6">
            <div class="contact-overview__field">
              <label class="contact-overview__label">{{ t('contact.page.fields.fullName') }} *</label>
              <InlineEditableField
                :model-value="contact.full_name"
                field-key="full_name"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="contact-overview__field">
              <label class="contact-overview__label">{{ t('contact.page.fields.position') }}</label>
              <InlineEditableField
                :model-value="contact.position"
                field-key="position"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="contact-overview__field">
              <label class="contact-overview__label">{{ t('contact.page.fields.phone') }}</label>
              <InlineEditableField
                :model-value="contact.phone"
                field-key="phone"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="contact-overview__field">
              <label class="contact-overview__label">{{ t('contact.page.fields.email') }}</label>
              <InlineEditableField
                :model-value="contact.email"
                field-key="email"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="contact-overview__field">
              <label class="contact-overview__label">{{ t('contact.page.fields.tgUsername') }}</label>
              <InlineEditableField
                :model-value="contact.tg_username"
                field-key="tg_username"
                field-type="text"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="contact-overview__field">
              <label class="contact-overview__label">{{ t('contact.page.fields.source') }}</label>
              <InlineEditableField
                :model-value="contact.source"
                field-key="source"
                field-type="select"
                :options="directoriesStore.activeSources"
                option-label="name"
                option-value="code"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-md-6">
            <div class="contact-overview__field">
              <label class="contact-overview__label">{{ t('contact.page.fields.status') }}</label>
              <InlineEditableField
                :model-value="contact.status"
                field-key="status"
                field-type="select"
                :options="statusOptions"
                option-label="label"
                option-value="value"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <div class="col-12">
            <div class="contact-overview__field">
              <label class="contact-overview__label">{{ t('contact.page.fields.notes') }}</label>
              <InlineEditableField
                :model-value="contact.notes"
                field-key="notes"
                field-type="textarea"
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
import type { Contact } from '@/entities/crm'

defineProps<{
  contact: Contact
  isSaving: boolean
}>()

const emit = defineEmits<{
  save: [fieldKey: string, value: unknown]
}>()

const { t } = useI18n()
const directoriesStore = useDirectoriesStore()

const statusOptions = [
  { label: t('contact.page.status.active'), value: 'active' },
  { label: t('contact.page.status.inactive'), value: 'inactive' },
]

function onSave(fieldKey: string, value: string | number | null) {
  emit('save', fieldKey, value)
}
</script>

<style lang="scss" scoped>
.contact-overview {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.contact-overview__card {
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-card;
}

.contact-overview__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.contact-overview__label {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
</style>
