<template>
  <div class="tg-notify-config">
    <!-- Recipient type -->
    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.recipient') }} <span class="required">*</span></label>
      <Select
        v-model="recipientType"
        :options="recipientOptions"
        option-label="label"
        option-value="value"
        fluid
        @change="clearRecipientFields"
      />
    </div>

    <!-- user_id select -->
    <div v-if="recipientType === 'user'" class="mb-3">
      <label class="field-label">{{ t('automation.fields.recipientUser') }} <span class="required">*</span></label>
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

    <!-- chat_id input -->
    <div v-if="recipientType === 'chat_id'" class="mb-3">
      <label class="field-label">{{ t('automation.fields.tgChatId') }} <span class="required">*</span></label>
      <InputText v-model="chatId" type="number" fluid />
    </div>

    <!-- Message -->
    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.message') }} <span class="required">*</span></label>
      <Textarea
        ref="textareaRef"
        v-model="message"
        rows="4"
        fluid
        :placeholder="t('automation.fields.messagePlaceholder')"
        :invalid="!!localErrors.message"
      />
      <small v-if="localErrors.message" class="field-error">{{ localErrors.message }}</small>
    </div>

    <!-- Placeholder chips -->
    <div class="mb-2">
      <span class="field-hint mb-2">{{ t('automation.fields.placeholders') }}:</span>
      <div class="d-flex flex-wrap gap-1 mt-1">
        <Chip
          v-for="p in PLACEHOLDERS"
          :key="p"
          :label="p"
          class="placeholder-chip"
          @click="insertPlaceholder(p)"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Chip from 'primevue/chip'
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
const textareaRef = ref<{ $el?: HTMLElement } | null>(null)

onMounted(() => {
  loadUsers()
})

const PLACEHOLDERS = ['{target_id}', '{target_type}', '{target_title}', '{owner_name}']

// Parse the canonical `recipient` spec ('owner' | 'user_id:N' | 'chat_id:X')
// back into the wizard's type / user / chat fields when re-hydrating a config.
function parseRecipient(spec: unknown): {
  type: 'owner' | 'user' | 'chat_id'
  userId: number | null
  chatId: string
} {
  const s = typeof spec === 'string' ? spec.trim() : ''
  if (s.startsWith('user_id:')) {
    const id = Number.parseInt(s.slice('user_id:'.length), 10)
    return { type: 'user', userId: Number.isFinite(id) && id > 0 ? id : null, chatId: '' }
  }
  if (s.startsWith('chat_id:')) {
    return { type: 'chat_id', userId: null, chatId: s.slice('chat_id:'.length) }
  }
  return { type: 'owner', userId: null, chatId: '' }
}

const initial = parseRecipient(props.config.recipient)

const recipientType = ref<'owner' | 'user' | 'chat_id'>(initial.type)
const userId = ref<number | null>(initial.userId)
const chatId = ref<string>(initial.chatId)
const message = ref<string>((props.config.message as string) ?? '')

const localErrors = ref<Record<string, string>>({})

const recipientOptions = computed(() => [
  { label: t('automation.fields.recipientOwner'), value: 'owner' },
  { label: t('automation.fields.recipientUser'), value: 'user' },
  { label: t('automation.fields.recipientChatId'), value: 'chat_id' },
])

function clearRecipientFields() {
  userId.value = null
  chatId.value = ''
}

function insertPlaceholder(p: string) {
  message.value = message.value + p
}

// Emit the single canonical `recipient` spec string the engine resolves, folding
// the friendly type/user/chat locals into it: owner → 'owner', user → 'user_id:N',
// chat_id → 'chat_id:X'. recipient_type/user_id/chat_id are NOT sent separately.
function buildRecipient(): string {
  if (recipientType.value === 'user') {
    return userId.value ? `user_id:${userId.value}` : 'owner'
  }
  if (recipientType.value === 'chat_id') {
    return chatId.value.trim() ? `chat_id:${chatId.value.trim()}` : 'owner'
  }
  return 'owner'
}

function buildConfig() {
  return {
    recipient: buildRecipient(),
    message: message.value,
  }
}

watch([recipientType, userId, chatId, message], () => {
  emit('update:config', buildConfig())
})

watch(
  () => props.config,
  (v) => {
    // Identity guard: skip re-hydration if incoming config equals our own last emit.
    if (JSON.stringify(v) === JSON.stringify(buildConfig())) return
    const parsed = parseRecipient(v.recipient)
    recipientType.value = parsed.type
    userId.value = parsed.userId
    chatId.value = parsed.chatId
    message.value = (v.message as string) ?? ''
  },
  { deep: true },
)

function validate(): boolean {
  localErrors.value = {}
  if (!message.value.trim()) {
    localErrors.value.message = t('automation.errors.messageRequired')
    return false
  }
  return true
}

defineExpose({ validate })
</script>

<style lang="scss" scoped>
.tg-notify-config {
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

  .placeholder-chip {
    cursor: pointer;
    font-size: $font-size-xs;

    &:hover {
      background-color: var(--p-primary-100);
    }
  }
}
</style>
