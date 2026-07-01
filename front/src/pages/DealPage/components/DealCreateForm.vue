<template>
  <div class="deal-create-form">
    <!-- ── ОБЯЗАТЕЛЬНЫЕ ПОЛЯ ─────────────────────────────────────────────────── -->
    <div class="deal-create-form__section">
      <h3 class="deal-create-form__section-title">{{ t('sales.deal.create.sections.required') }}</h3>

      <!-- Компания -->
      <div class="deal-create-form__field">
        <label class="deal-create-form__label">
          {{ t('sales.deal.create.fields.company') }} <span class="deal-create-form__req">*</span>
        </label>
        <AutoComplete
          v-model="form.company"
          :suggestions="companySuggestions"
          option-label="name"
          :placeholder="t('sales.deal.create.fields.company')"
          force-selection
          dropdown
          class="w-full"
          :class="{ 'p-invalid': errors.company_id }"
          :delay="300"
          :disabled="saving"
          @complete="searchCompanies($event.query)"
          @option-select="onCompanySelect"
        >
          <template #option="{ option }">
            <span>{{ option.name }}</span>
          </template>
        </AutoComplete>
        <small v-if="errors.company_id" class="p-error">{{ errors.company_id }}</small>
      </div>

      <!-- Название -->
      <div class="deal-create-form__field">
        <label class="deal-create-form__label">
          {{ t('sales.deal.create.fields.title') }} <span class="deal-create-form__req">*</span>
        </label>
        <InputText
          v-model="form.title"
          class="w-full"
          :class="{ 'p-invalid': errors.title }"
          :placeholder="t('sales.deal.create.fields.title')"
          :disabled="saving"
        />
        <small v-if="errors.title" class="p-error">{{ errors.title }}</small>
      </div>

      <!-- Воронка -->
      <div class="deal-create-form__field">
        <label class="deal-create-form__label">
          {{ t('sales.deal.create.fields.pipeline') }} <span class="deal-create-form__req">*</span>
        </label>
        <Select
          v-model="form.pipeline_id"
          :options="pipelines"
          option-label="name"
          option-value="id"
          :placeholder="t('sales.deal.create.fields.pipeline')"
          class="w-full"
          :class="{ 'p-invalid': errors.pipeline_id }"
          :disabled="saving || pipelinesLoading"
          @change="onPipelineChange"
        />
        <small v-if="errors.pipeline_id" class="p-error">{{ errors.pipeline_id }}</small>
      </div>

      <!-- Валюта -->
      <div class="deal-create-form__field">
        <label class="deal-create-form__label">
          {{ t('sales.deal.create.fields.currency') }} <span class="deal-create-form__req">*</span>
        </label>
        <Select
          v-model="form.currency"
          :options="currencyOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('sales.deal.create.fields.currency')"
          class="w-full"
          :class="{ 'p-invalid': errors.currency }"
          :disabled="saving"
        />
        <small v-if="errors.currency" class="p-error">{{ errors.currency }}</small>
      </div>
    </div>

    <!-- ── ДОПОЛНИТЕЛЬНО ─────────────────────────────────────────────────────── -->
    <div class="deal-create-form__section">
      <h3 class="deal-create-form__section-title">{{ t('sales.deal.create.sections.additional') }}</h3>

      <!-- Стадия (UI-only prefill hint) -->
      <div v-if="stageOptions.length > 0" class="deal-create-form__field">
        <label class="deal-create-form__label">{{ t('sales.deal.create.fields.stage') }}</label>
        <Select
          v-model="form.stage_id"
          :options="stageOptions"
          option-label="name"
          option-value="id"
          show-clear
          :placeholder="t('sales.deal.create.stageAutoNote')"
          class="w-full"
          :disabled="saving"
        />
        <small class="deal-create-form__hint">{{ t('sales.deal.create.stageAutoNote') }}</small>
      </div>

      <!-- Ответственный -->
      <div class="deal-create-form__field">
        <label class="deal-create-form__label">{{ t('sales.deal.create.fields.owner') }}</label>
        <Select
          v-model="form.owner_user_id"
          :options="ownerOptions"
          option-label="full_name"
          option-value="id"
          :placeholder="t('sales.deal.create.fields.owner')"
          class="w-full"
          :disabled="saving || isManagerRole"
        />
      </div>

      <!-- Ожидаемое закрытие -->
      <div class="deal-create-form__field">
        <label class="deal-create-form__label">{{ t('sales.deal.create.fields.expectedCloseDate') }}</label>
        <DatePicker
          v-model="form.expected_close_date"
          date-format="dd.mm.yy"
          show-clear
          show-icon
          class="w-full"
          :disabled="saving"
        />
      </div>
    </div>

    <!-- ── ACTION BAR ────────────────────────────────────────────────────────── -->
    <div class="deal-create-form__actions">
      <Button
        :label="t('sales.deal.create.cancelBtn')"
        severity="secondary"
        text
        :disabled="saving"
        @click="emit('cancel')"
      />
      <Button
        icon="pi pi-check"
        :label="t('sales.deal.create.saveBtn')"
        :loading="saving"
        :disabled="saving"
        @click="onSubmit"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
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
import { useSalesStore } from '@/stores/salesStore'
import { useUsersCache } from '@/composables/crm/useUsersCache'
import type { DealDto, PipelineDto, PipelineStageDto } from '@/entities/sales'

interface CompanyOption {
  id: number
  name: string
}

const props = defineProps<{
  /** Prefill from query param ?company_id=&company_name= */
  initialCompanyId?: number | null
  initialCompanyName?: string | null
  /** Prefill from query param ?pipeline_id= */
  initialPipelineId?: number | null
  /** Prefill from query param ?stage_id= */
  initialStageId?: number | null
}>()

const emit = defineEmits<{
  saved: [deal: DealDto]
  cancel: []
}>()

const { t } = useI18n()
const toast = useToast()
const userStore = useUserStore()
const salesStore = useSalesStore()
const { users: usersCache, load: loadUsers } = useUsersCache()

// Pipelines
const pipelines = ref<PipelineDto[]>([])
const pipelinesLoading = ref(false)

const isManagerRole = computed(() => userStore.getUserRole === 'manager')

interface DealCreateForm {
  company: CompanyOption | null
  title: string
  pipeline_id: number | null
  stage_id: number | null
  currency: string
  owner_user_id: number | null
  expected_close_date: Date | null
}

const form = ref<DealCreateForm>({
  company: null,
  title: '',
  pipeline_id: null,
  stage_id: null,
  currency: 'KZT',
  owner_user_id: userStore.getUser?.id ?? null,
  expected_close_date: null,
})

const errors = ref<Record<string, string>>({})
const companySuggestions = ref<CompanyOption[]>([])

const mutation = useMutation<DealDto>()
const saving = computed(() => mutation.isPending.value)

const currencyOptions = computed(() =>
  CURRENCY_WHITELIST.map((c) => ({ value: c, label: c })),
)

const stageOptions = computed<PipelineStageDto[]>(() => {
  const pid = form.value.pipeline_id
  if (!pid) return []
  return salesStore.getCachedStages(pid)
})

const ownerOptions = computed(() => {
  if (isManagerRole.value && userStore.getUser) {
    return [{ id: userStore.getUser.id, full_name: userStore.getUserName }]
  }
  if (usersCache.value.length > 0) return usersCache.value
  if (userStore.getUser) {
    return [{ id: userStore.getUser.id, full_name: userStore.getUserName }]
  }
  return []
})

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
    const { company_id: _c, ...rest } = errors.value
    void _c
    errors.value = rest
  }
}

function onPipelineChange() {
  // Clear stage when pipeline changes since stage IDs are pipeline-specific
  form.value.stage_id = null
}

function validate(): boolean {
  const errs: Record<string, string> = {}
  if (!form.value.company?.id) {
    errs.company_id = t('sales.deal.create.errors.companyRequired')
  }
  if (!form.value.title || form.value.title.length < 2) {
    errs.title = t('sales.deal.create.errors.titleRequired')
  }
  if (!form.value.pipeline_id) {
    errs.pipeline_id = t('sales.deal.create.errors.pipelineRequired')
  }
  if (!form.value.currency) {
    errs.currency = t('sales.deal.create.errors.currencyRequired')
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
        title: form.value.title.trim(),
        pipeline_id: form.value.pipeline_id!,
        stage_id: form.value.stage_id ?? undefined,
        currency: form.value.currency,
        owner_user_id: form.value.owner_user_id ?? userStore.getUser!.id,
        expected_close_date: formatDate(form.value.expected_close_date),
      }),
    )

    toast.add({
      severity: 'success',
      summary: t('sales.deal.create.success'),
      life: 3000,
    })
    emit('saved', deal)
  } catch (err) {
    const status = getApiErrorStatus(err)
    if (status === 422) {
      const ve = getValidationErrors(err)
      if (ve) {
        errors.value = {
          company_id: ve.company_id ? (Array.isArray(ve.company_id) ? ve.company_id[0] : ve.company_id) : '',
          title: ve.title ? (Array.isArray(ve.title) ? ve.title[0] : ve.title) : '',
          currency: ve.currency ? (Array.isArray(ve.currency) ? ve.currency[0] : ve.currency) : '',
          pipeline_id: ve.pipeline_id ? (Array.isArray(ve.pipeline_id) ? ve.pipeline_id[0] : ve.pipeline_id) : '',
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

// Apply prefill from query params on mount
onMounted(async () => {
  pipelinesLoading.value = true
  try {
    const list = await salesApi.getPipelines('sales')
    pipelines.value = list

    // Cache stages for all pipelines
    for (const p of list) {
      if (p.stages) {
        salesStore.cacheStages(p.id, p.stages)
      }
    }

    // Apply pipeline prefill or default to first
    if (props.initialPipelineId) {
      const found = list.find((p) => p.id === props.initialPipelineId)
      if (found) {
        form.value.pipeline_id = found.id
      } else {
        form.value.pipeline_id = list[0]?.id ?? null
      }
    } else {
      form.value.pipeline_id = list[0]?.id ?? null
    }

    // Apply stage prefill after pipeline is set
    if (props.initialStageId && form.value.pipeline_id) {
      const stages = salesStore.getCachedStages(form.value.pipeline_id)
      const stageExists = stages.some((s) => s.id === props.initialStageId)
      if (stageExists) {
        form.value.stage_id = props.initialStageId
      }
    }
  } finally {
    pipelinesLoading.value = false
  }

  // Apply company prefill
  if (props.initialCompanyId && props.initialCompanyName) {
    form.value.company = { id: props.initialCompanyId, name: props.initialCompanyName }
  }

  void loadUsers()
})

// Watch for initial company prop changes (in case parent sets it async)
watch(
  () => props.initialCompanyId,
  (id) => {
    if (id && props.initialCompanyName && !form.value.company) {
      form.value.company = { id, name: props.initialCompanyName }
    }
  },
)
</script>

<style lang="scss" scoped>
.deal-create-form {
  display: flex;
  flex-direction: column;
  gap: $space-6;
  padding: $space-5;
}

.deal-create-form__section {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-card;
  padding: $space-5;

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);
  }
}

.deal-create-form__section-title {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  margin: 0 0 $space-1;
  text-transform: uppercase;
  letter-spacing: 0.04em;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.deal-create-form__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.deal-create-form__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.deal-create-form__req {
  color: $red-500;
}

.deal-create-form__hint {
  font-size: $font-size-xs;
  color: $surface-400;
}

.deal-create-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: $space-2;
  padding-top: $space-2;
}

.w-full {
  width: 100%;
}
</style>
