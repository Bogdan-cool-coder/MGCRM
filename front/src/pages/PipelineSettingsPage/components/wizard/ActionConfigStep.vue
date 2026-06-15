<template>
  <div class="action-config-step">
    <!-- Name field (all actions) -->
    <div class="mb-4">
      <label class="field-label">{{ t('automation.fields.name') }} <span class="required">*</span></label>
      <InputText
        v-model="localName"
        fluid
        :placeholder="t('automation.fields.namePlaceholder')"
        :invalid="!!errors.name"
        @blur="validateName"
      />
      <small v-if="errors.name" class="field-error">{{ errors.name }}</small>
    </div>

    <!-- Per-action config -->
    <component
      :is="configComponent"
      v-if="configComponent"
      v-model:config="localConfig"
      :errors="errors"
      :stages="stages"
      :stage-id="stageId"
      @update:errors="(e: Record<string, string>) => Object.assign(errors, e)"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, defineAsyncComponent } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import type { ActionKind } from '@/entities/automation'
import type { PipelineStageDto } from '@/entities/sales'

const props = defineProps<{
  actionKind: ActionKind
  modelName: string
  modelConfig: Record<string, unknown>
  stages: PipelineStageDto[]
  stageId: number | null
}>()

const emit = defineEmits<{
  'update:modelName': [v: string]
  'update:modelConfig': [v: Record<string, unknown>]
  'update:valid': [v: boolean]
}>()

const { t } = useI18n()

const localName = ref(props.modelName)
const localConfig = ref<Record<string, unknown>>({ ...props.modelConfig })
const errors = ref<Record<string, string>>({})

watch(localName, (v) => emit('update:modelName', v))
watch(localConfig, (v) => emit('update:modelConfig', { ...v }), { deep: true })

watch(
  () => props.modelName,
  (v) => {
    if (v !== localName.value) localName.value = v
  },
)
watch(
  () => props.modelConfig,
  (v) => {
    localConfig.value = { ...v }
  },
  { deep: true },
)

// ─── Validation ───────────────────────────────────────────────────────────────

function validateName() {
  if (!localName.value.trim()) {
    errors.value.name = t('automation.errors.nameRequired')
  } else {
    delete errors.value.name
  }
}

// ─── Sub-components per action_kind ──────────────────────────────────────────

const CONFIG_COMPONENTS: Record<ActionKind, ReturnType<typeof defineAsyncComponent>> = {
  tg_notify: defineAsyncComponent(() => import('./config/TgNotifyConfig.vue')),
  create_task: defineAsyncComponent(() => import('./config/CreateTaskConfig.vue')),
  set_field: defineAsyncComponent(() => import('./config/SetFieldConfig.vue')),
  generate_document: defineAsyncComponent(() => import('./config/GenerateDocumentConfig.vue')),
  change_owner: defineAsyncComponent(() => import('./config/ChangeOwnerConfig.vue')),
  change_stage: defineAsyncComponent(() => import('./config/ChangeStageConfig.vue')),
  webhook: defineAsyncComponent(() => import('./config/WebhookConfig.vue')),
  email: defineAsyncComponent(() => import('./config/EmailConfig.vue')),
}

const configComponent = computed(() => CONFIG_COMPONENTS[props.actionKind] ?? null)

// ─── Expose validate for parent ───────────────────────────────────────────────

function validate(): boolean {
  validateName()
  return Object.keys(errors.value).length === 0
}

defineExpose({ validate })
</script>

<style lang="scss" scoped>
.action-config-step {
  .field-label {
    display: block;
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    margin-bottom: $space-1;
  }

  .required {
    color: var(--p-red-500);
    margin-left: 2px;
  }

  .field-error {
    display: block;
    color: var(--p-red-500);
    font-size: $font-size-xs;
    margin-top: $space-1;
  }
}
</style>
