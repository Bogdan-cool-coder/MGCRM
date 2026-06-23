<template>
  <div class="employees-tab">
    <!-- Empty state -->
    <div v-if="employees.length === 0" class="employees-tab__empty">
      <i class="pi pi-users employees-tab__empty-icon" />
      <p>{{ t('company.page.employees.empty') }}</p>
    </div>

    <!-- Table with expandable rows -->
    <DataTable
      v-else
      v-model:expanded-rows="expandedRows"
      :value="employees"
      :loading="loading"
      class="employees-tab__table"
      data-key="contact_id"
    >
      <Column style="width: 32px" expander />

      <Column :header="t('company.page.employees.columns.name')">
        <template #body="{ data }">
          <RouterLink
            :to="`/contacts/${data.contact_id}`"
            class="employees-tab__name employees-tab__name--link"
            @click.stop
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

      <!-- Expansion row: contact channels -->
      <template #expansion="{ data }">
        <div class="employees-tab__expansion">
          <template v-if="data.contact?.channels?.length">
            <span
              v-for="ch in data.contact.channels"
              :key="ch.id"
              class="employees-tab__channel-chip"
            >
              <i :class="['pi', channelIcon(ch.channel_type)]" />
              {{ ch.value }}
            </span>
          </template>
          <span v-else class="employees-tab__expansion-empty">{{ t('crm.contact.channels.empty') }}</span>
        </div>
      </template>
    </DataTable>

    <!-- ── Status picker Dialog ──────────────────────────────────────────────── -->
    <Dialog
      v-model:visible="statusPickerOpen"
      :header="t('company.page.employees.actions.changeStatus')"
      modal
      :style="{ width: '320px' }"
    >
      <div class="employees-tab__status-options">
        <button
          v-for="opt in statusOptions"
          :key="opt.value"
          type="button"
          class="employees-tab__status-option"
          :class="{ 'employees-tab__status-option--active': pendingStatus === opt.value }"
          @click="pendingStatus = opt.value"
        >
          <Tag
            :value="opt.label"
            :severity="opt.value === 'works' ? 'success' : 'secondary'"
            size="small"
          />
        </button>
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" text @click="statusPickerOpen = false" />
        <Button
          :label="t('common.save')"
          :disabled="pendingStatus === null"
          @click="confirmStatusChange"
        />
      </template>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import Dialog from 'primevue/dialog'
import { RouterLink } from 'vue-router'
import type { ContactCompanyLink, EmploymentStatus } from '@/entities/crm'

defineProps<{
  employees: ContactCompanyLink[]
  loading: boolean
}>()

const emit = defineEmits<{
  addEmployee: []
  setPrimary: [contactId: number]
  setStatus: [contactId: number, status: EmploymentStatus]
  unlink: [contactId: number]
}>()

const { t } = useI18n()
const router = useRouter()

const menuRefs = ref<Map<number, InstanceType<typeof Menu>>>(new Map())
const expandedRows = ref<Record<string, boolean>>({})

// ── Status picker dialog ────────────────────────────────────────────────────────

const statusPickerOpen = ref(false)
const statusPickerContactId = ref<number | null>(null)
const pendingStatus = ref<EmploymentStatus | null>(null)

const statusOptions = [
  { get label() { return t('company.page.employees.status.works') }, value: 'works' as EmploymentStatus },
  { get label() { return t('company.page.employees.status.left') }, value: 'left' as EmploymentStatus },
]

function openStatusPicker(contactId: number, current: EmploymentStatus) {
  statusPickerContactId.value = contactId
  pendingStatus.value = current
  statusPickerOpen.value = true
}

function confirmStatusChange() {
  if (statusPickerContactId.value === null || pendingStatus.value === null) return
  emit('setStatus', statusPickerContactId.value, pendingStatus.value)
  statusPickerOpen.value = false
}

function setMenuRef(id: number, el: unknown) {
  if (el) menuRefs.value.set(id, el as InstanceType<typeof Menu>)
}

function onMenuClick(event: Event, data: ContactCompanyLink) {
  menuRefs.value.get(data.contact_id)?.toggle(event)
}

function getMenuItems(data: ContactCompanyLink) {
  return [
    {
      label: t('company.page.employees.actions.goToCard'),
      icon: 'pi pi-user',
      command: () => void router.push(`/contacts/${data.contact_id}`),
    },
    {
      label: t('company.page.employees.actions.setPrimary'),
      icon: 'pi pi-star',
      command: () => emit('setPrimary', data.contact_id),
    },
    {
      label: t('company.page.employees.actions.changeStatus'),
      icon: 'pi pi-sync',
      command: () => openStatusPicker(data.contact_id, data.employment_status ?? 'works'),
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

function channelIcon(type: string): string {
  const map: Record<string, string> = {
    phone: 'pi-phone',
    email: 'pi-envelope',
    tg: 'pi-send',
    wa: 'pi-whatsapp',
    linkedin: 'pi-linkedin',
    instagram: 'pi-instagram',
    viber: 'pi-mobile',
  }
  return map[type] ?? 'pi-at'
}
</script>

<style lang="scss" scoped>
.employees-tab {
  display: flex;
  flex-direction: column;
  gap: $space-4;
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
  font-size: $font-size-icon-lg;
  color: $surface-400;
}

.employees-tab__table {
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  overflow: hidden;
}

.employees-tab__name {
  font-weight: $font-weight-medium;
  color: var(--p-text-color);

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

// ── Expansion row ─────────────────────────────────────────────────────────────

.employees-tab__expansion {
  padding: $space-2 $space-4 $space-2 48px;
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
  background: var(--p-surface-50);
  border-top: 1px solid var(--p-surface-100);

  .app-dark & {
    background: var(--p-surface-100);
    border-top-color: var(--p-surface-200);
  }
}

.employees-tab__channel-chip {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  padding: $space-1 $space-2;
  border-radius: $radius-md;
  background: var(--p-surface-100);
  color: $surface-700;
  border: 1px solid var(--p-surface-200);

  .app-dark & {
    background: var(--p-surface-200);
    color: var(--p-surface-800);
    border-color: var(--p-surface-600);
  }

  i {
    font-size: $font-size-xs;
    color: var(--p-primary-color);
  }
}

.employees-tab__expansion-empty {
  font-size: $font-size-xs;
  color: $surface-400;
}

// ── Status picker dialog ────────────────────────────────────────────────────────

.employees-tab__status-options {
  display: flex;
  gap: $space-3;
  padding: $space-2 0;
}

.employees-tab__status-option {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  border: 1px solid var(--p-surface-300);
  border-radius: $radius-md;
  background: transparent;
  cursor: pointer;
  transition: border-color var(--app-transition-fast), background var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-600);
  }

  &:hover {
    border-color: var(--p-primary-color);
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-800);
    }
  }

  &--active {
    border-color: var(--p-primary-color);
    background: $primary-50;

    .app-dark & {
      background: var(--p-primary-900);
    }
  }
}
</style>
