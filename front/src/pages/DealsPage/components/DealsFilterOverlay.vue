<template>
  <!-- Backdrop -->
  <Teleport to="body">
    <div v-if="visible" class="filter-overlay-backdrop" @click="onBackdropClick" />
    <div v-if="visible" class="filter-overlay">
      <!-- Presets row -->
      <div class="filter-overlay__presets">
        <span class="filter-overlay__section-label">{{ t('sales.deals.page.filters.presets') }}</span>
        <div class="filter-overlay__preset-chips">
          <ToggleButton
            v-model="localPresets.open"
            :on-label="t('sales.deals.page.filters.presetOpen')"
            :off-label="t('sales.deals.page.filters.presetOpen')"
            class="filter-overlay__preset-chip"
          />
          <ToggleButton
            v-model="localPresets.mine"
            :on-label="t('sales.deals.page.filters.presetMine')"
            :off-label="t('sales.deals.page.filters.presetMine')"
            class="filter-overlay__preset-chip"
          />
          <ToggleButton
            v-model="localPresets.won"
            :on-label="t('sales.deals.page.filters.presetWon')"
            :off-label="t('sales.deals.page.filters.presetWon')"
            class="filter-overlay__preset-chip filter-overlay__preset-chip--success"
          />
          <ToggleButton
            v-model="localPresets.lost"
            :on-label="t('sales.deals.page.filters.presetLost')"
            :off-label="t('sales.deals.page.filters.presetLost')"
            class="filter-overlay__preset-chip filter-overlay__preset-chip--danger"
          />
          <ToggleButton
            v-model="localPresets.noTask"
            :on-label="t('sales.deals.page.filters.presetNoTask')"
            :off-label="t('sales.deals.page.filters.presetNoTask')"
            class="filter-overlay__preset-chip filter-overlay__preset-chip--warning"
          />
          <ToggleButton
            v-model="localPresets.overdue"
            :on-label="t('sales.deals.page.filters.presetOverdue')"
            :off-label="t('sales.deals.page.filters.presetOverdue')"
            class="filter-overlay__preset-chip filter-overlay__preset-chip--danger"
          />
        </div>
        <Button
          icon="pi pi-times"
          text
          severity="secondary"
          size="small"
          class="filter-overlay__close-btn"
          @click="emit('close')"
        />
      </div>

      <div class="filter-overlay__divider" />

      <!-- 3 columns -->
      <div class="row g-4 filter-overlay__body">
        <!-- Col 1: Deal properties -->
        <div class="col-md-4">
          <p class="filter-overlay__col-title">{{ t('sales.deals.page.filters.properties') }}</p>

          <div class="filter-overlay__field">
            <label class="filter-overlay__label">{{ t('sales.deals.page.filters.search') }}</label>
            <InputText v-model="localFilters.q" class="w-100" />
          </div>

          <div class="filter-overlay__field">
            <label class="filter-overlay__label">{{ t('sales.deals.page.filters.period') }}</label>
            <DatePicker
              v-model="localFilters.dateRange"
              selection-mode="range"
              show-icon
              class="w-100"
            />
          </div>

          <div class="filter-overlay__field">
            <label class="filter-overlay__label">{{ t('sales.deals.page.filters.stage') }}</label>
            <MultiSelect
              v-model="localFilters.stage_ids"
              :options="stages"
              option-label="name"
              option-value="id"
              filter
              class="w-100"
            />
          </div>

          <div class="filter-overlay__field">
            <label class="filter-overlay__label">{{ t('sales.deals.page.filters.owner') }}</label>
            <MultiSelect
              v-model="localFilters.owner_ids"
              :options="users"
              option-label="name"
              option-value="id"
              filter
              class="w-100"
            />
          </div>

          <div class="filter-overlay__field">
            <label class="filter-overlay__label">{{ t('sales.deals.page.filters.product') }}</label>
            <InputText v-model="localFilters.product_q" class="w-100" placeholder="..." />
          </div>
        </div>

        <!-- Col 2: Additional -->
        <div class="col-md-4">
          <p class="filter-overlay__col-title">&nbsp;</p>

          <div class="filter-overlay__field">
            <label class="filter-overlay__label">{{ t('sales.deals.page.filters.region') }}</label>
            <InputText v-model="localFilters.region" class="w-100" />
          </div>

          <div class="filter-overlay__field">
            <label class="filter-overlay__label">{{ t('sales.deals.page.filters.city') }}</label>
            <InputText v-model="localFilters.city" class="w-100" />
          </div>

          <div class="filter-overlay__field">
            <label class="filter-overlay__label">{{ t('sales.deals.page.filters.budgetFrom') }}</label>
            <div class="d-flex gap-2">
              <InputNumber
                v-model="localFilters.budget_from"
                suffix=" ₽"
                mode="decimal"
                class="flex-1"
              />
              <span class="filter-overlay__budget-to">{{ t('sales.deals.page.filters.budgetTo') }}</span>
              <InputNumber
                v-model="localFilters.budget_to"
                suffix=" ₽"
                mode="decimal"
                class="flex-1"
              />
            </div>
          </div>
        </div>

        <!-- Col 3: Tags -->
        <div class="col-md-4">
          <p class="filter-overlay__col-title">{{ t('sales.deals.page.filters.tags') }}</p>

          <InputText
            v-model="tagSearch"
            :placeholder="t('sales.deals.page.filters.tagsSearch')"
            class="w-100 mb-2"
          />

          <div class="filter-overlay__tags-list">
            <div
              v-for="tag in filteredTags"
              :key="tag"
              class="filter-overlay__tag-item"
            >
              <Checkbox
                :model-value="localFilters.tags.includes(tag)"
                binary
                @update:model-value="toggleTag(tag)"
              />
              <span class="filter-overlay__tag-name">{{ tag }}</span>
            </div>
            <div v-if="filteredTags.length === 0" class="filter-overlay__tags-empty">
              {{ t('sales.deals.page.filters.tagsSearch') }}...
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="filter-overlay__footer">
        <Button
          :label="t('sales.deals.page.filters.reset')"
          severity="secondary"
          text
          @click="onReset"
        />
        <Button
          :label="t('sales.deals.page.filters.apply')"
          @click="onApply"
        />
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import MultiSelect from 'primevue/multiselect'
import DatePicker from 'primevue/datepicker'
import ToggleButton from 'primevue/togglebutton'
import Checkbox from 'primevue/checkbox'
import type { PipelineStageDto, UserRefDto } from '@/entities/sales'

export interface OverlayFilters {
  q: string
  dateRange: Date[] | null
  stage_ids: number[]
  owner_ids: number[]
  product_q: string
  region: string
  city: string
  budget_from: number | null
  budget_to: number | null
  tags: string[]
  // presets
  status: 'open' | 'won' | 'lost' | null
  only_mine: boolean
  only_no_task: boolean
  only_overdue: boolean
}

const props = defineProps<{
  visible: boolean
  stages: PipelineStageDto[]
  users: UserRefDto[]
  tags: string[]
  filters: OverlayFilters
}>()

const emit = defineEmits<{
  close: []
  apply: [filters: OverlayFilters]
  reset: []
}>()

const { t } = useI18n()

// ── Local state ────────────────────────────────────────────────────────────────

const localFilters = reactive<OverlayFilters>({ ...props.filters })

const localPresets = reactive({
  open: props.filters.status === 'open',
  mine: props.filters.only_mine,
  won: props.filters.status === 'won',
  lost: props.filters.status === 'lost',
  noTask: props.filters.only_no_task,
  overdue: props.filters.only_overdue,
})

const tagSearch = ref('')

// Sync when parent filters change
watch(() => props.filters, (next) => {
  Object.assign(localFilters, { ...next })
  localPresets.open = next.status === 'open'
  localPresets.mine = next.only_mine
  localPresets.won = next.status === 'won'
  localPresets.lost = next.status === 'lost'
  localPresets.noTask = next.only_no_task
  localPresets.overdue = next.only_overdue
}, { deep: true })

const filteredTags = computed(() => {
  if (!tagSearch.value) return props.tags
  const q = tagSearch.value.toLowerCase()
  return props.tags.filter((t) => t.toLowerCase().includes(q))
})

function toggleTag(tag: string) {
  const idx = localFilters.tags.indexOf(tag)
  if (idx >= 0) {
    localFilters.tags.splice(idx, 1)
  } else {
    localFilters.tags.push(tag)
  }
}

function onApply() {
  // Resolve presets into filter fields
  const status: OverlayFilters['status'] = localPresets.won
    ? 'won'
    : localPresets.lost
      ? 'lost'
      : localPresets.open
        ? 'open'
        : null

  emit('apply', {
    ...localFilters,
    status,
    only_mine: localPresets.mine,
    only_no_task: localPresets.noTask,
    only_overdue: localPresets.overdue,
  })
}

function onReset() {
  Object.assign(localFilters, {
    q: '',
    dateRange: null,
    stage_ids: [],
    owner_ids: [],
    product_q: '',
    region: '',
    city: '',
    budget_from: null,
    budget_to: null,
    tags: [],
    status: null,
    only_mine: false,
    only_no_task: false,
    only_overdue: false,
  })
  localPresets.open = false
  localPresets.mine = false
  localPresets.won = false
  localPresets.lost = false
  localPresets.noTask = false
  localPresets.overdue = false
  emit('reset')
}

function onBackdropClick() {
  emit('close')
}
</script>

<style lang="scss" scoped>
.filter-overlay-backdrop {
  position: fixed;
  inset: 0;
  z-index: 999;
  background: rgba(0, 0, 0, 0.3);
}

.filter-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 1000;
  background: $surface-card;
  border-bottom: 1px solid $surface-200;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
  padding: $space-4;

  :global(.app-dark) & {
    background: var(--p-surface-900);
    border-bottom-color: var(--p-surface-700);
  }
}

.filter-overlay__presets {
  display: flex;
  align-items: center;
  gap: $space-3;
  flex-wrap: wrap;
}

.filter-overlay__section-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  white-space: nowrap;
}

.filter-overlay__preset-chips {
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
  flex: 1;
}

.filter-overlay__preset-chip {
  font-size: $font-size-xs;
}

.filter-overlay__close-btn {
  margin-left: auto;
  flex-shrink: 0;
}

.filter-overlay__divider {
  height: 1px;
  background: $surface-100;
  margin: $space-3 0;

  :global(.app-dark) & {
    background: var(--p-surface-700);
  }
}

.filter-overlay__body {
  margin-bottom: $space-3;
}

.filter-overlay__col-title {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: $space-3;
}

.filter-overlay__field {
  margin-bottom: $space-3;
}

.filter-overlay__label {
  display: block;
  font-size: $font-size-xs;
  color: $surface-500;
  margin-bottom: $space-1;
}

.filter-overlay__budget-to {
  font-size: $font-size-sm;
  color: $surface-500;
  align-self: center;
  white-space: nowrap;
}

.filter-overlay__tags-list {
  max-height: 180px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.filter-overlay__tag-item {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-1 0;
}

.filter-overlay__tag-name {
  font-size: $font-size-sm;
  color: $surface-700;

  :global(.app-dark) & {
    color: var(--p-surface-200);
  }
}

.filter-overlay__tags-empty {
  font-size: $font-size-xs;
  color: $surface-400;
  padding: $space-2 0;
}

.filter-overlay__footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: $space-2;
  padding-top: $space-3;
  border-top: 1px solid $surface-100;

  :global(.app-dark) & {
    border-top-color: var(--p-surface-700);
  }
}
</style>
