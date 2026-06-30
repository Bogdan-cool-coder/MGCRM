<template>
  <div class="variables-page" :class="{ 'variables-page--embedded': embedded }">
    <PageHeader v-if="!embedded" :title="t('templateVariables.title')" icon="pi pi-list">
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
                class="variables-page__type-tag"
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
          <Column v-if="canManage" style="width: 90px">
            <template #body="{ data }">
              <div class="d-flex gap-1">
                <Button
                  icon="pi pi-pencil"
                  text
                  severity="secondary"
                  size="small"
                  @click="openEdit(data)"
                />
                <Button
                  icon="pi pi-trash"
                  text
                  severity="danger"
                  size="small"
                  :title="t('templateVariables.delete', 'Удалить')"
                  @click="deleteVariable(data)"
                />
              </div>
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

withDefaults(defineProps<{ embedded?: boolean }>(), { embedded: false })

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
  deleteVariable,
  copyKey,
  canManage,
  typeOptions,
} = useTemplateVariablesPage()

defineExpose({ canManage, openCreate })

function formatKey(key: string): string {
  return '{{' + key + '}}'
}
</script>

<style lang="scss" scoped>
.variables-page {
  padding: $space-3;

  &--embedded {
    padding: 0;
  }

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
    gap: $space-2;
    padding: $space-8;
    color: var(--p-text-muted-color);
  }

  &__type-tag {
    font-size: $font-size-3xs; // snap from 0.7rem (≈11.2px → 10px)
  }
}
</style>
