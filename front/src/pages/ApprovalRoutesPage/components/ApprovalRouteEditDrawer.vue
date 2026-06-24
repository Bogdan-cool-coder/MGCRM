<template>
  <Drawer
    v-model:visible="visible"
    position="right"
    :header="t('approvalRoutes.drawer.title')"
    style="width: 600px"
  >
    <div class="route-drawer">
      <div class="mb-3">
        <label class="route-drawer__label">{{ t('approvalRoutes.drawer.name') }} *</label>
        <InputText v-model="form.title" class="w-100 mt-1" />
      </div>
      <div class="mb-3">
        <label class="route-drawer__label">{{ t('approvalRoutes.drawer.kind') }} *</label>
        <Select
          v-model="form.document_kind"
          :options="kindOptions"
          option-label="label"
          option-value="value"
          class="w-100 mt-1"
        />
      </div>
      <div class="mb-3 d-flex align-items-center gap-2">
        <ToggleSwitch v-model="form.is_default" />
        <label class="route-drawer__label mb-0">{{ t('approvalRoutes.drawer.isDefault') }}</label>
      </div>
      <Message v-if="form.is_default" severity="info" :closable="false" class="mb-3">
        {{ t('approvalRoutes.drawer.defaultWarning') }}
      </Message>

      <!-- Stages -->
      <div class="mb-2">
        <p class="fw-semibold mb-2">{{ t('approvalRoutes.drawer.stages') }}</p>
        <div v-for="(stage, i) in form.stages" :key="i" class="route-drawer__stage mb-3">
          <div class="route-drawer__stage-header d-flex align-items-center justify-content-between mb-2">
            <span class="fw-medium">{{ i + 1 }}. {{ stage.name || t('approvalRoutes.drawer.stage.name') }}</span>
            <div class="d-flex gap-1">
              <Button icon="pi pi-arrow-up" text severity="secondary" size="small" :disabled="i === 0" @click="moveStage(i, -1)" />
              <Button icon="pi pi-arrow-down" text severity="secondary" size="small" :disabled="i === form.stages.length - 1" @click="moveStage(i, 1)" />
              <Button icon="pi pi-trash" text severity="danger" size="small" @click="form.stages.splice(i, 1)" />
            </div>
          </div>
          <div class="row g-2">
            <div class="col-12">
              <InputText v-model="stage.name" :placeholder="t('approvalRoutes.drawer.stage.name')" class="w-100" />
            </div>
            <div class="col-md-9">
              <MultiSelect
                v-model="stage.user_ids"
                :options="userOptions"
                option-label="full_name"
                option-value="id"
                :placeholder="t('approvalRoutes.drawer.stage.users')"
                :loading="loadingUsers"
                filter
                class="w-100"
              />
            </div>
            <div class="col-md-3">
              <InputNumber v-model="stage.min_required" :min="1" class="w-100" :placeholder="t('approvalRoutes.drawer.stage.minRequired')" />
            </div>
          </div>
        </div>
        <Button
          :label="t('approvalRoutes.drawer.addStage')"
          icon="pi pi-plus"
          text
          severity="secondary"
          @click="addStage"
        />
      </div>
    </div>

    <template #footer>
      <div class="d-flex gap-2 justify-content-end">
        <Button :label="t('approvalRoutes.drawer.cancel')" severity="secondary" text @click="visible = false" />
        <Button :label="t('approvalRoutes.drawer.save')" :loading="saving" @click="save" />
      </div>
    </template>
  </Drawer>
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Drawer from 'primevue/drawer'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import ToggleSwitch from 'primevue/toggleswitch'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import { approvalRoutesApi } from '@/api/approvalRoutes'
import { usersApi, type UserOptionDto } from '@/api/users'
import type { DocumentKind } from '@/entities/document'

interface StageForm {
  order: number
  name: string
  user_ids: number[]
  min_required: number
}

const props = defineProps<{
  modelValue: boolean
  routeId: number | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: []
}>()

const { t } = useI18n()
const toast = useToast()
const saving = ref(false)
const loadingUsers = ref(false)
const userOptions = ref<UserOptionDto[]>([])

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const kindOptions = [
  { label: t('documents.kinds.contract'), value: 'contract' as DocumentKind },
  { label: t('documents.kinds.invoice'), value: 'invoice' as DocumentKind },
  { label: t('documents.kinds.act'), value: 'act' as DocumentKind },
  { label: t('documents.kinds.reconciliation'), value: 'reconciliation' as DocumentKind },
  { label: t('documents.kinds.termination_agreement'), value: 'termination_agreement' as DocumentKind },
]

const form = ref<{
  title: string
  document_kind: DocumentKind
  is_default: boolean
  stages: StageForm[]
}>({
  title: '',
  document_kind: 'contract',
  is_default: false,
  stages: [],
})

async function loadUsers() {
  if (userOptions.value.length > 0) return
  loadingUsers.value = true
  try {
    userOptions.value = await usersApi.getUsers()
  } catch {
    userOptions.value = []
  } finally {
    loadingUsers.value = false
  }
}

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    await loadUsers()
    if (props.routeId) {
      const route = await approvalRoutesApi.getApprovalRoute(props.routeId)
      form.value = {
        title: route.title,
        document_kind: route.document_kind,
        is_default: route.is_default,
        stages: route.stages.map((s) => ({
          order: s.order,
          name: s.name,
          user_ids: s.user_ids,
          min_required: s.min_required,
        })),
      }
    } else {
      form.value = { title: '', document_kind: 'contract', is_default: false, stages: [] }
    }
  },
)

function addStage() {
  form.value.stages.push({
    order: form.value.stages.length + 1,
    name: '',
    user_ids: [],
    min_required: 1,
  })
}

function moveStage(i: number, dir: -1 | 1) {
  const arr = form.value.stages
  const tmp = arr[i]!
  arr[i] = arr[i + dir]!
  arr[i + dir] = tmp
  arr.forEach((s, idx) => { s.order = idx + 1 })
}

async function save() {
  saving.value = true
  try {
    const payload = {
      title: form.value.title,
      document_kind: form.value.document_kind,
      is_default: form.value.is_default,
      stages: form.value.stages.map((s) => ({
        order: s.order,
        name: s.name,
        user_ids: s.user_ids,
        min_required: s.min_required,
      })),
    }
    if (props.routeId) {
      await approvalRoutesApi.patchApprovalRoute(props.routeId, payload)
    } else {
      await approvalRoutesApi.createApprovalRoute(payload)
    }
    emit('saved')
    visible.value = false
    toast.add({ severity: 'success', summary: t('approvalRoutes.drawer.save'), life: 2000 })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  } finally {
    saving.value = false
  }
}
</script>

<style lang="scss" scoped>
.route-drawer {
  padding: 0.5rem;

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }

  &__stage {
    border: 1px solid var(--p-surface-200);
    border-radius: $radius-md;
    padding: 0.75rem;
    background: var(--p-surface-50);
  }

  &__stage-header {
    font-size: $font-size-sm;
  }
}
</style>
