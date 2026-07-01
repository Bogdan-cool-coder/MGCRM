<template>
  <div class="deal-tab-main">
    <!-- ── Quick fields ─────────────────────────────────────────────────────────── -->
    <div class="deal-tab-main__quick-fields">

      <!-- Ответственный — avatar circle + name → SearchPicker -->
      <div class="deal-tab-main__quick-row" @click="ownerPickerOpen = true">
        <span class="deal-tab-main__quick-label">{{ t('sales.deal.info.fields.owner') }}</span>
        <div class="deal-tab-main__quick-value deal-tab-main__quick-value--owner" @click.stop>
          <div class="deal-tab-main__owner-row" @click="ownerPickerOpen = !ownerPickerOpen">
            <span class="deal-tab-main__owner-avatar">{{ ownerInitials }}</span>
            <span class="deal-tab-main__owner-name">{{ deal.owner.name }}</span>
          </div>
          <!-- Owner SearchPicker popover -->
          <div
            v-if="ownerPickerOpen"
            v-click-outside="() => { ownerPickerOpen = false }"
            class="deal-tab-main__owner-picker"
            @click.stop
          >
            <div class="deal-tab-main__owner-picker-search">
              <i class="pi pi-search deal-tab-main__owner-picker-icon" />
              <input
                ref="ownerSearchRef"
                v-model="ownerQuery"
                class="deal-tab-main__owner-picker-input"
                :placeholder="t('common.search_placeholder')"
              />
            </div>
            <div class="deal-tab-main__owner-picker-options">
              <div
                v-for="u in filteredUsers"
                :key="u.id"
                class="deal-tab-main__owner-option"
                :class="{ 'deal-tab-main__owner-option--active': u.id === deal.owner.id }"
                @click="selectOwner(u)"
              >
                <i v-if="u.id === deal.owner.id" class="pi pi-check deal-tab-main__owner-check" />
                <span class="deal-tab-main__owner-option-name">{{ u.name }}</span>
                <i v-if="ownerSaving && ownerPendingId === u.id" class="pi pi-spin pi-spinner deal-tab-main__owner-saving" />
              </div>
              <div v-if="filteredUsers.length === 0" class="deal-tab-main__owner-empty">
                {{ t('common.no_results') }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Компания — link + editable picker -->
      <div class="deal-tab-main__quick-row">
        <span class="deal-tab-main__quick-label">{{ t('sales.deal.info.fields.company') }}</span>
        <div class="deal-tab-main__quick-value deal-tab-main__quick-value--company" @click.stop>
          <div class="deal-tab-main__company-row">
            <RouterLink v-if="deal.company" :to="`/companies/${deal.company.id}`" class="deal-tab-main__company-link">
              {{ deal.company.name }}
            </RouterLink>
            <span v-else class="deal-tab-main__company-deleted">{{ t('sales.deal.info.fields.companyDeleted') }}</span>
            <button
              class="deal-tab-main__company-edit-btn"
              type="button"
              :title="t('sales.deal.info.fields.changeCompany')"
              @click="companyPickerOpen = !companyPickerOpen"
            >
              <i class="pi pi-pencil" />
            </button>
            <i v-if="companySaving" class="pi pi-spin pi-spinner deal-tab-main__company-saving" />
          </div>
          <!-- Company search popover -->
          <div
            v-if="companyPickerOpen"
            v-click-outside="() => { companyPickerOpen = false }"
            class="deal-tab-main__company-picker"
            @click.stop
          >
            <div class="deal-tab-main__owner-picker-search">
              <i class="pi pi-search deal-tab-main__owner-picker-icon" />
              <input
                ref="companySearchRef"
                v-model="companyQuery"
                class="deal-tab-main__owner-picker-input"
                :placeholder="t('common.search_placeholder')"
                @input="onCompanyQueryInput"
              />
            </div>
            <div class="deal-tab-main__owner-picker-options">
              <div v-if="companySearching" class="deal-tab-main__owner-empty">
                <i class="pi pi-spin pi-spinner" />
              </div>
              <template v-else>
                <div
                  v-for="c in companyOptions"
                  :key="c.id"
                  class="deal-tab-main__owner-option"
                  :class="{ 'deal-tab-main__owner-option--active': c.id === deal.company?.id }"
                  @click="selectCompany(c)"
                >
                  <i v-if="c.id === deal.company?.id" class="pi pi-check deal-tab-main__owner-check" />
                  <span class="deal-tab-main__owner-option-name">{{ c.name }}</span>
                </div>
                <div v-if="!companySearching && companyOptions.length === 0" class="deal-tab-main__owner-empty">
                  {{ t('common.no_results') }}
                </div>
              </template>
            </div>
          </div>
        </div>
      </div>

      <!-- Договор план — DateField -->
      <div class="deal-tab-main__quick-row">
        <span class="deal-tab-main__quick-label">{{ t('sales.deal.info.fields.plannedContract') }}</span>
        <div class="deal-tab-main__quick-value">
          <DateField
            :model-value="deal.expected_sign_date"
            :min="todayIso"
            @update:model-value="saveDateField('expected_sign_date', $event)"
          />
        </div>
      </div>

      <!-- Оплата план — DateField -->
      <div class="deal-tab-main__quick-row">
        <span class="deal-tab-main__quick-label">{{ t('sales.deal.info.fields.plannedPayment') }}</span>
        <div class="deal-tab-main__quick-value">
          <DateField
            :model-value="deal.expected_payment_date"
            :min="todayIso"
            @update:model-value="saveDateField('expected_payment_date', $event)"
          />
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
      :lock-saving="false"
      :discount-percent="deal.discount_percent ?? 0"
      :products-net-total="deal.products_net_total"
      :products-discounted="deal.products_discounted"
      @add-product="emit('openAddProduct')"
      @remove-item="onRemoveProduct"
      @toggle-perpetual="onTogglePerpetual"
      @update-discount="onUpdateDiscount"
    />

    <!-- ── Group: Contacts (accent, open by default) ──────────────────────────── -->
    <DealContactsGroup
      ref="contactsGroupRef"
      :deal-id="deal.id"
      :contacts="contacts"
      :removing-id="removingContactId"
      @add-contact="emit('openAddContact')"
      @remove-contact="onRemoveContact"
      @contacts-updated="onContactsUpdated"
    />

    <!-- ── Group: Company (accent, collapsed by default) ─────────────────────── -->
    <DealCompanyGroup
      v-if="companyFull"
      ref="companyGroupRef"
      :company="companyFull"
      :default-collapsed="true"
      @company-updated="onCompanyUpdated"
    />

    <!-- ── Custom fields (scope=deal, accent, collapsed by default) ────────────── -->
    <DealFieldGroup
      v-if="dealCustomDefs.length > 0"
      ref="customGroupRef"
      :title="t('sales.deal.info.groups.customFields')"
      icon="pi-sliders-h"
      group-key="deal-custom"
      :accent="true"
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
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { RouterLink } from 'vue-router'

// ── Click-outside directive ───────────────────────────────────────────────────

const vClickOutside = {
  mounted(el: HTMLElement, binding: { value: () => void }) {
    el.__clickOutsideHandlerTab = (event: MouseEvent) => {
      if (!el.contains(event.target as Node)) {
        binding.value()
      }
    }
    document.addEventListener('click', el.__clickOutsideHandlerTab)
  },
  unmounted(el: HTMLElement) {
    if (el.__clickOutsideHandlerTab) {
      document.removeEventListener('click', el.__clickOutsideHandlerTab)
      delete el.__clickOutsideHandlerTab
    }
  },
}

declare global {
  interface HTMLElement {
    __clickOutsideHandlerTab?: (event: MouseEvent) => void
  }
}
import DealFieldGroup from './DealFieldGroup.vue'
import DealFieldRow from './DealFieldRow.vue'
import DealProductsGroup from './DealProductsGroup.vue'
import DealContactsGroup from './DealContactsGroup.vue'
import DealCompanyGroup from './DealCompanyGroup.vue'
import DateField from '@/components/crm/DateField.vue'
import { salesApi } from '@/api/sales'
import { companiesApi, type CompanyListParams } from '@/api/crm/companies'
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
  removeProduct: [id: number]
  removeContact: [contactId: number]
  contactsUpdated: [contacts: DealContactDto[]]
  /** Emitted when caller must do a full deal reload (e.g. discount % change). */
  reloadDeal: []
}>()

const { t } = useI18n()
const toast = useToast()

// C1: plan dates must not allow past dates
const todayIso = computed(() => {
  const d = new Date()
  const yyyy = d.getFullYear()
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  return `${yyyy}-${mm}-${dd}`
})

// ── Refs for collapse/expand all ───────────────────────────────────────────────

const productsGroupRef = ref<InstanceType<typeof DealProductsGroup> | null>(null)
const contactsGroupRef = ref<InstanceType<typeof DealContactsGroup> | null>(null)
const companyGroupRef = ref<InstanceType<typeof DealCompanyGroup> | null>(null)
const customGroupRef = ref<InstanceType<typeof DealFieldGroup> | null>(null)

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

// ── Owner SearchPicker ─────────────────────────────────────────────────────────

const ownerPickerOpen = ref(false)
const ownerQuery = ref('')
const ownerSearchRef = ref<HTMLInputElement | null>(null)
const ownerPendingId = ref<number | null>(null)

const ownerInitials = computed(() => {
  const name = props.deal.owner.name ?? ''
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length >= 2) return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase()
  return name.slice(0, 2).toUpperCase()
})

const filteredUsers = computed(() => {
  const q = ownerQuery.value.toLowerCase()
  return props.usersList.filter((u) => u.name.toLowerCase().includes(q))
})

const ownerMutation = useMutation<DealDto>()
const ownerSaving = computed(() => ownerMutation.isPending.value)

async function selectOwner(u: { id: number; name: string }) {
  if (u.id === props.deal.owner.id) {
    ownerPickerOpen.value = false
    return
  }
  ownerPendingId.value = u.id
  try {
    const updated = await ownerMutation.run(() =>
      salesApi.updateDeal(props.deal.id, { owner_user_id: u.id }),
    )
    emit('dealUpdated', { owner: updated.owner })
    ownerPickerOpen.value = false
    toast.add({ severity: 'success', summary: t('sales.deal.page.menu.changeOwner'), life: 2000 })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    ownerPendingId.value = null
  }
}

watch(ownerPickerOpen, (open) => {
  if (open) {
    ownerQuery.value = ''
    nextTick(() => ownerSearchRef.value?.focus())
  }
})

// ── Company picker ─────────────────────────────────────────────────────────────

const companyPickerOpen = ref(false)
const companyQuery = ref('')
const companySearchRef = ref<HTMLInputElement | null>(null)
const companyOptions = ref<Array<{ id: number; name: string }>>([])
const companySearching = ref(false)
const companyMutation = useMutation<DealDto>()
const companySaving = computed(() => companyMutation.isPending.value)

let companySearchTimer: ReturnType<typeof setTimeout> | null = null

async function doCompanySearch(q: string) {
  companySearching.value = true
  try {
    const params: CompanyListParams = { per_page: 20 }
    if (q.trim()) params.search = q.trim()
    const res = await companiesApi.list(params)
    companyOptions.value = res.data.map((c: Company) => ({ id: c.id, name: c.name }))
  } catch {
    companyOptions.value = []
  } finally {
    companySearching.value = false
  }
}

function onCompanyQueryInput() {
  if (companySearchTimer) clearTimeout(companySearchTimer)
  companySearchTimer = setTimeout(() => {
    void doCompanySearch(companyQuery.value)
  }, 250)
}

async function selectCompany(c: { id: number; name: string }) {
  if (c.id === props.deal.company?.id) {
    companyPickerOpen.value = false
    return
  }
  try {
    const updated = await companyMutation.run(() =>
      salesApi.updateDeal(props.deal.id, { company_id: c.id }),
    )
    emit('dealUpdated', {
      company: updated.company,
      department_id: updated.department_id,
    })
    companyPickerOpen.value = false
    toast.add({ severity: 'success', summary: t('sales.deal.info.fields.changeCompany'), life: 2000 })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

watch(companyPickerOpen, (open) => {
  if (open) {
    companyQuery.value = ''
    companyOptions.value = []
    void doCompanySearch('')
    nextTick(() => companySearchRef.value?.focus())
  } else {
    if (companySearchTimer) clearTimeout(companySearchTimer)
  }
})

// ── Discount update ───────────────────────────────────────────────────────────

const discountMutation = useMutation<DealDto>()

async function onUpdateDiscount(percent: number) {
  try {
    await discountMutation.run(() =>
      salesApi.updateDeal(props.deal.id, { discount_percent: percent }),
    )
    // Full reload needed: PATCH response omits products_discounted (products not loaded).
    // The SHOW endpoint returns the complete payload with discounted line amounts.
    emit('reloadDeal')
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

// ── Date field save (expected_sign_date / expected_payment_date) ───────────────

const dateMutation = useMutation<DealDto>()

async function saveDateField(field: 'expected_sign_date' | 'expected_payment_date', value: string | null) {
  try {
    const updated = await dateMutation.run(() =>
      salesApi.updateDeal(props.deal.id, { [field]: value }),
    )
    emit('dealUpdated', { [field]: updated[field] })
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

function onRemoveProduct(id: number) {
  emit('removeProduct', id)
}

// ── Contacts ───────────────────────────────────────────────────────────────────

function onRemoveContact(contactId: number) {
  emit('removeContact', contactId)
}

function onContactsUpdated(contacts: DealContactDto[]) {
  emit('contactsUpdated', contacts)
}

// ── Company full data ──────────────────────────────────────────────────────────

const companyFull = ref<Company | null>(null)

async function loadCompanyFull() {
  if (!props.deal.company) return
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

watch(() => props.deal.company?.id, (newId, oldId) => {
  if (newId && newId !== oldId) {
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
  grid-template-columns: 110px 1fr;
  align-items: center;
  gap: $space-2;
  padding: $space-1 $space-4;
  min-height: 32px;
}

.deal-tab-main__quick-label {
  font-size: $font-size-xs;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.deal-tab-main__quick-value {
  display: flex;
  align-items: center;
  min-width: 0;
  flex-wrap: wrap;
  gap: $space-1;
  position: relative;
}

// ── Owner row ──────────────────────────────────────────────────────────────────

.deal-tab-main__quick-value--owner {
  position: relative;
}

.deal-tab-main__owner-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  cursor: pointer;
  padding: 2px 6px;
  border-radius: $radius-sm;
  transition: background var(--app-transition-fast);

  &:hover {
    background: var(--p-surface-100);

    .app-dark & {
      background: var(--p-surface-700);
    }
  }
}

.deal-tab-main__owner-avatar {
  width: 22px;
  height: 22px;
  border-radius: $radius-circle;
  background: $primary-900;
  color: rgba(255, 255, 255, 1);
  font-size: $font-size-2xs;
  font-weight: $font-weight-semibold;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.deal-tab-main__owner-name {
  font-size: $font-size-sm;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

// ── Owner picker popover ──────────────────────────────────────────────────────

.deal-tab-main__owner-picker {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  z-index: 200;
  min-width: 220px;
  background: var(--p-card-background);
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  box-shadow: $shadow-lg;
  overflow: hidden;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

.deal-tab-main__owner-picker-search {
  display: flex;
  align-items: center;
  gap: $space-1;
  padding: $space-2 $space-3;
  border-bottom: 1px solid var(--p-surface-200);

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

.deal-tab-main__owner-picker-icon {
  font-size: $font-size-xs;
  color: $surface-400;
}

.deal-tab-main__owner-picker-input {
  flex: 1;
  border: none;
  outline: none;
  background: transparent;
  font-size: $font-size-sm;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-100);
  }

  &::placeholder {
    color: $surface-400;
  }
}

.deal-tab-main__owner-picker-options {
  max-height: 200px;
  overflow-y: auto;
  padding: $space-1;
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    width: 0;
    display: none;
  }
}

.deal-tab-main__owner-option {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  border-radius: $radius-sm;
  cursor: pointer;
  font-size: $font-size-sm;
  color: $surface-700;
  transition: background var(--app-transition-fast);

  .app-dark & {
    color: var(--p-surface-200);
  }

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-700);
    }
  }

  &--active {
    background: var(--p-primary-50);
    color: var(--p-primary-color);

    .app-dark & {
      background: var(--p-primary-900);
    }
  }
}

.deal-tab-main__owner-option-name {
  flex: 1;
}

.deal-tab-main__owner-check {
  font-size: $font-size-xs;
  color: var(--p-primary-color);
  flex-shrink: 0;
}

.deal-tab-main__owner-saving {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
}

.deal-tab-main__owner-empty {
  padding: $space-3;
  text-align: center;
  font-size: $font-size-sm;
  color: $surface-400;
}

// ── Company field ─────────────────────────────────────────────────────────────

.deal-tab-main__quick-value--company {
  position: relative;
}

.deal-tab-main__company-row {
  display: flex;
  align-items: center;
  gap: $space-1;
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

.deal-tab-main__company-deleted {
  font-size: $font-size-sm;
  color: $surface-400;
  font-style: italic;
  padding: $space-1 $space-2;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.deal-tab-main__company-edit-btn {
  background: none;
  border: none;
  cursor: pointer;
  padding: 2px 4px;
  border-radius: $radius-sm;
  color: $surface-400;
  font-size: $font-size-xs;
  opacity: 0;
  transition: opacity var(--app-transition-fast), color var(--app-transition-fast);

  .deal-tab-main__quick-value--company:hover & {
    opacity: 1;
  }

  &:hover {
    color: var(--p-primary-color);
  }

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.deal-tab-main__company-saving {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
}

.deal-tab-main__company-picker {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  z-index: 200;
  min-width: 260px;
  background: var(--p-card-background);
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  box-shadow: $shadow-lg;
  overflow: hidden;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}
</style>
