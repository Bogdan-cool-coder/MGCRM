<template>
  <InfoPanel
    :title="t('crm.company.sections.employees')"
    icon="pi-users"
    panel-key="company-employees-overview"
    :count="employees.length"
    :default-collapsed="false"
  >
    <template #header-action>
      <Button
        icon="pi pi-plus"
        size="small"
        text
        severity="secondary"
        :aria-label="t('company.page.employees.add')"
        @click.stop="$emit('addEmployee')"
      />
    </template>

    <!-- Empty state -->
    <div v-if="employees.length === 0" class="employees-panel__empty">
      <i class="pi pi-users employees-panel__empty-icon" />
      <p class="employees-panel__empty-text">{{ t('company.page.employees.empty') }}</p>
      <Button
        icon="pi pi-plus"
        :label="t('company.page.employees.add')"
        size="small"
        severity="secondary"
        outlined
        @click="$emit('addEmployee')"
      />
    </div>

    <!-- First 5 employees -->
    <template v-else>
      <EntityRow
        v-for="emp in firstFive"
        :key="emp.contact_id"
        :title="emp.contact?.full_name ?? `#${emp.contact_id}`"
        :subtitle="emp.position || undefined"
        :link-to="`/contacts/${emp.contact_id}`"
        :is-primary="emp.is_primary"
        :tag-label="emp.employment_status === 'works' ? t('company.page.employees.status.works') : t('company.page.employees.status.left')"
        :tag-severity="emp.employment_status === 'works' ? 'success' : 'secondary'"
        icon="pi-user"
        @set-primary="$emit('setPrimary', emp.contact_id)"
      >
        <template #actions>
          <a
            v-if="emp.contact?.phone"
            :href="`tel:${emp.contact.phone}`"
            class="employees-panel__action-icon"
            :title="emp.contact.phone"
          >
            <i class="pi pi-phone" />
          </a>
          <a
            v-if="emp.contact?.email"
            :href="`mailto:${emp.contact.email}`"
            class="employees-panel__action-icon"
            :title="emp.contact.email"
          >
            <i class="pi pi-envelope" />
          </a>
        </template>
      </EntityRow>

      <!-- "All employees" link -->
      <button
        v-if="employees.length > 5"
        type="button"
        class="employees-panel__see-all"
        @click="$emit('goToTab', 'contacts')"
      >
        {{ t('company.page.employees.seeAll', { count: employees.length }) }}
        <i class="pi pi-arrow-right" />
      </button>
    </template>
  </InfoPanel>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InfoPanel from '@/components/crm/entity/InfoPanel.vue'
import EntityRow from '@/components/crm/entity/EntityRow.vue'
import type { ContactCompanyLink } from '@/entities/crm'

const props = defineProps<{
  employees: ContactCompanyLink[]
}>()

defineEmits<{
  addEmployee: []
  setPrimary: [contactId: number]
  goToTab: [tab: string]
}>()

const { t } = useI18n()

const firstFive = computed(() => props.employees.slice(0, 5))
</script>

<style lang="scss" scoped>
.employees-panel__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-6 $space-4;
  text-align: center;
}

.employees-panel__empty-icon {
  font-size: 2rem;
  color: $surface-300;
}

.employees-panel__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

.employees-panel__see-all {
  display: flex;
  align-items: center;
  gap: $space-1;
  background: transparent;
  border: none;
  cursor: pointer;
  padding: $space-2 0;
  font-size: $font-size-xs;
  color: var(--p-primary-color);
  font-weight: $font-weight-medium;
  margin-top: $space-2;

  i {
    font-size: 10px;
  }
}

.employees-panel__action-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: $radius-sm;
  color: $surface-500;
  text-decoration: none;
  transition: background var(--app-transition-fast), color var(--app-transition-fast);

  &:hover {
    background: var(--p-surface-100);
    color: var(--p-primary-color);

    .app-dark & {
      background: var(--p-surface-800);
    }
  }

  i {
    font-size: 12px;
  }
}
</style>
