<template>
  <div class="notes-tab">
    <div class="notes-tab__field">
      <label class="notes-tab__label">{{ t('company.page.tabs.notes') }}</label>
      <InlineEditableField
        :model-value="company.notes"
        field-key="notes"
        field-type="textarea"
        :saving="isSaving"
        :placeholder="t('notes.placeholder', 'Добавьте заметку...')"
        @save="onSave"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import InlineEditableField from '@/components/crm/InlineEditableField.vue'
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
</script>

<style lang="scss" scoped>
.notes-tab {
  padding: $space-2 0;
}

.notes-tab__field {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.notes-tab__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-600;
}
</style>
