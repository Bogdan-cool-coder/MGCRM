<template>
  <DealFieldGroup
    :title="t('sales.deal.dates.group')"
    icon="pi-calendar"
    group-key="dates"
    :default-collapsed="false"
  >
    <!-- Contract Plan -->
    <div class="deal-dates-group__row">
      <span class="deal-dates-group__label">{{ t('sales.deal.dates.contractPlan') }}</span>
      <div class="deal-dates-group__value">
        <DatePicker
          v-model="expectedSignDate"
          date-format="dd.mm.yy"
          :show-icon="true"
          :disabled="planSaving === 'expected_sign_date'"
          class="deal-dates-group__picker"
          @update:model-value="savePlanDate('expected_sign_date', $event)"
        />
        <Tag
          v-if="contractOverdueDays !== null"
          :value="t('sales.deal.dates.overdueBy', { n: contractOverdueDays })"
          severity="danger"
          size="small"
          class="deal-dates-group__overdue"
        />
      </div>
    </div>

    <!-- Contract Fact -->
    <div class="deal-dates-group__row">
      <span class="deal-dates-group__label">{{ t('sales.deal.dates.contractFact') }}</span>
      <div class="deal-dates-group__value">
        <DatePicker
          v-model="signedAt"
          date-format="dd.mm.yy"
          :show-icon="true"
          :disabled="factSaving === 'signed_at'"
          class="deal-dates-group__picker"
          @update:model-value="saveFactDate('signed_at', $event)"
        />
        <i
          v-if="deal.signed_at"
          class="pi pi-check deal-dates-group__done-icon"
        />
      </div>
    </div>

    <!-- Payment Plan -->
    <div class="deal-dates-group__row">
      <span class="deal-dates-group__label">{{ t('sales.deal.dates.paymentPlan') }}</span>
      <div class="deal-dates-group__value">
        <DatePicker
          v-model="expectedPaymentDate"
          date-format="dd.mm.yy"
          :show-icon="true"
          :disabled="planSaving === 'expected_payment_date'"
          class="deal-dates-group__picker"
          @update:model-value="savePlanDate('expected_payment_date', $event)"
        />
        <Tag
          v-if="paymentOverdueDays !== null"
          :value="t('sales.deal.dates.overdueBy', { n: paymentOverdueDays })"
          severity="danger"
          size="small"
          class="deal-dates-group__overdue"
        />
      </div>
    </div>

    <!-- Payment Fact -->
    <div class="deal-dates-group__row">
      <span class="deal-dates-group__label">{{ t('sales.deal.dates.paymentFact') }}</span>
      <div class="deal-dates-group__value">
        <DatePicker
          v-model="paidAt"
          date-format="dd.mm.yy"
          :show-icon="true"
          :disabled="factSaving === 'paid_at'"
          class="deal-dates-group__picker"
          @update:model-value="saveFactDate('paid_at', $event)"
        />
        <i
          v-if="deal.paid_at"
          class="pi pi-check deal-dates-group__done-icon"
        />
      </div>
    </div>
  </DealFieldGroup>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
import DealFieldGroup from './DealFieldGroup.vue'
import { salesApi } from '@/api/sales'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorMessage } from '@/utils/errors'
import type { DealDto } from '@/entities/sales'

const props = defineProps<{
  deal: DealDto
}>()

const emit = defineEmits<{
  dealUpdated: [updates: Partial<DealDto>]
}>()

const { t } = useI18n()
const toast = useToast()

// ── Local date models — synced from props ─────────────────────────────────────

function isoToDate(val: string | null | undefined): Date | null {
  if (!val) return null
  const d = new Date(val)
  return isNaN(d.getTime()) ? null : d
}

function dateToIso(val: Date | null | undefined): string | null {
  if (!val) return null
  // Format as YYYY-MM-DD (date only, no time zone shift)
  const y = val.getFullYear()
  const m = String(val.getMonth() + 1).padStart(2, '0')
  const d = String(val.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

const expectedSignDate = ref<Date | null>(isoToDate(props.deal.expected_sign_date))
const expectedPaymentDate = ref<Date | null>(isoToDate(props.deal.expected_payment_date))
const signedAt = ref<Date | null>(isoToDate(props.deal.signed_at))
const paidAt = ref<Date | null>(isoToDate(props.deal.paid_at))

watch(() => props.deal.expected_sign_date, (v) => { expectedSignDate.value = isoToDate(v) })
watch(() => props.deal.expected_payment_date, (v) => { expectedPaymentDate.value = isoToDate(v) })
watch(() => props.deal.signed_at, (v) => { signedAt.value = isoToDate(v) })
watch(() => props.deal.paid_at, (v) => { paidAt.value = isoToDate(v) })

// ── Overdue calculation ───────────────────────────────────────────────────────

const today = new Date()
today.setHours(0, 0, 0, 0)

function overdueDays(planIso: string | null | undefined, factIso: string | null | undefined): number | null {
  // Only show overdue if plan date has passed AND fact date is not filled
  if (!planIso || factIso) return null
  const plan = new Date(planIso)
  plan.setHours(0, 0, 0, 0)
  const diff = Math.floor((today.getTime() - plan.getTime()) / (1000 * 60 * 60 * 24))
  return diff > 0 ? diff : null
}

const contractOverdueDays = computed(() =>
  overdueDays(props.deal.expected_sign_date, props.deal.signed_at),
)

const paymentOverdueDays = computed(() =>
  overdueDays(props.deal.expected_payment_date, props.deal.paid_at),
)

// ── Mutations ─────────────────────────────────────────────────────────────────

const planSaving = ref<string | null>(null)
const factSaving = ref<string | null>(null)
const patchMutation = useMutation<DealDto>()

async function savePlanDate(field: 'expected_sign_date' | 'expected_payment_date', val: Date | Date[] | (Date | null)[] | null | undefined) {
  const date = val instanceof Date ? val : null
  planSaving.value = field
  try {
    const iso = dateToIso(date)
    const updated = await patchMutation.run(() =>
      salesApi.updateDeal(props.deal.id, { [field]: iso }),
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
    planSaving.value = null
  }
}

async function saveFactDate(field: 'signed_at' | 'paid_at', val: Date | Date[] | (Date | null)[] | null | undefined) {
  const date = val instanceof Date ? val : null
  factSaving.value = field
  try {
    const iso = dateToIso(date)
    const updated = await patchMutation.run(() =>
      salesApi.updateDeal(props.deal.id, { [field]: iso }),
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
    factSaving.value = null
  }
}
</script>

<style lang="scss" scoped>
.deal-dates-group__row {
  display: grid;
  grid-template-columns: 120px 1fr;
  align-items: center;
  gap: $space-2;
  padding: $space-1 $space-4;
  min-height: 36px;
}

.deal-dates-group__label {
  font-size: $font-size-xs;
  color: $surface-500;
  line-height: 1.4;
}

.deal-dates-group__value {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
}

.deal-dates-group__picker {
  :deep(.p-datepicker-input) {
    font-size: $font-size-sm;
    padding: 4px 8px;
    height: 30px;
    width: 130px;
  }

  :deep(.p-datepicker-dropdown) {
    padding: 4px 6px;
    height: 30px;
  }
}

.deal-dates-group__overdue {
  flex-shrink: 0;
}

.deal-dates-group__done-icon {
  font-size: $font-size-sm;
  color: var(--p-green-600);
  flex-shrink: 0;

  .app-dark & {
    color: var(--p-green-400);
  }
}
</style>
