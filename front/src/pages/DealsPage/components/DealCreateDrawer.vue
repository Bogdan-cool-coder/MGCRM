<template>
  <Drawer
    v-model:visible="visible"
    position="right"
    style="width: 500px"
    @hide="onHide"
  >
    <template #header>
      <div class="deal-drawer__header">
        <span class="deal-drawer__header-title">{{ t('sales.deals.form.createTitle') }}</span>
        <Button
          icon="pi pi-times"
          severity="secondary"
          text
          rounded
          :disabled="saving"
          :aria-label="t('common.close')"
          @click="visible = false"
        />
      </div>
    </template>
    <div class="deal-drawer">
      <!-- Company -->
      <div class="deal-drawer__field">
        <label class="deal-drawer__label">
          {{ t('sales.deals.form.fields.company') }} <span class="req">*</span>
        </label>
        <AutoComplete
          v-model="form.company"
          :suggestions="companySuggestions"
          option-label="name"
          :placeholder="t('sales.deals.form.fields.company')"
          force-selection
          dropdown
          class="w-full"
          :class="{ 'p-invalid': errors.company_id }"
          :delay="300"
          @complete="searchCompanies($event.query)"
          @option-select="onCompanySelect"
        >
          <template #option="{ option }">
            <span>{{ option.name }}</span>
          </template>
        </AutoComplete>
        <small v-if="errors.company_id" class="p-error">{{ errors.company_id }}</small>
      </div>

      <!-- Title -->
      <div class="deal-drawer__field">
        <label class="deal-drawer__label">
          {{ t('sales.deals.form.fields.title') }} <span class="req">*</span>
        </label>
        <InputText
          v-model="form.title"
          class="w-full"
          :class="{ 'p-invalid': errors.title }"
          :placeholder="t('sales.deals.form.fields.title')"
        />
        <small v-if="errors.title" class="p-error">{{ errors.title }}</small>
      </div>

      <!-- Pipeline -->
      <div class="deal-drawer__field">
        <label class="deal-drawer__label">
          {{ t('sales.deals.form.fields.pipeline') }} <span class="req">*</span>
        </label>
        <Select
          v-model="form.pipeline_id"
          :options="pipelines"
          option-label="name"
          option-value="id"
          :placeholder="t('sales.deals.form.fields.pipeline')"
          class="w-full"
          :class="{ 'p-invalid': errors.pipeline_id }"
        />
        <small v-if="errors.pipeline_id" class="p-error">{{ errors.pipeline_id }}</small>
      </div>

      <!-- Currency -->
      <div class="deal-drawer__field">
        <label class="deal-drawer__label">
          {{ t('sales.deals.form.fields.currency') }} <span class="req">*</span>
        </label>
        <Select
          v-model="form.currency"
          :options="currencyOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('sales.deals.form.fields.currency')"
          class="w-full"
          :class="{ 'p-invalid': errors.currency }"
        />
        <small v-if="errors.currency" class="p-error">{{ errors.currency }}</small>
      </div>

      <!-- Owner -->
      <div class="deal-drawer__field">
        <label class="deal-drawer__label">{{ t('sales.deals.form.fields.owner') }}</label>
        <Select
          v-model="form.owner_user_id"
          :options="ownerOptions"
          option-label="full_name"
          option-value="id"
          :placeholder="t('sales.deals.form.fields.owner')"
          class="w-full"
          :disabled="isManagerRole"
        />
      </div>

      <!-- Expected close date -->
      <div class="deal-drawer__field">
        <label class="deal-drawer__label">{{ t('sales.deals.form.fields.expectedCloseDate') }}</label>
        <DatePicker
          v-model="form.expected_close_date"
          date-format="dd.mm.yy"
          show-clear
          show-icon
          class="w-full"
        />
      </div>
    </div>

    <template #footer>
      <div class="deal-drawer__footer">
        <Button
          :label="t('sales.deals.form.cancel')"
          severity="secondary"
          text
          :disabled="saving"
          @click="visible = false"
        />
        <Button
          icon="pi pi-check"
          :label="t('sales.deals.form.save')"
          :loading="saving"
          @click="onSubmit"
        />
      </div>
    </template>
  </Drawer>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Drawer from 'primevue/drawer'
import AutoComplete from 'primevue/autocomplete'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Button from 'primevue/button'
import { salesApi } from '@/api/sales'
import { companiesApi } from '@/api/crm/companies'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorStatus, getValidationErrors, getApiErrorMessage } from '@/utils/errors'
import { CURRENCY_WHITELIST } from '@/utils/currency'
import { useUserStore } from '@/stores/user'
import type { PipelineDto, DealDto } from '@/entities/sales'

interface CompanyOption {
  id: number
  name: string
}

interface OwnerOption {
  id: number
  full_name: string
}

const props = defineProps<{
  modelValue: boolean
  pipelines: PipelineDto[]
  owners?: OwnerOption[]
}>()

const emit = defineEmits<{
  'update:modelValue': [v: boolean]
  created: [deal: DealDto]
}>()

const { t } = useI18n()
const toast = useToast()
const userStore = useUserStore()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const isManagerRole = computed(() => {
  const role = userStore.getUserRole
  return role === 'manager'
})

interface DealCreateForm {
  company: CompanyOption | null
  title: string
  pipeline_id: number | null
  currency: string
  owner_user_id: number | null
  expected_close_date: Date | null
}

const defaultForm = (): DealCreateForm => ({
  company: null,
  title: '',
  pipeline_id: props.pipelines[0]?.id ?? null,
  currency: 'KZT',
  owner_user_id: userStore.getUser?.id ?? null,
  expected_close_date: null,
})

const form = ref<DealCreateForm>(defaultForm())
const errors = ref<Record<string, string>>({})
const companySuggestions = ref<CompanyOption[]>([])

const mutation = useMutation<DealDto>()
const saving = computed(() => mutation.isPending.value)

const currencyOptions = computed(() =>
  CURRENCY_WHITELIST.map((c) => ({ value: c, label: c })),
)

const ownerOptions = computed<OwnerOption[]>(() => {
  if (isManagerRole.value && userStore.getUser) {
    // Managers can only assign to themselves
    return [{ id: userStore.getUser.id, full_name: userStore.getUserName }]
  }
  // For admin/director: prefer the passed owners list; fall back to self so the
  // dropdown is never empty even when owners prop is not supplied.
  if (props.owners && props.owners.length > 0) {
    return props.owners
  }
  if (userStore.getUser) {
    return [{ id: userStore.getUser.id, full_name: userStore.getUserName }]
  }
  return []
})

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      form.value = defaultForm()
      errors.value = {}
    }
  },
)

// When pipelines load, set default
watch(
  () => props.pipelines,
  (list) => {
    if (list.length > 0 && !form.value.pipeline_id && list[0]) {
      form.value.pipeline_id = list[0].id
    }
  },
)

async function searchCompanies(query: string) {
  if (!query) {
    companySuggestions.value = []
    return
  }
  try {
    const res = await companiesApi.list({ search: query, per_page: 10 })
    companySuggestions.value = res.data.map((c) => ({ id: c.id, name: c.name }))
  } catch {
    companySuggestions.value = []
  }
}

function onCompanySelect() {
  if (errors.value.company_id) {
    errors.value = { ...errors.value, company_id: '' }
  }
}

function validate(): boolean {
  const errs: Record<string, string> = {}
  if (!form.value.company?.id) {
    errs.company_id = t('sales.deals.form.errors.companyRequired')
  }
  if (!form.value.title || form.value.title.length < 2) {
    errs.title = t('sales.deals.form.errors.titleRequired')
  }
  if (!form.value.currency) {
    errs.currency = t('sales.deals.form.errors.currencyRequired')
  }
  if (!form.value.pipeline_id) {
    errs.pipeline_id = t('sales.deals.form.errors.currencyRequired')
  }
  errors.value = errs
  return Object.keys(errs).length === 0
}

function formatDate(d: Date | null): string | null {
  if (!d) return null
  const year = d.getFullYear()
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

async function onSubmit() {
  if (!validate()) return

  try {
    const deal = await mutation.run(() =>
      salesApi.createDeal({
        company_id: form.value.company!.id,
        title: form.value.title,
        pipeline_id: form.value.pipeline_id!,
        currency: form.value.currency,
        owner_user_id: form.value.owner_user_id ?? userStore.getUser!.id,
        expected_close_date: formatDate(form.value.expected_close_date),
      }),
    )

    toast.add({
      severity: 'success',
      summary: t('sales.deals.form.createSuccess'),
      life: 3000,
    })
    visible.value = false
    emit('created', deal)
  } catch (err) {
    const status = getApiErrorStatus(err)
    if (status === 422) {
      const ve = getValidationErrors(err)
      if (ve) {
        errors.value = {
          company_id: ve.company_id ?? '',
          title: ve.title ?? '',
          currency: ve.currency ?? '',
          pipeline_id: ve.pipeline_id ?? '',
        }
        return
      }
    }
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

function onHide() {
  errors.value = {}
}
</script>

<style lang="scss" scoped>
.deal-drawer {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  padding: $space-2 0;

  &__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    gap: $space-2;
  }

  &__header-title {
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
    color: var(--p-text-color);
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.deal-drawer__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.deal-drawer__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
}

.deal-drawer__footer {
  display: flex;
  justify-content: flex-end;
  gap: $space-2;
  padding-top: $space-4;
  border-top: 1px solid $surface-200;
}

.req {
  color: var(--p-red-500, #ff5a44);
}

.w-full {
  width: 100%;
}

:deep(.p-drawer-close-button) {
  display: none !important;
}
</style>
