<template>
  <div class="variables-page">
    <PageHeader :title="t('templateVariables.title')" icon="pi pi-list">
      <template #actions>
        <Button
          v-if="canManage"
          icon="pi pi-plus"
          :label="t('templateVariables.create')"
          @click="openCreate"
        />
      </template>
    </PageHeader>

    <!-- Filters -->
    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
      <Select
        v-model="typeFilter"
        :options="typeOptions"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="t('templateVariables.type')"
        style="width: 140px"
      />
      <div class="d-flex align-items-center gap-2">
        <Checkbox v-model="onlyActive" :binary="true" input-id="only-active" />
        <label for="only-active" class="mb-0 variables-page__label">
          {{ t('templateVariables.active') }}
        </label>
      </div>
      <IconField class="flex-1">
        <InputIcon class="pi pi-search" />
        <InputText v-model="searchFilter" :placeholder="t('common.search')" class="w-100" />
      </IconField>
    </div>

    <!-- Table -->
    <Card>
      <template #content>
        <DataTable :value="variables" :loading="loading" row-hover size="small">
          <!-- Key (copy on hover) -->
          <Column :header="t('templateVariables.key')" style="width: 220px">
            <template #body="{ data }">
              <span class="d-flex align-items-center gap-1">
                <code class="variables-page__key">{{ formatKey(data.key) }}</code>
                <Button
                  icon="pi pi-copy"
                  text
                  severity="secondary"
                  size="small"
                  class="variables-page__copy-btn"
                  :title="t('templateVariables.copied')"
                  @click.stop="copyKey(data.key)"
                />
              </span>
            </template>
          </Column>

          <!-- Label -->
          <Column :header="t('templateVariables.label')">
            <template #body="{ data }">{{ data.label }}</template>
          </Column>

          <!-- Type -->
          <Column :header="t('templateVariables.type')" style="width: 100px">
            <template #body="{ data }">
              <Tag
                severity="secondary"
                :value="t(`templateVariables.types.${data.var_type}`, data.var_type)"
                style="font-size: 0.7rem;"
              />
            </template>
          </Column>

          <!-- Group -->
          <Column :header="t('templateVariables.group')" style="width: 110px">
            <template #body="{ data }">{{ data.group ?? '—' }}</template>
          </Column>

          <!-- Products -->
          <Column :header="t('templateVariables.products')" style="width: 110px">
            <template #body="{ data }">
              {{ data.product_codes.length ? data.product_codes.join(', ') : '*' }}
            </template>
          </Column>

          <!-- Required -->
          <Column :header="t('templateVariables.required')" style="width: 90px">
            <template #body="{ data }">
              <i :class="data.required ? 'pi pi-circle-fill text-primary' : 'pi pi-circle text-secondary'" />
            </template>
          </Column>

          <!-- Active (toggle for admin/lawyer) -->
          <Column :header="t('templateVariables.active')" style="width: 80px">
            <template #body="{ data }">
              <ToggleSwitch
                v-if="canManage"
                :model-value="data.is_active"
                @update:model-value="() => toggleActive(data)"
              />
              <i v-else :class="data.is_active ? 'pi pi-check text-success' : 'pi pi-times text-secondary'" />
            </template>
          </Column>

          <!-- Actions -->
          <Column v-if="canManage" style="width: 60px">
            <template #body="{ data }">
              <Button
                icon="pi pi-pencil"
                text
                severity="secondary"
                size="small"
                @click="openEdit(data)"
              />
            </template>
          </Column>

          <template #empty>
            <div class="variables-page__empty">
              <i class="pi pi-list" />
              <span>{{ t('templateVariables.empty') }}</span>
            </div>
          </template>
        </DataTable>
      </template>
    </Card>

    <!-- Variable dialog -->
    <VariableDialog
      v-model="dialogVisible"
      :editing="editingVariable"
      :loading="saveMutation.isPending.value"
      :type-options="typeOptions"
      @save="save"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import Card from 'primevue/card'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import VariableDialog from './components/VariableDialog.vue'
import { useTemplateVariablesPage } from './composables/useTemplateVariablesPage'

const { t } = useI18n()
const {
  searchFilter,
  typeFilter,
  onlyActive,
  variables,
  loading,
  dialogVisible,
  editingVariable,
  openCreate,
  openEdit,
  save,
  saveMutation,
  toggleActive,
  copyKey,
  canManage,
  typeOptions,
} = useTemplateVariablesPage()

function formatKey(key: string): string {
  return '{{' + key + '}}'
}
</script>

<style lang="scss" scoped>
.variables-page {
  padding: 0.75rem;

  &__label {
    font-size: $font-size-sm;
    color: var(--p-text-color);
    cursor: pointer;
    user-select: none;
  }

  &__key {
    font-size: $font-size-xs;
    background: var(--p-surface-100);
    padding: 2px 4px;
    border-radius: $radius-sm;
  }

  &__copy-btn {
    opacity: 0;
    transition: opacity 0.15s;
  }

  :deep(tr:hover) .variables-page__copy-btn {
    opacity: 1;
  }

  &__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 2rem;
    color: var(--p-text-muted-color);
  }
}
</style>
