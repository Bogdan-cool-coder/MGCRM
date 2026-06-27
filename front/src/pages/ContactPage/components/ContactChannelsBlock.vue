<template>
  <div class="contact-channels">
    <!-- Loading -->
    <div v-if="loading" class="contact-channels__skeleton">
      <Skeleton height="36px" class="mb-2" />
      <Skeleton height="36px" class="mb-2" />
      <Skeleton height="36px" />
    </div>

    <!-- Content -->
    <template v-else>
      <!-- Channel rows — spec §4: circular action icon 30px that turns navy on hover -->
      <div
        v-for="ch in channels"
        :key="ch.id"
        class="contact-channels__row"
        @mouseenter="hoveredId = ch.id"
        @mouseleave="hoveredId = null"
      >
        <!-- Left: circular action icon 30×30 -->
        <component
          :is="channelLinkTag(ch)"
          :href="channelHref(ch)"
          :target="channelLinkTarget(ch)"
          :rel="channelLinkTarget(ch) ? 'noopener noreferrer' : undefined"
          class="contact-channels__action-icon"
          :class="{ 'contact-channels__action-icon--hovered': hoveredId === ch.id }"
          :title="channelActionLabel(ch)"
        >
          <i :class="['pi', channelIcon(ch)]" />
        </component>

        <!-- Center: value + action label on hover -->
        <div class="contact-channels__info">
          <div class="contact-channels__value-row">
            <span class="contact-channels__value">{{ ch.value }}</span>
            <!-- Primary star indicator -->
            <i
              v-if="ch.is_primary_for_channel"
              class="pi pi-star-fill contact-channels__primary-star"
              :title="t('crm.contact.channels.primary')"
            />
          </div>
          <span
            class="contact-channels__action-label"
            :class="{ 'contact-channels__action-label--visible': hoveredId === ch.id }"
          >
            {{ channelActionLabel(ch) }}
          </span>
        </div>

        <!-- Right: copy icon -->
        <button
          type="button"
          class="contact-channels__copy-btn"
          :title="t('common.copy')"
          @click.stop="copyValue(ch.value)"
        >
          <i class="pi pi-copy" />
        </button>

        <!-- More menu -->
        <button
          type="button"
          class="contact-channels__more-btn"
          :title="t('common.actions')"
          @click.stop="onMenuClick($event, ch)"
        >
          <i class="pi pi-ellipsis-v" />
        </button>
        <Menu
          :ref="(el) => setMenuRef(ch.id, el)"
          :model="channelMenuItems(ch)"
          popup
        />
      </div>

      <!-- Empty -->
      <div v-if="channels.length === 0" class="contact-channels__empty">
        <i class="pi pi-phone contact-channels__empty-icon" />
        <p class="contact-channels__empty-text">{{ t('crm.contact.channels.empty') }}</p>
      </div>

      <!-- «+канал» Popover trigger -->
      <div class="contact-channels__add-trigger">
        <Button
          ref="addTriggerRef"
          :label="t('crm.contact.channels.addChannel')"
          icon="pi pi-plus"
          size="small"
          severity="secondary"
          text
          @click="toggleAddPopover"
        />
      </div>

      <!-- Add channel Popover -->
      <Popover ref="addPopoverRef" class="contact-channels__popover">
        <div class="contact-channels__add-form">
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
          <div class="contact-channels__add-actions">
            <Button
              :label="t('common.add')"
              size="small"
              :loading="saving"
              :disabled="!newChannelType || !newChannelValue.trim()"
              @click="submitAddChannel"
            />
            <Button
              :label="t('common.cancel')"
              size="small"
              severity="secondary"
              text
              @click="cancelAdd"
            />
          </div>
        </div>
      </Popover>
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
import Menu from 'primevue/menu'
import Popover from 'primevue/popover'
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

const hoveredId = ref<number | null>(null)
const menuRefs = ref<Map<number, InstanceType<typeof Menu>>>(new Map())
const addPopoverRef = ref<InstanceType<typeof Popover> | null>(null)

// ── Channel action helpers (spec §4) ──────────────────────────────────────────

function channelIcon(ch: ContactChannel): string {
  const map: Record<ChannelType, string> = {
    phone: 'pi-phone',
    email: 'pi-envelope',
    tg: 'pi-send',
    wa: 'pi-whatsapp',
    linkedin: 'pi-linkedin',
    instagram: 'pi-instagram',
    viber: 'pi-phone',
    website: 'pi-globe',
  }
  return map[ch.channel_type] ?? 'pi-comment'
}

function channelHref(ch: ContactChannel): string {
  if (ch.channel_type === 'phone') return `tel:${ch.value}`
  if (ch.channel_type === 'email') return `mailto:${ch.value}`
  if (ch.channel_type === 'tg') return `https://t.me/${ch.value.replace('@', '')}`
  if (ch.channel_type === 'wa') return `https://wa.me/${ch.value.replace(/\D/g, '')}`
  if (ch.channel_type === 'website') {
    return ch.value.startsWith('http') ? ch.value : `https://${ch.value}`
  }
  return '#'
}

function channelLinkTag(ch: ContactChannel): string {
  return ['phone', 'email', 'tg', 'wa', 'website'].includes(ch.channel_type) ? 'a' : 'span'
}

function channelLinkTarget(ch: ContactChannel): string | undefined {
  if (['tg', 'wa', 'website', 'linkedin', 'instagram'].includes(ch.channel_type)) return '_blank'
  return undefined
}

function channelActionLabel(ch: ContactChannel): string {
  if (ch.channel_type === 'phone') return t('crm.contact.channels.call')
  if (ch.channel_type === 'email') return t('crm.contact.channels.sendEmail')
  if (ch.channel_type === 'website') return t('crm.contact.channels.openSite')
  return t('crm.contact.channels.openChat')
}

// ── Menu ──────────────────────────────────────────────────────────────────────

function setMenuRef(id: number, el: unknown) {
  if (el) menuRefs.value.set(id, el as InstanceType<typeof Menu>)
  else menuRefs.value.delete(id)
}

function onMenuClick(event: Event, ch: ContactChannel) {
  menuRefs.value.get(ch.id)?.toggle(event)
}

const settingPrimaryId = ref<number | null>(null)

function channelMenuItems(ch: ContactChannel) {
  const items = []
  if (!ch.is_primary_for_channel) {
    items.push({
      label: t('crm.contact.channels.setPrimary'),
      icon: 'pi pi-star',
      command: () => onSetPrimary(ch),
    })
  }
  items.push({
    label: t('common.delete'),
    icon: 'pi pi-times',
    command: () => onDeleteChannel(ch),
  })
  return items
}

// ── Copy ──────────────────────────────────────────────────────────────────────

async function copyValue(value: string) {
  try {
    await navigator.clipboard.writeText(value)
    toast.add({ severity: 'success', summary: t('common.copied', 'Скопировано'), life: 1500 })
  } catch {
    // silent fail
  }
}

// ── Add — Popover ─────────────────────────────────────────────────────────────

const newChannelType = ref<ChannelType | null>(null)
const newChannelValue = ref('')
const saving = ref(false)

const channelTypeOptions = computed(() => [
  { value: 'phone', label: t('crm.contact.channels.phone') },
  { value: 'email', label: t('crm.contact.channels.email') },
  { value: 'tg', label: t('crm.contact.channels.telegram') },
  { value: 'wa', label: t('crm.contact.channels.whatsapp') },
  { value: 'linkedin', label: t('crm.contact.channels.linkedin') },
  { value: 'instagram', label: t('crm.contact.channels.instagram') },
  { value: 'viber', label: t('crm.contact.channels.viber') },
  { value: 'website', label: t('crm.contact.channels.website') },
])

function channelPlaceholder(type: ChannelType | null): string {
  if (!type) return t('crm.contact.channels.valuePlaceholder')
  if (type === 'phone') return '+7 (999) 000-00-00'
  if (type === 'email') return 'email@example.com'
  if (type === 'tg') return '@username'
  if (type === 'wa') return '+7 (999) 000-00-00'
  if (type === 'linkedin') return 'https://linkedin.com/in/...'
  if (type === 'instagram') return '@username'
  if (type === 'website') return 'https://example.com'
  return ''
}

function toggleAddPopover(event: Event) {
  addPopoverRef.value?.toggle(event)
}

function openAdd() {
  // Legacy expose kept for CompanyPage/ContactPage openAdd() delegation
  // Now delegates to popover — trigger via button click simulation is not needed;
  // parent just calls openAdd() to programmatically show it; we open state instead.
  newChannelType.value = null
  newChannelValue.value = ''
}

defineExpose({ openAdd })

function cancelAdd() {
  addPopoverRef.value?.hide()
  newChannelType.value = null
  newChannelValue.value = ''
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
    cancelAdd()
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

// ── Set primary ───────────────────────────────────────────────────────────────

async function onSetPrimary(ch: ContactChannel) {
  if (settingPrimaryId.value) return
  settingPrimaryId.value = ch.id
  try {
    const updated = await contactsApi.updateChannel(props.contactId, ch.id, {
      is_primary_for_channel: true,
    })
    // Backend auto-unsets others of same type; reflect via refetch
    const newChannels = props.channels.map((c) =>
      c.id === updated.id
        ? updated
        : c.channel_type === updated.channel_type
          ? { ...c, is_primary_for_channel: false }
          : c,
    )
    emit('updated', newChannels)
    toast.add({ severity: 'success', summary: t('crm.contact.channels.setPrimarySuccess'), life: 2500 })
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
}

.contact-channels__skeleton {
  display: flex;
  flex-direction: column;
  padding: $space-2 0;
}

// ─── Channel row — spec §4 ────────────────────────────────────────────────────

.contact-channels__row {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-2 $space-3;
  border-bottom: 1px solid var(--p-surface-100);
  transition: background var(--app-transition-fast);

  &:last-of-type {
    border-bottom: none;
  }

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-100);
    }
  }
}

// ─── Action icon circle 30×30 — spec §4 ──────────────────────────────────────

.contact-channels__action-icon {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 30px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  height: 30px;
  border-radius: $radius-circle;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  background: $primary-100;
  color: $primary-900;
  text-decoration: none;
  transition: background var(--app-transition-fast), color var(--app-transition-fast);
  cursor: pointer;

  i {
    font-size: $font-size-sm;
  }

  // On hover: turns navy (#172747) with white icon — spec §4
  &--hovered {
    background: $brand-header-bg;
    color: $sidebar-text-active;
  }

  .app-dark & {
    background: var(--p-primary-900);
    color: var(--p-primary-200);

    &.contact-channels__action-icon--hovered {
      background: $brand-header-bg;
      color: $sidebar-text-active;
    }
  }
}

// ─── Info column (value + action label) ──────────────────────────────────────

.contact-channels__info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.contact-channels__value-row {
  display: flex;
  align-items: center;
  gap: $space-1;
  min-width: 0;
}

.contact-channels__value {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-800;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.contact-channels__primary-star {
  font-size: $font-size-2xs;
  color: var(--p-yellow-500);
  flex-shrink: 0;
}

// Action label — shown on hover; spec §4: right label = «Позвонить / Написать / Открыть чат»
.contact-channels__action-label {
  font-size: $font-size-2xs;
  color: $primary-900;
  font-weight: $font-weight-semibold;
  opacity: 0;
  transition: opacity var(--app-transition-fast);
  white-space: nowrap;

  &--visible {
    opacity: 1;
  }

  .app-dark & {
    color: var(--p-primary-200);
  }
}

// ─── Right actions (copy + more) ─────────────────────────────────────────────

.contact-channels__copy-btn,
.contact-channels__more-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 24px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  height: 24px;
  border-radius: $radius-sm;
  border: none;
  background: transparent;
  color: $surface-400;
  cursor: pointer;
  flex-shrink: 0;
  opacity: 0;
  transition: opacity var(--app-transition-fast), background var(--app-transition-fast);

  i {
    font-size: $font-size-2xs;
  }

  .contact-channels__row:hover & {
    opacity: 1;
  }

  &:hover {
    background: var(--p-surface-100);
    color: $surface-600;

    .app-dark & {
      background: var(--p-surface-200);
      color: var(--p-surface-200);
    }
  }
}

// ─── Add trigger ──────────────────────────────────────────────────────────────

.contact-channels__add-trigger {
  padding: $space-1 $space-2;
}

// ─── Add form (inside Popover) ─────────────────────────────────────────────────

.contact-channels__add-form {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  min-width: 240px;
}

.contact-channels__type-select {
  width: 100%;
}

.contact-channels__value-input {
  width: 100%;
}

.contact-channels__add-actions {
  display: flex;
  gap: $space-2;
  justify-content: flex-end;
}

// ─── Empty ────────────────────────────────────────────────────────────────────

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
</style>
