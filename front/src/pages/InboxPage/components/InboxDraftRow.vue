<template>
  <div
    :class="['inbox-draft-row', { 'inbox-draft-row--selected': selected }]"
    role="button"
    tabindex="0"
    @click="emit('open', draft)"
    @keydown.enter="emit('open', draft)"
    @keydown.space.prevent="emit('open', draft)"
  >
    <span class="inbox-draft-row__icon">
      <i class="pi pi-file-edit" />
    </span>
    <div class="inbox-draft-row__main">
      <div class="inbox-draft-row__top">
        <span class="inbox-draft-row__subject">
          {{ draft.subject || t('inbox.drafts.noSubject') }}
        </span>
        <span class="inbox-draft-row__when" :title="fullDatetime">{{ relativeTime }}</span>
      </div>
      <div class="inbox-draft-row__snippet">
        {{ draft.body || t('inbox.drafts.emptyBody') }}
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { OPERATIONAL_TZ } from '@/utils/activity'
import type { InboxDraft } from '@/api/inbox'

const props = defineProps<{
  draft: InboxDraft
  selected?: boolean
}>()

const emit = defineEmits<{
  open: [draft: InboxDraft]
}>()

const { t } = useI18n()

const relativeTime = computed(() => {
  const d = new Date(props.draft.updated_at)
  const diffMs = Date.now() - d.getTime()
  const diffMin = Math.floor(diffMs / 60_000)
  const diffH = Math.floor(diffMs / 3_600_000)
  const diffD = Math.floor(diffMs / 86_400_000)
  if (diffMin < 1) return t('inbox.drafts.justNow')
  if (diffMin < 60) return `${diffMin} мин`
  if (diffH < 24) return `${diffH} ч`
  if (diffD === 1) return 'вчера'
  const parts = new Intl.DateTimeFormat('ru-RU', {
    timeZone: OPERATIONAL_TZ,
    day: '2-digit',
    month: '2-digit',
  }).formatToParts(d)
  const day = parts.find((p) => p.type === 'day')?.value ?? '??'
  const month = parts.find((p) => p.type === 'month')?.value ?? '??'
  return `${day}.${month}`
})

const fullDatetime = computed(() =>
  new Intl.DateTimeFormat('ru-RU', {
    timeZone: OPERATIONAL_TZ,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(props.draft.updated_at)),
)
</script>

<style lang="scss" scoped>
.inbox-draft-row {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-3 $space-4;
  border-bottom: 1px solid $surface-200;
  border-left: 3px solid transparent;
  cursor: pointer;
  transition: background-color $transition-fast;

  .app-dark & {
    border-bottom-color: var(--p-surface-200);
  }

  &:last-child {
    border-bottom: none;
  }

  &:hover {
    background-color: var(--mg-surface-hover);
  }

  &:focus-visible {
    outline: 2px solid $primary-color;
    outline-offset: -2px;
  }

  &--selected {
    border-left-color: $primary-color;
    background-color: $primary-50;

    .app-dark & {
      background-color: var(--p-surface-200);
    }

    &:hover {
      background-color: $primary-50;

      .app-dark & {
        background-color: var(--p-surface-200);
      }
    }
  }
}

.inbox-draft-row__icon {
  width: 34px;
  height: 34px;
  flex-shrink: 0;
  border-radius: $radius-circle;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: color-mix(in srgb, var(--p-primary-color) 14%, var(--mg-surface-card));
  color: var(--p-primary-color);

  .pi {
    font-size: $font-size-sm;
  }
}

.inbox-draft-row__main {
  flex: 1;
  min-width: 0;
}

.inbox-draft-row__top {
  display: flex;
  align-items: baseline;
  gap: $space-2;
}

.inbox-draft-row__subject {
  flex: 1;
  min-width: 0;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.inbox-draft-row__when {
  flex-shrink: 0;
  font-size: $font-size-xs;
  color: $surface-400;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.inbox-draft-row__snippet {
  margin-top: $space-1;
  font-size: $font-size-sm;
  color: $surface-500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-500);
  }
}
</style>
