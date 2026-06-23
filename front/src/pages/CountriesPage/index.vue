<template>
  <div class="countries-page">
    <PageHeader
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
        >
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

          <!-- Sort order -->
          <Column :header="t('admin.countries.columns.sortOrder')" style="width: 100px">
            <template #body="{ data }">{{ data.sort_order }}</template>
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

          <!-- Actions -->
          <Column v-if="canManage" style="width: 90px">
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
                  @click="deleteCountry(data)"
                />
              </span>
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
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import Card from 'primevue/card'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'
import CountryDialog from './components/CountryDialog.vue'
import { useCountriesPage } from './composables/useCountriesPage'

const { t } = useI18n()

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
} = useCountriesPage()
</script>

<style lang="scss" scoped>
.countries-page {
  padding: $space-3;
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
</style>
