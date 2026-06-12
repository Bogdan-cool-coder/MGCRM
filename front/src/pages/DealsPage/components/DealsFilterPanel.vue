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

    <!-- Owner filter -->
    <Select
      v-model="localOwnerId"
      :options="users"
      option-label="full_name"
      option-value="id"
      :placeholder="t('sales.deals.page.filters.owner')"
      show-clear
      class="deals-filter-panel__select"
      @change="onOwnerChange"
    />

    <!-- Company filter -->
    <AutoComplete
      v-model="localCompany"
      :suggestions="companySuggestions"
      option-label="name"
      :placeholder="t('sales.deals.page.filters.company')"
      show-clear
      :delay="300"
      class="deals-filter-panel__autocomplete"
      @complete="searchCompanies($event.query)"
      @option-select="onCompanySelect"
      @clear="onCompanyClear"
    />

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
import AutoComplete from 'primevue/autocomplete'
import Button from 'primevue/button'
import { companiesApi } from '@/api/crm/companies'
import type { DealsFilters, CompanyFilterRef } from '../composables/useDealsFilters'
import type { PipelineStageDto } from '@/entities/sales'

interface UserOption {
  id: number
  full_name: string
}

const props = defineProps<{
  filters: DealsFilters
  stages: PipelineStageDto[]
  showStageFilter?: boolean
  users?: UserOption[]
}>()

const emit = defineEmits<{
  'update:filters': [filters: DealsFilters]
  searchInput: []
  filterSelect: []
  reset: []
}>()

const { t } = useI18n()

// Local mirror state (avoids direct prop mutation)
const localQ = ref(props.filters.q)
const localOwnerId = ref<number | null>(props.filters.owner_id)
const localCompany = ref<CompanyFilterRef | null>(props.filters.company)
const localStageId = ref<number | null>(props.filters.stage_id)

// Keep in sync if parent resets
watch(() => props.filters, (next) => {
  localQ.value = next.q
  localOwnerId.value = next.owner_id
  localCompany.value = next.company
  localStageId.value = next.stage_id
}, { deep: true })

const companySuggestions = ref<CompanyFilterRef[]>([])

let debounceTimer: ReturnType<typeof setTimeout> | null = null

function onQInput() {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    emit('update:filters', { ...props.filters, q: localQ.value })
    emit('searchInput')
  }, 300)
}

function onOwnerChange() {
  emit('update:filters', { ...props.filters, owner_id: localOwnerId.value })
  emit('filterSelect')
}

function onCompanySelect() {
  emit('update:filters', { ...props.filters, company: localCompany.value })
  emit('filterSelect')
}

function onCompanyClear() {
  localCompany.value = null
  emit('update:filters', { ...props.filters, company: null })
  emit('filterSelect')
}

function onStageChange() {
  emit('update:filters', { ...props.filters, stage_id: localStageId.value })
  emit('filterSelect')
}

function onReset() {
  localQ.value = ''
  localOwnerId.value = null
  localCompany.value = null
  localStageId.value = null
  emit('reset')
}

async function searchCompanies(query: string) {
  if (!query || query.length < 1) {
    companySuggestions.value = []
    return
  }
  try {
    const res = await companiesApi.list({ search: query, per_page: 10 })
    companySuggestions.value = res.data.map((c) => ({ id: c.id, name: c.name }))
  } catch {
    companySuggestions.value = []
  }
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

.deals-filter-panel__autocomplete {
  min-width: 180px;
}
</style>
