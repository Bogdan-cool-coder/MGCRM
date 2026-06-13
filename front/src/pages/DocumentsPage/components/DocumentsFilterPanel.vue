<template>
  <Card class="documents-filter-panel mb-3">
    <template #content>
      <div class="row g-2 align-items-end">
        <!-- Status -->
        <div class="col-md-2">
          <Select
            :model-value="modelValue.status"
            :options="statusOptions"
            option-label="label"
            option-value="value"
            show-clear
            :placeholder="t('documents.list.filters.status')"
            class="w-100"
            @update:model-value="(v) => $emit('update:modelValue', { ...modelValue, status: v })"
          />
        </div>

        <!-- Kind -->
        <div class="col-md-2">
          <Select
            :model-value="modelValue.kind"
            :options="kindOptions"
            option-label="label"
            option-value="value"
            show-clear
            :placeholder="t('documents.list.filters.kind')"
            class="w-100"
            @update:model-value="(v) => $emit('update:modelValue', { ...modelValue, kind: v })"
          />
        </div>

        <!-- Search -->
        <div class="col-md-3">
          <IconField>
            <InputIcon class="pi pi-search" />
            <InputText
              :model-value="modelValue.search"
              :placeholder="t('documents.list.filters.search')"
              class="w-100"
              @update:model-value="(v) => $emit('update:modelValue', { ...modelValue, search: v as string })"
            />
          </IconField>
        </div>

        <!-- Show archived -->
        <div class="col-md-2 d-flex align-items-center gap-2">
          <Checkbox
            :model-value="modelValue.archived"
            :binary="true"
            input-id="show-archived"
            @update:model-value="(v) => $emit('update:modelValue', { ...modelValue, archived: !!v })"
          />
          <label for="show-archived" class="documents-filter-panel__label mb-0">
            {{ t('documents.list.filters.archived') }}
          </label>
        </div>

        <!-- Reset -->
        <div class="col-md-1 d-flex justify-content-end">
          <Button
            :label="t('common.reset')"
            severity="secondary"
            text
            icon="pi pi-filter-slash"
            :disabled="!hasActiveFilters"
            @click="$emit('reset')"
          />
        </div>
      </div>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
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

defineEmits<{
  'update:modelValue': [value: FilterModel]
  reset: []
}>()

const { t } = useI18n()

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
.documents-filter-panel {
  &__label {
    font-size: $font-size-sm;
    color: var(--p-text-color);
    cursor: pointer;
    user-select: none;
  }
}
</style>
