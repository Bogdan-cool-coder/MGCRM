<template>
  <Dialog
    v-model:visible="visible"
    :header="t('contacts_columns.title', 'Выбор колонок')"
    modal
    :style="{ width: '360px' }"
  >
    <div class="col-chooser">
      <div
        v-for="col in allColumns"
        :key="col.field"
        class="col-chooser__item"
      >
        <Checkbox
          :model-value="visibleFields.includes(col.field)"
          :binary="true"
          :disabled="col.required"
          @update:model-value="toggleColumn(col.field)"
        />
        <span class="col-chooser__label">{{ col.header }}</span>
        <Tag
          v-if="col.required"
          :value="t('contacts_columns.required', 'Обязат.')"
          severity="secondary"
          size="small"
          class="col-chooser__required"
        />
      </div>
    </div>
    <template #footer>
      <Button
        :label="t('common.cancel')"
        severity="secondary"
        text
        @click="visible = false"
      />
      <Button
        :label="t('common.apply')"
        @click="onApply"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Checkbox from 'primevue/checkbox'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import type { ContactColumnDef } from '../composables/useContactsView'

const props = defineProps<{
  modelValue: boolean
  allColumns: ContactColumnDef[]
  visibleFields: string[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'apply': [fields: string[]]
}>()

const { t } = useI18n()

const visible = ref(props.modelValue)
const localFields = ref<string[]>([...props.visibleFields])

watch(() => props.modelValue, (v) => { visible.value = v })
watch(visible, (v) => emit('update:modelValue', v))
watch(() => props.visibleFields, (v) => { localFields.value = [...v] })

function toggleColumn(field: string) {
  const col = props.allColumns.find((c) => c.field === field)
  if (col?.required) return
  const idx = localFields.value.indexOf(field)
  if (idx >= 0) {
    localFields.value.splice(idx, 1)
  } else {
    localFields.value.push(field)
  }
}

function onApply() {
  emit('apply', [...localFields.value])
  visible.value = false
}
</script>

<style lang="scss" scoped>
.col-chooser {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.col-chooser__item {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-2 0;
}

.col-chooser__label {
  flex: 1;
  font-size: $font-size-sm;
  color: $surface-700;

  :global(.app-dark) & {
    color: var(--p-surface-200);
  }
}

.col-chooser__required {
  flex-shrink: 0;
}
</style>
