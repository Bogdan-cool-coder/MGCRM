<template>
  <div class="email-config">
    <Message severity="warning" :closable="false" class="mb-3">
      {{ t('automation.fields.emailMvpNote') }}
    </Message>

    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.emailTo') }} <span class="required">*</span></label>
      <Select
        v-model="recipientType"
        :options="recipientOptions"
        option-label="label"
        option-value="value"
        fluid
      />
    </div>

    <div v-if="recipientType === 'manual'" class="mb-3">
      <label class="field-label">{{ t('automation.fields.emailAddress') }} <span class="required">*</span></label>
      <InputText v-model="to" type="email" fluid :placeholder="t('automation.fields.emailAddressPlaceholder')" />
    </div>

    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.emailSubject') }} <span class="required">*</span></label>
      <InputText
        v-model="subject"
        fluid
        :placeholder="t('automation.fields.emailSubjectPlaceholder')"
        :invalid="!!errors['action_config.subject']"
      />
      <small v-if="errors['action_config.subject']" class="field-error">{{ errors['action_config.subject'] }}</small>
    </div>

    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.emailBody') }} <span class="required">*</span></label>
      <Textarea
        v-model="body"
        rows="6"
        fluid
        :invalid="!!errors['action_config.body']"
      />
      <small v-if="errors['action_config.body']" class="field-error">{{ errors['action_config.body'] }}</small>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Message from 'primevue/message'

const props = defineProps<{
  config: Record<string, unknown>
  errors: Record<string, string>
}>()

const emit = defineEmits<{
  'update:config': [v: Record<string, unknown>]
}>()

const { t } = useI18n()

const recipientType = ref<'owner' | 'manual'>((props.config.recipient_type as 'owner' | 'manual') ?? 'owner')
const to = ref<string>((props.config.to as string) ?? '')
const subject = ref<string>((props.config.subject as string) ?? '')
const body = ref<string>((props.config.body as string) ?? '')

const recipientOptions = computed(() => [
  { label: t('automation.fields.recipientOwner'), value: 'owner' },
  { label: t('automation.fields.emailManual'), value: 'manual' },
])

watch([recipientType, to, subject, body], () => {
  const cfg: Record<string, unknown> = {
    recipient_type: recipientType.value,
    subject: subject.value,
    body: body.value,
  }
  if (recipientType.value === 'manual') cfg.to = to.value
  emit('update:config', cfg)
})

watch(
  () => props.config,
  (v) => {
    recipientType.value = (v.recipient_type as 'owner' | 'manual') ?? 'owner'
    to.value = (v.to as string) ?? ''
    subject.value = (v.subject as string) ?? ''
    body.value = (v.body as string) ?? ''
  },
  { deep: true },
)
</script>

<style lang="scss" scoped>
.email-config {
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
