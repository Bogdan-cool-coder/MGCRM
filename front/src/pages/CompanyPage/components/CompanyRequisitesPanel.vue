<template>
  <!-- ── Classification (FE-A.1) — retained from FE-1, always shown ────────────── -->
  <InfoPanel
    :title="t('company.requisites.section.classification')"
    icon="pi-tag"
    panel-key="company-classification"
    :default-collapsed="false"
  >
    <KeyFactsBlock>
      <KeyFactsItem :label="t('crm.company.fields.specialization')">
        <InlineEditableField
          :model-value="company.specialization"
          field-key="specialization"
          field-type="select"
          :options="specializationOptions"
          option-label="name"
          option-value="id"
          :placeholder="t('crm.company.specialization.placeholder')"
          :saving="isSaving"
          @save="onSave"
        >
          <template #display="{ value }">
            <span v-if="value">{{ specializationLabel(value as string) }}</span>
            <span v-else>—</span>
          </template>
        </InlineEditableField>
      </KeyFactsItem>
    </KeyFactsBlock>
  </InfoPanel>

  <!-- ── Requisites list (FE-A.4) ────────────────────────────────────────────── -->
  <!-- spec §4: requisites panel icon = pi-id-card (not pi-building) -->
  <InfoPanel
    :title="t('crm.company.sections.requisites')"
    icon="pi-id-card"
    panel-key="company-requisites"
    :default-collapsed="false"
  >
    <template #header-action>
      <!-- spec §4: AddBtn text-link style -->
      <button type="button" class="requisites-panel__add-btn" @click="openCreate">
        <i class="pi pi-plus" />
        {{ t('crm.company.requisites.add') }}
      </button>
    </template>

    <!-- Loading -->
    <template v-if="loading">
      <Skeleton height="80px" class="mb-2" />
      <Skeleton height="80px" />
    </template>

    <!-- Error -->
    <template v-else-if="loadError">
      <div class="requisites-panel__error">
        <i class="pi pi-exclamation-triangle requisites-panel__error-icon" />
        <span>{{ t('crm.company.requisites.loadError') }}</span>
        <Button
          :label="t('common.retry')"
          severity="secondary"
          text
          size="small"
          @click="load"
        />
      </div>
    </template>

    <!-- Empty -->
    <template v-else-if="!loading && requisites.length === 0">
      <div class="requisites-panel__empty">
        <i class="pi pi-building requisites-panel__empty-icon" />
        <p class="requisites-panel__empty-text">{{ t('crm.company.requisites.empty') }}</p>
        <Button
          :label="t('crm.company.requisites.add')"
          icon="pi pi-plus"
          size="small"
          @click="openCreate"
        />
      </div>
    </template>

    <!-- List -->
    <template v-else>
      <RequisiteCard
        v-for="(req, idx) in sortedRequisites"
        :key="req.id"
        :requisite="req"
        :index="idx"
        @set-current="onSetCurrent"
        @edit="openEdit"
        @delete="onDelete"
      />
    </template>
  </InfoPanel>

  <!-- Form dialog -->
  <RequisiteFormDialog
    ref="formDialog"
    v-model="formOpen"
    :requisite="editingRequisite"
    @saved="onFormSaved"
  />
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import InfoPanel from '@/components/crm/entity/InfoPanel.vue'
import KeyFactsBlock from '@/components/crm/entity/KeyFactsBlock.vue'
import KeyFactsItem from '@/components/crm/entity/KeyFactsItem.vue'
import InlineEditableField from '@/components/crm/InlineEditableField.vue'
import RequisiteCard from './RequisiteCard.vue'
import RequisiteFormDialog from './RequisiteFormDialog.vue'
import { companiesApi } from '@/api/crm/companies'
import { getApiErrorMessage } from '@/utils/errors'
import type { Company, CompanyRequisite, CreateRequisitePayload } from '@/entities/crm'

const props = defineProps<{
  company: Company
  isSaving: boolean
}>()

const emit = defineEmits<{
  save: [fieldKey: string, value: unknown]
}>()

const { t } = useI18n()
const toast = useToast()

// ── Specialization enum (FE-A.1, N1) ─────────────────────────────────────────

const SPECIALIZATION_VALUES = [
  'agency',
  'developer',
  'builder',
  'contractor',
  'supplier',
  'partner',
] as const

const specializationOptions = SPECIALIZATION_VALUES.map((v) => ({
  id: v,
  name: t(`crm.company.specialization.${v}`),
}))

function specializationLabel(value: string): string {
  return t(`crm.company.specialization.${value}`, value)
}

function onSave(fieldKey: string, value: string | number | null) {
  emit('save', fieldKey, value)
}

// ── Requisites list state ──────────────────────────────────────────────────────

const requisites = ref<CompanyRequisite[]>([])
const loading = ref(false)
const loadError = ref(false)

const sortedRequisites = computed(() => {
  // current first, then by id desc
  return [...requisites.value].sort((a, b) => {
    if (a.is_current && !b.is_current) return -1
    if (!a.is_current && b.is_current) return 1
    return b.id - a.id
  })
})

async function load() {
  loading.value = true
  loadError.value = false
  try {
    requisites.value = await companiesApi.getRequisites(props.company.id)
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
}

// ── Form dialog ────────────────────────────────────────────────────────────────

const formOpen = ref(false)
const editingRequisite = ref<CompanyRequisite | null>(null)
const formDialog = ref<{ setSaving: (v: boolean) => void } | null>(null)

function openCreate() {
  editingRequisite.value = null
  formOpen.value = true
}

function openEdit(req: CompanyRequisite) {
  editingRequisite.value = req
  formOpen.value = true
}

async function onFormSaved(payload: CreateRequisitePayload, id?: number, setAsCurrent?: boolean) {
  formDialog.value?.setSaving(true)
  try {
    let savedId: number
    if (id) {
      await companiesApi.updateRequisite(props.company.id, id, payload)
      savedId = id
    } else {
      const created = await companiesApi.createRequisite(props.company.id, payload)
      savedId = created.id
    }
    // If user checked "set as current", call the dedicated endpoint
    if (setAsCurrent) {
      await companiesApi.setCurrentRequisite(props.company.id, savedId)
    }
    // Close dialog immediately on success; background-refresh list without blocking
    formOpen.value = false
    formDialog.value?.setSaving(false)
    toast.add({
      severity: 'success',
      summary: id
        ? t('crm.company.requisites.updateSuccess')
        : t('crm.company.requisites.createSuccess'),
      life: 3000,
    })
    void load()
  } catch (err) {
    formDialog.value?.setSaving(false)
    toast.add({
      severity: 'error',
      summary: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

// ── Set current ────────────────────────────────────────────────────────────────

async function onSetCurrent(requisiteId: number) {
  // Optimistic: mark current locally
  const prev = requisites.value.map((r) => ({ ...r }))
  requisites.value = requisites.value.map((r) => ({
    ...r,
    is_current: r.id === requisiteId,
  }))
  try {
    await companiesApi.setCurrentRequisite(props.company.id, requisiteId)
    toast.add({
      severity: 'success',
      summary: t('crm.company.requisites.setCurrentSuccess'),
      life: 3000,
    })
    await load()
  } catch (err) {
    // rollback
    requisites.value = prev
    toast.add({
      severity: 'error',
      summary: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

// ── Delete ─────────────────────────────────────────────────────────────────────

async function onDelete(requisiteId: number) {
  try {
    await companiesApi.deleteRequisite(props.company.id, requisiteId)
    toast.add({
      severity: 'success',
      summary: t('crm.company.requisites.deleteSuccess'),
      life: 3000,
    })
    await load()
  } catch (err) {
    // 422: cannot delete current/attached
    const status = (err as { response?: { status?: number; data?: { message?: string } } })?.response?.status
    const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
    if (status === 422) {
      toast.add({
        severity: 'error',
        summary: t('crm.company.requisites.deleteGuardError'),
        detail: msg ?? t('crm.company.requisites.deleteGuardHint'),
        life: 5000,
      })
    } else {
      toast.add({
        severity: 'error',
        summary: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    }
  }
}

// ── Lifecycle ──────────────────────────────────────────────────────────────────

onMounted(() => {
  void load()
})
</script>

<style lang="scss" scoped>
.requisites-panel__error {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-3;
  color: $surface-500;
  font-size: $font-size-sm;
}

.requisites-panel__error-icon {
  color: var(--p-orange-500);
}

.requisites-panel__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-5 $space-4;
  text-align: center;
}

.requisites-panel__empty-icon {
  font-size: $font-size-icon-lg; // 2rem
  color: $surface-300;
}

.requisites-panel__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

// spec §4: AddBtn text-link
.requisites-panel__add-btn {
  display: inline-flex;
  align-items: center;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  gap: 5px;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: var(--p-primary-color);
  background: transparent;
  border: none;
  cursor: pointer;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 4px 9px;
  border-radius: $radius-sm;
  white-space: nowrap;
  transition: background var(--app-transition-fast);

  &:hover {
    background: $primary-100;
  }

  .app-dark & {
    color: var(--p-primary-300);

    &:hover {
      background: var(--p-primary-900);
    }
  }

  i {
    font-size: $font-size-xs;
  }
}
</style>
