<template>
  <div class="employees-tab">
    <div class="employees-tab__header">
      <Button
        icon="pi pi-user-plus"
        :label="t('company.page.employees.add')"
        severity="secondary"
        outlined
        @click="$emit('addEmployee')"
      />
    </div>

    <!-- Empty state -->
    <div v-if="employees.length === 0" class="employees-tab__empty">
      <i class="pi pi-users employees-tab__empty-icon" />
      <p>{{ t('company.page.employees.empty') }}</p>
    </div>

    <!-- Table -->
    <DataTable
      v-else
      :value="employees"
      :loading="loading"
      striped-rows
      class="employees-tab__table"
    >
      <Column :header="t('company.page.employees.columns.name')">
        <template #body="{ data }">
          <RouterLink
            :to="`/contacts/${data.contact_id}`"
            class="employees-tab__name employees-tab__name--link"
          >
            {{ data.contact?.full_name ?? `#${data.contact_id}` }}
          </RouterLink>
        </template>
      </Column>

      <Column :header="t('company.page.employees.columns.position')">
        <template #body="{ data }">
          {{ data.position || '—' }}
        </template>
      </Column>

      <Column :header="t('company.page.employees.columns.status')">
        <template #body="{ data }">
          <Tag
            :value="data.employment_status === 'works'
              ? t('company.page.employees.status.works')
              : t('company.page.employees.status.left')"
            :severity="data.employment_status === 'works' ? 'success' : 'secondary'"
            size="small"
          />
        </template>
      </Column>

      <Column :header="t('company.page.employees.columns.primary')" style="width: 90px">
        <template #body="{ data }">
          <i
            v-if="data.is_primary"
            class="pi pi-star-fill employees-tab__star employees-tab__star--active"
          />
          <i v-else class="pi pi-star employees-tab__star" />
        </template>
      </Column>

      <Column style="width: 60px">
        <template #body="{ data }">
          <Button
            icon="pi pi-ellipsis-v"
            text
            severity="secondary"
            size="small"
            @click.stop="onMenuClick($event, data)"
          />
          <Menu
            :ref="(el) => setMenuRef(data.contact_id, el)"
            :model="getMenuItems(data)"
            popup
          />
        </template>
      </Column>
    </DataTable>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import type { ContactCompanyLink, EmploymentStatus } from '@/entities/crm'

defineProps<{
  employees: ContactCompanyLink[]
  loading: boolean
}>()

const emit = defineEmits<{
  addEmployee: []
  setPrimary: [contactId: number]
  toggleStatus: [contactId: number, current: EmploymentStatus]
  unlink: [contactId: number]
}>()

const { t } = useI18n()

const menuRefs = ref<Map<number, InstanceType<typeof Menu>>>(new Map())

function setMenuRef(id: number, el: unknown) {
  if (el) menuRefs.value.set(id, el as InstanceType<typeof Menu>)
}

function onMenuClick(event: Event, data: ContactCompanyLink) {
  menuRefs.value.get(data.contact_id)?.toggle(event)
}

function getMenuItems(data: ContactCompanyLink) {
  return [
    {
      label: t('company.page.employees.actions.setPrimary'),
      icon: 'pi pi-star',
      command: () => emit('setPrimary', data.contact_id),
    },
    {
      label: t('company.page.employees.actions.changeStatus'),
      icon: 'pi pi-sync',
      command: () =>
        emit('toggleStatus', data.contact_id, data.employment_status ?? 'works'),
    },
    {
      separator: true,
    },
    {
      label: t('company.page.employees.actions.unlink'),
      icon: 'pi pi-user-minus',
      command: () => emit('unlink', data.contact_id),
    },
  ]
}
</script>

<style lang="scss" scoped>
.employees-tab {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.employees-tab__header {
  display: flex;
  justify-content: flex-end;
}

.employees-tab__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
  color: $surface-500;
  text-align: center;
}

.employees-tab__empty-icon {
  font-size: 2rem;
  color: $surface-400;
}

.employees-tab__table {
  border: 1px solid $surface-200;
  border-radius: $radius-md;
}

.employees-tab__name {
  font-weight: $font-weight-medium;
  color: $surface-900;

  .app-dark & {
    color: var(--p-surface-100);
  }

  &--link {
    text-decoration: none;
    color: var(--p-primary-color);

    &:hover {
      text-decoration: underline;
    }
  }
}

.employees-tab__star {
  color: $surface-400;
  font-size: $font-size-md;

  &--active {
    color: var(--p-orange-400, #ffb38a);
  }
}
</style>
