<template>
  <div class="create-task-config">
    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.taskTitle') }} <span class="required">*</span></label>
      <InputText
        v-model="title"
        fluid
        :placeholder="t('automation.fields.taskTitlePlaceholder')"
        :invalid="!!errors['action_config.title']"
      />
      <small v-if="errors['action_config.title']" class="field-error">{{ errors['action_config.title'] }}</small>
    </div>

    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.taskDesc') }}</label>
      <Textarea v-model="description" rows="3" fluid />
    </div>

    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.recipient') }}</label>
      <Select
        v-model="assigneeType"
        :options="assigneeOptions"
        option-label="label"
        option-value="value"
        fluid
      />
    </div>

    <div v-if="assigneeType === 'user'" class="mb-3">
      <label class="field-label">{{ t('automation.fields.recipientUser') }}</label>
      <Select
        v-model="userId"
        :options="users"
        option-label="full_name"
        option-value="id"
        :placeholder="t('automation.fields.searchUser')"
        filter
        fluid
      />
    </div>

    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.dueDays') }}</label>
      <InputNumber
        v-model="dueDays"
        :min="0"
        :max="365"
        fluid
        :placeholder="t('automation.fields.dueDaysPlaceholder')"
      />
      <small class="field-hint">{{ t('automation.fields.dueDaysHint') }}</small>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import { usersApi } from '@/api/users'

const props = defineProps<{
  config: Record<string, unknown>
  errors: Record<string, string>
}>()

const emit = defineEmits<{
  'update:config': [v: Record<string, unknown>]
}>()

const { t } = useI18n()

interface UserOption {
  id: number
  full_name: string
}
const users = ref<UserOption[]>([])

onMounted(async () => {
  try {
    users.value = await usersApi.getUsers()
  } catch {
    // non-critical
  }
})

const title = ref<string>((props.config.title as string) ?? '')
const description = ref<string>((props.config.description as string) ?? '')
const assigneeType = ref<'owner' | 'user'>((props.config.assignee_type as 'owner' | 'user') ?? 'owner')
const userId = ref<number | null>((props.config.user_id as number | null) ?? null)
const dueDays = ref<number | null>((props.config.due_days as number | null) ?? null)

const assigneeOptions = computed(() => [
  { label: t('automation.fields.recipientOwner'), value: 'owner' },
  { label: t('automation.fields.recipientUser'), value: 'user' },
])

function buildConfig() {
  const cfg: Record<string, unknown> = {
    title: title.value,
    description: description.value || null,
    assignee_type: assigneeType.value,
    due_days: dueDays.value,
  }
  if (assigneeType.value === 'user') cfg.user_id = userId.value
  return cfg
}

watch([title, description, assigneeType, userId, dueDays], () => {
  emit('update:config', buildConfig())
})

watch(
  () => props.config,
  (v) => {
    title.value = (v.title as string) ?? ''
    description.value = (v.description as string) ?? ''
    assigneeType.value = (v.assignee_type as 'owner' | 'user') ?? 'owner'
    userId.value = (v.user_id as number | null) ?? null
    dueDays.value = (v.due_days as number | null) ?? null
  },
  { deep: true },
)
</script>

<style lang="scss" scoped>
.create-task-config {
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
