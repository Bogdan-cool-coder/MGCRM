<template>
  <div class="deal-tab-main">
    <!-- ── Quick fields ─────────────────────────────────────────────────────────── -->
    <div class="deal-tab-main__quick-fields">
      <!-- Owner — inline select -->
      <div class="deal-tab-main__quick-row">
        <span class="deal-tab-main__quick-label">{{ t('sales.deal.info.fields.owner') }}</span>
        <div class="deal-tab-main__quick-value deal-tab-main__quick-value--owner">
          <InlineEditableField
            :model-value="deal.owner.id"
            field-key="owner_user_id"
            field-type="select"
            :options="usersList as Array<Record<string, unknown>>"
            option-label="name"
            option-value="id"
            :saving="ownerSaving"
            @save="saveOwner"
          />
        </div>
      </div>

      <!-- Company — read-only link -->
      <div class="deal-tab-main__quick-row">
        <span class="deal-tab-main__quick-label">{{ t('sales.deal.info.fields.company') }}</span>
        <div class="deal-tab-main__quick-value">
          <RouterLink :to="`/companies/${deal.company.id}`" class="deal-tab-main__company-link">
            {{ deal.company.name }}
          </RouterLink>
        </div>
      </div>
    </div>

    <!-- ── Group: Products (accent, open by default) ──────────────────────────── -->
    <DealProductsGroup
      ref="productsGroupRef"
      :items="products"
      :currency="deal.currency"
      :loading="productsLoading"
      :updating-id="updatingId"
      :deleting-id="deletingId"
      :deal-amount="deal.amount"
      :amount-locked="deal.amount_locked ?? false"
      :perpetual-license="deal.perpetual_license ?? false"
      :perpetual-saving="perpetualSaving"
      :lock-saving="lockSaving"
      @add-product="emit('openAddProduct')"
      @update-item="onUpdateProduct"
      @remove-item="onRemoveProduct"
      @amount-changed="emit('amountChanged', $event)"
      @toggle-perpetual="onTogglePerpetual"
      @toggle-lock="onToggleLock"
    />

    <!-- ── Group: Key dates ─────────────────────────────────────────────────── -->
    <DealDatesGroup
      ref="datesGroupRef"
      :deal="deal"
      @deal-updated="(updates) => emit('dealUpdated', updates)"
    />

    <!-- ── Group: Contacts (accent, open by default) ──────────────────────────── -->
    <DealContactsGroup
      ref="contactsGroupRef"
      :contacts="contacts"
      :removing-id="removingContactId"
      @add-contact="emit('openAddContact')"
      @remove-contact="onRemoveContact"
    />

    <!-- ── Group: Company (quiet, collapsed by default) ─────────────────────── -->
    <DealCompanyGroup
      v-if="companyFull"
      ref="companyGroupRef"
      :company="companyFull"
      :default-collapsed="true"
      @company-updated="onCompanyUpdated"
    />

    <!-- ── Custom fields (scope=deal, quiet, collapsed by default) ────────────── -->
    <DealFieldGroup
      v-if="dealCustomDefs.length > 0"
      ref="customGroupRef"
      :title="t('sales.deal.info.groups.customFields')"
      icon="pi-sliders-h"
      group-key="deal-custom"
      :default-collapsed="true"
    >
      <DealFieldRow
        v-for="def in dealCustomDefs"
        :key="def.code"
        :label="def.label"
        :field-key="def.code"
        :model-value="(customFieldValues[def.code] as string | number | null)"
        :field-type="(def.field_type as 'text' | 'select' | 'url' | 'number' | 'bool')"
        :options="buildSelectOptions(def)"
        :saving="customFieldSaving === def.code"
        @save="saveCustomField"
      />
    </DealFieldGroup>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { RouterLink } from 'vue-router'
import DealFieldGroup from './DealFieldGroup.vue'
import DealFieldRow from './DealFieldRow.vue'
import DealProductsGroup from './DealProductsGroup.vue'
import DealDatesGroup from './DealDatesGroup.vue'
import DealContactsGroup from './DealContactsGroup.vue'
import DealCompanyGroup from './DealCompanyGroup.vue'
import InlineEditableField from '@/components/crm/InlineEditableField.vue'
import { salesApi } from '@/api/sales'
import { companiesApi } from '@/api/crm/companies'
import { useMutation } from '@/composables/async/useMutation'
import { useDealCustomFields } from '../composables/useDealCustomFields'
import { getApiErrorMessage } from '@/utils/errors'
import type { DealDto, DealProductDto, DealContactDto } from '@/entities/sales'
import type { Company, CustomFieldDef } from '@/entities/crm'

// ── Props / emits ──────────────────────────────────────────────────────────────

const props = defineProps<{
  deal: DealDto
  daysInStage: number
  products: DealProductDto[]
  productsLoading: boolean
  updatingId: number | null
  deletingId: number | null
  contacts: DealContactDto[]
  removingContactId: number | null
  usersList: { id: number; name: string }[]
  collapseAllSignal?: number
  expandAllSignal?: number
}>()

const emit = defineEmits<{
  dealUpdated: [updates: Partial<DealDto>]
  openAddProduct: []
  openAddContact: []
  updateProduct: [id: number, payload: { quantity?: number; unit_price?: number; discount?: number }]
  removeProduct: [id: number]
  removeContact: [contactId: number]
  amountChanged: [total: number]
}>()

const { t } = useI18n()
const toast = useToast()

// ── Refs for collapse/expand all ───────────────────────────────────────────────

const productsGroupRef = ref<InstanceType<typeof DealProductsGroup> | null>(null)
const datesGroupRef = ref<InstanceType<typeof DealDatesGroup> | null>(null)
const contactsGroupRef = ref<InstanceType<typeof DealContactsGroup> | null>(null)
const companyGroupRef = ref<InstanceType<typeof DealCompanyGroup> | null>(null)
const customGroupRef = ref<InstanceType<typeof DealFieldGroup> | null>(null)

// Watch signals to collapse/expand all groups
watch(
  () => props.collapseAllSignal,
  (val, old) => {
    if (val !== old && val !== undefined && val > 0) {
      companyGroupRef.value?.collapse?.()
      customGroupRef.value?.collapse?.()
    }
  },
)

watch(
  () => props.expandAllSignal,
  (val, old) => {
    if (val !== old && val !== undefined && val > 0) {
      companyGroupRef.value?.expand?.()
      customGroupRef.value?.expand?.()
    }
  },
)

// ── Owner save ─────────────────────────────────────────────────────────────────

const ownerMutation = useMutation<DealDto>()
const ownerSaving = computed(() => ownerMutation.isPending.value)

async function saveOwner(_key: string, value: string | number | null) {
  if (!value) return
  try {
    const updated = await ownerMutation.run(() =>
      salesApi.updateDeal(props.deal.id, { owner_user_id: Number(value) }),
    )
    emit('dealUpdated', { owner: updated.owner })
    toast.add({ severity: 'success', summary: t('sales.deal.page.menu.changeOwner'), life: 2000 })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

// ── Products ───────────────────────────────────────────────────────────────────

function onUpdateProduct(id: number, payload: { quantity?: number; unit_price?: number; discount?: number }) {
  emit('updateProduct', id, payload)
}

function onRemoveProduct(id: number) {
  emit('removeProduct', id)
}

// ── Contacts ───────────────────────────────────────────────────────────────────

function onRemoveContact(contactId: number) {
  emit('removeContact', contactId)
}

// ── Company full data ──────────────────────────────────────────────────────────

const companyFull = ref<Company | null>(null)

async function loadCompanyFull() {
  try {
    companyFull.value = await companiesApi.get(props.deal.company.id)
  } catch {
    // Non-critical
  }
}

function onCompanyUpdated(updated: Company) {
  companyFull.value = updated
}

// ── Perpetual license toggle ───────────────────────────────────────────────────

const perpetualMutation = useMutation<DealDto>()
const perpetualSaving = computed(() => perpetualMutation.isPending.value)

async function onTogglePerpetual(newValue: boolean) {
  try {
    const updated = await perpetualMutation.run(() =>
      salesApi.updateDeal(props.deal.id, { perpetual_license: newValue }),
    )
    emit('dealUpdated', {
      perpetual_license: updated.perpetual_license,
      amount: updated.amount,
    })
    toast.add({
      severity: 'success',
      summary: newValue
        ? t('sales.deal.perpetual.successOn')
        : t('sales.deal.perpetual.successOff'),
      life: 2500,
    })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

// ── Budget lock toggle ─────────────────────────────────────────────────────────

const lockMutation = useMutation<DealDto>()
const lockSaving = computed(() => lockMutation.isPending.value)

async function onToggleLock() {
  const newLocked = !(props.deal.amount_locked ?? false)
  try {
    const updated = await lockMutation.run(() =>
      salesApi.updateDeal(props.deal.id, { amount_locked: newLocked }),
    )
    emit('dealUpdated', {
      amount_locked: updated.amount_locked,
      amount: updated.amount,
    })
    toast.add({
      severity: 'success',
      summary: newLocked
        ? t('sales.deal.budget.lockedSuccess')
        : t('sales.deal.budget.unlockedSuccess'),
      life: 2000,
    })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

// ── Custom fields (scope=deal) ─────────────────────────────────────────────────

const customFieldsComposable = useDealCustomFields(() => props.deal.id)
const { dealCustomDefs, values: customFieldValues } = customFieldsComposable

const customFieldSaving = ref<string | null>(null)
const customFieldMutation = useMutation<DealDto>()

function buildSelectOptions(def: CustomFieldDef): Array<Record<string, unknown>> {
  if (!def.options?.length) return []
  return def.options.map((opt) => ({ id: opt, name: opt }))
}

async function saveCustomField(code: string, value: string | number | null) {
  customFieldSaving.value = code
  try {
    const extra = { ...(props.deal.extra_fields ?? {}), [code]: value }
    const updated = await customFieldMutation.run(() =>
      salesApi.updateDeal(props.deal.id, { extra_fields: extra }),
    )
    customFieldsComposable.updateLocalValue(code, value)
    emit('dealUpdated', { extra_fields: updated.extra_fields })
    toast.add({ severity: 'success', summary: t('common.saved'), life: 2000 })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    customFieldSaving.value = null
  }
}

// ── Lifecycle ──────────────────────────────────────────────────────────────────

onMounted(async () => {
  await Promise.all([
    loadCompanyFull(),
    customFieldsComposable.load(),
  ])
})

// Reload company if deal.company.id changes
watch(() => props.deal.company.id, (newId, oldId) => {
  if (newId !== oldId) {
    void loadCompanyFull()
  }
})
</script>

<style lang="scss" scoped>
.deal-tab-main {
  display: flex;
  flex-direction: column;
}

// ── Quick fields ───────────────────────────────────────────────────────────────

.deal-tab-main__quick-fields {
  padding: $space-3 0 $space-2;
  border-bottom: 1px solid var(--p-surface-200);

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

.deal-tab-main__quick-row {
  display: grid;
  grid-template-columns: 100px 1fr;
  align-items: start;
  gap: $space-2;
  padding: $space-1 $space-4;
  min-height: 32px;
}

.deal-tab-main__quick-label {
  font-size: $font-size-xs;
  color: $surface-500;
  padding-top: 6px;
}

.deal-tab-main__quick-value {
  display: flex;
  align-items: center;
  min-width: 0;
  flex-wrap: wrap;
  gap: $space-1;

  // Owner field: pencil icon only on hover
  &--owner {
    :deep(.inline-edit-icon) {
      opacity: 0;
      transition: opacity 0.15s;
    }

    &:hover :deep(.inline-edit-icon) {
      opacity: 1;
    }
  }
}

.deal-tab-main__company-link {
  font-size: $font-size-sm;
  color: $primary-color;
  text-decoration: none;
  font-weight: $font-weight-medium;
  padding: $space-1 $space-2;

  &:hover {
    text-decoration: underline;
  }
}
</style>
