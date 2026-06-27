<template>
  <div class="create-task-config">
    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.taskTitle') }} <span class="required">*</span></label>
      <InputText
        v-model="title"
        fluid
        :placeholder="t('automation.fields.taskTitlePlaceholder')"
        :invalid="!!localErrors.title"
      />
      <small v-if="localErrors.title" class="field-error">{{ localErrors.title }}</small>
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
        :loading="usersLoading"
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
import { useUsersCache } from '@/composables/crm/useUsersCache'

const props = defineProps<{
  config: Record<string, unknown>
  errors: Record<string, string>
}>()

const emit = defineEmits<{
  'update:config': [v: Record<string, unknown>]
}>()

const { t } = useI18n()

const { users, loading: usersLoading, load: loadUsers } = useUsersCache()

onMounted(() => {
  // loadUsers is idempotent: singleton cache, safe to call from multiple components
  loadUsers()
})

// Parse the canonical `responsible` spec ('owner' | 'user_id:N') back into the
// wizard's assignee_type / user_id fields when re-hydrating a stored config.
function parseResponsible(spec: unknown): { assigneeType: 'owner' | 'user'; userId: number | null } {
  const s = typeof spec === 'string' ? spec.trim() : ''
  if (s.startsWith('user_id:')) {
    const id = Number.parseInt(s.slice('user_id:'.length), 10)
    return { assigneeType: 'user', userId: Number.isFinite(id) && id > 0 ? id : null }
  }
  return { assigneeType: 'owner', userId: null }
}

const initial = parseResponsible(props.config.responsible)

const title = ref<string>((props.config.title as string) ?? '')
const description = ref<string>((props.config.body as string) ?? '')
const assigneeType = ref<'owner' | 'user'>(initial.assigneeType)
const userId = ref<number | null>(initial.userId)
const dueDays = ref<number | null>((props.config.due_days as number | null) ?? null)

const localErrors = ref<Record<string, string>>({})

const assigneeOptions = computed(() => [
  { label: t('automation.fields.recipientOwner'), value: 'owner' },
  { label: t('automation.fields.recipientUser'), value: 'user' },
])

// Emit the canonical engine contract: body / responsible spec string / due_days.
// (The friendly assignee_type + user_id locals are folded into `responsible`.)
function buildConfig() {
  return {
    title: title.value,
    body: description.value || null,
    responsible: assigneeType.value === 'user' && userId.value ? `user_id:${userId.value}` : 'owner',
    due_days: dueDays.value,
  }
}

watch([title, description, assigneeType, userId, dueDays], () => {
  emit('update:config', buildConfig())
})

watch(
  () => props.config,
  (v) => {
    // Identity guard: only re-hydrate locals when the incoming config differs from
    // what we already have. Prevents the echo-cycle where our own emit('update:config')
    // is reflected back by the parent as a new prop object and restarts the emission.
    if (JSON.stringify(v) === JSON.stringify(buildConfig())) return
    title.value = (v.title as string) ?? ''
    description.value = (v.body as string) ?? ''
    const parsed = parseResponsible(v.responsible)
    assigneeType.value = parsed.assigneeType
    userId.value = parsed.userId
    dueDays.value = (v.due_days as number | null) ?? null
  },
  { deep: true },
)

function validate(): boolean {
  localErrors.value = {}
  if (!title.value.trim()) {
    localErrors.value.title = t('automation.errors.taskTitleRequired')
    return false
  }
  return true
}

defineExpose({ validate })
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
