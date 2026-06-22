<template>
  <div class="contact-channels">
    <!-- Loading -->
    <div v-if="loading" class="contact-channels__skeleton">
      <Skeleton height="32px" class="mb-2" />
      <Skeleton height="32px" class="mb-2" />
      <Skeleton height="32px" />
    </div>

    <!-- Content -->
    <template v-else>
      <KeyFactsBlock v-if="channels.length > 0">
        <KeyFactsItem
          v-for="ch in channels"
          :key="ch.id"
          :label="channelLabel(ch.channel_type)"
        >
          <div class="contact-channels__row">
            <span class="contact-channels__value">{{ ch.value }}</span>
            <div class="contact-channels__row-actions">
              <a
                v-if="ch.channel_type === 'phone'"
                :href="`tel:${ch.value}`"
                class="contact-channels__link-btn"
                :title="t('crm.contact.channels.call')"
              >
                <i class="pi pi-phone" />
              </a>
              <a
                v-else-if="ch.channel_type === 'email'"
                :href="`mailto:${ch.value}`"
                class="contact-channels__link-btn"
                :title="t('crm.contact.channels.sendEmail')"
              >
                <i class="pi pi-envelope" />
              </a>
              <a
                v-else-if="ch.channel_type === 'tg'"
                :href="`https://t.me/${ch.value.replace('@','')}`"
                target="_blank"
                rel="noopener noreferrer"
                class="contact-channels__link-btn"
              >
                <i class="pi pi-send" />
              </a>
              <a
                v-else-if="ch.channel_type === 'wa'"
                :href="`https://wa.me/${ch.value.replace(/\D/g,'')}`"
                target="_blank"
                rel="noopener noreferrer"
                class="contact-channels__link-btn"
              >
                <i class="pi pi-whatsapp" />
              </a>
              <button
                class="contact-channels__delete-btn"
                :title="t('common.delete')"
                @click="onDeleteChannel(ch)"
              >
                <i class="pi pi-times" />
              </button>
            </div>
          </div>
        </KeyFactsItem>
      </KeyFactsBlock>

      <!-- Empty -->
      <div v-else class="contact-channels__empty">
        <i class="pi pi-phone contact-channels__empty-icon" />
        <p class="contact-channels__empty-text">{{ t('crm.contact.channels.empty') }}</p>
      </div>

      <!-- Add channel form -->
      <div v-if="addingOpen" class="contact-channels__add-form">
        <Select
          v-model="newChannelType"
          :options="channelTypeOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('crm.contact.channels.selectType')"
          class="contact-channels__type-select"
        />
        <InputText
          v-model="newChannelValue"
          :placeholder="channelPlaceholder(newChannelType)"
          class="contact-channels__value-input"
          @keyup.enter="submitAddChannel"
        />
        <Button
          icon="pi pi-check"
          size="small"
          :loading="saving"
          :disabled="!newChannelType || !newChannelValue.trim()"
          @click="submitAddChannel"
        />
        <Button
          icon="pi pi-times"
          size="small"
          severity="secondary"
          text
          @click="cancelAdd"
        />
      </div>

      <!-- Add button -->
      <button
        v-if="!addingOpen"
        class="contact-channels__add-btn"
        @click="openAdd"
      >
        <i class="pi pi-plus" />
        {{ t('crm.contact.channels.addChannel') }}
      </button>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Skeleton from 'primevue/skeleton'
import KeyFactsBlock from '@/components/crm/entity/KeyFactsBlock.vue'
import KeyFactsItem from '@/components/crm/entity/KeyFactsItem.vue'
import { contactsApi } from '@/api/crm/contacts'
import { getApiErrorMessage } from '@/utils/errors'
import type { ContactChannel, ChannelType } from '@/entities/crm'

const props = defineProps<{
  contactId: number
  channels: ContactChannel[]
  loading?: boolean
}>()

const emit = defineEmits<{
  updated: [channels: ContactChannel[]]
}>()

const { t } = useI18n()
const toast = useToast()
const confirm = useConfirm()

// ── Add form ──────────────────────────────────────────────────────────────────

const addingOpen = ref(false)
const newChannelType = ref<ChannelType | null>(null)
const newChannelValue = ref('')
const saving = ref(false)

const channelTypeOptions = computed(() => [
  { value: 'phone', label: t('crm.contact.channels.phone') },
  { value: 'email', label: t('crm.contact.channels.email') },
  { value: 'tg', label: t('crm.contact.channels.telegram') },
  { value: 'wa', label: t('crm.contact.channels.whatsapp') },
])

function channelLabel(type: ChannelType): string {
  const map: Record<ChannelType, string> = {
    phone: t('crm.contact.channels.phone'),
    email: t('crm.contact.channels.email'),
    tg: t('crm.contact.channels.telegram'),
    wa: t('crm.contact.channels.whatsapp'),
    linkedin: 'LinkedIn',
    instagram: 'Instagram',
    viber: 'Viber',
  }
  return map[type] ?? type
}

function channelPlaceholder(type: ChannelType | null): string {
  if (!type) return t('crm.contact.channels.valuePlaceholder')
  if (type === 'phone') return '+7 (999) 000-00-00'
  if (type === 'email') return 'email@example.com'
  if (type === 'tg') return '@username'
  if (type === 'wa') return '+7 (999) 000-00-00'
  return ''
}

function openAdd() {
  addingOpen.value = true
  newChannelType.value = null
  newChannelValue.value = ''
}

defineExpose({ openAdd })

function cancelAdd() {
  addingOpen.value = false
}

async function submitAddChannel() {
  if (!newChannelType.value || !newChannelValue.value.trim()) return
  saving.value = true
  try {
    const created = await contactsApi.addChannel(props.contactId, {
      channel_type: newChannelType.value,
      value: newChannelValue.value.trim(),
    })
    emit('updated', [...props.channels, created])
    addingOpen.value = false
    toast.add({ severity: 'success', summary: t('crm.contact.channels.added'), life: 2500 })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

// ── Delete ────────────────────────────────────────────────────────────────────

function onDeleteChannel(ch: ContactChannel) {
  confirm.require({
    message: t('crm.contact.channels.deleteConfirm'),
    header: t('common.confirm'),
    icon: 'pi pi-exclamation-triangle',
    acceptClass: 'p-button-danger',
    accept: async () => {
      try {
        await contactsApi.deleteChannel(props.contactId, ch.id)
        emit('updated', props.channels.filter((c) => c.id !== ch.id))
        toast.add({ severity: 'success', summary: t('crm.contact.channels.deleted'), life: 2500 })
      } catch (err) {
        toast.add({
          severity: 'error',
          summary: t('errors.server_error'),
          detail: getApiErrorMessage(err, t('errors.server_error')),
          life: 4000,
        })
      }
    },
  })
}
</script>

<style lang="scss" scoped>
.contact-channels {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.contact-channels__skeleton {
  display: flex;
  flex-direction: column;
}

.contact-channels__row {
  display: flex;
  align-items: center;
  gap: $space-2;
  width: 100%;
}

.contact-channels__value {
  flex: 1;
  font-size: $font-size-sm;
  color: $surface-800;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.contact-channels__row-actions {
  display: flex;
  align-items: center;
  gap: $space-1;
  opacity: 0;
  transition: opacity var(--app-transition-fast);
  flex-shrink: 0;

  .contact-channels__row:hover & {
    opacity: 1;
  }
}

.contact-channels__link-btn,
.contact-channels__delete-btn {
  background: transparent;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: $radius-sm;
  padding: 0;
  text-decoration: none;
  transition: background var(--app-transition-fast);

  i {
    font-size: $font-size-2xs;
  }
}

.contact-channels__link-btn {
  color: var(--p-primary-color);

  &:hover {
    background: var(--p-primary-100);
  }

  .app-dark &:hover {
    background: var(--p-primary-900);
  }
}

.contact-channels__delete-btn {
  color: $surface-400;

  &:hover {
    background: var(--p-surface-100);
    color: var(--p-red-500);
  }

  .app-dark &:hover {
    background: var(--p-surface-800);
  }
}

.contact-channels__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-4;
  text-align: center;
}

.contact-channels__empty-icon {
  font-size: $font-size-2xl;
  color: $surface-300;
}

.contact-channels__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

.contact-channels__add-form {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
  padding-top: $space-2;
}

.contact-channels__type-select {
  width: 140px;
  flex-shrink: 0;
}

.contact-channels__value-input {
  flex: 1;
  min-width: 0;
}

.contact-channels__add-btn {
  display: flex;
  align-items: center;
  gap: $space-2;
  background: transparent;
  border: none;
  cursor: pointer;
  color: var(--p-primary-color);
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  padding: $space-1 0;
  transition: opacity var(--app-transition-fast);

  &:hover {
    opacity: 0.75;
  }

  i {
    font-size: $font-size-2xs;
  }
}
</style>
