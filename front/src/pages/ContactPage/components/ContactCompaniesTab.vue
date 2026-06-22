<template>
  <div class="contact-companies">
    <div class="contact-companies__header">
      <Button
        icon="pi pi-building"
        :label="t('contact.page.companies.add')"
        severity="secondary"
        outlined
        @click="$emit('attachCompany')"
      />
    </div>

    <div v-if="companies.length === 0" class="contact-companies__empty">
      <i class="pi pi-building contact-companies__empty-icon" />
      <p>{{ t('contact.page.companies.empty') }}</p>
    </div>

    <DataTable
      v-else
      :value="companies"
      :loading="loading"
      striped-rows
      class="contact-companies__table"
    >
      <Column :header="t('contact.page.companies.columns.company')">
        <template #body="{ data }">
          <router-link
            :to="`/companies/${data.company_id}`"
            class="contact-companies__company-link"
          >
            {{ data.company?.name ?? `#${data.company_id}` }}
          </router-link>
        </template>
      </Column>

      <Column :header="t('contact.page.companies.columns.position')">
        <template #body="{ data }">
          {{ data.position || '—' }}
        </template>
      </Column>

      <Column :header="t('contact.page.companies.columns.status')">
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

      <Column :header="t('contact.page.companies.columns.primary')" style="width: 90px">
        <template #body="{ data }">
          <i
            v-if="data.is_primary"
            class="pi pi-star-fill contact-companies__star contact-companies__star--active"
          />
          <i v-else class="pi pi-star contact-companies__star" />
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
            :ref="(el) => setMenuRef(data.company_id, el)"
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
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import type { ContactCompanyLink } from '@/entities/crm'

defineProps<{
  companies: ContactCompanyLink[]
  loading: boolean
}>()

const emit = defineEmits<{
  attachCompany: []
  setPrimary: [companyId: number]
  detach: [companyId: number]
}>()

const { t } = useI18n()

const menuRefs = ref<Map<number, InstanceType<typeof Menu>>>(new Map())

function setMenuRef(id: number, el: unknown) {
  if (el) menuRefs.value.set(id, el as InstanceType<typeof Menu>)
}

function onMenuClick(event: Event, data: ContactCompanyLink) {
  menuRefs.value.get(data.company_id)?.toggle(event)
}

function getMenuItems(data: ContactCompanyLink) {
  return [
    {
      label: t('contact.page.companies.actions.setPrimary'),
      icon: 'pi pi-star',
      command: () => emit('setPrimary', data.company_id),
    },
    { separator: true },
    {
      label: t('contact.page.companies.actions.unlink'),
      icon: 'pi pi-times',
      command: () => emit('detach', data.company_id),
    },
  ]
}
</script>

<style lang="scss" scoped>
.contact-companies {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.contact-companies__header {
  display: flex;
  justify-content: flex-end;
}

.contact-companies__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
  color: $surface-500;
  text-align: center;
}

.contact-companies__empty-icon {
  font-size: $font-size-icon-lg;
  color: $surface-400;
}

.contact-companies__table {
  border: 1px solid $surface-200;
  border-radius: $radius-md;
}

.contact-companies__company-link {
  color: var(--p-primary-color);
  text-decoration: none;
  font-weight: $font-weight-medium;

  &:hover {
    text-decoration: underline;
  }
}

.contact-companies__star {
  color: $surface-400;
  font-size: $font-size-md;

  &--active {
    color: var(--p-orange-400, #ffb38a);
  }
}
</style>
