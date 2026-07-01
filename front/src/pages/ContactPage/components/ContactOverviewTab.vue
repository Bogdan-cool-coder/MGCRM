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
          <!-- Responsible (owner_id) — editable -->
          <div class="col-md-6">
            <div class="contact-overview__field">
              <label class="contact-overview__label">{{ t('crm.entity.responsible') }}</label>
              <InlineEditableField
                :model-value="contact.owner_id"
                field-key="owner_id"
                field-type="select"
                :options="users"
                option-label="full_name"
                option-value="id"
                :saving="isSaving"
                @save="onSave"
              />
            </div>
          </div>
          <!-- Author (created_by) — read-only -->
          <div class="col-md-6">
            <div class="contact-overview__field">
              <label class="contact-overview__label">{{ t('crm.entity.author') }}</label>
              <span class="contact-overview__readonly">{{ contact.author?.full_name || '—' }}</span>
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
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import InlineEditableField from '@/components/crm/InlineEditableField.vue'
import { useDirectoriesStore } from '@/stores/directories'
import { useUsersCache } from '@/composables/crm/useUsersCache'
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
const { users, load: loadUsers } = useUsersCache()

const statusOptions = [
  { label: t('contact.page.status.active'), value: 'active' },
  { label: t('contact.page.status.inactive'), value: 'inactive' },
]

function onSave(fieldKey: string, value: string | number | null) {
  emit('save', fieldKey, value)
}

onMounted(() => {
  void loadUsers()
})
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

.contact-overview__readonly {
  font-size: $font-size-sm;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-300);
  }
}
</style>
