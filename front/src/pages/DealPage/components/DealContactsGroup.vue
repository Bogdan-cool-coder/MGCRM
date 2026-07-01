<template>
  <DealFieldGroup
    ref="groupRef"
    :title="t('sales.deal.info.groups.contacts')"
    icon="pi-users"
    group-key="contacts"
    :accent="true"
    :count="contacts.length"
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
        <!-- ── Orphaned link (contact was deleted) ────────────────────────────── -->
        <template v-if="!link.contact">
          <div class="deal-contacts-group__orphan">
            <i class="pi pi-user-minus deal-contacts-group__orphan-icon" />
            <span class="deal-contacts-group__orphan-text">{{ t('sales.deal.page.contacts.deleted') }}</span>
          </div>
        </template>

        <!-- ── View mode ──────────────────────────────────────────────────────── -->
        <template v-else-if="editingContactId !== link.contact.id">
          <div class="deal-contacts-group__item-header">
            <div class="deal-contacts-group__item-name-row">
              <RouterLink
                :to="`/contacts/${link.contact.id}`"
                class="deal-contacts-group__link"
              >
                {{ link.contact.full_name }}
              </RouterLink>
              <span v-if="link.is_primary" class="deal-contacts-group__primary-badge">
                {{ t('sales.deal.page.contacts.primary') }}
              </span>
              <button
                v-else
                type="button"
                class="deal-contacts-group__set-primary-link"
                :disabled="settingPrimaryId === link.contact.id"
                @click.stop="doSetPrimary(link)"
              >
                {{ t('sales.deal.page.contacts.setPrimary') }}
              </button>
            </div>
            <!-- ⋮ menu trigger -->
            <div class="deal-contacts-group__menu-wrap">
              <button
                class="deal-contacts-group__menu-btn"
                type="button"
                @click.stop="toggleMenu(link.contact.id)"
              >
                <i class="pi pi-ellipsis-v" />
              </button>
              <!-- Inline popover menu -->
              <div
                v-if="openMenuId === link.contact.id"
                class="deal-contacts-group__menu-popover"
                @click.stop
              >
                <button
                  class="deal-contacts-group__menu-item"
                  type="button"
                  @click="startEdit(link)"
                >
                  <i class="pi pi-pencil" />
                  {{ t('common.edit') }}
                </button>
                <button
                  class="deal-contacts-group__menu-item deal-contacts-group__menu-item--danger"
                  type="button"
                  :disabled="removingId === link.contact.id"
                  @click="doUnlink(link.contact.id)"
                >
                  <i class="pi pi-times-circle" />
                  {{ t('sales.deal.info.contacts.unlink') }}
                </button>
              </div>
            </div>
          </div>

          <!-- Position -->
          <p v-if="link.contact.position" class="deal-contacts-group__position">
            {{ link.contact.position }}
          </p>

          <!-- Channels -->
          <div class="deal-contacts-group__channels">
            <template v-if="channelsLoading[link.contact.id]">
              <i class="pi pi-spin pi-spinner deal-contacts-group__spinner" />
            </template>
            <template v-else>
              <template v-if="getChannels(link.contact.id).length > 0">
                <a
                  v-for="ch in getChannels(link.contact.id)"
                  :key="ch.id"
                  :href="channelUrl(ch) ?? undefined"
                  :target="channelUrl(ch) ? '_blank' : undefined"
                  rel="noopener noreferrer"
                  class="deal-contacts-group__channel-tag"
                  :title="ch.value"
                >
                  <i :class="['pi', channelIcon(ch.channel_type)]" />
                  <span class="deal-contacts-group__channel-value">{{ ch.value }}</span>
                </a>
              </template>
              <template v-else>
                <a
                  v-if="link.contact.phone"
                  :href="`tel:${link.contact.phone}`"
                  class="deal-contacts-group__channel-tag"
                >
                  <i class="pi pi-phone" />
                  <span class="deal-contacts-group__channel-value">{{ link.contact.phone }}</span>
                </a>
                <a
                  v-if="link.contact.email"
                  :href="`mailto:${link.contact.email}`"
                  class="deal-contacts-group__channel-tag"
                >
                  <i class="pi pi-envelope" />
                  <span class="deal-contacts-group__channel-value">{{ link.contact.email }}</span>
                </a>
              </template>
            </template>

            <!-- Add channel button (inline popover) -->
            <div class="deal-contacts-group__add-ch-wrap">
              <button
                class="deal-contacts-group__add-channel"
                type="button"
                @click.stop="openAddChannelPopover(link.contact.id)"
              >
                <i class="pi pi-plus" />
                {{ t('sales.deal.info.contacts.addChannel') }}
              </button>
              <!-- Inline add-channel popover -->
              <div
                v-if="addChContactId === link.contact.id"
                class="deal-contacts-group__add-ch-popover"
                @click.stop
              >
                <div class="deal-contacts-group__add-ch-type-row">
                  <button
                    v-for="opt in channelTypeOptions"
                    :key="opt.value"
                    type="button"
                    class="deal-contacts-group__type-btn"
                    :class="{ 'deal-contacts-group__type-btn--active': addChType === opt.value }"
                    @click="addChType = opt.value"
                  >
                    <i :class="['pi', opt.icon]" />
                  </button>
                </div>
                <InputText
                  v-model="addChValue"
                  :placeholder="t('sales.deal.addChannel.valuePlaceholder')"
                  size="small"
                  fluid
                  @keydown.enter="submitAddChannel(link.contact.id)"
                />
                <Button
                  :label="t('common.add')"
                  size="small"
                  :loading="addChSaving"
                  :disabled="!addChValue.trim()"
                  @click="submitAddChannel(link.contact.id)"
                />
              </div>
            </div>
          </div>
        </template>

        <!-- ── Edit mode ──────────────────────────────────────────────────────── -->
        <template v-else-if="link.contact">
          <div class="deal-contacts-group__edit-form">
            <!-- Имя -->
            <div class="deal-contacts-group__edit-field">
              <label class="deal-contacts-group__edit-label">{{ t('crm.contact.fields.name') }}</label>
              <InputText v-model="editForm.full_name" size="small" fluid />
            </div>
            <!-- Должность -->
            <div class="deal-contacts-group__edit-field">
              <label class="deal-contacts-group__edit-label">{{ t('crm.contact.fields.position') }}</label>
              <InputText v-model="editForm.position" size="small" fluid />
            </div>
            <!-- Channels edit -->
            <div class="deal-contacts-group__edit-field">
              <label class="deal-contacts-group__edit-label">{{ t('crm.contact.fields.channels') }}</label>
              <div class="deal-contacts-group__edit-channels">
                <div
                  v-for="ch in editChannels"
                  :key="ch.id"
                  class="deal-contacts-group__edit-channel-row"
                >
                  <i :class="['pi', channelIcon(ch.channel_type), 'deal-contacts-group__ch-icon']" />
                  <InputText v-model="ch.value" size="small" class="deal-contacts-group__ch-input" />
                  <button
                    type="button"
                    class="deal-contacts-group__ch-remove"
                    @click="removeEditChannel(ch.id)"
                  >
                    <i class="pi pi-times" />
                  </button>
                </div>
                <!-- Add channel row -->
                <div class="deal-contacts-group__edit-add-ch">
                  <div class="deal-contacts-group__add-ch-type-row">
                    <button
                      v-for="opt in channelTypeOptions"
                      :key="opt.value"
                      type="button"
                      class="deal-contacts-group__type-btn"
                      :class="{ 'deal-contacts-group__type-btn--active': editNewChType === opt.value }"
                      @click="editNewChType = opt.value"
                    >
                      <i :class="['pi', opt.icon]" />
                    </button>
                  </div>
                  <InputText
                    v-model="editNewChValue"
                    :placeholder="t('sales.deal.addChannel.valuePlaceholder')"
                    size="small"
                    fluid
                  />
                  <Button
                    :label="t('common.add')"
                    size="small"
                    :disabled="!editNewChValue.trim()"
                    text
                    @click="addEditChannel"
                  />
                </div>
              </div>
            </div>
            <!-- Основной контакт toggle -->
            <div class="deal-contacts-group__edit-primary">
              <label class="deal-contacts-group__edit-label">{{ t('sales.deal.page.contacts.addDialog.fields.isPrimary') }}</label>
              <ToggleSwitch v-model="editForm.is_primary" />
            </div>
            <!-- Actions -->
            <div class="deal-contacts-group__edit-actions">
              <Button
                :label="t('common.cancel')"
                size="small"
                text
                severity="secondary"
                @click="cancelEdit"
              />
              <Button
                :label="t('common.save')"
                size="small"
                :loading="editSaving"
                @click="submitEdit(link.contact.id)"
              />
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- Add contact button -->
    <div class="deal-contacts-group__footer">
      <button class="deal-contacts-group__add-contact-btn" type="button" @click="emit('addContact')">
        <i class="pi pi-user-plus" />
        {{ t('sales.deal.info.contacts.addContact') }}
      </button>
    </div>
  </DealFieldGroup>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import ToggleSwitch from 'primevue/toggleswitch'
import DealFieldGroup from './DealFieldGroup.vue'
import { contactsApi } from '@/api/crm/contacts'
import { salesApi } from '@/api/sales'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorMessage } from '@/utils/errors'
import type { DealContactDto } from '@/entities/sales'
import type { ContactChannel, ChannelType } from '@/entities/crm'


const props = defineProps<{
  dealId: number
  contacts: DealContactDto[]
  removingId?: number | null
}>()

const emit = defineEmits<{
  addContact: []
  removeContact: [contactId: number]
  contactsUpdated: [contacts: DealContactDto[]]
}>()

const { t } = useI18n()
const toast = useToast()

// ── Group ref ─────────────────────────────────────────────────────────────────

const groupRef = ref<InstanceType<typeof DealFieldGroup> | null>(null)
function collapse() { groupRef.value?.collapse?.() }
function expand() { groupRef.value?.expand?.() }
defineExpose({ collapse, expand })

// ── Channels state ─────────────────────────────────────────────────────────────

const channelsMap = ref<Record<number, ContactChannel[]>>({})
const channelsLoading = ref<Record<number, boolean>>({})

async function loadChannels(contactId: number) {
  if (channelsMap.value[contactId] !== undefined) return
  channelsLoading.value[contactId] = true
  try {
    const channels = await contactsApi.getChannels(contactId)
    channelsMap.value = { ...channelsMap.value, [contactId]: channels }
  } catch {
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
    if (link.contact) void loadChannels(link.contact.id)
  }
})

const _prevContactIds = ref<number[]>([])
watch(
  () => props.contacts.flatMap((c) => (c.contact ? [c.contact.id] : [])),
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

const channelTypeOptions = [
  { label: 'TG', value: 'tg', icon: 'pi-send' },
  { label: 'WA', value: 'wa', icon: 'pi-whatsapp' },
  { label: 'LI', value: 'linkedin', icon: 'pi-linkedin' },
  { label: 'Email', value: 'email', icon: 'pi-envelope' },
  { label: 'Тел.', value: 'phone', icon: 'pi-phone' },
]

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
    case 'tg': return `https://t.me/${v.replace(/^@/, '')}`
    case 'wa': return `https://wa.me/${v.replace(/\D/g, '')}`
    case 'email': return `mailto:${v}`
    case 'linkedin': return v.startsWith('http') ? v : `https://linkedin.com/in/${v}`
    case 'instagram': return `https://instagram.com/${v.replace(/^@/, '')}`
    default: return null
  }
}

// ── ⋮ Menu state ──────────────────────────────────────────────────────────────

const openMenuId = ref<number | null>(null)

function toggleMenu(id: number) {
  openMenuId.value = openMenuId.value === id ? null : id
}

function closeMenu() {
  openMenuId.value = null
}

function doUnlink(contactId: number) {
  openMenuId.value = null
  emit('removeContact', contactId)
}

// Close menus on any document click (not inside popover — popover itself has @click.stop)
function onDocClick() {
  if (openMenuId.value !== null) closeMenu()
  if (addChContactId.value !== null) closeAddChannelPopover()
}

onMounted(() => {
  document.addEventListener('click', onDocClick)
})

onUnmounted(() => {
  document.removeEventListener('click', onDocClick)
})

// ── One-click set-primary ─────────────────────────────────────────────────────

const settingPrimaryId = ref<number | null>(null)

async function doSetPrimary(link: DealContactDto): Promise<void> {
  if (!link.contact || link.is_primary || settingPrimaryId.value !== null) return
  settingPrimaryId.value = link.contact.id
  try {
    const updatedContacts = await salesApi.updateDealContact(
      props.dealId,
      link.id,
      { is_primary: true },
    )
    emit('contactsUpdated', updatedContacts)
    toast.add({ severity: 'success', summary: t('sales.deal.page.contacts.setPrimarySuccess'), life: 2000 })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    settingPrimaryId.value = null
  }
}

// ── Inline edit state ─────────────────────────────────────────────────────────

const editingContactId = ref<number | null>(null)
const editForm = ref({ full_name: '', position: '', is_primary: false })

interface EditableChannel {
  id: number
  channel_type: string
  value: string
  _isNew?: boolean
}

const editChannels = ref<EditableChannel[]>([])
const editNewChType = ref('tg')
const editNewChValue = ref('')

const editSaving = ref(false)

function startEdit(link: DealContactDto) {
  if (!link.contact) return
  openMenuId.value = null
  editingContactId.value = link.contact.id
  editForm.value = {
    full_name: link.contact.full_name,
    position: link.contact.position ?? '',
    is_primary: link.is_primary,
  }
  editChannels.value = getChannels(link.contact.id).map((ch) => ({
    id: ch.id,
    channel_type: ch.channel_type,
    value: ch.value,
  }))
  editNewChType.value = 'tg'
  editNewChValue.value = ''
}

function cancelEdit() {
  editingContactId.value = null
}

function removeEditChannel(channelId: number) {
  editChannels.value = editChannels.value.filter((ch) => ch.id !== channelId)
}

function addEditChannel() {
  if (!editNewChValue.value.trim()) return
  editChannels.value.push({
    id: Date.now(), // temporary id
    channel_type: editNewChType.value,
    value: editNewChValue.value.trim(),
    _isNew: true,
  })
  editNewChValue.value = ''
}

async function submitEdit(contactId: number) {
  editSaving.value = true
  try {
    // Update contact basic info
    await contactsApi.update(contactId, {
      full_name: editForm.value.full_name,
      position: editForm.value.position || null,
    })

    // Sync channels: delete removed, add new
    const originalChannels = getChannels(contactId)
    const remainingIds = new Set(editChannels.value.filter((c) => !c._isNew).map((c) => c.id))
    const toDelete = originalChannels.filter((ch) => !remainingIds.has(ch.id))
    for (const ch of toDelete) {
      await contactsApi.deleteChannel(contactId, ch.id)
    }
    const toAdd = editChannels.value.filter((c) => c._isNew)
    const addedChannels: ContactChannel[] = []
    for (const ch of toAdd) {
      const added = await contactsApi.addChannel(contactId, {
        channel_type: ch.channel_type,
        value: ch.value,
      })
      addedChannels.push(added)
    }

    // Update channels map
    const surviving = originalChannels.filter((ch) => remainingIds.has(ch.id))
    channelsMap.value = {
      ...channelsMap.value,
      [contactId]: [...surviving, ...addedChannels],
    }

    // ── is_primary toggle via PATCH /api/deals/{deal}/contacts/{pivot} ───────
    const link = props.contacts.find((c) => c.contact?.id === contactId)
    if (link && editForm.value.is_primary !== link.is_primary) {
      const updatedContacts = await salesApi.updateDealContact(
        props.dealId,
        link.id,
        { is_primary: editForm.value.is_primary },
      )
      emit('contactsUpdated', updatedContacts)
    }

    toast.add({ severity: 'success', summary: t('common.saved'), life: 2000 })
    editingContactId.value = null
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    editSaving.value = false
  }
}

// ── Inline add-channel popover (collapsed view) ───────────────────────────────

const addChContactId = ref<number | null>(null)
const addChType = ref('tg')
const addChValue = ref('')
const addChMutation = useMutation<ContactChannel>()
const addChSaving = ref(false)

function openAddChannelPopover(contactId: number) {
  addChContactId.value = contactId
  addChType.value = 'tg'
  addChValue.value = ''
}

function closeAddChannelPopover() {
  addChContactId.value = null
}

async function submitAddChannel(contactId: number) {
  if (!addChValue.value.trim()) return
  addChSaving.value = true
  try {
    const channel = await addChMutation.run(() =>
      contactsApi.addChannel(contactId, {
        channel_type: addChType.value,
        value: addChValue.value.trim(),
      }),
    )
    channelsMap.value = {
      ...channelsMap.value,
      [contactId]: [...(channelsMap.value[contactId] ?? []), channel],
    }
    addChContactId.value = null
    toast.add({ severity: 'success', summary: t('sales.deal.addChannel.success'), life: 2000 })
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    addChSaving.value = false
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
  font-size: $font-size-2xl;
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

// ── View mode ─────────────────────────────────────────────────────────────────

.deal-contacts-group__item-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-1;
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

.deal-contacts-group__primary-badge {
  font-size: $font-size-2xs;
  font-weight: $font-weight-semibold;
  padding: 2px 6px;
  border-radius: $radius-sm;
  background: var(--p-blue-100);
  color: var(--p-blue-700);
  white-space: nowrap;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-blue-900);
    color: var(--p-blue-300);
  }
}

.deal-contacts-group__set-primary-link {
  font-size: $font-size-2xs;
  font-weight: $font-weight-medium;
  color: var(--p-primary-color);
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
  text-decoration: none;
  opacity: 0.7;
  transition: opacity var(--app-transition-fast), text-decoration var(--app-transition-fast);

  &:hover {
    opacity: 1;
    text-decoration: underline;
  }

  &:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }

  .app-dark & {
    color: var(--p-primary-300);
  }
}

.deal-contacts-group__position {
  font-size: $font-size-xs;
  color: $surface-500;
  margin: 2px 0 $space-1;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// ── ⋮ Menu ────────────────────────────────────────────────────────────────────

.deal-contacts-group__menu-wrap {
  position: relative;
  flex-shrink: 0;
}

.deal-contacts-group__menu-btn {
  background: none;
  border: none;
  cursor: pointer;
  padding: 2px 4px;
  color: $surface-400;
  display: flex;
  align-items: center;
  border-radius: $radius-sm;
  transition: color var(--app-transition-fast), background var(--app-transition-fast);

  .app-dark & {
    color: var(--p-surface-500);
  }

  &:hover {
    color: $surface-700;
    background: var(--p-surface-100);

    .app-dark & {
      color: var(--p-surface-200);
      background: var(--p-surface-700);
    }
  }

  .pi {
    font-size: $font-size-xs;
  }
}

.deal-contacts-group__menu-popover {
  position: absolute;
  top: calc(100% + 4px);
  right: 0;
  z-index: 100;
  min-width: 160px;
  background: var(--p-card-background);
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  box-shadow: $shadow-lg;
  padding: $space-1;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

.deal-contacts-group__menu-item {
  display: flex;
  align-items: center;
  gap: $space-2;
  width: 100%;
  padding: $space-2 $space-3;
  border: none;
  background: none;
  cursor: pointer;
  font-size: $font-size-sm;
  color: $surface-700;
  border-radius: $radius-sm;
  text-align: left;
  transition: background var(--app-transition-fast);

  .app-dark & {
    color: var(--p-surface-100);
  }

  &:hover {
    background: var(--p-surface-100);

    .app-dark & {
      background: var(--p-surface-700);
    }
  }

  &--danger {
    color: var(--p-red-600);

    .app-dark & {
      color: var(--p-red-400);
    }

    &:hover {
      background: var(--p-red-50);

      .app-dark & {
        background: var(--p-red-950);
      }
    }
  }

  .pi {
    font-size: $font-size-xs;
    flex-shrink: 0;
  }

  &:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
}

// ── Channels ──────────────────────────────────────────────────────────────────

.deal-contacts-group__channels {
  display: flex;
  flex-wrap: wrap;
  gap: $space-1;
  margin-top: $space-1;
  align-items: center;
}

.deal-contacts-group__spinner {
  font-size: $font-size-xs;
  color: $surface-400;
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

  &:hover {
    border-color: var(--p-primary-400);
    color: $primary-color;
  }

  .pi {
    font-size: $font-size-3xs;
  }
}

.deal-contacts-group__channel-value {
  max-width: 90px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

// ── Add channel (inline popover) ──────────────────────────────────────────────

.deal-contacts-group__add-ch-wrap {
  position: relative;
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
    font-size: $font-size-3xs;
  }
}

.deal-contacts-group__add-ch-popover {
  position: absolute;
  bottom: calc(100% + 6px);
  left: 0;
  z-index: 100;
  min-width: 220px;
  background: var(--p-card-background);
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  box-shadow: $shadow-md;
  padding: $space-3;
  display: flex;
  flex-direction: column;
  gap: $space-2;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

.deal-contacts-group__add-ch-type-row {
  display: flex;
  gap: $space-1;
  flex-wrap: wrap;
}

.deal-contacts-group__type-btn {
  padding: $space-1 $space-2;
  border: 1px solid var(--p-surface-300);
  border-radius: $radius-sm;
  background: transparent;
  cursor: pointer;
  font-size: $font-size-xs;
  color: $surface-600;
  transition: all var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-600);
    color: var(--p-surface-300);
  }

  &:hover {
    border-color: var(--p-primary-400);
    color: $primary-color;
  }

  &--active {
    border-color: var(--p-primary-color);
    background: var(--p-primary-50);
    color: var(--p-primary-color);

    .app-dark & {
      background: var(--p-primary-900);
    }
  }

  .pi {
    font-size: $font-size-xs;
  }
}

// ── Edit form ─────────────────────────────────────────────────────────────────

.deal-contacts-group__edit-form {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding: $space-2 0;
}

.deal-contacts-group__edit-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.deal-contacts-group__edit-label {
  font-size: $font-size-xs;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.deal-contacts-group__edit-channels {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.deal-contacts-group__edit-channel-row {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.deal-contacts-group__ch-icon {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.deal-contacts-group__ch-input {
  flex: 1;
  min-width: 0;
}

.deal-contacts-group__ch-remove {
  background: none;
  border: none;
  cursor: pointer;
  color: $surface-400;
  padding: 2px;
  display: flex;
  align-items: center;
  flex-shrink: 0;

  &:hover {
    color: var(--p-red-500);
  }

  .pi {
    font-size: $font-size-xs;
  }
}

.deal-contacts-group__edit-add-ch {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  margin-top: $space-1;
  padding: $space-2;
  background: var(--p-surface-50);
  border: 1px dashed var(--p-surface-300);
  border-radius: $radius-sm;

  .app-dark & {
    background: var(--p-surface-800);
    border-color: var(--p-surface-600);
  }
}

.deal-contacts-group__edit-primary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-1 0;
}

.deal-contacts-group__edit-actions {
  display: flex;
  gap: $space-2;
  justify-content: flex-end;
}

// ── Orphaned contact (deleted) ────────────────────────────────────────────────

.deal-contacts-group__orphan {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-1 0;
  color: $surface-400;
  font-size: $font-size-xs;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.deal-contacts-group__orphan-icon {
  font-size: $font-size-xs;
  flex-shrink: 0;
}

.deal-contacts-group__orphan-text {
  font-style: italic;
}

// ── Footer ────────────────────────────────────────────────────────────────────

.deal-contacts-group__footer {
  padding: $space-2 $space-4;
}

.deal-contacts-group__add-contact-btn {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  background: none;
  border: none;
  cursor: pointer;
  font-size: $font-size-sm;
  color: var(--p-primary-color);
  font-weight: $font-weight-medium;
  padding: 0;

  &:hover {
    text-decoration: underline;
  }

  .pi {
    font-size: $font-size-xs;
  }
}
</style>
