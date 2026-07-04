<template>
  <div class="countries-page" :class="{ 'countries-page--embedded': embedded }">
    <PageHeader
      v-if="!embedded"
      :title="t('admin.countries.title')"
      icon="pi pi-globe"
    >
      <template #actions>
        <Button
          v-if="canManage"
          icon="pi pi-plus"
          :label="t('admin.countries.add')"
          @click="openCreate"
        />
      </template>
    </PageHeader>

    <Card>
      <template #content>
        <DataTable
          :value="countries"
          :loading="loading"
          row-hover
          size="small"
          :reorderable-rows="editMode && canManage"
          @row-reorder="onRowReorder"
        >
          <!-- Drag handle — edit-mode only -->
          <Column
            v-if="editMode && canManage"
            row-reorder
            style="width: 40px"
            :pt="{ rowReorderIcon: { class: 'dir-page__drag-handle' } }"
          />

          <!-- Code -->
          <Column :header="t('admin.countries.columns.code')" style="width: 70px">
            <template #body="{ data }">
              <span class="countries-page__code">{{ data.code }}</span>
            </template>
          </Column>

          <!-- Name -->
          <Column :header="t('admin.countries.columns.name')">
            <template #body="{ data }">{{ data.name }}</template>
          </Column>

          <!-- Name EN -->
          <Column :header="t('admin.countries.columns.nameEn')">
            <template #body="{ data }">{{ data.name_en || '—' }}</template>
          </Column>

          <!-- Phone prefix -->
          <Column :header="t('admin.countries.columns.phonePrefix')" style="width: 110px">
            <template #body="{ data }">{{ data.phone_prefix || '—' }}</template>
          </Column>

          <!-- Is active -->
          <Column :header="t('admin.countries.columns.isActive')" style="width: 100px">
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

          <!-- Actions (kebab) — edit-mode only -->
          <Column v-if="editMode && canManage" style="width: 56px">
            <template #body="{ data }">
              <DirRowActionsMenu
                @edit="openEdit(data)"
                @delete="deleteCountry(data)"
              />
            </template>
          </Column>

          <template #empty>
            <div class="dir-page__empty">
              <i class="pi pi-globe" />
              <span>{{ t('admin.countries.empty') }}</span>
              <Button
                v-if="canManage"
                :label="t('admin.countries.add')"
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

    <CountryDialog
      v-model="dialogVisible"
      :editing="editingCountry"
      :loading="saveMutation.isPending.value"
      @save="save"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import Card from 'primevue/card'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'
import CountryDialog from './components/CountryDialog.vue'
import DirRowActionsMenu from '@/pages/SettingsPage/components/sections/directories/DirRowActionsMenu.vue'
import { useCountriesPage } from './composables/useCountriesPage'
import type { Country } from '@/entities/crm'

interface DataTableRowReorderEvent {
  dragIndex: number
  dropIndex: number
  value: unknown[]
}

const { t } = useI18n()

withDefaults(defineProps<{ embedded?: boolean; editMode?: boolean }>(), {
  embedded: false,
  editMode: false,
})

const {
  countries,
  loading,
  dialogVisible,
  editingCountry,
  canManage,
  saveMutation,
  openCreate,
  openEdit,
  save,
  toggleActive,
  deleteCountry,
  reorder,
} = useCountriesPage()

function onRowReorder(event: DataTableRowReorderEvent) {
  void reorder(event.value as Country[])
}

const rowCount = computed(() => countries.value.length)

defineExpose({ canManage, openCreate, rowCount })
</script>

<style lang="scss" scoped>
.countries-page {
  padding: $space-3;

  &--embedded {
    padding: 0;
  }
}

.countries-page__code {
  font-family: $font-family-mono;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: var(--p-primary-color);
  text-transform: uppercase;
}

.dir-page__empty {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: $space-2;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 2.5rem;
  color: var(--p-text-muted-color);

  i {
    font-size: $font-size-2xl;
    opacity: 0.4;
  }
}

// Drag handle (edit-mode reorder). Muted surface, grab cursor.
:deep(.dir-page__drag-handle) {
  cursor: grab;
  color: var(--p-surface-400);

  &:hover {
    color: var(--p-surface-500);
  }
}
</style>
