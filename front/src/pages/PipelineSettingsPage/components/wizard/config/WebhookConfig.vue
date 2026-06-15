<template>
  <div class="webhook-config">
    <Message severity="info" :closable="false" class="mb-3">
      <i class="pi pi-info-circle me-2" />
      {{ t('automation.fields.webhookAdminNote') }}
    </Message>

    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.webhookUrl') }} <span class="required">*</span></label>
      <InputText
        v-model="url"
        type="url"
        fluid
        placeholder="https://..."
        :invalid="!!errors['action_config.url'] || !!localErrors.url"
      />
      <small v-if="errors['action_config.url']" class="field-error">{{ errors['action_config.url'] }}</small>
      <small v-else-if="localErrors.url" class="field-error">{{ localErrors.url }}</small>
      <small class="field-hint">{{ t('automation.fields.webhookUrlNote') }}</small>
    </div>

    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.webhookSecret') }}</label>
      <InputText
        v-model="secret"
        :type="showSecret ? 'text' : 'password'"
        fluid
        :placeholder="t('automation.fields.webhookSecretPlaceholder')"
      />
      <div class="mt-1">
        <Button
          :label="showSecret ? t('common.hide') : t('common.show')"
          link
          size="small"
          @click="showSecret = !showSecret"
        />
      </div>
      <small class="field-hint">{{ t('automation.fields.webhookSecretNote') }}</small>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Button from 'primevue/button'

const props = defineProps<{
  config: Record<string, unknown>
  errors: Record<string, string>
}>()

const emit = defineEmits<{
  'update:config': [v: Record<string, unknown>]
}>()

const { t } = useI18n()

const url = ref<string>((props.config.url as string) ?? '')
const secret = ref<string>((props.config.secret as string) ?? '')
const showSecret = ref(false)
const localErrors = ref<Record<string, string>>({})

watch([url, secret], () => {
  emit('update:config', { url: url.value, secret: secret.value || null })
})

watch(
  () => props.config,
  (v) => {
    // Identity guard: skip re-hydration if incoming config equals our own last emit.
    if (JSON.stringify(v) === JSON.stringify({ url: url.value, secret: secret.value || null })) return
    url.value = (v.url as string) ?? ''
    secret.value = (v.secret as string) ?? ''
  },
  { deep: true },
)

function validate(): boolean {
  localErrors.value = {}
  if (!url.value.trim()) {
    localErrors.value.url = t('automation.errors.webhookUrlRequired')
    return false
  }
  return true
}

defineExpose({ validate })
</script>

<style lang="scss" scoped>
.webhook-config {
  .field-label {
    display: block;
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    margin-bottom: $space-1;
  }

  .field-hint {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
    display: block;
    margin-top: $space-1;
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
