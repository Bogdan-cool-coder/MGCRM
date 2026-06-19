<template>
  <div class="contact-companies-panel">
    <!-- Loading -->
    <div v-if="loading" class="contact-companies-panel__skeleton">
      <Skeleton height="48px" class="mb-2" />
      <Skeleton height="48px" class="mb-2" />
    </div>

    <!-- Companies list -->
    <template v-else>
      <EntityRow
        v-for="link in companies"
        :key="link.id"
        :title="link.company?.name ?? `#${link.company_id}`"
        :subtitle="link.position || undefined"
        :link-to="`/companies/${link.company_id}`"
        :is-primary="link.is_primary"
        icon="pi-building"
        :tag-label="employmentLabel(link.employment_status)"
        :tag-severity="link.employment_status === 'works' ? 'success' : 'secondary'"
        @set-primary="emit('setPrimary', link.company_id)"
      >
        <template #actions>
          <Button
            icon="pi pi-ellipsis-v"
            text
            severity="secondary"
            size="small"
            @click.stop="onMenuClick($event, link)"
          />
          <Menu
            :ref="(el) => setMenuRef(link.id, el)"
            :model="menuItems(link)"
            popup
          />
        </template>
      </EntityRow>

      <!-- Empty -->
      <div v-if="companies.length === 0" class="contact-companies-panel__empty">
        <i class="pi pi-building contact-companies-panel__empty-icon" />
        <p class="contact-companies-panel__empty-text">{{ t('contact.page.companies.empty') }}</p>
      </div>

      <!-- Attach button -->
      <button class="contact-companies-panel__add-btn" @click="emit('attach')">
        <i class="pi pi-plus" />
        {{ t('contact.page.companies.add') }}
      </button>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import Skeleton from 'primevue/skeleton'
import EntityRow from '@/components/crm/entity/EntityRow.vue'
import type { ContactCompanyLink, EmploymentStatus } from '@/entities/crm'

defineProps<{
  companies: ContactCompanyLink[]
  loading?: boolean
}>()

const emit = defineEmits<{
  attach: []
  setPrimary: [companyId: number]
  detach: [companyId: number]
}>()

const { t } = useI18n()

const menuRefs = ref<Map<number, InstanceType<typeof Menu>>>(new Map())

function setMenuRef(id: number, el: unknown) {
  if (el) menuRefs.value.set(id, el as InstanceType<typeof Menu>)
  else menuRefs.value.delete(id)
}

function onMenuClick(event: Event, link: ContactCompanyLink) {
  menuRefs.value.get(link.id)?.toggle(event)
}

function menuItems(link: ContactCompanyLink) {
  return [
    {
      label: t('contact.page.companies.actions.setPrimary'),
      icon: 'pi pi-star',
      command: () => emit('setPrimary', link.company_id),
    },
    { separator: true },
    {
      label: t('contact.page.companies.actions.unlink'),
      icon: 'pi pi-times',
      command: () => emit('detach', link.company_id),
    },
  ]
}

function employmentLabel(status: EmploymentStatus | null | undefined): string {
  if (!status) return ''
  return status === 'works'
    ? t('company.page.employees.status.works')
    : t('company.page.employees.status.left')
}
</script>

<style lang="scss" scoped>
.contact-companies-panel {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.contact-companies-panel__skeleton {
  display: flex;
  flex-direction: column;
}

.contact-companies-panel__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-4;
  text-align: center;
}

.contact-companies-panel__empty-icon {
  font-size: 1.5rem;
  color: $surface-300;
}

.contact-companies-panel__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

.contact-companies-panel__add-btn {
  display: flex;
  align-items: center;
  gap: $space-2;
  background: transparent;
  border: none;
  cursor: pointer;
  color: var(--p-primary-color);
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  padding: $space-1 0;
  margin-top: $space-1;
  transition: opacity var(--app-transition-fast);

  &:hover {
    opacity: 0.75;
  }

  i {
    font-size: 11px;
  }
}
</style>
