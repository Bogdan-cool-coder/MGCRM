<template>
  <div class="filter-overlay">
    <!-- Search row (standalone, max-width 460) -->
    <div class="filter-overlay__search-row">
      <div class="filter-overlay__search-wrap">
        <i class="pi pi-search filter-overlay__search-icon" />
        <InputText
          v-model="localFilters.q"
          :placeholder="t('sales.deals.page.filters.search')"
          class="filter-overlay__search-input"
        />
      </div>
    </div>

    <!-- Presets row: label + chips + spacer + close button -->
    <div class="filter-overlay__presets">
      <span class="filter-overlay__section-label">{{ t('sales.deals.page.filters.presets') }}</span>
      <div class="filter-overlay__preset-chips">
        <button
          v-for="preset in presets"
          :key="preset.key"
          type="button"
          :class="[
            'filter-overlay__chip',
            `filter-overlay__chip--${preset.severity}`,
            { 'filter-overlay__chip--on': localPresets[preset.key] },
          ]"
          @click="togglePreset(preset.key)"
        >
          <i v-if="localPresets[preset.key]" class="pi pi-check filter-overlay__chip-check" />
          {{ preset.label }}
        </button>
      </div>
      <div class="filter-overlay__presets-spacer" />
      <Button
        icon="pi pi-times"
        text
        severity="secondary"
        size="small"
        class="filter-overlay__close-btn"
        @click="emit('close')"
      />
    </div>

    <!-- Fields grid (4 col, no divider) -->
    <div class="filter-overlay__grid">
      <!-- Ответственный -->
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

      <!-- Этап -->
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

      <!-- Продукт -->
      <div class="filter-overlay__field">
        <label class="filter-overlay__label">{{ t('sales.deals.page.filters.product') }}</label>
        <InputText v-model="localFilters.product_q" class="w-100" placeholder="..." />
      </div>

      <!-- Регион / страна -->
      <div class="filter-overlay__field">
        <label class="filter-overlay__label">{{ t('sales.deals.page.filters.country') }}</label>
        <InputText v-model="localFilters.country" class="w-100" />
      </div>

      <!-- Город -->
      <div class="filter-overlay__field">
        <label class="filter-overlay__label">{{ t('sales.deals.page.filters.city') }}</label>
        <InputText v-model="localFilters.city" class="w-100" />
      </div>

      <!-- Бюджет (single column, two inputs 50/50) -->
      <div class="filter-overlay__field">
        <label class="filter-overlay__label">{{ t('sales.deals.page.filters.budgetFrom') }}</label>
        <div class="filter-overlay__budget-row">
          <InputNumber
            v-model="localFilters.budget_from"
            suffix=" ₽"
            mode="decimal"
            class="flex-1"
          />
          <span class="filter-overlay__budget-sep">{{ t('sales.deals.page.filters.budgetTo') }}</span>
          <InputNumber
            v-model="localFilters.budget_to"
            suffix=" ₽"
            mode="decimal"
            class="flex-1"
          />
        </div>
      </div>

      <!-- Теги -->
      <div class="filter-overlay__field">
        <label class="filter-overlay__label">{{ t('sales.deals.page.filters.tags') }}</label>
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

      <!-- Период создания -->
      <div class="filter-overlay__field">
        <label class="filter-overlay__label">{{ t('sales.deals.page.filters.period') }}</label>
        <DatePicker
          v-model="localFilters.dateRange"
          selection-mode="range"
          show-icon
          class="w-100"
        />
      </div>
    </div>

    <!-- Hidden stages accordion (max-width 50%) -->
    <div v-if="hiddenStages.length > 0" class="filter-overlay__hidden">
      <button
        type="button"
        class="filter-overlay__hidden-toggle"
        @click="hiddenExpanded = !hiddenExpanded"
      >
        <i class="pi pi-eye-slash filter-overlay__hidden-eye" />
        <span class="filter-overlay__hidden-label">{{ t('sales.deals.page.filters.hiddenStages') }}</span>
        <span class="filter-overlay__hidden-count-pill">
          {{ shownHiddenStageIds.length }} вкл.
        </span>
        <i :class="['pi', hiddenExpanded ? 'pi-chevron-up' : 'pi-chevron-down', 'filter-overlay__hidden-chevron']" />
      </button>

      <div v-if="hiddenExpanded" class="filter-overlay__hidden-body">
        <p class="filter-overlay__hidden-hint">{{ t('sales.deals.page.filters.hiddenStagesHint') }}</p>
        <div
          v-for="col in hiddenStages"
          :key="col.stage.id"
          class="filter-overlay__hidden-row"
        >
          <span class="filter-overlay__hidden-dot" :style="{ background: col.stage.color ?? 'var(--p-surface-400)' }" />
          <span class="filter-overlay__hidden-name">{{ col.stage.name }}</span>
          <span class="filter-overlay__hidden-count-badge">{{ col.total }}</span>
          <!-- Custom toggle switch -->
          <button
            type="button"
            class="filter-overlay__toggle"
            :class="{ 'filter-overlay__toggle--on': shownHiddenStageIds.includes(col.stage.id) }"
            @click="emit('toggleHiddenStage', col.stage.id)"
          >
            <span class="filter-overlay__toggle-knob" />
          </button>
        </div>

        <div class="filter-overlay__hidden-settings-divider" />
        <button type="button" class="filter-overlay__hidden-settings-btn" @click="() => {}">
          <i class="pi pi-cog" />
          <span>{{ t('sales.deals.page.filters.hiddenStagesSettings') }}</span>
        </button>
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
        icon="pi pi-check"
        :label="t('sales.deals.page.filters.apply')"
        @click="onApply"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import MultiSelect from 'primevue/multiselect'
import DatePicker from 'primevue/datepicker'
import Checkbox from 'primevue/checkbox'
import type { PipelineStageDto, UserRefDto, BoardColumnDto } from '@/entities/sales'

export interface OverlayFilters {
  q: string
  dateRange: Date[] | null
  stage_ids: number[]
  owner_ids: number[]
  product_q: string
  country: string
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

type PresetKey = 'open' | 'mine' | 'won' | 'lost' | 'noTask' | 'overdue'

const props = defineProps<{
  stages: PipelineStageDto[]
  users: UserRefDto[]
  tags: string[]
  filters: OverlayFilters
  hiddenStages: BoardColumnDto[]
  shownHiddenStageIds: number[]
}>()

const emit = defineEmits<{
  close: []
  apply: [filters: OverlayFilters]
  reset: []
  toggleHiddenStage: [stageId: number]
}>()

const { t } = useI18n()

// ── Local state ────────────────────────────────────────────────────────────────

const localFilters = reactive<OverlayFilters>({ ...props.filters })

const localPresets = reactive<Record<PresetKey, boolean>>({
  open: props.filters.status === 'open',
  mine: props.filters.only_mine,
  won: props.filters.status === 'won',
  lost: props.filters.status === 'lost',
  noTask: props.filters.only_no_task,
  overdue: props.filters.only_overdue,
})

const tagSearch = ref('')
const hiddenExpanded = ref(false)

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

// ── Presets config ─────────────────────────────────────────────────────────────

const presets = computed(() => [
  { key: 'open' as PresetKey, label: t('sales.deals.page.filters.presetOpen'), severity: 'brand' },
  { key: 'mine' as PresetKey, label: t('sales.deals.page.filters.presetMine'), severity: 'brand' },
  { key: 'won' as PresetKey, label: t('sales.deals.page.filters.presetWon'), severity: 'success' },
  { key: 'lost' as PresetKey, label: t('sales.deals.page.filters.presetLost'), severity: 'danger' },
  { key: 'noTask' as PresetKey, label: t('sales.deals.page.filters.presetNoTask'), severity: 'warning' },
  { key: 'overdue' as PresetKey, label: t('sales.deals.page.filters.presetOverdue'), severity: 'danger' },
])

function togglePreset(key: PresetKey) {
  localPresets[key] = !localPresets[key]
}

// ── Tags ───────────────────────────────────────────────────────────────────────

const filteredTags = computed(() => {
  if (!tagSearch.value) return props.tags
  const q = tagSearch.value.toLowerCase()
  return props.tags.filter((tag) => tag.toLowerCase().includes(q))
})

function toggleTag(tag: string) {
  const idx = localFilters.tags.indexOf(tag)
  if (idx >= 0) {
    localFilters.tags.splice(idx, 1)
  } else {
    localFilters.tags.push(tag)
  }
}

// ── Actions ────────────────────────────────────────────────────────────────────

function onApply() {
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
    country: '',
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
</script>

<style lang="scss" scoped>
.filter-overlay {
  border-bottom: 1px solid var(--p-surface-200);
  background: var(--p-surface-50);
  padding: $space-4 $space-5;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-100);
    border-bottom-color: var(--p-surface-300);
  }
}

// Search row (standalone, max-width 460)
.filter-overlay__search-row {
  margin-bottom: $space-3;
}

.filter-overlay__search-wrap {
  position: relative;
  max-width: 460px;
}

.filter-overlay__search-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  font-size: $font-size-sm;
  color: $surface-400;
  pointer-events: none;
}

.filter-overlay__search-input {
  width: 100%;
  padding-left: 34px !important;
  height: 38px;
}

// Presets row (label + chips + spacer + close)
.filter-overlay__presets {
  display: flex;
  align-items: center;
  gap: $space-3;
  flex-wrap: wrap;
  margin-bottom: $space-3;
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
}

.filter-overlay__presets-spacer {
  flex: 1;
}

.filter-overlay__close-btn {
  flex-shrink: 0;
}

// Pill chip — custom (NOT ToggleButton)
.filter-overlay__chip {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  padding: 6px 13px;
  border-radius: $radius-pill;
  border: 1px solid $surface-200;
  background: transparent;
  color: $surface-600;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  cursor: pointer;
  transition: background var(--app-transition-fast), color var(--app-transition-fast), border-color var(--app-transition-fast);
  line-height: 1;

  .app-dark & {
    border-color: var(--p-surface-300); // dark surface-300 = #54595E (not too light)
    color: var(--p-surface-300);
  }

  &:hover {
    background: var(--p-surface-100);

    .app-dark & {
      background: var(--p-surface-200);
    }
  }

  // Active states per severity
  &--brand#{&}--on {
    background: $primary-100;
    border-color: $primary-900;
    color: $primary-900;

    .app-dark & {
      background: color-mix(in srgb, $primary-900 40%, transparent);
      border-color: var(--p-primary-300);
      color: var(--p-primary-200);
    }
  }

  &--success#{&}--on {
    background: var(--p-green-50);
    border-color: var(--p-green-600);
    color: var(--p-green-700);

    .app-dark & {
      background: color-mix(in srgb, var(--p-green-600) 20%, transparent);
      border-color: var(--p-green-400);
      color: var(--p-green-300);
    }
  }

  &--danger#{&}--on {
    background: var(--p-red-50);
    border-color: var(--p-red-600);
    color: var(--p-red-700);

    .app-dark & {
      background: color-mix(in srgb, var(--p-red-600) 20%, transparent);
      border-color: var(--p-red-400);
      color: var(--p-red-300);
    }
  }

  &--warning#{&}--on {
    background: var(--p-orange-50);
    border-color: var(--p-orange-600);
    color: var(--p-orange-700);

    .app-dark & {
      background: color-mix(in srgb, var(--p-orange-600) 20%, transparent);
      border-color: var(--p-orange-400);
      color: var(--p-orange-300);
    }
  }
}

.filter-overlay__chip-check {
  font-size: $font-size-2xs;
}

// Fields grid (4 col, no divider)
.filter-overlay__grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px 18px;
  margin-bottom: $space-3;
}

.filter-overlay__field {
  display: flex;
  flex-direction: column;
}

.filter-overlay__label {
  display: block;
  font-size: $font-size-xs;
  color: $surface-500;
  margin-bottom: $space-1;
}

.filter-overlay__budget-row {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.filter-overlay__budget-sep {
  font-size: $font-size-sm;
  color: $surface-500;
  white-space: nowrap;
}

.filter-overlay__tags-list {
  max-height: 120px;
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

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.filter-overlay__tags-empty {
  font-size: $font-size-xs;
  color: $surface-400;
  padding: $space-2 0;
}

// Hidden stages accordion (max-width 50%)
.filter-overlay__hidden {
  max-width: 50%;
  margin-bottom: $space-3;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  overflow: hidden;
  background: $surface-card;

  .app-dark & {
    border-color: var(--p-surface-600);
  }
}

.filter-overlay__hidden-toggle {
  display: flex;
  align-items: center;
  gap: $space-2;
  width: 100%;
  padding: $space-2 $space-3;
  background: $surface-50;
  border: none;
  cursor: pointer;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
  text-align: left;

  .app-dark & {
    background: var(--p-surface-100);
    color: var(--p-surface-200);
  }
}

.filter-overlay__hidden-eye {
  font-size: $font-size-sm;
  color: $surface-500;
  flex-shrink: 0;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.filter-overlay__hidden-label {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.filter-overlay__hidden-count-pill {
  background: $primary-100;
  color: $primary-900;
  border-radius: $radius-pill;
  padding: 1px $space-2;
  font-size: $font-size-2xs;
  font-weight: $font-weight-bold;
  line-height: 1.4;
  white-space: nowrap;

  .app-dark & {
    background: color-mix(in srgb, $primary-900 30%, transparent);
    color: var(--p-primary-200);
  }
}

.filter-overlay__hidden-chevron {
  margin-left: auto;
  font-size: $font-size-xs;
  color: $surface-400;
}

.filter-overlay__hidden-body {
  padding: $space-2 $space-3;
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.filter-overlay__hidden-hint {
  font-size: $font-size-xs;
  color: $surface-400;
  margin: 0 0 $space-2;
}

.filter-overlay__hidden-row {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.filter-overlay__hidden-dot {
  width: 9px;
  height: 9px;
  border-radius: $radius-circle;
  flex-shrink: 0;
}

.filter-overlay__hidden-name {
  flex: 1;
  font-size: $font-size-sm;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.filter-overlay__hidden-count-badge {
  font-size: $font-size-xs;
  color: $surface-400;
  min-width: 24px;
  text-align: right;
}

// Custom toggle switch (34×19)
.filter-overlay__toggle {
  width: 34px;
  height: 19px;
  border-radius: $radius-pill;
  background: $surface-200;
  border: none;
  cursor: pointer;
  position: relative;
  flex-shrink: 0;
  transition: background var(--app-transition-fast);

  .app-dark & {
    background: var(--p-surface-600);
  }

  &--on {
    background: $primary-900;

    .app-dark & {
      background: var(--p-primary-400);
    }

    .filter-overlay__toggle-knob {
      transform: translateX(15px);
    }
  }
}

.filter-overlay__toggle-knob {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 15px;
  height: 15px;
  border-radius: $radius-circle;
  background: $surface-0;
  transition: transform var(--app-transition-fast);
}

// Hidden settings row
.filter-overlay__hidden-settings-divider {
  height: 1px;
  background: $surface-100;
  margin: $space-1 0;

  .app-dark & {
    background: var(--p-surface-700);
  }
}

.filter-overlay__hidden-settings-btn {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  background: none;
  border: none;
  cursor: pointer;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-500;
  padding: $space-1 0;

  .app-dark & {
    color: var(--p-surface-400);
  }

  &:hover {
    color: $surface-700;

    .app-dark & {
      color: var(--p-surface-200);
    }
  }

  i {
    font-size: $font-size-sm;
  }
}

// Footer
.filter-overlay__footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: $space-2;
  padding-top: $space-3;
  border-top: 1px solid $surface-100;

  .app-dark & {
    border-top-color: var(--p-surface-700);
  }
}
</style>
