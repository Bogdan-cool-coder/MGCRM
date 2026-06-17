<template>
  <Dialog
    v-model:visible="visible"
    :header="t('sales.deal.addChannel.title')"
    modal
    style="width: 28rem"
    @hide="onHide"
  >
    <div class="add-channel-dialog__body">
      <!-- Channel type -->
      <div class="add-channel-dialog__field">
        <label class="add-channel-dialog__label">{{ t('sales.deal.addChannel.channelType') }} *</label>
        <div class="add-channel-dialog__type-buttons">
          <button
            v-for="opt in channelTypeOptions"
            :key="opt.value"
            type="button"
            class="add-channel-dialog__type-btn"
            :class="{ 'add-channel-dialog__type-btn--active': form.channelType === opt.value }"
            @click="form.channelType = opt.value"
          >
            <i :class="['pi', opt.icon]" />
            <span>{{ opt.label }}</span>
          </button>
        </div>
      </div>

      <!-- Value -->
      <div class="add-channel-dialog__field">
        <label class="add-channel-dialog__label">{{ t('sales.deal.addChannel.value') }} *</label>
        <InputText
          v-model="form.value"
          :placeholder="channelPlaceholder"
          :invalid="!!errors.value"
          fluid
          @keydown.enter="submit"
        />
        <small v-if="errors.value" class="add-channel-dialog__error">{{ errors.value }}</small>
        <small v-else-if="channelHint" class="add-channel-dialog__hint">{{ channelHint }}</small>
      </div>
    </div>

    <template #footer>
      <Button
        :label="t('common.cancel')"
        severity="secondary"
        text
        @click="visible = false"
      />
      <Button
        :label="t('sales.deal.addChannel.submit')"
        icon="pi pi-plus"
        :loading="saving"
        :disabled="!form.channelType || !form.value.trim()"
        @click="submit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import { contactsApi } from '@/api/crm/contacts'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorMessage } from '@/utils/errors'
import type { ContactChannel } from '@/entities/crm'

interface ChannelOption {
  label: string
  value: string
  icon: string
}

const props = defineProps<{
  contactId: number
}>()

const emit = defineEmits<{
  added: [channel: ContactChannel]
}>()

const visible = defineModel<boolean>({ required: true })

const { t } = useI18n()
const toast = useToast()

const form = ref({
  channelType: 'tg' as string,
  value: '',
})

const errors = ref<{ value?: string }>({})

const channelTypeOptions: ChannelOption[] = [
  { label: 'TG', value: 'tg', icon: 'pi-send' },
  { label: 'WA', value: 'wa', icon: 'pi-whatsapp' },
  { label: 'LI', value: 'linkedin', icon: 'pi-linkedin' },
  { label: 'Email', value: 'email', icon: 'pi-envelope' },
  { label: 'Тел.', value: 'phone', icon: 'pi-phone' },
]

const channelPlaceholder = computed((): string => {
  const key = `deal.addChannel.placeholders.${form.value.channelType}`
  const val = t(key)
  return val === key ? '' : val
})

const channelHint = computed((): string => {
  const hints: Record<string, string> = {
    tg: t('sales.deal.addChannel.placeholders.tg'),
    wa: t('sales.deal.addChannel.placeholders.wa'),
    linkedin: t('sales.deal.addChannel.placeholders.linkedin'),
    email: t('sales.deal.addChannel.placeholders.email'),
    phone: t('sales.deal.addChannel.placeholders.phone'),
  }
  return hints[form.value.channelType] ?? ''
})

const addMutation = useMutation<ContactChannel>()
const saving = computed(() => addMutation.isPending.value)

watch(
  () => form.value.channelType,
  () => {
    form.value.value = ''
    errors.value = {}
  },
)

function onHide() {
  form.value = { channelType: 'tg', value: '' }
  errors.value = {}
}

async function submit() {
  errors.value = {}
  if (!form.value.value.trim()) {
    errors.value.value = t('errors.required')
    return
  }

  try {
    const channel = await addMutation.run(() =>
      contactsApi.addChannel(props.contactId, {
        channel_type: form.value.channelType,
        value: form.value.value.trim(),
      }),
    )
    emit('added', channel)
    toast.add({
      severity: 'success',
      summary: t('sales.deal.addChannel.success'),
      life: 3000,
    })
    visible.value = false
  } catch (err: unknown) {
    // 422 duplicate — show inline error
    const status = (err as { response?: { status?: number; data?: { errors?: Record<string, string[]> } } })?.response?.status
    if (status === 422) {
      const apiErrors = (err as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors
      if (apiErrors?.value) {
        errors.value.value = apiErrors.value[0] ?? t('errors.duplicate')
      } else {
        errors.value.value = t('errors.duplicate')
      }
      return
    }
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}
</script>

<style lang="scss" scoped>
.add-channel-dialog__body {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  padding: $space-2 0 $space-4;
}

.add-channel-dialog__field {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.add-channel-dialog__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
}

.add-channel-dialog__type-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
}

.add-channel-dialog__type-btn {
  display: flex;
  align-items: center;
  gap: $space-1;
  padding: $space-1 $space-3;
  border: 1px solid var(--p-surface-300);
  border-radius: $radius-sm;
  background: transparent;
  color: $surface-600;
  cursor: pointer;
  font-size: $font-size-sm;
  transition: all var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-600);
    color: var(--p-surface-300);
  }

  &:hover {
    border-color: var(--p-primary-400);
    color: var(--p-primary-color);
  }

  &--active {
    border-color: var(--p-primary-color);
    background: var(--p-primary-50);
    color: var(--p-primary-color);
    font-weight: $font-weight-semibold;

    .app-dark & {
      background: var(--p-primary-900);
    }
  }

  i {
    font-size: $font-size-xs;
  }
}

.add-channel-dialog__error {
  color: var(--p-red-500);
  font-size: $font-size-xs;
}

.add-channel-dialog__hint {
  color: $surface-400;
  font-size: $font-size-xs;
}
</style>
