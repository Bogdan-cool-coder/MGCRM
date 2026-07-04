<template>
  <Popover ref="panelRef" class="documents-filter-panel">
    <div class="documents-filter-panel__body">
      <!-- Search -->
      <div class="documents-filter-panel__field">
        <label class="documents-filter-panel__field-label">
          {{ t('common.search') }}
        </label>
        <IconField>
          <InputIcon class="pi pi-search" />
          <InputText
            :model-value="modelValue.search"
            :placeholder="t('documents.list.filters.search')"
            class="documents-filter-panel__control"
            @update:model-value="(v) => emit('update:modelValue', { ...modelValue, search: v as string })"
          />
        </IconField>
      </div>

      <!-- Status -->
      <div class="documents-filter-panel__field">
        <label class="documents-filter-panel__field-label">
          {{ t('documents.list.filters.status') }}
        </label>
        <Select
          :model-value="modelValue.status"
          :options="statusOptions"
          option-label="label"
          option-value="value"
          show-clear
          :placeholder="t('documents.list.filters.status')"
          class="documents-filter-panel__control"
          @update:model-value="(v) => emit('update:modelValue', { ...modelValue, status: v })"
        />
      </div>

      <!-- Kind -->
      <div class="documents-filter-panel__field">
        <label class="documents-filter-panel__field-label">
          {{ t('documents.list.filters.kind') }}
        </label>
        <Select
          :model-value="modelValue.kind"
          :options="kindOptions"
          option-label="label"
          option-value="value"
          show-clear
          :placeholder="t('documents.list.filters.kind')"
          class="documents-filter-panel__control"
          @update:model-value="(v) => emit('update:modelValue', { ...modelValue, kind: v })"
        />
      </div>

      <!-- Show archived -->
      <div class="documents-filter-panel__check">
        <Checkbox
          :model-value="modelValue.archived"
          :binary="true"
          input-id="show-archived"
          @update:model-value="(v) => emit('update:modelValue', { ...modelValue, archived: !!v })"
        />
        <label for="show-archived" class="documents-filter-panel__label">
          {{ t('documents.list.filters.archived') }}
        </label>
      </div>

      <!-- Footer -->
      <div class="documents-filter-panel__footer">
        <Button
          :label="t('common.reset')"
          severity="secondary"
          text
          size="small"
          icon="pi pi-filter-slash"
          :disabled="!hasActiveFilters"
          @click="emit('reset')"
        />
        <Button
          :label="t('common.apply')"
          size="small"
          @click="hide"
        />
      </div>
    </div>
  </Popover>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Popover from 'primevue/popover'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import Checkbox from 'primevue/checkbox'
import Button from 'primevue/button'
import type { ContractStatus, DocumentKind } from '@/entities/document'

interface FilterModel {
  status: ContractStatus | null
  kind: DocumentKind | null
  product_code: string | null
  country_code: string | null
  author_user_id: number | null
  search: string
  archived: boolean
}

defineProps<{
  modelValue: FilterModel
  hasActiveFilters: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: FilterModel]
  reset: []
}>()

const { t } = useI18n()

const panelRef = ref<InstanceType<typeof Popover> | null>(null)

function toggle(event: Event) {
  panelRef.value?.toggle(event)
}

function hide() {
  panelRef.value?.hide()
}

defineExpose({ toggle, hide })

const statusOptions = computed(() => [
  { label: t('documents.statuses.draft'), value: 'draft' as ContractStatus },
  { label: t('documents.statuses.submitted'), value: 'submitted' as ContractStatus },
  { label: t('documents.statuses.in_review'), value: 'in_review' as ContractStatus },
  { label: t('documents.statuses.needs_rework'), value: 'needs_rework' as ContractStatus },
  { label: t('documents.statuses.approved'), value: 'approved' as ContractStatus },
  { label: t('documents.statuses.rejected'), value: 'rejected' as ContractStatus },
  { label: t('documents.statuses.signed'), value: 'signed' as ContractStatus },
  { label: t('documents.statuses.uploaded'), value: 'uploaded' as ContractStatus },
  { label: t('documents.statuses.archived'), value: 'archived' as ContractStatus },
])

const kindOptions = computed(() => [
  { label: t('documents.kinds.contract'), value: 'contract' as DocumentKind },
  { label: t('documents.kinds.invoice'), value: 'invoice' as DocumentKind },
  { label: t('documents.kinds.act'), value: 'act' as DocumentKind },
  { label: t('documents.kinds.reconciliation'), value: 'reconciliation' as DocumentKind },
])
</script>

<style lang="scss" scoped>
.documents-filter-panel__body {
  width: 300px;
  max-width: 90vw;
  display: flex;
  flex-direction: column;
  gap: $space-3;
}

.documents-filter-panel__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.documents-filter-panel__field-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: var(--p-text-muted-color);
}

.documents-filter-panel__control {
  width: 100%;
}

.documents-filter-panel__check {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.documents-filter-panel__label {
  font-size: $font-size-sm;
  color: var(--p-text-color);
  cursor: pointer;
  user-select: none;
  margin: 0;
}

.documents-filter-panel__footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: $space-2;
  padding-top: $space-3;
  border-top: 1px solid $surface-200;

  .app-dark & {
    border-top-color: var(--p-surface-300);
  }
}
</style>
