<!-- Feed search + type filter — PrimeVue Popover (Inbox pattern A canon):
     search input → uppercase section-label + reset → pill chips → footer (Reset + Done).
     Exposes toggle(event) so the parent search button can open/close it. -->
<template>
  <Popover ref="panelRef" class="feed-search-overlay">
    <div class="feed-search-overlay__body">
      <!-- Search input -->
      <div class="feed-search-overlay__input-wrap">
        <InputText
          v-model="localSearch"
          :placeholder="t('sales.deal.feed.searchPlaceholder')"
          autofocus
          size="small"
          class="feed-search-overlay__input"
          @input="emit('search', localSearch)"
        />
      </div>

      <!-- Type filter — uppercase section-label + reset link (A2, spec §7.1) -->
      <div class="feed-search-overlay__type-header">
        <span class="feed-search-overlay__type-label">{{ t('sales.deal.feed.filterType') }}</span>
      </div>

      <div class="feed-search-overlay__chips">
        <button
          v-for="opt in feedTypeOptions"
          :key="opt.value"
          type="button"
          class="feed-search-overlay__chip"
          :class="{ 'feed-search-overlay__chip--active': localType === opt.value }"
          @click="toggleType(opt.value)"
        >{{ opt.label }}</button>
      </div>

      <!-- Footer actions -->
      <div class="feed-search-overlay__footer">
        <Button
          v-if="hasActiveFilters"
          :label="t('common.reset')"
          severity="secondary"
          text
          size="small"
          @click="onReset"
        />
        <Button
          :label="t('sales.deal.feed.done')"
          severity="primary"
          size="small"
          @click="closePanel"
        />
      </div>
    </div>
  </Popover>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Popover from 'primevue/popover'

const emit = defineEmits<{
  search: [query: string]
  filter: [type: string]
  reset: []
}>()

const { t } = useI18n()

const panelRef = ref<InstanceType<typeof Popover> | null>(null)
const localSearch = ref('')
const localType = ref<string>('all')

const hasActiveFilters = computed(() => localSearch.value.trim() !== '' || localType.value !== 'all')

const feedTypeOptions = computed(() => [
  { value: 'all', label: t('sales.deal.feed.types.all') },
  { value: 'stage_change', label: t('sales.deal.feed.types.stage_change') },
  { value: 'field_change', label: t('sales.deal.feed.types.field_change') },
  { value: 'note', label: t('sales.deal.feed.types.note') },
  { value: 'task', label: t('sales.deal.feed.types.task') },
  { value: 'call', label: t('sales.deal.feed.types.call') },
  { value: 'meeting', label: t('sales.deal.feed.types.meeting') },
])

function toggleType(value: string) {
  localType.value = value
  emit('filter', value === 'all' ? '' : value)
}

function onReset() {
  localSearch.value = ''
  localType.value = 'all'
  emit('reset')
}

function toggle(event: Event) {
  panelRef.value?.toggle(event)
}

function closePanel() {
  panelRef.value?.hide()
}

defineExpose({ toggle })
</script>

<style lang="scss" scoped>
.feed-search-overlay {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 320px;
}

.feed-search-overlay__body {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.feed-search-overlay__input-wrap {
  display: flex;
}

.feed-search-overlay__input {
  width: 100%;
}

.feed-search-overlay__type-header {
  display: flex;
  align-items: center;
  gap: $space-2;
  margin-top: $space-1;
}

.feed-search-overlay__type-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--p-text-muted-color);
  flex: 1;
}

.feed-search-overlay__chips {
  display: flex;
  flex-wrap: wrap;
  gap: $space-1;
}

.feed-search-overlay__chip {
  display: inline-flex;
  align-items: center;
  padding: 2px $space-2;
  border-radius: $radius-pill;
  border: 1px solid var(--p-surface-300);
  background: transparent;
  font-size: $font-size-xs;
  color: $surface-700;
  cursor: pointer;
  transition: background var(--app-transition-fast), color var(--app-transition-fast), border-color var(--app-transition-fast);
  white-space: nowrap;
  // color $surface-700 + border var(--p-surface-300) theme-reactive — no dark override

  &:hover:not(.feed-search-overlay__chip--active) {
    background: var(--p-surface-100);

    .app-dark & {
      background: var(--p-surface-200);
    }
  }

  &--active {
    background: $primary-100;
    color: $primary-900;
    border-color: transparent;

    .app-dark & {
      background: var(--p-primary-color);
      color: var(--p-primary-contrast-color);
    }
  }
}

.feed-search-overlay__footer {
  display: flex;
  justify-content: flex-end;
  gap: $space-2;
  margin-top: $space-1;
  padding-top: $space-2;
  border-top: 1px solid var(--p-surface-200);
  // theme-reactive soft divider — no dark override
}
</style>
