<template>
  <div class="company-channels">
    <!-- Loading -->
    <div v-if="loading" class="company-channels__skeleton">
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
        class="company-channels__row"
        @mouseenter="hoveredId = ch.id"
        @mouseleave="hoveredId = null"
      >
        <!-- Left: circular action icon 30×30 -->
        <component
          :is="channelLinkTag(ch)"
          :href="channelHref(ch)"
          :target="channelLinkTarget(ch)"
          :rel="channelLinkTarget(ch) ? 'noopener noreferrer' : undefined"
          class="company-channels__action-icon"
          :class="{ 'company-channels__action-icon--hovered': hoveredId === ch.id }"
          :title="channelActionLabel(ch)"
        >
          <i :class="['pi', channelIcon(ch)]" />
        </component>

        <!-- Center: value + action label on hover -->
        <div class="company-channels__info">
          <div class="company-channels__value-row">
            <span class="company-channels__value">{{ ch.value }}</span>
            <!-- Primary star indicator -->
            <i
              v-if="ch.is_primary_for_channel"
              class="pi pi-star-fill company-channels__primary-star"
              :title="t('crm.company.channels.primary')"
            />
          </div>
          <span
            class="company-channels__action-label"
            :class="{ 'company-channels__action-label--visible': hoveredId === ch.id }"
          >
            {{ channelActionLabel(ch) }}
          </span>
        </div>

        <!-- Right: copy icon -->
        <button
          type="button"
          class="company-channels__copy-btn"
          :title="t('common.copy')"
          @click.stop="copyValue(ch.value)"
        >
          <i class="pi pi-copy" />
        </button>

        <!-- Edit / Delete menu trigger -->
        <button
          type="button"
          class="company-channels__more-btn"
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
      <div v-if="channels.length === 0" class="company-channels__empty">
        <i class="pi pi-phone company-channels__empty-icon" />
        <p class="company-channels__empty-text">{{ t('crm.company.channels.empty') }}</p>
      </div>

      <!-- «+канал» Popover trigger -->
      <div class="company-channels__add-trigger">
        <Button
          :label="t('crm.company.channels.addChannel')"
          icon="pi pi-plus"
          size="small"
          severity="secondary"
          text
          @click="toggleAddPopover"
        />
      </div>

      <!-- Add channel Popover -->
      <Popover ref="addPopoverRef" class="company-channels__popover">
        <div class="company-channels__add-form">
          <Select
            v-model="newChannelType"
            :options="channelTypeOptions"
            option-label="label"
            option-value="value"
            :placeholder="t('crm.company.channels.selectType')"
            class="company-channels__type-select"
          />
          <InputText
            v-model="newChannelValue"
            :placeholder="channelPlaceholder(newChannelType)"
            class="company-channels__value-input"
            @keyup.enter="submitAddChannel"
          />
          <label class="company-channels__primary-check">
            <Checkbox v-model="newChannelIsPrimary" :binary="true" />
            <span>{{ t('crm.company.channels.setPrimary') }}</span>
          </label>
          <div class="company-channels__add-actions">
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
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Skeleton from 'primevue/skeleton'
import Menu from 'primevue/menu'
import Popover from 'primevue/popover'
import { companiesApi } from '@/api/crm/companies'
import { getApiErrorMessage } from '@/utils/errors'
import type { CompanyChannel, ChannelType } from '@/entities/crm'

const props = defineProps<{
  companyId: number
  channels: CompanyChannel[]
  loading?: boolean
}>()

const emit = defineEmits<{
  updated: [channels: CompanyChannel[]]
}>()

const { t } = useI18n()
const toast = useToast()
const confirm = useConfirm()

const hoveredId = ref<number | null>(null)
const menuRefs = ref<Map<number, InstanceType<typeof Menu>>>(new Map())
const addPopoverRef = ref<InstanceType<typeof Popover> | null>(null)

// ── Channel action helpers ────────────────────────────────────────────────────

function channelIcon(ch: CompanyChannel): string {
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

function channelHref(ch: CompanyChannel): string {
  if (ch.channel_type === 'phone') return `tel:${ch.value}`
  if (ch.channel_type === 'email') return `mailto:${ch.value}`
  if (ch.channel_type === 'tg') return `https://t.me/${ch.value.replace('@', '')}`
  if (ch.channel_type === 'wa') return `https://wa.me/${ch.value.replace(/\D/g, '')}`
  if (ch.channel_type === 'website') {
    return ch.value.startsWith('http') ? ch.value : `https://${ch.value}`
  }
  if (ch.channel_type === 'linkedin') {
    return ch.value.startsWith('http') ? ch.value : `https://linkedin.com/in/${ch.value}`
  }
  if (ch.channel_type === 'instagram') {
    return `https://instagram.com/${ch.value.replace('@', '')}`
  }
  return '#'
}

function channelLinkTag(ch: CompanyChannel): string {
  return ['phone', 'email', 'tg', 'wa', 'website', 'linkedin', 'instagram'].includes(ch.channel_type)
    ? 'a'
    : 'span'
}

function channelLinkTarget(ch: CompanyChannel): string | undefined {
  if (['tg', 'wa', 'website', 'linkedin', 'instagram'].includes(ch.channel_type)) return '_blank'
  return undefined
}

function channelActionLabel(ch: CompanyChannel): string {
  if (ch.channel_type === 'phone') return t('crm.company.channels.call')
  if (ch.channel_type === 'email') return t('crm.company.channels.sendEmail')
  if (ch.channel_type === 'website') return t('crm.company.channels.openSite')
  return t('crm.company.channels.openChat')
}

// ── Menu ──────────────────────────────────────────────────────────────────────

function setMenuRef(id: number, el: unknown) {
  if (el) menuRefs.value.set(id, el as InstanceType<typeof Menu>)
  else menuRefs.value.delete(id)
}

function onMenuClick(event: Event, ch: CompanyChannel) {
  menuRefs.value.get(ch.id)?.toggle(event)
}

const settingPrimaryId = ref<number | null>(null)

function channelMenuItems(ch: CompanyChannel) {
  const items = []
  if (!ch.is_primary_for_channel) {
    items.push({
      label: t('crm.company.channels.setPrimary'),
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

const addingOpen = ref(false)
const newChannelType = ref<ChannelType | null>(null)
const newChannelValue = ref('')
const newChannelIsPrimary = ref(false)
const saving = ref(false)

// Auto-check «Основной» when the selected type has no existing channel of that type
watch(newChannelType, (type) => {
  if (!type) {
    newChannelIsPrimary.value = false
    return
  }
  newChannelIsPrimary.value = !props.channels.some((c) => c.channel_type === type)
})

const channelTypeOptions = computed(() => [
  { value: 'phone', label: t('crm.company.channels.phone') },
  { value: 'email', label: t('crm.company.channels.email') },
  { value: 'website', label: t('crm.company.channels.website') },
  { value: 'tg', label: t('crm.company.channels.telegram') },
  { value: 'wa', label: t('crm.company.channels.whatsapp') },
  { value: 'linkedin', label: t('crm.company.channels.linkedin') },
  { value: 'instagram', label: t('crm.company.channels.instagram') },
  { value: 'viber', label: t('crm.company.channels.viber') },
])

function channelPlaceholder(type: ChannelType | null): string {
  if (!type) return t('crm.company.channels.valuePlaceholder')
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
  newChannelType.value = null
  newChannelValue.value = ''
}

defineExpose({ openAdd })

function cancelAdd() {
  addPopoverRef.value?.hide()
  addingOpen.value = false
  newChannelType.value = null
  newChannelValue.value = ''
  newChannelIsPrimary.value = false
}

async function submitAddChannel() {
  if (!newChannelType.value || !newChannelValue.value.trim()) return
  saving.value = true
  try {
    const created = await companiesApi.addChannel(props.companyId, {
      channel_type: newChannelType.value,
      value: newChannelValue.value.trim(),
      is_primary_for_channel: newChannelIsPrimary.value || undefined,
    })
    emit('updated', [...props.channels, created])
    cancelAdd()
    toast.add({ severity: 'success', summary: t('crm.company.channels.added'), life: 2500 })
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

async function onSetPrimary(ch: CompanyChannel) {
  if (settingPrimaryId.value) return
  settingPrimaryId.value = ch.id
  try {
    const updated = await companiesApi.updateChannel(props.companyId, ch.id, {
      is_primary_for_channel: true,
    })
    // Optimistic unset others of same channel_type
    const newChannels = props.channels.map((c) =>
      c.id === updated.id
        ? updated
        : c.channel_type === updated.channel_type
          ? { ...c, is_primary_for_channel: false }
          : c,
    )
    emit('updated', newChannels)
    toast.add({ severity: 'success', summary: t('crm.company.channels.setPrimarySuccess'), life: 2500 })
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

function onDeleteChannel(ch: CompanyChannel) {
  confirm.require({
    message: t('crm.company.channels.deleteConfirm'),
    header: t('common.confirm'),
    icon: 'pi pi-exclamation-triangle',
    acceptClass: 'p-button-danger',
    accept: async () => {
      try {
        await companiesApi.deleteChannel(props.companyId, ch.id)
        emit('updated', props.channels.filter((c) => c.id !== ch.id))
        toast.add({ severity: 'success', summary: t('crm.company.channels.deleted'), life: 2500 })
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
.company-channels {
  display: flex;
  flex-direction: column;
}

.company-channels__skeleton {
  display: flex;
  flex-direction: column;
  padding: $space-2 0;
}

// ─── Channel row ─────────────────────────────────────────────────────────────

.company-channels__row {
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

// ─── Action icon circle 30×30 ─────────────────────────────────────────────────

.company-channels__action-icon {
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

  &--hovered {
    background: $brand-header-bg;
    color: $sidebar-text-active;
  }

  .app-dark & {
    background: var(--p-primary-900);
    color: var(--p-primary-200);

    &.company-channels__action-icon--hovered {
      background: $brand-header-bg;
      color: $sidebar-text-active;
    }
  }
}

// ─── Info column (value + action label) ──────────────────────────────────────

.company-channels__info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.company-channels__value-row {
  display: flex;
  align-items: center;
  gap: $space-1;
  min-width: 0;
}

.company-channels__value {
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

.company-channels__primary-star {
  font-size: $font-size-2xs;
  color: var(--p-yellow-500);
  flex-shrink: 0;
}

.company-channels__action-label {
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

.company-channels__copy-btn,
.company-channels__more-btn {
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

  .company-channels__row:hover & {
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

// ─── Empty ────────────────────────────────────────────────────────────────────

.company-channels__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-4;
  text-align: center;
}

.company-channels__empty-icon {
  font-size: $font-size-2xl;
  color: $surface-300;
}

.company-channels__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

// ─── Add trigger ──────────────────────────────────────────────────────────────

.company-channels__add-trigger {
  padding: $space-1 $space-2;
}

// ─── Add form (inside Popover) ────────────────────────────────────────────────

.company-channels__add-form {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  min-width: 240px;
}

.company-channels__type-select {
  width: 100%;
}

.company-channels__value-input {
  width: 100%;
}

.company-channels__primary-check {
  display: flex;
  align-items: center;
  gap: $space-2;
  cursor: pointer;
  font-size: $font-size-sm;
  color: var(--p-text-color);
  user-select: none;
}

.company-channels__add-actions {
  display: flex;
  gap: $space-2;
  justify-content: flex-end;
}
</style>
