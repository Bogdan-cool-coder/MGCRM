<template>
  <div class="generate-document-config">
    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.template') }} <span class="required">*</span></label>
      <Select
        v-model="templateCode"
        :options="templates"
        option-label="title"
        option-value="code"
        :placeholder="t('automation.fields.searchTemplate')"
        filter
        fluid
        :loading="templatesLoading"
        :invalid="!!localErrors.template_code"
      />
      <small v-if="localErrors.template_code" class="field-error">
        {{ localErrors.template_code }}
      </small>
    </div>

    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.attachTo') }}</label>
      <Select
        v-model="attachTo"
        :options="attachOptions"
        option-label="label"
        option-value="value"
        fluid
      />
    </div>

    <Message severity="warning" :closable="false" class="mt-2">
      {{ t('automation.fields.generateDocNote') }}
    </Message>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import Message from 'primevue/message'
import { useTemplatesCache } from '@/composables/crm/useTemplatesCache'

const props = defineProps<{
  config: Record<string, unknown>
  errors: Record<string, string>
}>()

const emit = defineEmits<{
  'update:config': [v: Record<string, unknown>]
}>()

const { t } = useI18n()

const { templates, loading: templatesLoading, load: loadTemplates } = useTemplatesCache()

onMounted(() => {
  loadTemplates()
})

const templateCode = ref<string>((props.config.template_code as string) ?? '')
const attachTo = ref<'deal' | 'company'>((props.config.attach_to as 'deal' | 'company') ?? 'deal')

const localErrors = ref<Record<string, string>>({})

const attachOptions = computed(() => [
  { label: t('automation.fields.attachToDeal'), value: 'deal' },
  { label: t('automation.fields.attachToCompany'), value: 'company' },
])

watch([templateCode, attachTo], () => {
  emit('update:config', { template_code: templateCode.value, attach_to: attachTo.value })
})

watch(
  () => props.config,
  (v) => {
    // Identity guard: skip re-hydration if incoming config equals our own last emit.
    if (JSON.stringify(v) === JSON.stringify({ template_code: templateCode.value, attach_to: attachTo.value })) return
    templateCode.value = (v.template_code as string) ?? ''
    attachTo.value = (v.attach_to as 'deal' | 'company') ?? 'deal'
  },
  { deep: true },
)

function validate(): boolean {
  localErrors.value = {}
  if (!templateCode.value) {
    localErrors.value.template_code = t('automation.errors.templateRequired')
    return false
  }
  return true
}

defineExpose({ validate })
</script>

<style lang="scss" scoped>
.generate-document-config {
  .field-label {
    display: block;
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    margin-bottom: $space-1;
  }

  .field-error {
    display: block;
    color: var(--p-red-500);
    font-size: $font-size-xs;
    margin-top: $space-1;
  }

  .required {
    color: var(--p-red-500);
    margin-left: 2px;
  }
}
</style>
