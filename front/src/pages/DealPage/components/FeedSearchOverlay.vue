<template>
  <Transition name="feed-search">
    <div v-if="open" class="feed-search-overlay">
      <InputText
        v-model="localSearch"
        :placeholder="t('sales.deal.feed.searchPlaceholder')"
        autofocus
        size="small"
        class="feed-search-overlay__input"
        @input="emit('search', localSearch)"
      />
      <Select
        v-model="localType"
        :options="feedTypeOptions"
        option-label="label"
        option-value="value"
        :placeholder="t('sales.deal.feed.filterType')"
        show-clear
        size="small"
        class="feed-search-overlay__type"
        @change="emit('filter', localType ?? '')"
      />
      <Button
        icon="pi pi-times"
        severity="secondary"
        text
        size="small"
        @click="onReset"
      />
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'

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
const localType = ref<string | null>(null)

const feedTypeOptions = computed(() => [
  { value: 'stage_change', label: t('sales.deal.feed.types.stage_change') },
  { value: 'field_change', label: t('sales.deal.feed.types.field_change') },
  { value: 'note', label: t('sales.deal.feed.types.note') },
  { value: 'task', label: t('sales.deal.feed.types.task') },
  { value: 'call', label: t('sales.deal.feed.types.call') },
  { value: 'meeting', label: t('sales.deal.feed.types.meeting') },
])

function onReset() {
  localSearch.value = ''
  localType.value = null
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
  padding: $space-2 $space-3;
  display: flex;
  align-items: center;
  gap: $space-2;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);

  .app-dark & {
    border-color: var(--p-surface-700);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  }
}

.feed-search-overlay__input {
  width: 180px;
}

.feed-search-overlay__type {
  width: 130px;
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
