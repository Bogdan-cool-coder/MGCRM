<template>
  <div class="chat-sidebar-list">
    <div class="chat-sidebar-list__header">
      <Button
        icon="pi pi-plus"
        :label="resolvedCreateLabel"
        size="small"
        text
        :disabled="disabled"
        @click="handleCreate"
      />
    </div>

    <div class="chat-sidebar-list__items">
      <EmptyState v-if="chats.length === 0" :message="t('sidebar.empty')" icon="pi pi-comments" />

      <button
        v-for="chat in chats"
        :key="chat.id"
        :class="[
          'chat-item',
          {
            'chat-item--active': chat.id === activeChatId,
            'chat-item--disabled': disabled,
          },
        ]"
        :disabled="disabled"
        @click="handleSelect(chat.id)"
      >
        <span class="chat-item__text">{{ getLabel(chat) }}</span>
        <Button
          icon="pi pi-trash"
          text
          rounded
          size="small"
          class="chat-item__delete"
          :aria-label="t('common.delete')"
          :disabled="disabled"
          @click.stop="handleDelete(chat.id)"
        />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import Button from 'primevue/button'
import EmptyState from '@/components/states/EmptyState.vue'
import { useLocalI18n } from '@/composables/useLocalI18n'
import type { ChatListItem } from '@/entities/chat'
import en from './locale/en.json'
import ru from './locale/ru.json'

interface Props {
  chats: ChatListItem[]
  activeChatId: number | null
  createLabel?: string
  disabled?: boolean
}

const props = defineProps<Props>()
const { t } = useLocalI18n({ en, ru })

const emit = defineEmits<{
  select: [id: number]
  create: []
  delete: [id: number]
}>()

const resolvedCreateLabel = computed(() => props.createLabel ?? t('sidebar.defaultCreateLabel'))

const handleCreate = (): void => {
  if (props.disabled) return
  emit('create')
}

const handleSelect = (id: number): void => {
  if (props.disabled) return
  emit('select', id)
}

const handleDelete = (id: number): void => {
  if (props.disabled) return
  emit('delete', id)
}

const getLabel = (chat: ChatListItem): string => {
  const raw = chat.title ?? chat.lastMessage?.content ?? t('sidebar.newChatFallback')
  return raw.length > 50 ? raw.slice(0, 50) + '…' : raw
}
</script>

<style lang="scss" scoped>
.chat-sidebar-list {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;

  &__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5rem 0.75rem;
    border-bottom: 1px solid $surface-200;
    flex-shrink: 0;
  }

  &__items {
    flex: 1;
    overflow-y: auto;
    padding: 0.25rem 0;
  }
}

.chat-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  padding: 0.5rem 0.75rem;
  text-align: left;
  background: none;
  border: none;
  cursor: pointer;
  color: $surface-700;
  transition: background-color $transition-fast;

  &:hover {
    background-color: $surface-200;
  }

  &--active {
    background-color: $surface-200;
    color: $primary;
    font-weight: $font-weight-medium;
  }

  &--disabled {
    cursor: not-allowed;
    opacity: 0.72;
  }

  &__text {
    display: block;
    flex: 1;
    min-width: 0;
    font-size: $font-size-sm;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__delete {
    opacity: 0;
    flex-shrink: 0;
    transition: opacity $transition-fast;
  }

  &:hover &__delete,
  &--active &__delete {
    opacity: 1;
  }

  &--disabled:hover {
    background: none;
  }
}
</style>
