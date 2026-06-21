<template>
  <Drawer
    v-model:visible="visible"
    position="right"
    style="width: 680px"
    :show-close-icon="false"
    @hide="emit('update:modelValue', false)"
  >
    <template #header>
      <div class="d-flex align-items-center justify-content-between w-100 gap-2">
        <span class="fw-semibold">{{ t('messageTemplates.drawer.title') }}</span>
        <Button
          icon="pi pi-times"
          severity="secondary"
          text
          rounded
          size="small"
          :aria-label="t('common.close')"
          @click="visible = false"
        />
      </div>
    </template>

    <div class="mt-drawer">
      <!-- Name -->
      <div class="mb-3">
        <label class="mt-drawer__label">{{ t('messageTemplates.drawer.name') }} *</label>
        <InputText v-model="form.title" class="w-100 mt-1" :invalid="!!errors.title" />
        <small v-if="errors.title" class="p-error">{{ errors.title }}</small>
      </div>

      <!-- Subject -->
      <div class="mb-3">
        <label class="mt-drawer__label">{{ t('messageTemplates.drawer.subject') }}</label>
        <InputText v-model="form.subject" class="w-100 mt-1" />
      </div>

      <!-- Active toggle -->
      <div class="mb-3 d-flex align-items-center gap-2">
        <ToggleSwitch v-model="form.is_active" />
        <label class="mt-drawer__label mb-0">{{ t('messageTemplates.drawer.isActive') }}</label>
      </div>

      <!-- Body -->
      <div class="mb-3">
        <label class="mt-drawer__label">{{ t('messageTemplates.drawer.body') }} *</label>
        <Textarea
          v-model="form.body"
          :auto-resize="true"
          :rows="8"
          class="w-100 mt-1 mt-drawer__monospace"
          :invalid="!!errors.body"
        />
        <small v-if="errors.body" class="p-error">{{ errors.body }}</small>
        <small class="text-secondary d-block mt-1">{{ t('messageTemplates.drawer.catalogHint') }}</small>
      </div>

      <Divider />

      <!-- Preview -->
      <div class="mb-3">
        <p class="fw-semibold mb-2">{{ t('messageTemplates.drawer.preview') }}</p>
        <!-- Inputs for unresolved keys -->
        <div v-if="previewVarKeys.length > 0" class="mb-2">
          <div
            v-for="key in previewVarKeys"
            :key="key"
            class="mt-drawer__preview-var mb-2"
          >
            <label class="mt-drawer__label-sm">{{ fmtKey(key) }}</label>
            <InputText
              v-model="previewVars[key]"
              size="small"
              class="w-100 mt-1"
            />
          </div>
        </div>
        <Button
          :label="t('messageTemplates.drawer.renderBtn')"
          size="small"
          severity="secondary"
          outlined
          :loading="previewing"
          @click="renderPreview"
        />
        <div v-if="previewResult" class="mt-drawer__preview-block mt-2">
          <div v-if="previewResult.subject" class="mt-drawer__preview-subject">
            <span class="fw-medium">{{ t('messageTemplates.drawer.subject') }}:</span>
            {{ previewResult.subject }}
          </div>
          <div class="mt-drawer__preview-body mt-1">{{ previewResult.body }}</div>
        </div>
        <Message
          v-if="previewResult && previewResult.unresolved_keys.length > 0"
          severity="warn"
          :closable="false"
          class="mt-2"
        >
          {{ t('messageTemplates.drawer.unresolvedKeys') }}:
          <Tag
            v-for="k in previewResult.unresolved_keys"
            :key="k"
            severity="danger"
            :value="'{{' + k + '}}'"
            class="ms-1"
          />
        </Message>
      </div>

      <Divider />

      <!-- Bindings list -->
      <div class="mb-3">
        <p class="fw-semibold mb-2">{{ t('messageTemplates.drawer.bindings') }}</p>
        <div v-if="form.bindings.length > 0" class="d-flex flex-wrap gap-2 mb-2">
          <MessageTemplateBindingChip
            v-for="b in form.bindings"
            :key="b.id"
            :binding="b"
            @remove="removeBinding(b.id)"
          />
        </div>
        <div v-else class="text-secondary mb-2 mt-drawer__bindings-empty">—</div>

        <!-- Add binding inline form -->
        <div class="mt-drawer__binding-form">
          <Button
            v-if="!bindingFormOpen"
            :label="t('messageTemplates.drawer.addBinding')"
            icon="pi pi-plus"
            text
            severity="secondary"
            size="small"
            @click="bindingFormOpen = true"
          />
          <div v-else class="mt-drawer__binding-fields">
            <div class="row g-2 mb-2">
              <div class="col-md-4">
                <label class="mt-drawer__label-sm">{{ t('messageTemplates.drawer.bindingChannel') }}</label>
                <Select
                  v-model="bindingForm.channel_kind"
                  :options="channelOptions"
                  option-label="label"
                  option-value="value"
                  show-clear
                  class="w-100 mt-1"
                  :placeholder="t('messageTemplates.drawer.bindingChannel')"
                />
              </div>
              <div class="col-md-4">
                <label class="mt-drawer__label-sm">{{ t('messageTemplates.drawer.bindingPipeline') }}</label>
                <Select
                  v-model="bindingForm.pipeline_id"
                  :options="pipelineOptions"
                  option-label="label"
                  option-value="value"
                  show-clear
                  class="w-100 mt-1"
                  :placeholder="t('messageTemplates.drawer.bindingPipeline')"
                  @change="onPipelineChange"
                />
              </div>
              <div class="col-md-4">
                <label class="mt-drawer__label-sm">{{ t('messageTemplates.drawer.bindingStage') }}</label>
                <Select
                  v-model="bindingForm.pipeline_stage_id"
                  :options="stageOptions"
                  option-label="label"
                  option-value="value"
                  show-clear
                  class="w-100 mt-1"
                  :placeholder="t('messageTemplates.drawer.bindingStage')"
                  :disabled="!bindingForm.pipeline_id"
                />
              </div>
              <div class="col-md-6">
                <label class="mt-drawer__label-sm">{{ t('messageTemplates.drawer.bindingActivityType') }}</label>
                <Select
                  v-model="bindingForm.activity_type"
                  :options="activityTypeOptions"
                  option-label="label"
                  option-value="value"
                  show-clear
                  class="w-100 mt-1"
                  :placeholder="t('messageTemplates.drawer.bindingActivityType')"
                />
              </div>
              <div class="col-md-6">
                <label class="mt-drawer__label-sm">{{ t('messageTemplates.drawer.bindingSlot') }}</label>
                <InputText
                  v-model="bindingForm.automation_slot"
                  class="w-100 mt-1"
                  :placeholder="t('messageTemplates.drawer.bindingSlot')"
                />
              </div>
            </div>
            <div class="d-flex gap-2">
              <Button
                :label="t('messageTemplates.drawer.addBinding')"
                size="small"
                :loading="addingBinding"
                @click="submitBinding"
              />
              <Button
                :label="t('common.cancel')"
                size="small"
                severity="secondary"
                text
                @click="bindingFormOpen = false"
              />
            </div>
          </div>
        </div>
      </div>
    </div>

    <template #footer>
      <div class="d-flex gap-2 justify-content-end">
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          text
          @click="visible = false"
        />
        <Button
          :label="t('common.save')"
          :loading="saving"
          @click="save"
        />
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
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import Divider from 'primevue/divider'
import { useToast } from 'primevue/usetoast'
import { messageTemplatesApi } from '@/api/messageTemplates'
import { salesApi } from '@/api/sales'
import MessageTemplateBindingChip from './MessageTemplateBindingChip.vue'
import type {
  MessageTemplateBindingDto,
  MessageChannel,
  ActivityTypeBinding,
} from '@/entities/messageTemplate'

function fmtKey(key: string): string {
  return '{{' + key + '}}'
}

const props = defineProps<{
  modelValue: boolean
  templateId: number | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: []
}>()

const { t } = useI18n()
const toast = useToast()
const saving = ref(false)
const previewing = ref(false)
const addingBinding = ref(false)
const bindingFormOpen = ref(false)

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

// ─── Form ─────────────────────────────────────────────────────────────────────
interface FormState {
  title: string
  subject: string
  body: string
  is_active: boolean
  bindings: MessageTemplateBindingDto[]
}

const form = ref<FormState>({
  title: '',
  subject: '',
  body: '',
  is_active: true,
  bindings: [],
})

const errors = ref<Record<string, string>>({})

// ─── Preview ──────────────────────────────────────────────────────────────────
const previewVarKeys = computed<string[]>(() => {
  const matches = form.value.body.matchAll(/\{\{(\w+)\}\}/g)
  const keys = new Set<string>()
  for (const m of matches) keys.add(m[1] ?? '')
  return [...keys].filter(Boolean)
})

const previewVars = ref<Record<string, string>>({})

interface PreviewResult {
  subject: string | null
  body: string
  unresolved_keys: string[]
}

const previewResult = ref<PreviewResult | null>(null)

async function renderPreview() {
  if (!props.templateId) return
  previewing.value = true
  try {
    const res = await messageTemplatesApi.previewMessageTemplate(props.templateId, { vars: previewVars.value })
    previewResult.value = res as PreviewResult
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  } finally {
    previewing.value = false
  }
}

// ─── Binding form ─────────────────────────────────────────────────────────────
interface BindingForm {
  channel_kind: MessageChannel | null
  pipeline_id: number | null
  pipeline_stage_id: number | null
  activity_type: ActivityTypeBinding | null
  automation_slot: string
}

const bindingForm = ref<BindingForm>({
  channel_kind: null,
  pipeline_id: null,
  pipeline_stage_id: null,
  activity_type: null,
  automation_slot: '',
})

const channelOptions = [
  { label: 'Telegram', value: 'tg' as MessageChannel },
  { label: 'WhatsApp', value: 'wa' as MessageChannel },
  { label: 'Email', value: 'email' as MessageChannel },
  { label: 'Web Form', value: 'web_form' as MessageChannel },
  { label: 'API', value: 'api' as MessageChannel },
]

const activityTypeOptions = [
  { label: t('activity.kinds.call', 'Звонок'), value: 'call' as ActivityTypeBinding },
  { label: t('activity.kinds.meeting', 'Встреча'), value: 'meeting' as ActivityTypeBinding },
  { label: t('activity.kinds.task', 'Задача'), value: 'task' as ActivityTypeBinding },
  { label: t('activity.kinds.note', 'Заметка'), value: 'note' as ActivityTypeBinding },
]

interface SelectOption { label: string; value: number }

const pipelineOptions = ref<SelectOption[]>([])
const stageOptions = ref<SelectOption[]>([])

async function loadPipelines() {
  try {
    const pipelines = await salesApi.getPipelines()
    pipelineOptions.value = pipelines.map((p) => ({ label: p.name, value: p.id }))
  } catch {
    // non-critical
  }
}

async function onPipelineChange() {
  bindingForm.value.pipeline_stage_id = null
  stageOptions.value = []
  if (!bindingForm.value.pipeline_id) return
  try {
    const stages = await salesApi.getPipelineStages(bindingForm.value.pipeline_id)
    stageOptions.value = stages.map((s) => ({ label: s.name, value: s.id }))
  } catch {
    // non-critical
  }
}

async function submitBinding() {
  if (!props.templateId) return
  addingBinding.value = true
  try {
    const binding = await messageTemplatesApi.addTemplateBinding(props.templateId, {
      channel_kind: bindingForm.value.channel_kind,
      pipeline_id: bindingForm.value.pipeline_id,
      pipeline_stage_id: bindingForm.value.pipeline_stage_id,
      activity_type: bindingForm.value.activity_type,
      automation_slot: bindingForm.value.automation_slot || null,
    })
    form.value.bindings.push(binding)
    bindingFormOpen.value = false
    bindingForm.value = { channel_kind: null, pipeline_id: null, pipeline_stage_id: null, activity_type: null, automation_slot: '' }
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  } finally {
    addingBinding.value = false
  }
}

async function removeBinding(bindingId: number) {
  if (!props.templateId) return
  try {
    await messageTemplatesApi.deleteTemplateBinding(props.templateId, bindingId)
    form.value.bindings = form.value.bindings.filter((b) => b.id !== bindingId)
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  }
}

// ─── Load data ────────────────────────────────────────────────────────────────
watch(
  () => props.modelValue,
  async (open) => {
    if (!open) {
      previewResult.value = null
      bindingFormOpen.value = false
      errors.value = {}
      return
    }
    void loadPipelines()
    if (props.templateId) {
      try {
        const tpl = await messageTemplatesApi.getMessageTemplate(props.templateId)
        form.value = {
          title: tpl.title,
          subject: tpl.subject ?? '',
          body: tpl.body,
          is_active: tpl.is_active,
          bindings: [...(tpl.bindings ?? [])],
        }
      } catch {
        toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
      }
    } else {
      form.value = { title: '', subject: '', body: '', is_active: true, bindings: [] }
    }
  },
)

// ─── Save ─────────────────────────────────────────────────────────────────────
async function save() {
  errors.value = {}
  if (!form.value.title.trim()) {
    errors.value.title = t('errors.required', 'Обязательное поле')
    return
  }
  if (!form.value.body.trim()) {
    errors.value.body = t('errors.required', 'Обязательное поле')
    return
  }
  saving.value = true
  try {
    const payload = {
      title: form.value.title,
      subject: form.value.subject || null,
      body: form.value.body,
      is_active: form.value.is_active,
    }
    if (props.templateId) {
      await messageTemplatesApi.patchMessageTemplate(props.templateId, payload)
    } else {
      await messageTemplatesApi.createMessageTemplate(payload)
    }
    emit('saved')
    visible.value = false
    toast.add({ severity: 'success', summary: t('common.saved', 'Сохранено'), life: 2000 })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  } finally {
    saving.value = false
  }
}
</script>

<style lang="scss" scoped>
.mt-drawer {
  padding: 0.5rem 0;

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }

  &__label-sm {
    font-size: $font-size-xs;
    font-weight: $font-weight-medium;
    color: var(--p-text-muted-color);
    display: block;
  }

  &__monospace {
    font-family: $font-family-mono;
    font-size: $font-size-sm;
  }

  &__preview-block {
    background: var(--p-surface-50);
    border: 1px solid var(--p-surface-200);
    border-radius: $radius-md;
    padding: 0.75rem;
  }

  &__preview-subject {
    font-size: $font-size-sm;
    color: var(--p-text-muted-color);
  }

  &__preview-body {
    font-size: $font-size-sm;
    color: var(--p-text-color);
    white-space: pre-wrap;
  }

  &__preview-var {
    display: flex;
    flex-direction: column;
  }

  &__binding-form {
    margin-top: 0.25rem;
  }

  &__binding-fields {
    border: 1px solid var(--p-surface-200);
    border-radius: $radius-md;
    padding: 0.75rem;
    background: var(--p-surface-50);
  }

  &__bindings-empty {
    font-size: $font-size-sm; // snap from 0.85rem (~13.6px → 14px)
  }
}
</style>
