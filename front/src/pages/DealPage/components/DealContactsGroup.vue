<template>
  <DealFieldGroup
    :title="t('sales.deal.info.groups.contacts')"
    icon="pi-users"
    group-key="contacts"
  >
    <!-- Empty state -->
    <div v-if="contacts.length === 0" class="deal-contacts-group__empty">
      <i class="pi pi-user deal-contacts-group__empty-icon" />
      <p class="deal-contacts-group__empty-text">{{ t('sales.deal.page.contacts.empty.title') }}</p>
    </div>

    <!-- Contact list -->
    <div v-else class="deal-contacts-group__list">
      <div
        v-for="link in contacts"
        :key="link.id"
        class="deal-contacts-group__item"
      >
        <!-- Contact header -->
        <div class="deal-contacts-group__item-header">
          <div class="deal-contacts-group__item-name-row">
            <RouterLink
              :to="`/contacts/${link.contact.id}`"
              class="deal-contacts-group__link"
            >
              {{ link.contact.full_name }}
            </RouterLink>
            <Tag
              v-if="link.is_primary"
              :value="t('sales.deal.page.contacts.primary')"
              severity="info"
              size="small"
            />
          </div>
          <Button
            icon="pi pi-times"
            text
            severity="secondary"
            size="small"
            :loading="removingId === link.contact.id"
            @click="emit('removeContact', link.contact.id)"
          />
        </div>

        <!-- Position -->
        <p v-if="link.contact.position" class="deal-contacts-group__position">
          {{ link.contact.position }}
        </p>

        <!-- Channels from GET /api/crm/contacts/{contact}/channels -->
        <div class="deal-contacts-group__channels">
          <!-- Loading channels -->
          <template v-if="channelsLoading[link.contact.id]">
            <span class="deal-contacts-group__channels-loading">
              <i class="pi pi-spin pi-spinner" style="font-size: 10px" />
            </span>
          </template>
          <!-- Channel tags -->
          <template v-else>
            <template v-if="getChannels(link.contact.id).length > 0">
              <span
                v-for="ch in getChannels(link.contact.id)"
                :key="ch.id"
                class="deal-contacts-group__channel-tag"
                :title="ch.value"
              >
                <a
                  v-if="channelUrl(ch)"
                  :href="channelUrl(ch) ?? undefined"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="deal-contacts-group__channel-link"
                >
                  <i :class="['pi', channelIcon(ch.channel_type)]" />
                  <span class="deal-contacts-group__channel-value">{{ ch.value }}</span>
                </a>
                <span v-else class="deal-contacts-group__channel-link">
                  <i :class="['pi', channelIcon(ch.channel_type)]" />
                  <span class="deal-contacts-group__channel-value">{{ ch.value }}</span>
                </span>
                <button
                  class="deal-contacts-group__channel-remove"
                  type="button"
                  :disabled="deletingChannel[ch.id]"
                  @click="removeChannel(link.contact.id, ch.id)"
                >
                  <i class="pi pi-times" style="font-size: 9px" />
                </button>
              </span>
            </template>
            <!-- Fallback: phone/email/tg from Contact dto if no channels -->
            <template v-else>
              <a
                v-if="link.contact.phone"
                :href="`tel:${link.contact.phone}`"
                class="deal-contacts-group__channel-tag deal-contacts-group__channel-tag--legacy"
              >
                <i class="pi pi-phone" />
                <span class="deal-contacts-group__channel-value">{{ link.contact.phone }}</span>
              </a>
              <a
                v-if="link.contact.email"
                :href="`mailto:${link.contact.email}`"
                class="deal-contacts-group__channel-tag deal-contacts-group__channel-tag--legacy"
              >
                <i class="pi pi-envelope" />
                <span class="deal-contacts-group__channel-value">{{ link.contact.email }}</span>
              </a>
            </template>
          </template>

          <!-- Add channel button -->
          <button
            class="deal-contacts-group__add-channel"
            type="button"
            @click="openAddChannel(link.contact.id)"
          >
            <i class="pi pi-plus" />
            {{ t('sales.deal.info.contacts.addChannel') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Add contact button -->
    <div class="deal-contacts-group__footer">
      <Button
        :label="t('sales.deal.info.contacts.addContact')"
        icon="pi pi-user-plus"
        text
        severity="secondary"
        size="small"
        @click="emit('addContact')"
      />
    </div>
  </DealFieldGroup>

  <!-- Add channel dialog -->
  <DealAddChannelDialog
    v-if="addChannelContactId !== null"
    v-model="addChannelVisible"
    :contact-id="addChannelContactId"
    @added="onChannelAdded"
  />
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import DealFieldGroup from './DealFieldGroup.vue'
import DealAddChannelDialog from './DealAddChannelDialog.vue'
import { contactsApi } from '@/api/crm/contacts'
import { getApiErrorMessage } from '@/utils/errors'
import type { DealContactDto } from '@/entities/sales'
import type { ContactChannel, ChannelType } from '@/entities/crm'

const props = defineProps<{
  contacts: DealContactDto[]
  removingId?: number | null
}>()

const emit = defineEmits<{
  addContact: []
  removeContact: [contactId: number]
}>()

const { t } = useI18n()
const toast = useToast()

// ── Channels state ─────────────────────────────────────────────────────────────

const channelsMap = ref<Record<number, ContactChannel[]>>({})
const channelsLoading = ref<Record<number, boolean>>({})
const deletingChannel = ref<Record<number, boolean>>({})

async function loadChannels(contactId: number) {
  if (channelsMap.value[contactId] !== undefined) return
  channelsLoading.value[contactId] = true
  try {
    const channels = await contactsApi.getChannels(contactId)
    channelsMap.value = { ...channelsMap.value, [contactId]: channels }
  } catch {
    // Non-critical — fallback to dto fields
    channelsMap.value = { ...channelsMap.value, [contactId]: [] }
  } finally {
    channelsLoading.value[contactId] = false
  }
}

function getChannels(contactId: number): ContactChannel[] {
  return channelsMap.value[contactId] ?? []
}

onMounted(() => {
  for (const link of props.contacts) {
    void loadChannels(link.contact.id)
  }
})

// Load channels when contacts list changes
const _prevContactIds = ref<number[]>([])
import { watch } from 'vue'
watch(
  () => props.contacts.map((c) => c.contact.id),
  (ids) => {
    for (const id of ids) {
      if (!_prevContactIds.value.includes(id)) {
        void loadChannels(id)
      }
    }
    _prevContactIds.value = ids
  },
)

// ── Channel helpers ────────────────────────────────────────────────────────────

function channelIcon(type: ChannelType | string): string {
  const icons: Record<string, string> = {
    tg: 'pi-send',
    wa: 'pi-whatsapp',
    linkedin: 'pi-linkedin',
    email: 'pi-envelope',
    phone: 'pi-phone',
    instagram: 'pi-instagram',
    viber: 'pi-mobile',
  }
  return icons[type] ?? 'pi-link'
}

function channelUrl(ch: ContactChannel): string | null {
  const v = ch.value
  switch (ch.channel_type) {
    case 'tg':
      return `https://t.me/${v.replace(/^@/, '')}`
    case 'wa':
      return `https://wa.me/${v.replace(/\D/g, '')}`
    case 'email':
      return `mailto:${v}`
    case 'linkedin':
      return v.startsWith('http') ? v : `https://linkedin.com/in/${v}`
    case 'instagram':
      return `https://instagram.com/${v.replace(/^@/, '')}`
    default:
      return null
  }
}

async function removeChannel(contactId: number, channelId: number) {
  deletingChannel.value = { ...deletingChannel.value, [channelId]: true }
  try {
    await contactsApi.deleteChannel(contactId, channelId)
    channelsMap.value = {
      ...channelsMap.value,
      [contactId]: (channelsMap.value[contactId] ?? []).filter((c) => c.id !== channelId),
    }
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    deletingChannel.value = { ...deletingChannel.value, [channelId]: false }
  }
}

// ── Add channel dialog ─────────────────────────────────────────────────────────

const addChannelVisible = ref(false)
const addChannelContactId = ref<number | null>(null)

function openAddChannel(contactId: number) {
  addChannelContactId.value = contactId
  addChannelVisible.value = true
}

function onChannelAdded(channel: ContactChannel) {
  const cid = channel.contact_id
  channelsMap.value = {
    ...channelsMap.value,
    [cid]: [...(channelsMap.value[cid] ?? []), channel],
  }
}
</script>

<style lang="scss" scoped>
.deal-contacts-group__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-4;
  text-align: center;
}

.deal-contacts-group__empty-icon {
  font-size: 1.5rem;
  color: $surface-300;
}

.deal-contacts-group__empty-text {
  font-size: $font-size-xs;
  color: $surface-400;
  margin: 0;
}

.deal-contacts-group__list {
  display: flex;
  flex-direction: column;
}

.deal-contacts-group__item {
  padding: $space-2 $space-4;
  border-bottom: 1px solid var(--p-surface-100);

  .app-dark & {
    border-bottom-color: var(--p-surface-800);
  }

  &:last-child {
    border-bottom: none;
  }
}

.deal-contacts-group__item-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.deal-contacts-group__item-name-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex: 1;
  min-width: 0;
}

.deal-contacts-group__link {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $primary-color;
  text-decoration: none;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  &:hover {
    text-decoration: underline;
  }
}

.deal-contacts-group__position {
  font-size: $font-size-xs;
  color: $surface-500;
  margin: 2px 0 $space-1;
}

.deal-contacts-group__channels {
  display: flex;
  flex-wrap: wrap;
  gap: $space-1;
  margin-top: $space-1;
  align-items: center;
}

.deal-contacts-group__channels-loading {
  color: $surface-400;
  font-size: $font-size-xs;
}

.deal-contacts-group__channel-tag {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 6px;
  border-radius: $radius-sm;
  border: 1px solid var(--p-surface-300);
  background: var(--p-surface-50);
  font-size: $font-size-xs;
  color: $surface-600;
  text-decoration: none;
  transition: border-color var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-600);
    background: var(--p-surface-800);
    color: var(--p-surface-300);
  }

  &--legacy {
    // fallback styling — same as channel tags but no remove button
  }

  .pi {
    font-size: 10px;
  }
}

a.deal-contacts-group__channel-tag,
.deal-contacts-group__channel-link {
  color: $surface-600;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 3px;

  .app-dark & {
    color: var(--p-surface-300);
  }

  &:hover {
    color: $primary-color;
  }
}

.deal-contacts-group__channel-value {
  max-width: 90px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.deal-contacts-group__channel-remove {
  background: transparent;
  border: none;
  cursor: pointer;
  color: $surface-400;
  padding: 0;
  display: flex;
  align-items: center;
  margin-left: 2px;
  line-height: 1;

  &:hover:not(:disabled) {
    color: var(--p-red-500);
  }

  &:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }
}

.deal-contacts-group__add-channel {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  background: transparent;
  border: 1px dashed var(--p-surface-300);
  border-radius: $radius-sm;
  color: $surface-500;
  cursor: pointer;
  font-size: $font-size-xs;
  padding: 2px 6px;
  transition: all var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-600);
  }

  &:hover {
    border-color: var(--p-primary-400);
    color: $primary-color;
  }

  .pi {
    font-size: 10px;
  }
}

.deal-contacts-group__footer {
  padding: $space-2 $space-3;
}
</style>
