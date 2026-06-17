<template>
  <div class="bulk-toolbar">
    <Button
      icon="pi pi-times"
      :label="t('sales.deals.page.bulk.cancel')"
      severity="secondary"
      text
      @click="emit('cancel')"
    />

    <!-- Select all checkbox -->
    <div v-if="totalVisible > 0" class="bulk-toolbar__select-all">
      <Checkbox
        :model-value="allSelected"
        :binary="true"
        :indeterminate="someSelected"
        @change="onSelectAllChange"
      />
      <span class="bulk-toolbar__select-all-label">{{ t('sales.deals.page.bulk.selectAll') }}</span>
    </div>

    <span class="bulk-toolbar__count">
      {{ t('sales.deals.page.bulk.selected', { n: selectedCount }) }}
    </span>
    <div class="bulk-toolbar__divider" />
    <Button
      icon="pi pi-user-edit"
      :label="t('sales.deals.page.bulk.assignOwner')"
      severity="secondary"
      outlined
      size="small"
      :disabled="selectedCount === 0"
      @click="emit('assignOwner')"
    />
    <Button
      icon="pi pi-plus"
      :label="t('sales.deals.page.bulk.addTask')"
      severity="secondary"
      outlined
      size="small"
      :disabled="selectedCount === 0"
      @click="emit('addTask')"
    />
    <Button
      icon="pi pi-arrow-right"
      :label="t('sales.deals.page.bulk.moveStage')"
      severity="secondary"
      outlined
      size="small"
      :disabled="selectedCount === 0"
      @click="emit('moveStage')"
    />
    <Button
      icon="pi pi-pencil"
      :label="t('sales.deals.page.bulk.editField')"
      severity="secondary"
      outlined
      size="small"
      :disabled="selectedCount === 0"
      @click="emit('editField')"
    />
    <Button
      icon="pi pi-tag"
      :label="t('sales.deals.page.bulk.editTags')"
      severity="secondary"
      outlined
      size="small"
      :disabled="selectedCount === 0"
      @click="emit('editTags')"
    />
    <Button
      icon="pi pi-trash"
      :label="t('sales.deals.page.bulk.delete')"
      severity="danger"
      outlined
      size="small"
      :disabled="selectedCount === 0"
      @click="emit('delete')"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'

const props = defineProps<{
  selectedCount: number
  totalVisible: number
}>()

const emit = defineEmits<{
  cancel: []
  assignOwner: []
  addTask: []
  moveStage: []
  editField: []
  editTags: []
  delete: []
  selectAll: []
  clearSelection: []
}>()

const { t } = useI18n()

const allSelected = computed(() => props.totalVisible > 0 && props.selectedCount === props.totalVisible)
const someSelected = computed(() => props.selectedCount > 0 && props.selectedCount < props.totalVisible)

function onSelectAllChange() {
  if (allSelected.value) {
    emit('clearSelection')
  } else {
    emit('selectAll')
  }
}
</script>

<style lang="scss" scoped>
.bulk-toolbar {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-4;
  background: var(--p-primary-50);
  border-bottom: 1px solid var(--p-primary-100);
  flex-shrink: 0;
  flex-wrap: wrap;

  :global(.app-dark) & {
    background: rgba(23, 39, 71, 0.25);
    border-bottom-color: rgba(23, 39, 71, 0.5);
  }
}

.bulk-toolbar__select-all {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.bulk-toolbar__select-all-label {
  font-size: $font-size-sm;
  color: $surface-600;
  white-space: nowrap;
  cursor: pointer;
}

.bulk-toolbar__count {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $primary-color;
  white-space: nowrap;
}

.bulk-toolbar__divider {
  width: 1px;
  height: 24px;
  background: $surface-200;
  flex-shrink: 0;

  :global(.app-dark) & {
    background: var(--p-surface-700);
  }
}
</style>
