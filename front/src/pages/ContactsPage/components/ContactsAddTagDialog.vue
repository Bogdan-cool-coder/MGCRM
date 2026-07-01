<template>
  <Dialog
    v-model:visible="visible"
    :header="t('crm.contacts_page.bulk.addTag')"
    modal
    :style="{ width: '360px' }"
  >
    <div class="tag-dialog__field">
      <label class="tag-dialog__label">{{ t('contacts.page.columns.tags') }}</label>
      <AutoComplete
        v-model="tagValue"
        :suggestions="tagSuggestions"
        class="tag-dialog__autocomplete"
        :placeholder="t('contacts.page.columns.tags')"
        autofocus
        @complete="onSearchTags"
        @keydown.enter="onApply"
        @item-select="onApply"
      />
    </div>
    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" text @click="visible = false" />
      <Button
        :label="t('common.apply')"
        :disabled="!tagValue || !String(tagValue).trim()"
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
import AutoComplete from 'primevue/autocomplete'
import Button from 'primevue/button'
import { useDirectoriesStore } from '@/stores/directories'

const props = defineProps<{
  modelValue: boolean
  loading?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  apply: [tag: string]
}>()

const { t } = useI18n()
const directoriesStore = useDirectoriesStore()
const visible = ref(props.modelValue)
const tagValue = ref<string>('')
const tagSuggestions = ref<string[]>([])

watch(() => props.modelValue, (v) => { visible.value = v; if (v) tagValue.value = '' })
watch(visible, (v) => emit('update:modelValue', v))

function onSearchTags(event: { query: string }) {
  const q = event.query.toLowerCase()
  const contactTags = directoriesStore.getTagsForScope('contact')
  tagSuggestions.value = contactTags
    .map((t) => t.name)
    .filter((name) => name.toLowerCase().includes(q))
}

function onApply() {
  const val = String(tagValue.value ?? '').trim()
  if (!val) return
  emit('apply', val)
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

.tag-dialog__autocomplete {
  width: 100%;

  :deep(.p-autocomplete) {
    width: 100%;
  }

  :deep(.p-autocomplete-input) {
    width: 100%;
  }
}
</style>
