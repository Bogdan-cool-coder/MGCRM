<!-- Legacy filter panel — superseded by DealsFilterOverlay + DealsToolbar (Kanban 2.0). -->
<!-- Kept for reference but no longer rendered by DealsPage/index.vue. -->
<template>
  <div class="deals-filter-panel">
    <!-- Search -->
    <span class="p-input-icon-left deals-filter-panel__search-wrap">
      <i class="pi pi-search" />
      <InputText
        v-model="localQ"
        :placeholder="t('sales.deals.page.filters.search')"
        class="deals-filter-panel__search"
        @input="onQInput"
      />
    </span>

    <!-- Stage filter (list view only) -->
    <Select
      v-if="showStageFilter"
      v-model="localStageId"
      :options="stages"
      option-label="name"
      option-value="id"
      :placeholder="t('sales.deals.page.filters.stage')"
      show-clear
      class="deals-filter-panel__select"
      @change="onStageChange"
    />

    <!-- Reset -->
    <Button
      icon="pi pi-refresh"
      :label="t('sales.deals.page.filters.reset')"
      severity="secondary"
      text
      @click="onReset"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import type { DealsFilters } from '../composables/useDealsFilters'
import type { PipelineStageDto } from '@/entities/sales'

const props = defineProps<{
  filters: DealsFilters
  stages: PipelineStageDto[]
  showStageFilter?: boolean
  users?: { id: number; full_name: string }[]
}>()

const emit = defineEmits<{
  'update:filters': [filters: DealsFilters]
  searchInput: []
  filterSelect: []
  reset: []
}>()

const { t } = useI18n()

const localQ = ref(props.filters.q)
const localStageId = ref<number | null>(props.filters.stage_id)

watch(() => props.filters, (next) => {
  localQ.value = next.q
  localStageId.value = next.stage_id
}, { deep: true })

let debounceTimer: ReturnType<typeof setTimeout> | null = null

function onQInput() {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    emit('update:filters', { ...props.filters, q: localQ.value })
    emit('searchInput')
  }, 300)
}

function onStageChange() {
  emit('update:filters', { ...props.filters, stage_id: localStageId.value })
  emit('filterSelect')
}

function onReset() {
  localQ.value = ''
  localStageId.value = null
  emit('reset')
}
</script>

<style lang="scss" scoped>
.deals-filter-panel {
  display: flex;
  align-items: center;
  gap: $space-3;
  flex-wrap: wrap;
  padding: $space-3 0;
}

.deals-filter-panel__search-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;

  i {
    position: absolute;
    left: $space-3;
    color: $surface-400;
    pointer-events: none;
    z-index: 1;
  }
}

.deals-filter-panel__search {
  padding-left: 2.25rem;
  min-width: 220px;
}

.deals-filter-panel__select {
  min-width: 160px;
}
</style>
