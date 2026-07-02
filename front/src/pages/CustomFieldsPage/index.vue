<template>
  <div class="cf-page" :class="{ 'cf-page--embedded': embedded }">
    <!-- Page header — hidden in embedded mode (DirTabCustomFields provides toolbar) -->
    <PageHeader
      v-if="!embedded"
      :title="t('customFields.pageTitle')"
      icon="pi pi-sliders-h"
    >
      <template #actions>
        <Button
          v-if="canManage"
          icon="pi pi-plus"
          :label="t('customFields.add')"
          @click="openCreate"
        />
      </template>
    </PageHeader>

    <div class="cf-page__body">
      <Card>
        <template #content>
          <!-- Scope-filter tabs (line-style) -->
          <div class="cf-page__scope-tabs">
            <Tabs :value="activeScope" @update:value="onScopeChange">
              <TabList>
                <Tab value="all">{{ t('customFields.scopes.all') }}</Tab>
                <Tab value="deal">{{ t('customFields.scopes.deal') }}</Tab>
                <Tab value="contact">{{ t('customFields.scopes.contact') }}</Tab>
                <Tab value="company">{{ t('customFields.scopes.company') }}</Tab>
                <Tab value="contract">{{ t('customFields.scopes.contract') }}</Tab>
              </TabList>
            </Tabs>
          </div>

          <!-- DataTable -->
          <DataTable
            :value="filteredFields"
            :loading="loading"
            row-hover
            size="small"
            :reorderable-rows="activeScope !== 'all'"
            @row-reorder="onRowReorder"
          >
            <!-- Drag handle -->
            <Column
              v-if="canManage"
              row-reorder
              style="width: 40px"
              :pt="{ rowReorderIcon: { class: activeScope === 'all' ? 'cf-page__drag-handle--disabled' : 'cf-page__drag-handle' } }"
            />

            <!-- Label -->
            <Column :header="t('customFields.columns.label')">
              <template #body="{ data }">
                <span class="cf-page__label-cell">{{ data.label }}</span>
                <span class="cf-page__code-cell">{{ data.code }}</span>
              </template>
            </Column>

            <!-- Type -->
            <Column :header="t('customFields.columns.kind')" style="width: 150px">
              <template #body="{ data }">
                <FieldKindTag :kind="data.field_type" />
              </template>
            </Column>

            <!-- Scope / entity -->
            <Column :header="t('customFields.columns.scope')" style="width: 110px">
              <template #body="{ data }">
                <span class="cf-page__scope-badge" :data-scope="data.entity_scope">
                  {{ scopeLabel(data.entity_scope) }}
                </span>
              </template>
            </Column>

            <!-- Required -->
            <Column :header="t('customFields.columns.isRequired')" style="width: 100px">
              <template #body="{ data }">
                <i
                  v-if="data.required"
                  class="pi pi-circle-fill cf-page__required-dot"
                />
              </template>
            </Column>

            <!-- Active -->
            <Column :header="t('customFields.columns.isActive')" style="width: 90px">
              <template #body="{ data }">
                <ToggleSwitch
                  v-if="canManage"
                  :model-value="data.is_active"
                  @update:model-value="() => toggleActive(data)"
                />
                <i
                  v-else
                  :class="data.is_active ? 'pi pi-check text-success' : 'pi pi-times text-secondary'"
                />
              </template>
            </Column>

            <!-- Actions -->
            <Column v-if="canManage" style="width: 80px">
              <template #body="{ data }">
                <span class="d-flex gap-1">
                  <Button
                    icon="pi pi-pencil"
                    text
                    severity="secondary"
                    size="small"
                    :title="t('common.edit')"
                    @click="openEdit(data)"
                  />
                  <Button
                    icon="pi pi-trash"
                    text
                    severity="danger"
                    size="small"
                    :title="t('common.delete')"
                    @click="deleteField(data)"
                  />
                </span>
              </template>
            </Column>

            <!-- Empty state -->
            <template #empty>
              <div class="cf-page__empty">
                <i class="pi pi-sliders-h cf-page__empty-icon" />
                <p class="cf-page__empty-title">{{ t('customFields.empty.title') }}</p>
                <p class="cf-page__empty-hint">{{ t('customFields.empty.hint') }}</p>
                <Button
                  v-if="canManage"
                  :label="t('customFields.add')"
                  icon="pi pi-plus"
                  size="small"
                  text
                  severity="secondary"
                  @click="openCreate"
                />
              </div>
            </template>
          </DataTable>
        </template>
      </Card>
    </div>

    <!-- Create / Edit dialog -->
    <CustomFieldDialog
      v-model="dialogVisible"
      :editing="editingField"
      :loading="saveMutation.isPending.value"
      @save="save"
    />

    <ConfirmDialog />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import Card from 'primevue/card'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import ConfirmDialog from 'primevue/confirmdialog'
import FieldKindTag from '@/components/crm/FieldKindTag.vue'
import CustomFieldDialog from './components/CustomFieldDialog.vue'
import { useCustomFieldsPage } from './composables/useCustomFieldsPage'
import type { CustomFieldScope } from '@/entities/crm'
import type { ScopeFilter } from './composables/useCustomFieldsPage'

interface DataTableRowReorderEvent {
  dragIndex: number
  dropIndex: number
  value: InstanceType<typeof Array>
}

withDefaults(defineProps<{ embedded?: boolean }>(), { embedded: false })

const { t } = useI18n()

const {
  filteredFields,
  loading,
  activeScope,
  dialogVisible,
  editingField,
  canManage,
  saveMutation,
  openCreate,
  openEdit,
  save,
  toggleActive,
  deleteField,
  reorder,
} = useCustomFieldsPage()

function onScopeChange(val: string | number) {
  activeScope.value = String(val) as ScopeFilter
}

function scopeLabel(scope: CustomFieldScope): string {
  return t(`customFields.scopes.${scope}`)
}

function onRowReorder(event: DataTableRowReorderEvent) {
  if (activeScope.value === 'all') return
  // event.value is the updated array after drag
  void reorder(event.value as Array<import('@/entities/crm').CustomFieldDef>)
}

defineExpose({ canManage, openCreate })
</script>

<style lang="scss" scoped>
.cf-page {
  padding: $space-3;

  &--embedded {
    padding: 0;
  }
}

.cf-page__body {
  margin-top: $space-3;

  .cf-page--embedded & {
    margin-top: 0;
  }
}

// ── Scope tabs ────────────────────────────────────────────────────────────────
.cf-page__scope-tabs {
  margin-bottom: $space-3;

  :deep(.p-tablist) {
    background: transparent;
    border-bottom: 1px solid var(--p-surface-200);
    padding: 0;

    .app-dark & {
      border-bottom-color: var(--p-surface-600);
    }
  }

  :deep(.p-tab) {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    padding: $space-2 $space-3;
    color: $surface-600;
    border-bottom: 2px solid transparent;
    white-space: nowrap;

    &:hover {
      color: $surface-900;
    }

    .app-dark & {
      color: var(--p-surface-400);

      &:hover {
        color: var(--p-surface-100);
      }
    }
  }

  :deep(.p-tab[data-p-active="true"]) {
    color: $primary-900;
    font-weight: $font-weight-semibold;
    border-bottom-color: $primary-900;

    .app-dark & {
      color: var(--p-primary-200);
      border-bottom-color: var(--p-primary-200);
    }
  }

  :deep(.p-tablist-active-bar) {
    display: none;
  }
}

// ── Table cells ───────────────────────────────────────────────────────────────
.cf-page__label-cell {
  display: block;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.cf-page__code-cell {
  display: block;
  font-size: $font-size-xs;
  color: $surface-500;
  font-family: $font-family-mono;
  margin-top: 1px;
}

// ── Drag handle ───────────────────────────────────────────────────────────────
.cf-page__drag-handle {
  cursor: grab;
  color: $surface-400;
}

.cf-page__drag-handle--disabled {
  cursor: default;
  opacity: 0.3;
  color: $surface-400;
  pointer-events: none;
}

// ── Required dot ──────────────────────────────────────────────────────────────
.cf-page__required-dot {
  color: var(--p-red-500);
  font-size: $font-size-xs;
}

// ── Scope badge (mirrors tags-page__scope-badge) ──────────────────────────────
.cf-page__scope-badge {
  display: inline-flex;
  align-items: center;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  padding: 2px $space-2;
  border-radius: $radius-pill;

  &[data-scope="deal"] {
    background: var(--p-blue-50);
    color: var(--p-blue-700);

    .app-dark & {
      background: var(--p-blue-950);
      color: var(--p-blue-300);
    }
  }

  &[data-scope="contact"] {
    background: var(--p-green-50);
    color: var(--p-green-700);

    .app-dark & {
      background: var(--p-green-950);
      color: var(--p-green-300);
    }
  }

  &[data-scope="company"] {
    background: var(--p-orange-50);
    color: var(--p-orange-700);

    .app-dark & {
      background: var(--p-orange-950);
      color: var(--p-orange-300);
    }
  }

  &[data-scope="contract"] {
    background: var(--p-teal-50);
    color: var(--p-teal-700);

    .app-dark & {
      background: var(--p-teal-950);
      color: var(--p-teal-300);
    }
  }
}

// ── Empty state ───────────────────────────────────────────────────────────────
.cf-page__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-2;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 2.5rem;
  text-align: center;
}

.cf-page__empty-icon {
  font-size: $font-size-2xl;
  color: $surface-300;
  opacity: 0.7;
}

.cf-page__empty-title {
  font-size: $font-size-md;
  color: $surface-500;
  margin: 0;
}

.cf-page__empty-hint {
  font-size: $font-size-sm;
  color: $surface-400;
  margin: 0;
}
</style>
