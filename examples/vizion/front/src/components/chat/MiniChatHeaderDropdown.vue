<template>
  <div class="mini-chat-dropdown">
    <button
      ref="triggerRef"
      type="button"
      class="mini-chat-dropdown__trigger"
      :class="{ 'is-open': isOpen, 'is-disabled': disabled }"
      :disabled="disabled"
      :aria-haspopup="true"
      :aria-expanded="isOpen"
      :title="triggerLabel"
      @click="togglePopover"
    >
      <i class="pi pi-sparkles mini-chat-dropdown__trigger-icon" aria-hidden="true" />
      <span class="mini-chat-dropdown__trigger-label">{{ triggerLabel }}</span>
      <i
        class="pi pi-chevron-down mini-chat-dropdown__trigger-arrow"
        :class="{ 'is-rotated': isOpen }"
        aria-hidden="true"
      />
    </button>

    <Popover
      ref="popoverRef"
      append-to="body"
      :base-z-index="DROPDOWN_BASE_Z_INDEX"
      :dismissable="true"
      :pt="{
        root: { class: 'mini-chat-dropdown__overlay' },
        content: { class: 'mini-chat-dropdown__content' },
      }"
      @show="handleShow"
      @hide="handleHide"
    >
      <div class="mini-chat-dropdown__panel" @click.stop>
        <button
          type="button"
          class="mini-chat-dropdown__new"
          @click="handleNewChat"
        >
          <i class="pi pi-plus" aria-hidden="true" />
          <span>{{ t('miniChat.newChat') }}</span>
        </button>

        <div class="mini-chat-dropdown__divider" role="separator" />

        <div class="mini-chat-dropdown__header">
          {{ t('miniChat.recentChats') }}
        </div>

        <div v-if="isLoading" class="mini-chat-dropdown__state">
          <i class="pi pi-spin pi-spinner" aria-hidden="true" />
          <span>{{ t('miniChat.loadingHistory') }}</span>
        </div>

        <div v-else-if="items.length === 0" class="mini-chat-dropdown__state">
          {{ t('miniChat.noRecentChats') }}
        </div>

        <ul v-else class="mini-chat-dropdown__list" role="listbox">
          <li
            v-for="chat in items"
            :key="chat.id"
            class="mini-chat-dropdown__item"
            :class="{
              'is-active': chat.id === currentId,
              'is-stale': chat.isActiveWindow === false,
            }"
            role="option"
            :aria-selected="chat.id === currentId"
            @click="handleSelect(chat.id)"
          >
            <span class="mini-chat-dropdown__item-title">
              {{ getLabel(chat) }}
            </span>
            <span class="mini-chat-dropdown__item-time">
              {{ formatRelative(chat) }}
            </span>
          </li>
        </ul>
      </div>
    </Popover>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import Popover from 'primevue/popover'
import { useI18n } from 'vue-i18n'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { TOOLBOX_POPOVER_BASE_Z_INDEX } from '@/components/Toolbox'

/**
 * Bumped above the parent MiniChat popover's base z-index so the dropdown
 * opens above the chat panel (the dropdown is nested inside the popover
 * but PrimeUix's internal counter is unreliable for inkrements — see
 * [[primevue_zindex_runtime_quirk]]). +100 leaves headroom for tooltips
 * that may anchor inside the dropdown.
 */
const DROPDOWN_BASE_Z_INDEX = TOOLBOX_POPOVER_BASE_Z_INDEX + 100
import type { ChatListItem } from '@/entities/chat'
import en from './locale/en.json'
import ru from './locale/ru.json'

interface Props {
  items: ChatListItem[]
  /** Id of the currently open chat (highlighted in the list). `null` in preview-state. */
  currentId: number | null
  /** Label rendered on the trigger button (current chat title or "New chat" fallback). */
  triggerLabel: string
  /** `true` while the widget is in preview-state — disables the "New chat" action. */
  isPreview: boolean
  isLoading?: boolean
  disabled?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  isLoading: false,
  disabled: false,
})

const emit = defineEmits<{
  select: [chatId: number]
  'new-chat': []
}>()

const { t } = useLocalI18n({ en, ru })
const { locale } = useI18n()

type PopoverInstance = {
  toggle: (event: Event, target?: HTMLElement) => void
  hide: () => void
}

const popoverRef = ref<PopoverInstance | null>(null)
const triggerRef = ref<HTMLButtonElement | null>(null)
const isOpen = ref(false)

const togglePopover = (event: MouseEvent): void => {
  if (props.disabled) return
  popoverRef.value?.toggle(event)
}

const closePopover = (): void => {
  popoverRef.value?.hide()
}

const handleShow = (): void => {
  isOpen.value = true
}

const handleHide = (): void => {
  isOpen.value = false
}

/**
 * "Новый диалог" — idempotent. In preview-state we're already on a fresh
 * canvas, so the action is a no-op visually (just dismiss the dropdown).
 * In existing-chat state, emit so the parent calls `enterPreview()` to
 * drop the open chat and reset the body to the empty-state.
 */
const handleNewChat = (): void => {
  if (!props.isPreview) {
    emit('new-chat')
  }
  closePopover()
}

const handleSelect = (chatId: number): void => {
  if (chatId === props.currentId) {
    closePopover()
    return
  }
  emit('select', chatId)
  closePopover()
}

const getLabel = (chat: ChatListItem): string => {
  const raw =
    chat.title ?? chat.lastMessage?.content ?? t('sidebar.newChatFallback')
  return raw.length > 60 ? raw.slice(0, 60) + '…' : raw
}

/**
 * Compact relative time ("5 минут назад" / "2 ч назад" / "вчера" / fallback
 * to short locale-formatted date). `Intl.RelativeTimeFormat` is sufficient
 * for the precision we need here; we don't pull in a heavyweight date lib
 * for a single use-site.
 */
const formatRelative = (chat: ChatListItem): string => {
  const raw = chat.lastMessageAt ?? chat.updatedAt
  if (!raw) return ''

  const ts = Date.parse(raw)
  if (Number.isNaN(ts)) return ''

  const diffSec = Math.round((Date.now() - ts) / 1000)
  const localeCode = locale.value === 'ru' ? 'ru-RU' : 'en-US'
  const rtf = new Intl.RelativeTimeFormat(localeCode, { numeric: 'auto' })

  if (diffSec < 60) return rtf.format(-Math.max(diffSec, 1), 'second')
  const diffMin = Math.round(diffSec / 60)
  if (diffMin < 60) return rtf.format(-diffMin, 'minute')
  const diffHr = Math.round(diffMin / 60)
  if (diffHr < 24) return rtf.format(-diffHr, 'hour')
  const diffDay = Math.round(diffHr / 24)
  if (diffDay < 7) return rtf.format(-diffDay, 'day')

  // Older than a week → short locale date (no relative wording).
  return new Date(ts).toLocaleDateString(localeCode, {
    day: '2-digit',
    month: '2-digit',
  })
}

const closeFromOutside = (): void => closePopover()

defineExpose({
  /** Used by the widget to dismiss the dropdown when the user clicks elsewhere. */
  close: closeFromOutside,
})
</script>

<style lang="scss" scoped>
.mini-chat-dropdown {
  position: relative;
  display: inline-flex;
  align-items: center;
  min-width: 0;
  flex: 1 1 auto;
}

.mini-chat-dropdown__trigger {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  min-width: 0;
  max-width: 100%;
  padding: 0.25rem 0.5rem;
  background: transparent;
  border: 1px solid transparent;
  border-radius: $radius-md;
  color: $surface-900;
  font-weight: $font-weight-semibold;
  font-size: $font-size-sm;
  cursor: pointer;
  transition:
    background-color $transition-fast,
    border-color $transition-fast,
    color $transition-fast;

  &:hover:not(.is-disabled) {
    background: $surface-100;
    border-color: $surface-200;
  }

  &.is-open {
    background: $surface-100;
    border-color: $surface-300;
  }

  &.is-disabled {
    opacity: 0.65;
    cursor: not-allowed;
  }
}

.mini-chat-dropdown__trigger-icon {
  color: $primary;
  flex-shrink: 0;
}

.mini-chat-dropdown__trigger-label {
  flex: 1 1 auto;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: left;
}

.mini-chat-dropdown__trigger-arrow {
  flex-shrink: 0;
  color: $surface-500;
  font-size: 0.75rem;
  transition: transform $transition-fast;

  &.is-rotated {
    transform: rotate(180deg);
  }
}

// Overlay (teleported to body) — global selectors so they reach the
// body-mounted Popover. Same pattern as `_mini-chat-overlay.scss`.
:global(.mini-chat-dropdown__overlay) {
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-md;
  max-width: 360px;
  min-width: 260px;
}

:global(.mini-chat-dropdown__content) {
  padding: 0 !important;
}

.mini-chat-dropdown__panel {
  display: flex;
  flex-direction: column;
  max-height: 360px;
  overflow: hidden;
  background: $surface-0;
  border-radius: inherit;
}

.mini-chat-dropdown__new {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  padding: $space-3;
  border: none;
  background: transparent;
  color: $primary;
  font-weight: $font-weight-semibold;
  font-size: $font-size-sm;
  cursor: pointer;
  text-align: left;
  width: 100%;

  &:hover {
    background: $surface-100;
  }

  .pi {
    color: $primary;
  }
}

.mini-chat-dropdown__divider {
  border-top: 1px solid $surface-200;
  margin: 0;
}

.mini-chat-dropdown__header {
  padding: $space-2 $space-3;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: $surface-500;
}

.mini-chat-dropdown__state {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  padding: $space-3;
  color: $surface-600;
  font-size: $font-size-sm;

  .pi {
    color: $primary;
  }
}

.mini-chat-dropdown__list {
  list-style: none;
  margin: 0;
  padding: 0;
  overflow-y: auto;
  flex: 1;
}

.mini-chat-dropdown__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-2;
  padding: $space-2 $space-3;
  cursor: pointer;
  color: $surface-800;
  font-size: $font-size-sm;
  transition: background-color $transition-fast;

  &:hover {
    background: $surface-100;
  }

  &.is-active {
    background: rgba($primary, 0.1);
    color: $primary;
    font-weight: $font-weight-semibold;
  }

  &.is-stale {
    opacity: 0.6;
  }
}

.mini-chat-dropdown__item-title {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mini-chat-dropdown__item-time {
  flex-shrink: 0;
  font-size: $font-size-xs;
  color: $surface-500;
}
</style>
