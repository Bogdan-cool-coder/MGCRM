<template>
  <div class="companies-table">
    <div class="table-header">
      <h3>{{ t('tableTitle') }}</h3>
      <Button
        severity="primary"
        :label="t('tableCreate')"
        icon="pi pi-plus"
        @click="$emit('create')"
      />
    </div>

    <DataTable :value="companies" :loading="loading" stripedRows tableStyle="min-width: 50rem">
      <Column field="id" header="ID" style="width: 60px"></Column>
      <Column field="name" :header="t('settingsName')"></Column>
      <Column field="is_system" :header="t('tableSystem')" style="width: 100px">
        <template #body="slotProps">
          <Tag v-if="slotProps.data.is_system" :value="t('yes')" severity="success" />
          <Tag v-else :value="t('no')" severity="secondary" />
        </template>
      </Column>
      <Column :header="t('common.actions')" style="width: 150px">
        <template #body="slotProps">
          <div class="actions">
            <Button icon="pi pi-pencil" rounded text @click="$emit('edit', slotProps.data)" />
            <Button
              v-if="!slotProps.data.is_system"
              icon="pi pi-trash"
              rounded
              text
              severity="danger"
              @click="$emit('delete', slotProps.data)"
            />
          </div>
        </template>
      </Column>
    </DataTable>
  </div>
</template>

<script setup lang="ts">
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import type { Company } from '@/entities/company'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from '@/components/Company/locale/en.json'
import ru from '@/components/Company/locale/ru.json'

const { t } = useLocalI18n({ en, ru })

interface Props {
  companies: Company[]
  loading: boolean
}

defineProps<Props>()

defineEmits<{
  create: []
  edit: [company: Company]
  delete: [company: Company]
}>()
</script>

<style lang="scss" scoped>
.companies-table {
  .table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;

    h3 {
      margin: 0;
      font-size: $font-size-lg;
      color: $surface-800;
    }
  }

  .actions {
    display: flex;
    gap: 0.25rem;
  }
}
</style>
