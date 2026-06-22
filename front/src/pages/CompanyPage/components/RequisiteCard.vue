<template>
  <!--
    RequisiteCard — spec §4 redesign:
    Header: pi-id-card 24×24 tile + label + «Основной» badge + spacer + pi-copy + pi-pencil inline buttons
    Body: 2-column CSS grid (1fr 1fr), each field has pi-copy on hover
    Note: delete action accessible via context only (or add secondary ellipsis if needed)
  -->
  <div class="requisite-card" :class="{ 'requisite-card--current': requisite.is_current }">
    <!-- Header row -->
    <div class="requisite-card__header">
      <!-- pi-id-card 24×24 icon tile -->
      <div class="requisite-card__icon-tile">
        <i class="pi pi-id-card" />
      </div>

      <!-- Label + badge -->
      <span class="requisite-card__label">{{ displayLabel }}</span>
      <Tag
        v-if="requisite.is_current"
        :value="t('crm.company.requisites.current')"
        severity="success"
        size="small"
        class="requisite-card__badge"
      />

      <div class="requisite-card__header-spacer" />

      <!-- Inline icon actions: pi-copy (Копировать реквизиты) + pi-pencil (Редактировать) -->
      <button
        v-tooltip.top="t('crm.company.requisites.copyTooltip', 'Копировать реквизиты')"
        type="button"
        class="requisite-card__icon-btn"
        :aria-label="t('crm.company.requisites.copyTooltip', 'Копировать реквизиты')"
        @click="copyRequisites"
      >
        <i class="pi pi-copy" />
      </button>
      <button
        v-tooltip.top="t('crm.company.requisites.edit')"
        type="button"
        class="requisite-card__icon-btn"
        :aria-label="t('crm.company.requisites.edit')"
        @click="emit('edit', requisite)"
      >
        <i class="pi pi-pencil" />
      </button>
      <!-- Secondary ellipsis for set-current / delete (low-use actions) -->
      <button
        type="button"
        class="requisite-card__icon-btn requisite-card__icon-btn--muted"
        :aria-label="t('common.actions')"
        @click="(e) => menu?.toggle(e)"
      >
        <i class="pi pi-ellipsis-v" />
      </button>
      <Menu ref="menu" :model="menuItems" popup />
    </div>

    <!-- Body: 2-column CSS grid per spec §4 -->
    <div class="requisite-card__body">
      <div
        v-for="field in visibleFields"
        :key="field.key"
        class="requisite-card__field"
        :class="{ 'requisite-card__field--full': field.full }"
      >
        <span class="requisite-card__field-label">{{ field.label }}</span>
        <div class="requisite-card__field-value-wrap">
          <span class="requisite-card__field-value">{{ field.value }}</span>
          <!-- per-field pi-copy on hover -->
          <button
            type="button"
            class="requisite-card__field-copy"
            :title="t('common.copy', 'Копировать')"
            @click.stop="copyToClipboard(field.value)"
          >
            <i class="pi pi-copy" />
          </button>
        </div>
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
import { useToast } from 'primevue/usetoast'
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
const toast = useToast()

const menu = ref<InstanceType<typeof Menu> | null>(null)

const displayLabel = computed(() =>
  props.requisite.label
    ? props.requisite.label
    : `${t('crm.company.requisites.defaultLabel')} #${props.index + 1}`,
)

// ─── Visible fields for 2-col grid ───────────────────────────────────────────

interface ReqField { key: string; label: string; value: string; full?: boolean }

const visibleFields = computed((): ReqField[] => {
  const r = props.requisite
  const fields: ReqField[] = []
  if (r.tax_id) {
    fields.push({ key: 'tax_id', label: r.tax_id_label ?? 'ИНН', value: r.tax_id })
  }
  if (r.legal_name) {
    fields.push({ key: 'legal_name', label: t('crm.company.requisites.fields.legalName'), value: r.legal_name })
  }
  if (r.country_code) {
    fields.push({ key: 'country_code', label: t('company.page.fields.country'), value: r.country_code })
  }
  if (r.director) {
    fields.push({ key: 'director', label: t('crm.company.requisites.fields.director'), value: r.director })
  }
  if (r.bank_details?.bank) {
    fields.push({ key: 'bank', label: t('crm.company.requisites.fields.bank'), value: r.bank_details.bank })
  }
  if (r.bank_details?.account) {
    fields.push({ key: 'account', label: t('crm.company.requisites.fields.account'), value: r.bank_details.account })
  }
  if (r.address) {
    fields.push({ key: 'address', label: t('company.page.fields.address'), value: r.address, full: true })
  }
  if (r.valid_from) {
    fields.push({ key: 'valid_from', label: t('crm.company.requisites.fields.validFrom'), value: formatDate(r.valid_from) })
  }
  return fields
})

// ─── Secondary menu (set-current + delete) ───────────────────────────────────

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

// ─── Copy helpers ─────────────────────────────────────────────────────────────

async function copyToClipboard(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    toast.add({ severity: 'success', summary: t('common.copied'), life: 2000 })
  } catch {
    // non-fatal
  }
}

async function copyRequisites() {
  const r = props.requisite
  const parts: string[] = []
  if (r.legal_name) parts.push(r.legal_name)
  if (r.tax_id) parts.push(`${r.tax_id_label ?? 'ИНН'}: ${r.tax_id}`)
  if (r.bank_details?.bank) parts.push(`Банк: ${r.bank_details.bank}`)
  if (r.bank_details?.account) parts.push(`Счёт: ${r.bank_details.account}`)
  if (r.address) parts.push(r.address)
  await copyToClipboard(parts.join('\n'))
}

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
    border-color: var(--p-surface-600);
    background: var(--p-surface-100); // dark card bg
  }

  &--current {
    border-color: var(--p-green-300);
    background: var(--p-green-50);

    .app-dark & {
      border-color: var(--p-green-700);
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      background: rgba(21, 48, 31, 0.4); // dark green tint
    }
  }

  & + & {
    margin-top: $space-3;
  }
}

// ── Header ────────────────────────────────────────────────────────────────────

.requisite-card__header {
  display: flex;
  align-items: center;
  gap: $space-2;
  margin-bottom: $space-3;
}

// pi-id-card tile 24×24, spec §4
.requisite-card__icon-tile {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 24px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  height: 24px;
  border-radius: $radius-sm;
  background: var(--p-primary-100);
  color: var(--p-primary-color);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-primary-900);
    color: var(--p-primary-300);
  }

  i {
    font-size: $font-size-xs;
  }
}

.requisite-card__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-800;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.requisite-card__badge {
  flex-shrink: 0;
}

.requisite-card__header-spacer {
  flex: 1;
}

// Inline icon buttons: pi-copy + pi-pencil + pi-ellipsis-v
.requisite-card__icon-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 26px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  height: 26px;
  border-radius: $radius-sm;
  background: transparent;
  border: none;
  cursor: pointer;
  color: $surface-400;
  transition: background var(--app-transition-fast), color var(--app-transition-fast);
  flex-shrink: 0;

  &:hover {
    background: var(--p-surface-100);
    color: var(--p-primary-color);

    .app-dark & {
      background: var(--p-surface-200);
      color: var(--p-primary-300);
    }
  }

  &--muted {
    color: $surface-300;
  }

  i {
    font-size: $font-size-xs;
  }
}

// ── Body: 2-column grid ───────────────────────────────────────────────────────

.requisite-card__body {
  display: grid;
  grid-template-columns: 1fr 1fr; // spec §4: 2 columns
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  gap: 10px 20px; // spec §4: row-gap 10px, col-gap 20px
}

.requisite-card__field {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;

  // Full-width fields (e.g. address) span both columns
  &--full {
    grid-column: span 2;
  }
}

.requisite-card__field-label {
  // spec §4: label uppercase 10px
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  font-size: 10px; // spec: label = uppercase 10px
  font-weight: $font-weight-medium;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: $surface-400;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.requisite-card__field-value-wrap {
  display: flex;
  align-items: center;
  gap: $space-1;
  min-width: 0;
}

.requisite-card__field-value {
  // spec §4: value 13px/500
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex: 1;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

// pi-copy per field — visible on hover of the field
.requisite-card__field-copy {
  display: none;
  align-items: center;
  justify-content: center;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 20px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  height: 20px;
  border: none;
  background: transparent;
  cursor: pointer;
  color: $surface-300;
  border-radius: $radius-sm;
  flex-shrink: 0;
  transition: color var(--app-transition-fast);

  i {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    font-size: 10px;
  }

  &:hover {
    color: var(--p-primary-color);
  }
}

.requisite-card__field:hover .requisite-card__field-copy {
  display: flex;
}

// ── Note ──────────────────────────────────────────────────────────────────────

.requisite-card__note {
  margin-top: $space-2;
  font-size: $font-size-xs;
  color: $surface-500;
  font-style: italic;
  border-top: 1px solid var(--p-surface-200);
  padding-top: $space-2;

  .app-dark & {
    border-top-color: var(--p-surface-600);
    color: var(--p-surface-400);
  }
}
</style>
