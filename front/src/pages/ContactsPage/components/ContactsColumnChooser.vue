<template>
  <Dialog
    v-model:visible="visible"
    :header="t('contacts_columns.title', 'Выбор колонок')"
    modal
    :style="{ width: '380px' }"
  >
    <!-- Hint -->
    <p class="col-chooser__hint">{{ t('contacts_columns.dragHint', 'Перетащите строки для изменения порядка колонок') }}</p>

    <draggable
      v-model="localCols"
      item-key="field"
      handle=".col-chooser__drag-handle"
      :animation="180"
      ghost-class="col-chooser__item--ghost"
      class="col-chooser"
    >
      <template #item="{ element: col }">
        <div class="col-chooser__item">
          <!-- Drag handle -->
          <i class="pi pi-bars col-chooser__drag-handle" />

          <Checkbox
            :model-value="localVisible.includes(col.field)"
            :binary="true"
            :disabled="col.required"
            @update:model-value="toggleColumn(col.field, col.required)"
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
      </template>
    </draggable>

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
import draggable from 'vuedraggable'
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
  /** emits ordered list of visible field keys */
  'apply': [fields: string[]]
}>()

const { t } = useI18n()

const visible = ref(props.modelValue)

// Full ordered column list (drives drag-and-drop)
const localCols = ref<ContactColumnDef[]>([])
// Which fields are checked (visible)
const localVisible = ref<string[]>([])

function syncFromProps() {
  // Build ordered list: visible fields first (in their saved order), then hidden
  const visSet = new Set(props.visibleFields)
  const ordered: ContactColumnDef[] = []
  // 1. visible in saved order
  for (const field of props.visibleFields) {
    const def = props.allColumns.find((c) => c.field === field)
    if (def) ordered.push(def)
  }
  // 2. hidden columns appended at end
  for (const def of props.allColumns) {
    if (!visSet.has(def.field)) ordered.push(def)
  }
  localCols.value = ordered
  localVisible.value = [...props.visibleFields]
}

watch(() => props.modelValue, (v) => {
  visible.value = v
  if (v) syncFromProps()
})
watch(visible, (v) => emit('update:modelValue', v))
watch(() => props.visibleFields, () => { if (visible.value) syncFromProps() })

function toggleColumn(field: string, required?: boolean) {
  if (required) return
  const idx = localVisible.value.indexOf(field)
  if (idx >= 0) {
    localVisible.value.splice(idx, 1)
  } else {
    localVisible.value.push(field)
  }
}

function onApply() {
  // Emit fields in drag order, filtered to checked ones; required cols always included
  const requiredFields = props.allColumns.filter((c) => c.required).map((c) => c.field)
  const orderedVisible = localCols.value
    .map((c) => c.field)
    .filter((f) => localVisible.value.includes(f) || requiredFields.includes(f))
  emit('apply', orderedVisible)
  visible.value = false
}
</script>

<style lang="scss" scoped>
.col-chooser__hint {
  font-size: $font-size-xs;
  color: $surface-400;
  margin: 0 0 $space-3;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.col-chooser {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.col-chooser__item {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-2 $space-1;
  border-radius: $radius-sm;
  transition: background var(--app-transition-fast);

  &:hover {
    background: $surface-50;

    .app-dark & {
      background: var(--p-surface-200);
    }
  }
}

// Ghost (placeholder while dragging)
.col-chooser__item--ghost {
  opacity: 0.4;
  background: $surface-100;

  .app-dark & {
    background: var(--p-surface-200);
  }
}

.col-chooser__drag-handle {
  font-size: $font-size-sm;
  color: $surface-300;
  cursor: grab;
  flex-shrink: 0;

  &:active {
    cursor: grabbing;
  }

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.col-chooser__label {
  flex: 1;
  font-size: $font-size-sm;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.col-chooser__required {
  flex-shrink: 0;
}
</style>
