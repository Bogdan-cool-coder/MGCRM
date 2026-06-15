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
        :invalid="!!errors.message"
      />
      <small v-if="errors.message" class="field-error">{{ errors.message }}</small>
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
const textareaRef = ref<{ $el?: HTMLElement } | null>(null)

onMounted(async () => {
  try {
    users.value = await usersApi.getUsers()
  } catch {
    // non-critical
  }
})

const PLACEHOLDERS = ['{target_id}', '{target_type}', '{target_title}', '{owner_name}']

const recipientType = ref<'owner' | 'user' | 'chat_id'>(
  (props.config.recipient_type as 'owner' | 'user' | 'chat_id') ?? 'owner',
)
const userId = ref<number | null>((props.config.user_id as number | null) ?? null)
const chatId = ref<string>((props.config.chat_id as string) ?? '')
const message = ref<string>((props.config.message as string) ?? '')

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

function buildConfig() {
  const cfg: Record<string, unknown> = {
    recipient_type: recipientType.value,
    message: message.value,
  }
  if (recipientType.value === 'user') cfg.user_id = userId.value
  if (recipientType.value === 'chat_id') cfg.chat_id = chatId.value
  return cfg
}

watch([recipientType, userId, chatId, message], () => {
  emit('update:config', buildConfig())
})

watch(
  () => props.config,
  (v) => {
    recipientType.value = (v.recipient_type as 'owner' | 'user' | 'chat_id') ?? 'owner'
    userId.value = (v.user_id as number | null) ?? null
    chatId.value = (v.chat_id as string) ?? ''
    message.value = (v.message as string) ?? ''
  },
  { deep: true },
)
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
