<template>
  <div class="inbox-search">
    <!-- Search pill + filter trigger -->
    <div :class="['inbox-search__pill', { 'inbox-search__pill--active': activeCount > 0 }]">
      <i class="pi pi-search inbox-search__search-icon" />
      <input
        :value="q"
        type="text"
        class="inbox-search__input"
        :placeholder="t('inbox.filters.searchPlaceholder')"
        @input="onInput"
      />
      <button
        v-if="q"
        type="button"
        class="inbox-search__clear"
        :aria-label="t('inbox.filters.reset')"
        @click="clearSearch"
      >
        <i class="pi pi-times" />
      </button>
      <button
        type="button"
        :class="['inbox-search__trigger', { 'inbox-search__trigger--active': activeCount > 0 }]"
        :title="t('inbox.filters.openFilters')"
        :aria-label="t('inbox.filters.openFilters')"
        @click="togglePanel"
      >
        <i class="pi pi-sliders-h" />
        <span v-if="activeCount > 0" class="inbox-search__trigger-count">{{ activeCount }}</span>
      </button>
    </div>

    <!-- Filter panel (folders + channels) -->
    <Popover ref="panelRef" class="inbox-search__panel">
      <div class="inbox-search__panel-body">
        <!-- Folders (radio-like) -->
        <div class="inbox-search__section-label">{{ t('inbox.folders.label') }}</div>
        <div class="inbox-search__chips">
          <button
            v-for="opt in folderOptions"
            :key="opt.value"
            type="button"
            :class="['inbox-search__chip', { 'inbox-search__chip--on': folder === opt.value }]"
            @click="emit('update:folder', opt.value)"
          >
            <i :class="['pi', opt.icon]" />
            {{ opt.label }}
            <span v-if="opt.count > 0" class="inbox-search__chip-count">· {{ opt.count }}</span>
          </button>
        </div>

        <div class="inbox-search__divider" />

        <!-- Channels (toggle) -->
        <div class="inbox-search__section-label">{{ t('inbox.channels.label') }}</div>
        <div class="inbox-search__chips">
          <button
            v-for="opt in channelOptions"
            :key="opt.value"
            type="button"
            :class="['inbox-search__chip', { 'inbox-search__chip--on': channel === opt.value }]"
            @click="emit('update:channel', channel === opt.value ? null : opt.value)"
          >
            <i :class="['pi', opt.icon]" />
            {{ opt.label }}
            <span v-if="opt.count > 0" class="inbox-search__chip-count">· {{ opt.count }}</span>
          </button>
        </div>

        <div class="inbox-search__divider" />

        <!-- Received date range -->
        <div class="inbox-search__section-label">{{ t('inbox.filters.dateRange') }}</div>
        <DatePicker
          :model-value="dateRange ?? undefined"
          selection-mode="range"
          :manual-input="false"
          date-format="dd.mm.yy"
          show-button-bar
          show-icon
          icon-display="input"
          :placeholder="t('inbox.filters.dateRangePlaceholder')"
          class="inbox-search__date"
          append-to="self"
          @update:model-value="onDateRange"
        />

        <!-- Footer actions -->
        <div class="inbox-search__panel-footer">
          <Button
            v-if="hasActiveFilters"
            :label="t('inbox.filters.reset')"
            severity="secondary"
            text
            size="small"
            @click="onReset"
          />
          <Button
            :label="t('inbox.filters.done')"
            severity="primary"
            size="small"
            @click="closePanel"
          />
        </div>
      </div>
    </Popover>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Popover from 'primevue/popover'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import type { ChannelKind, InboxCounts } from '@/api/inbox'
import type { InboxFolder } from '../composables/useInboxPage'

const props = defineProps<{
  q: string
  folder: InboxFolder
  channel: ChannelKind | null
  hasActiveFilters: boolean
  counts: InboxCounts | null
  dateRange: [Date | null, Date | null] | null
}>()

const emit = defineEmits<{
  search: [value: string]
  'update:folder': [value: InboxFolder]
  'update:channel': [value: ChannelKind | null]
  'update:dateRange': [value: [Date | null, Date | null] | null]
  reset: []
}>()

const { t } = useI18n()

const panelRef = ref<InstanceType<typeof Popover> | null>(null)

const hasDateRange = computed(
  () => !!props.dateRange && (props.dateRange[0] !== null || props.dateRange[1] !== null),
)

/** Count of non-default filters shown as a badge on the trigger. */
const activeCount = computed(() => {
  let n = 0
  if (props.folder !== 'all') n += 1
  if (props.channel !== null) n += 1
  if (hasDateRange.value) n += 1
  return n
})

/** Per-folder badge counts (0 when counts not yet loaded). */
const folderOptions = computed<{ value: InboxFolder; icon: string; label: string; count: number }[]>(
  () => {
    const c = props.counts?.folders
    return [
      { value: 'all', icon: 'pi-inbox', label: t('inbox.folders.all'), count: c?.inbox_unread ?? 0 },
      { value: 'starred', icon: 'pi-star', label: t('inbox.folders.starred'), count: c?.starred ?? 0 },
      {
        value: 'important',
        icon: 'pi-flag',
        label: t('inbox.folders.important'),
        count: c?.important ?? 0,
      },
      {
        value: 'failed',
        icon: 'pi-exclamation-triangle',
        label: t('inbox.folders.failed'),
        count: c?.failed ?? 0,
      },
      { value: 'deals', icon: 'pi-briefcase', label: t('inbox.folders.deals'), count: c?.in_deals ?? 0 },
      { value: 'snoozed', icon: 'pi-clock', label: t('inbox.folders.snoozed'), count: c?.snoozed ?? 0 },
      {
        value: 'drafts',
        icon: 'pi-file-edit',
        label: t('inbox.folders.drafts'),
        count: c?.drafts ?? 0,
      },
    ]
  },
)

const channelOptions = computed<{ value: ChannelKind; icon: string; label: string; count: number }[]>(
  () => {
    const c = props.counts?.channels
    return [
      { value: 'tg', icon: 'pi-telegram', label: t('inbox.channelKind.tg'), count: c?.tg ?? 0 },
      { value: 'wa', icon: 'pi-whatsapp', label: t('inbox.channelKind.wa'), count: c?.wa ?? 0 },
      { value: 'email', icon: 'pi-envelope', label: t('inbox.channelKind.email'), count: c?.email ?? 0 },
      {
        value: 'web_form',
        icon: 'pi-globe',
        label: t('inbox.channelKind.web_form'),
        count: c?.web_form ?? 0,
      },
      { value: 'api', icon: 'pi-code', label: t('inbox.channelKind.api'), count: c?.api ?? 0 },
    ]
  },
)

function onDateRange(value: unknown) {
  // PrimeVue range mode emits (Date | null)[] | null. Normalize to a 2-tuple.
  if (Array.isArray(value)) {
    const [from = null, to = null] = value as (Date | null)[]
    if (from === null && to === null) {
      emit('update:dateRange', null)
    } else {
      emit('update:dateRange', [from, to])
    }
  } else {
    emit('update:dateRange', null)
  }
}

function onInput(event: Event) {
  emit('search', (event.target as HTMLInputElement).value)
}

function clearSearch() {
  emit('search', '')
}

function togglePanel(event: Event) {
  panelRef.value?.toggle(event)
}

function closePanel() {
  panelRef.value?.hide()
}

function onReset() {
  emit('reset')
  closePanel()
}
</script>

<style lang="scss" scoped>
.inbox-search {
  flex: 1;
  min-width: 240px;
}

// ── Search pill ───────────────────────────────────────────────────────────────
.inbox-search__pill {
  display: flex;
  align-items: center;
  height: 38px;
  padding: 0 $space-1 0 $space-3;
  gap: $space-2;
  border-radius: $radius-pill;
  border: 1px solid $surface-200;
  background: $surface-card; // theme-reactive (light #fff / dark navy card)
  transition: border-color $transition-fast;

  .app-dark & {
    border-color: var(--p-surface-300);
  }

  &:focus-within {
    border-color: $primary-color;
  }
}

.inbox-search__search-icon {
  font-size: $font-size-sm;
  color: var(--p-text-muted-color);
  flex-shrink: 0;
}

.inbox-search__input {
  flex: 1;
  min-width: 0;
  height: 100%;
  border: none;
  outline: none;
  background: transparent;
  font-family: $font-family-sans;
  font-size: $font-size-sm;
  color: var(--p-text-color);

  &::placeholder {
    color: var(--p-text-muted-color);
  }
}

.inbox-search__clear {
  flex-shrink: 0;
  width: 22px;
  height: 22px;
  border: none;
  background: transparent;
  color: var(--p-text-muted-color);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;

  .pi {
    font-size: $font-size-xs;
  }
}

.inbox-search__trigger {
  position: relative;
  flex-shrink: 0;
  width: 30px;
  height: 30px;
  border: none;
  border-radius: $radius-circle;
  background: transparent;
  color: var(--p-text-muted-color);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background-color $transition-fast, color $transition-fast;

  .pi {
    font-size: $font-size-md;
  }

  &:hover {
    background: var(--mg-surface-hover);
  }

  &--active {
    background: $primary-50;
    color: $primary-color;

    .app-dark & {
      background: var(--p-surface-200);
      color: var(--p-primary-color);
    }
  }
}

.inbox-search__trigger-count {
  position: absolute;
  top: -2px;
  right: -2px;
  min-width: 15px;
  height: 15px;
  padding: 0 $space-1;
  border-radius: $radius-pill;
  background: var(--p-red-500);
  color: var(--p-surface-0);
  font-size: $font-size-2xs;
  font-weight: $font-weight-bold;
  display: inline-flex;
  align-items: center;
  justify-content: center;

  .app-dark & {
    color: var(--p-surface-900); // readable light text on the red badge in dark
  }
}

// ── Panel ─────────────────────────────────────────────────────────────────────
.inbox-search__panel-body {
  width: 320px;
  max-width: 90vw;
}

.inbox-search__section-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--p-text-muted-color);
  margin-bottom: $space-2;
}

.inbox-search__chips {
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
}

.inbox-search__chip {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  height: 30px;
  padding: 0 $space-3;
  border-radius: $radius-pill;
  border: 1px solid $surface-200;
  background: transparent;
  color: var(--p-text-muted-color);
  font-family: $font-family-sans;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  white-space: nowrap;
  cursor: pointer;
  transition: border-color $transition-fast, background-color $transition-fast, color $transition-fast;

  .pi {
    font-size: $font-size-xs;
  }

  .app-dark & {
    border-color: var(--p-surface-300);
  }

  &:hover {
    border-color: $primary-300;

    .app-dark & {
      border-color: var(--p-primary-color);
    }
  }

  &--on {
    border-color: $primary-color;
    background: $primary-50;
    color: $primary-color;

    .app-dark & {
      border-color: var(--p-primary-color);
      background: var(--p-surface-200);
      color: var(--p-primary-color);
    }
  }
}

.inbox-search__chip-count {
  font-size: $font-size-xs;
  font-weight: $font-weight-bold;
  opacity: 0.9;
}

.inbox-search__date {
  width: 100%;
}

.inbox-search__divider {
  height: 1px;
  background: $surface-200;
  margin: $space-4 0;

  .app-dark & {
    background: var(--p-surface-300);
  }
}

.inbox-search__panel-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: $space-2;
  margin-top: $space-4;
  padding-top: $space-3;
  border-top: 1px solid $surface-200;

  .app-dark & {
    border-top-color: var(--p-surface-300);
  }
}
</style>
