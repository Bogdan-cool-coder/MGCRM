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

      <!-- Budget (auto) + primary product chip -->
      <div class="deal-tab-main__quick-row">
        <span class="deal-tab-main__quick-label">{{ t('sales.deal.info.fields.amountAuto') }}</span>
        <div class="deal-tab-main__quick-value">
          <span class="deal-tab-main__budget">{{ formatCurrency(deal.amount, deal.currency) }}</span>
          <Tag
            v-if="primaryProductName"
            :value="primaryProductName"
            severity="secondary"
            size="small"
            class="ms-1"
          />
        </div>
      </div>

      <!-- Days in work with rotting indicator -->
      <div class="deal-tab-main__quick-row">
        <span class="deal-tab-main__quick-label">{{ t('sales.deal.info.fields.daysInWork') }}</span>
        <div class="deal-tab-main__quick-value">
          <span
            class="deal-tab-main__days-badge"
            :class="{
              'deal-tab-main__days-badge--warn': daysWarning && !daysDanger,
              'deal-tab-main__days-badge--danger': daysDanger,
            }"
          >
            {{ daysInStage }} {{ t('sales.deal.page.daysInStage') }}
          </span>
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
      @add-product="emit('openAddProduct')"
      @update-item="onUpdateProduct"
      @remove-item="onRemoveProduct"
      @amount-changed="emit('amountChanged', $event)"
    />

    <!-- ── Group: Contacts (accent, open by default) ──────────────────────────── -->
    <DealContactsGroup
      ref="contactsGroupRef"
      :contacts="contacts"
      :removing-id="removingContactId"
      @add-contact="emit('openAddContact')"
      @remove-contact="onRemoveContact"
    />

    <!-- ── Group: Deal dates (quiet, collapsed by default) ───────────────────── -->
    <DealFieldGroup
      ref="datesGroupRef"
      :title="t('sales.deal.info.groups.deal')"
      icon="pi-calendar"
      group-key="deal-dates"
      :default-collapsed="true"
    >
      <!-- Expected sign date -->
      <div class="deal-tab-main__date-row">
        <span class="deal-tab-main__date-label">{{ t('sales.deal.info.fields.expectedSignDate') }}</span>
        <div class="deal-tab-main__date-value">
          <DateEditField
            :value="deal.expected_sign_date"
            :saving="dateSaving === 'expected_sign_date'"
            @save="(v) => saveDealDate('expected_sign_date', v)"
          />
        </div>
      </div>

      <!-- Expected payment date -->
      <div class="deal-tab-main__date-row">
        <span class="deal-tab-main__date-label">{{ t('sales.deal.info.fields.expectedPaymentDate') }}</span>
        <div class="deal-tab-main__date-value">
          <DateEditField
            :value="deal.expected_payment_date"
            :saving="dateSaving === 'expected_payment_date'"
            @save="(v) => saveDealDate('expected_payment_date', v)"
          />
        </div>
      </div>
    </DealFieldGroup>

    <!-- ── Group: Company data (quiet, collapsed by default) ─────────────────── -->
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
import { ref, computed, defineComponent, h, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { RouterLink } from 'vue-router'
import Tag from 'primevue/tag'
import DatePicker from 'primevue/datepicker'
import DealFieldGroup from './DealFieldGroup.vue'
import DealFieldRow from './DealFieldRow.vue'
import DealProductsGroup from './DealProductsGroup.vue'
import DealContactsGroup from './DealContactsGroup.vue'
import DealCompanyGroup from './DealCompanyGroup.vue'
import InlineEditableField from '@/components/crm/InlineEditableField.vue'
import { salesApi } from '@/api/sales'
import { companiesApi } from '@/api/crm/companies'
import { useMutation } from '@/composables/async/useMutation'
import { useDealCustomFields } from '../composables/useDealCustomFields'
import { formatCurrency } from '@/utils/currency'
import { getApiErrorMessage } from '@/utils/errors'
import type { DealDto, DealProductDto, DealContactDto } from '@/entities/sales'
import type { Company, CustomFieldDef } from '@/entities/crm'

// ── Local DateEditField component ──────────────────────────────────────────────

const DateEditField = defineComponent({
  name: 'DateEditField',
  props: {
    value: { type: String as () => string | null, default: null },
    saving: { type: Boolean, default: false },
  },
  emits: ['save'],
  setup(props, { emit: emitField }) {
    const isEditing = ref(false)
    const localDate = ref<Date | null>(null)

    function startEdit() {
      localDate.value = props.value ? new Date(props.value) : null
      isEditing.value = true
    }

    function formatDisplay(val: string | null): string {
      if (!val) return '—'
      const d = new Date(val)
      return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
    }

    function submitDate() {
      if (localDate.value) {
        const d = localDate.value
        const y = d.getFullYear()
        const m = String(d.getMonth() + 1).padStart(2, '0')
        const day = String(d.getDate()).padStart(2, '0')
        emitField('save', `${y}-${m}-${day}`)
      } else {
        emitField('save', null)
      }
      isEditing.value = false
    }

    return () => {
      if (!isEditing.value) {
        return h('div', { class: 'date-edit-field' }, [
          h('span', {
            class: ['date-edit-field__value', props.value ? '' : 'date-edit-field__value--empty'],
            onClick: startEdit,
          }, formatDisplay(props.value)),
          props.saving
            ? h('i', { class: 'pi pi-spin pi-spinner', style: 'font-size:11px;color:var(--p-primary-400)' })
            : h('i', { class: 'pi pi-pencil date-edit-field__icon', onClick: startEdit }),
        ])
      }
      return h('div', { class: 'date-edit-field' }, [
        h(DatePicker, {
          modelValue: localDate.value,
          dateFormat: 'dd.mm.yy',
          showClear: true,
          showIcon: true,
          style: 'width: 160px',
          'onUpdate:modelValue': (v: Date | null) => { localDate.value = v },
          onDateSelect: submitDate,
          onKeydown: (e: KeyboardEvent) => {
            if (e.key === 'Enter') submitDate()
            if (e.key === 'Escape') { isEditing.value = false }
          },
        }),
      ])
    }
  },
})

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
  updateProduct: [id: number, payload: { quantity?: number; unit_price?: number }]
  removeProduct: [id: number]
  removeContact: [contactId: number]
  amountChanged: [total: number]
}>()

const { t } = useI18n()
const toast = useToast()

// ── Refs for collapse/expand all ───────────────────────────────────────────────

const productsGroupRef = ref<InstanceType<typeof DealProductsGroup> | null>(null)
const contactsGroupRef = ref<InstanceType<typeof DealContactsGroup> | null>(null)
const datesGroupRef = ref<InstanceType<typeof DealFieldGroup> | null>(null)
const companyGroupRef = ref<InstanceType<typeof DealCompanyGroup> | null>(null)
const customGroupRef = ref<InstanceType<typeof DealFieldGroup> | null>(null)

// Watch signals to collapse/expand all groups
watch(
  () => props.collapseAllSignal,
  (val, old) => {
    if (val !== old && val !== undefined && val > 0) {
      // collapse all field groups
      datesGroupRef.value?.collapse?.()
      companyGroupRef.value?.collapse?.()
      customGroupRef.value?.collapse?.()
      // Note: products/contacts groups go through DealProductsGroup/DealContactsGroup
      // which wrap DealFieldGroup — we'd need refs into them. They stay open (accent groups).
    }
  },
)

watch(
  () => props.expandAllSignal,
  (val, old) => {
    if (val !== old && val !== undefined && val > 0) {
      datesGroupRef.value?.expand?.()
      companyGroupRef.value?.expand?.()
      customGroupRef.value?.expand?.()
    }
  },
)

// ── Rotting thresholds ─────────────────────────────────────────────────────────

const warnDays = computed(() => props.deal.stage.warn_days ?? 7)
const dangerDays = computed(() => props.deal.stage.danger_days ?? 14)

const daysWarning = computed(() => props.daysInStage >= warnDays.value)
const daysDanger = computed(() => props.daysInStage >= dangerDays.value)

// ── Primary product chip ───────────────────────────────────────────────────────

const primaryProductName = computed(() => props.products[0]?.product?.name ?? null)

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

// ── Date fields ────────────────────────────────────────────────────────────────

const dateSaving = ref<string | null>(null)
const dateMutation = useMutation<DealDto>()

async function saveDealDate(field: 'expected_sign_date' | 'expected_payment_date', value: string | null) {
  dateSaving.value = field
  try {
    const updated = await dateMutation.run(() =>
      salesApi.updateDeal(props.deal.id, { [field]: value }),
    )
    emit('dealUpdated', { [field]: updated[field] })
    toast.add({ severity: 'success', summary: t('common.saved'), life: 2000 })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    dateSaving.value = null
  }
}

// ── Products ───────────────────────────────────────────────────────────────────

function onUpdateProduct(id: number, payload: { quantity?: number; unit_price?: number }) {
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

.deal-tab-main__budget {
  font-size: $font-size-sm;
  font-weight: $font-weight-bold;
  color: $primary-color;
  padding: $space-1 $space-2;
}

.deal-tab-main__days-badge {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-600;
  padding: $space-1 $space-2;

  .app-dark & {
    color: var(--p-surface-300);
  }

  &--warn {
    color: var(--p-yellow-600);

    .app-dark & {
      color: var(--p-yellow-400);
    }
  }

  &--danger {
    color: var(--p-red-600);
    font-weight: $font-weight-bold;

    .app-dark & {
      color: var(--p-red-400);
    }
  }
}

// ── Date fields ────────────────────────────────────────────────────────────────

.deal-tab-main__date-row {
  display: grid;
  grid-template-columns: 120px 1fr;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-4;
}

.deal-tab-main__date-label {
  font-size: $font-size-xs;
  color: $surface-500;
  line-height: 1.4;
}

.deal-tab-main__date-value {
  display: flex;
  align-items: center;
}
</style>

<style lang="scss">
// Unscoped — date-edit-field is rendered by the render function
.date-edit-field {
  display: flex;
  align-items: center;
  gap: $space-1;
  cursor: pointer;
}

.date-edit-field__value {
  font-size: $font-size-sm;
  color: $surface-800;
  padding: 4px 6px;
  border-radius: $radius-sm;
  border: 1px solid transparent;
  transition: border-color var(--app-transition-fast);

  &:hover {
    border-color: $surface-200;
    background: $surface-50;
  }

  &--empty {
    color: $surface-400;
  }
}

.date-edit-field__icon {
  font-size: $font-size-xs;
  color: $surface-400;
  cursor: pointer;

  &:hover {
    color: $primary-color;
  }
}
</style>
