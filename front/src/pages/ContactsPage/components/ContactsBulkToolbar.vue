<template>
  <div class="contacts-bulk-toolbar">
    <Button
      icon="pi pi-times"
      :label="t('sales.deals.page.bulk.cancel')"
      severity="secondary"
      text
      @click="emit('cancel')"
    />

    <!-- Select all -->
    <div v-if="totalVisible > 0" class="contacts-bulk-toolbar__select-all">
      <Checkbox
        :model-value="allSelected"
        :binary="true"
        :indeterminate="someSelected"
        @change="onSelectAllChange"
      />
      <span class="contacts-bulk-toolbar__select-all-label">
        {{ t('sales.deals.page.bulk.selectAll') }}
      </span>
    </div>

    <span class="contacts-bulk-toolbar__count">
      {{ t('sales.deals.page.bulk.selected', { n: selectedCount }) }}
    </span>

    <div class="contacts-bulk-toolbar__divider" />

    <!-- Assign owner/responsible — elevated roles only -->
    <Button
      v-if="canAssignOwner !== false"
      icon="pi pi-user-edit"
      :label="t('crm.contacts_page.bulk.assignResponsible')"
      severity="secondary"
      outlined
      size="small"
      :disabled="selectedCount === 0"
      @click="emit('assignOwner')"
    />

    <!-- Add tag -->
    <Button
      icon="pi pi-tag"
      :label="t('crm.contacts_page.bulk.addTag')"
      severity="secondary"
      outlined
      size="small"
      :disabled="selectedCount === 0"
      @click="emit('addTag')"
    />

    <!-- Merge — active when 2 or more selected (bulk supports N records) -->
    <Button
      icon="pi pi-copy"
      :label="t('crm.contacts_page.bulk.merge')"
      severity="secondary"
      outlined
      size="small"
      :disabled="selectedCount < 2"
      :title="selectedCount < 2 ? t('contacts_bulk.mergeHint') : selectedCount > 5 ? t('contacts_bulk.mergeHintMax') : ''"
      @click="emit('merge')"
    />

    <!-- Export XLSX -->
    <Button
      icon="pi pi-download"
      :label="t('crm.contacts_page.bulk.exportXlsx')"
      severity="secondary"
      outlined
      size="small"
      :disabled="selectedCount === 0"
      :loading="exporting"
      @click="emit('export')"
    />

    <!-- Delete — elevated roles only -->
    <Button
      v-if="canDelete !== false"
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
  exporting?: boolean
  /** Whether the current user may bulk-delete (admin/director only) */
  canDelete?: boolean
  /** Whether the current user may assign a new owner (admin/director only) */
  canAssignOwner?: boolean
}>()

const emit = defineEmits<{
  cancel: []
  assignOwner: []
  addTag: []
  merge: []
  export: []
  delete: []
  selectAll: []
  clearSelection: []
}>()

const { t } = useI18n()

const allSelected = computed(
  () => props.totalVisible > 0 && props.selectedCount === props.totalVisible,
)
const someSelected = computed(
  () => props.selectedCount > 0 && props.selectedCount < props.totalVisible,
)

function onSelectAllChange() {
  if (allSelected.value) {
    emit('clearSelection')
  } else {
    emit('selectAll')
  }
}
</script>

<style lang="scss" scoped>
.contacts-bulk-toolbar {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-4;
  background: var(--p-primary-50);
  border-bottom: 1px solid var(--p-primary-100);
  flex-shrink: 0;
  flex-wrap: wrap;

  .app-dark & {
    background: rgba(23, 39, 71, 0.25);
    border-bottom-color: rgba(23, 39, 71, 0.5);
  }
}

.contacts-bulk-toolbar__select-all {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.contacts-bulk-toolbar__select-all-label {
  font-size: $font-size-sm;
  color: $surface-600;
  white-space: nowrap;
  cursor: pointer;
}

.contacts-bulk-toolbar__count {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $primary-color;
  white-space: nowrap;
}

.contacts-bulk-toolbar__divider {
  width: 1px;
  height: 24px;
  background: $surface-200;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-700);
  }
}
</style>
