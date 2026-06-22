<template>
  <div class="requisite-card" :class="{ 'requisite-card--current': requisite.is_current }">
    <!-- Header row -->
    <div class="requisite-card__header">
      <div class="requisite-card__title-row">
        <span class="requisite-card__label">{{ displayLabel }}</span>
        <Tag
          v-if="requisite.is_current"
          :value="t('crm.company.requisites.current')"
          severity="success"
          size="small"
          class="requisite-card__badge"
        />
      </div>

      <!-- Actions menu -->
      <div class="requisite-card__actions">
        <Button
          icon="pi pi-ellipsis-v"
          text
          severity="secondary"
          size="small"
          rounded
          :aria-label="t('common.actions')"
          @click="(e) => menu?.toggle(e)"
        />
        <Menu ref="menu" :model="menuItems" popup />
      </div>
    </div>

    <!-- Body: key fields in a compact row -->
    <div class="requisite-card__body">
      <div v-if="requisite.tax_id" class="requisite-card__field">
        <span class="requisite-card__field-label">{{ requisite.tax_id_label ?? 'ИНН' }}</span>
        <span class="requisite-card__field-value">{{ requisite.tax_id }}</span>
      </div>
      <div v-if="requisite.legal_name" class="requisite-card__field">
        <span class="requisite-card__field-label">{{ t('crm.company.requisites.fields.legalName') }}</span>
        <span class="requisite-card__field-value">{{ requisite.legal_name }}</span>
      </div>
      <div v-if="requisite.country_code" class="requisite-card__field">
        <span class="requisite-card__field-label">{{ t('company.page.fields.country') }}</span>
        <span class="requisite-card__field-value">{{ requisite.country_code }}</span>
      </div>
      <div v-if="requisite.director" class="requisite-card__field">
        <span class="requisite-card__field-label">{{ t('crm.company.requisites.fields.director') }}</span>
        <span class="requisite-card__field-value">{{ requisite.director }}</span>
      </div>
      <div v-if="requisite.bank_details?.bank" class="requisite-card__field">
        <span class="requisite-card__field-label">{{ t('crm.company.requisites.fields.bank') }}</span>
        <span class="requisite-card__field-value">{{ requisite.bank_details.bank }}</span>
      </div>
      <div v-if="requisite.bank_details?.account" class="requisite-card__field">
        <span class="requisite-card__field-label">{{ t('crm.company.requisites.fields.account') }}</span>
        <span class="requisite-card__field-value">{{ requisite.bank_details.account }}</span>
      </div>
      <div v-if="requisite.address" class="requisite-card__field requisite-card__field--full">
        <span class="requisite-card__field-label">{{ t('company.page.fields.address') }}</span>
        <span class="requisite-card__field-value">{{ requisite.address }}</span>
      </div>
      <div v-if="requisite.valid_from" class="requisite-card__field">
        <span class="requisite-card__field-label">{{ t('crm.company.requisites.fields.validFrom') }}</span>
        <span class="requisite-card__field-value">{{ formatDate(requisite.valid_from) }}</span>
      </div>
    </div>

    <!-- Note -->
    <div v-if="requisite.note" class="requisite-card__note">
      {{ requisite.note }}
    </div>

    <ConfirmDialog :group="`req-delete-${requisite.id}`" />
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Menu from 'primevue/menu'
import ConfirmDialog from 'primevue/confirmdialog'
import type { MenuItem } from 'primevue/menuitem'
import type { CompanyRequisite } from '@/entities/crm'

const props = defineProps<{
  requisite: CompanyRequisite
  index: number
}>()

const emit = defineEmits<{
  setCurrent: [id: number]
  edit: [requisite: CompanyRequisite]
  delete: [id: number]
}>()

const { t } = useI18n()
const confirm = useConfirm()

const menu = ref<InstanceType<typeof Menu> | null>(null)

const displayLabel = computed(() =>
  props.requisite.label
    ? props.requisite.label
    : `${t('crm.company.requisites.defaultLabel')} #${props.index + 1}`,
)

const menuItems = computed((): MenuItem[] => {
  const items: MenuItem[] = []
  if (!props.requisite.is_current) {
    items.push({
      label: t('crm.company.requisites.setCurrent'),
      icon: 'pi pi-check-circle',
      command: () => emit('setCurrent', props.requisite.id),
    })
  }
  items.push({
    label: t('crm.company.requisites.edit'),
    icon: 'pi pi-pencil',
    command: () => emit('edit', props.requisite),
  })
  items.push({
    label: t('crm.company.requisites.delete'),
    icon: 'pi pi-trash',
    class: 'menu-item--danger',
    command: () => {
      confirm.require({
        group: `req-delete-${props.requisite.id}`,
        message: t('crm.company.requisites.deleteConfirm'),
        header: t('common.confirm'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('common.delete'),
        rejectLabel: t('common.cancel'),
        acceptClass: 'p-button-danger',
        accept: () => emit('delete', props.requisite.id),
      })
    },
  })
  return items
})

function formatDate(dateStr: string): string {
  try {
    const d = new Date(dateStr)
    return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
  } catch {
    return dateStr
  }
}
</script>

<style lang="scss" scoped>
.requisite-card {
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  padding: $space-3;
  background: var(--p-surface-0);
  transition: border-color 0.2s;

  .app-dark & {
    border-color: var(--p-surface-700);
    background: var(--p-surface-900);
  }

  &--current {
    border-color: var(--p-green-300);
    background: var(--p-green-50);

    .app-dark & {
      border-color: var(--p-green-700);
      background: rgba(var(--p-green-900-rgb, 21, 48, 31), 0.4);
    }
  }

  & + & {
    margin-top: $space-3;
  }
}

.requisite-card__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: $space-2;
  margin-bottom: $space-2;
}

.requisite-card__title-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: $space-2;
  flex: 1;
  min-width: 0;
}

.requisite-card__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-800;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.requisite-card__badge {
  flex-shrink: 0;
}

.requisite-card__actions {
  flex-shrink: 0;
}

.requisite-card__body {
  display: flex;
  flex-wrap: wrap;
  gap: $space-1 $space-4;
}

.requisite-card__field {
  display: flex;
  gap: $space-1;
  font-size: $font-size-xs;
  min-width: 0;

  &--full {
    width: 100%;
  }
}

.requisite-card__field-label {
  color: $surface-400;
  flex-shrink: 0;

  &::after {
    content: ':';
  }

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.requisite-card__field-value {
  color: $surface-700;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.requisite-card__note {
  margin-top: $space-2;
  font-size: $font-size-xs;
  color: $surface-500;
  font-style: italic;
  border-top: 1px solid var(--p-surface-200);
  padding-top: $space-2;

  .app-dark & {
    border-top-color: var(--p-surface-700);
    color: var(--p-surface-400);
  }
}
</style>
