<template>
  <Dialog
    v-model:visible="visible"
    :header="t('crm.contacts_page.bulk.addTag')"
    modal
    :style="{ width: '360px' }"
  >
    <div class="tag-dialog__field">
      <label class="tag-dialog__label">{{ t('contacts.page.columns.tags') }}</label>
      <InputText
        v-model="tagValue"
        class="w-full"
        :placeholder="t('contacts.page.columns.tags')"
        autofocus
        @keydown.enter="onApply"
      />
    </div>
    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" text @click="visible = false" />
      <Button
        :label="t('common.apply')"
        :disabled="!tagValue.trim()"
        :loading="loading"
        @click="onApply"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'

const props = defineProps<{
  modelValue: boolean
  loading?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  apply: [tag: string]
}>()

const { t } = useI18n()
const visible = ref(props.modelValue)
const tagValue = ref('')

watch(() => props.modelValue, (v) => { visible.value = v; if (v) tagValue.value = '' })
watch(visible, (v) => emit('update:modelValue', v))

function onApply() {
  if (!tagValue.value.trim()) return
  emit('apply', tagValue.value.trim())
}
</script>

<style lang="scss" scoped>
.tag-dialog__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}
.tag-dialog__label {
  font-size: $font-size-sm;
  color: $surface-600;
}
.w-full { width: 100%; }
</style>
