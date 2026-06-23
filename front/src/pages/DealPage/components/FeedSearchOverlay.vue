<template>
  <Transition name="feed-search">
    <div v-if="open" class="feed-search-overlay">
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

      <!-- Type filter — label + reset link + pill chips (A2, spec §7.1) -->
      <div class="feed-search-overlay__type-header">
        <span class="feed-search-overlay__type-label">{{ t('sales.deal.feed.filterType') }}</span>
        <button
          v-if="localType !== 'all'"
          type="button"
          class="feed-search-overlay__reset"
          @click="onReset"
        >{{ t('common.reset') }}</button>
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
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'

defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  search: [query: string]
  filter: [type: string]
  reset: []
}>()

const { t } = useI18n()

const localSearch = ref('')
const localType = ref<string>('all')

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
</script>

<style lang="scss" scoped>
.feed-search-overlay {
  position: absolute;
  top: 44px;
  right: $space-3;
  z-index: 20;
  background: var(--p-card-background);
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  padding: $space-3;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 320px;
  box-shadow: $shadow-md;
  display: flex;
  flex-direction: column;
  gap: $space-2;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
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
  color: $surface-600;
  flex: 1;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.feed-search-overlay__reset {
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  font-size: $font-size-xs;
  color: var(--p-primary-color);
  text-decoration: underline;
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

  .app-dark & {
    border-color: var(--p-surface-600);
    color: var(--p-surface-300);
  }

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
      background: $primary-900;
      color: $sidebar-text-active;
    }
  }
}

.feed-search-enter-active,
.feed-search-leave-active {
  transition: opacity 0.15s, transform 0.15s;
}

.feed-search-enter-from,
.feed-search-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}
</style>
