<template>
  <div class="filter-overlay">
    <!-- Search row (standalone, max-width 460) -->
    <div class="filter-overlay__search-row">
      <div class="filter-overlay__search-wrap">
        <i class="pi pi-search filter-overlay__search-icon" />
        <InputText
          v-model="localFilters.q"
          :placeholder="t('sales.deals.page.filters.search')"
          class="filter-overlay__search-input filter-overlay__control"
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
          class="w-100 filter-overlay__control"
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
          class="w-100 filter-overlay__control"
        />
      </div>

      <!-- Продукт (searchable dropdown backed by catalog) -->
      <div class="filter-overlay__field">
        <label class="filter-overlay__label">{{ t('sales.deals.page.filters.product') }}</label>
        <div class="filter-overlay__product-wrap">
          <i class="pi pi-search filter-overlay__product-icon" />
          <InputText
            v-model="productSearch"
            class="w-100 filter-overlay__control filter-overlay__product-input"
            :placeholder="t('sales.deals.page.filters.productSearch')"
            autocomplete="off"
            @focus="productDropdownOpen = true"
            @input="onProductSearchInput"
            @blur="onProductBlur"
          />
          <div
            v-if="productDropdownOpen && filteredProducts.length > 0"
            class="filter-overlay__product-dropdown"
          >
            <div
              v-for="prod in filteredProducts"
              :key="prod.id"
              class="filter-overlay__product-option"
              :class="{ 'filter-overlay__product-option--selected': localFilters.product_q === prod.name }"
              @mousedown.prevent="selectProduct(prod)"
            >
              {{ prod.name }}
            </div>
          </div>
          <div
            v-if="productDropdownOpen && filteredProducts.length === 0 && productSearch.length > 0"
            class="filter-overlay__product-dropdown"
          >
            <div class="filter-overlay__product-empty">
              {{ t('sales.deals.page.filters.productEmpty') }}
            </div>
          </div>
        </div>
      </div>

      <!-- Страна (multi-select) — NOTE: backend currently accepts single country only -->
      <!-- C1 multi-select sends country_codes[] but backend needs IN-clause update  -->
      <!-- For now: single Select (multi UX pending backend IAM patch)               -->
      <div class="filter-overlay__field">
        <label class="filter-overlay__label">{{ t('sales.deals.page.filters.country') }}</label>
        <MultiSelect
          v-model="localFilters.countries"
          :options="countriesOptions"
          option-label="name"
          option-value="code"
          filter
          show-clear
          class="w-100 filter-overlay__control"
          :placeholder="t('sales.deals.page.filters.country')"
        />
      </div>

      <!-- Город -->
      <div class="filter-overlay__field">
        <label class="filter-overlay__label">{{ t('sales.deals.page.filters.city') }}</label>
        <InputText v-model="localFilters.city" class="w-100 filter-overlay__control" />
      </div>

      <!-- Бюджет (single column, two inputs 50/50) -->
      <div class="filter-overlay__field">
        <label class="filter-overlay__label">{{ t('sales.deals.page.filters.budgetFrom') }}</label>
        <div class="filter-overlay__budget-row">
          <InputNumber
            v-model="localFilters.budget_from"
            suffix=" ₽"
            mode="decimal"
            class="flex-1 filter-overlay__control"
          />
          <span class="filter-overlay__budget-sep">{{ t('sales.deals.page.filters.budgetTo') }}</span>
          <InputNumber
            v-model="localFilters.budget_to"
            suffix=" ₽"
            mode="decimal"
            class="flex-1 filter-overlay__control"
          />
        </div>
      </div>

      <!-- Теги — checkbox list only, no standalone search field below -->
      <div class="filter-overlay__field">
        <label class="filter-overlay__label">{{ t('sales.deals.page.filters.tags') }}</label>
        <div class="filter-overlay__tags-list">
          <div
            v-for="tag in tags"
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
          <div v-if="tags.length === 0" class="filter-overlay__tags-empty">
            {{ t('sales.deals.page.filters.tagsEmpty') }}
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
          class="w-100 filter-overlay__control"
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
          {{ revealedCount }} вкл.
        </span>
        <i :class="['pi', hiddenExpanded ? 'pi-chevron-up' : 'pi-chevron-down', 'filter-overlay__hidden-chevron']" />
      </button>

      <div v-if="hiddenExpanded" class="filter-overlay__hidden-body">
        <p class="filter-overlay__hidden-hint">{{ t('sales.deals.page.filters.hiddenStagesHint') }}</p>
        <div
          v-for="hs in hiddenStages"
          :key="hs.id"
          class="filter-overlay__hidden-row"
        >
          <span class="filter-overlay__hidden-dot" :style="{ background: hs.color ?? 'var(--p-surface-400)' }" />
          <span class="filter-overlay__hidden-name">{{ hs.name }}</span>
          <span class="filter-overlay__hidden-count-badge">{{ hs.deals_count }}</span>
          <!-- Custom toggle switch — state from Pinia store (in-memory, SPA-persistent) -->
          <button
            type="button"
            class="filter-overlay__toggle"
            :class="{ 'filter-overlay__toggle--on': salesStore.revealedStageIds.has(hs.id) }"
            @click="emit('toggleHiddenStage', hs.id)"
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
import type { PipelineStageDto, UserRefDto, HiddenStageDto } from '@/entities/sales'
import type { ProductDto } from '@/entities/catalog'
import { useSalesStore } from '@/stores/salesStore'
import { useDirectoriesStore } from '@/stores/directories'
import { catalogApi } from '@/api/catalog'

export interface OverlayFilters {
  q: string
  dateRange: Date[] | null
  stage_ids: number[]
  owner_ids: number[]
  product_q: string
  /** @deprecated kept for backward compat; when countries[] is non-empty, use that instead. */
  country: string
  /** Multi-country filter: array of lowercase ISO-2 codes. Backend supports whereIn via countries[]. */
  countries: string[]
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
  hiddenStages: HiddenStageDto[]
}>()

const emit = defineEmits<{
  close: []
  apply: [filters: OverlayFilters]
  reset: []
  toggleHiddenStage: [stageId: number]
}>()

const salesStore = useSalesStore()
const directoriesStore = useDirectoriesStore()

const countriesOptions = computed(() => directoriesStore.activeCountries)

const { t } = useI18n()

// ── Revealed stage count (from store, for the pill in the accordion header) ────

const revealedCount = computed(() => {
  // Count only hidden stages that are currently revealed (intersection)
  const ids = salesStore.revealedStageIds
  return props.hiddenStages.filter((hs) => ids.has(hs.id)).length
})

// ── Local state ────────────────────────────────────────────────────────────────

const localFilters = reactive<OverlayFilters>({
  ...props.filters,
  countries: props.filters.countries ?? (props.filters.country ? [props.filters.country] : []),
})

const localPresets = reactive<Record<PresetKey, boolean>>({
  open: props.filters.status === 'open',
  mine: props.filters.only_mine,
  won: props.filters.status === 'won',
  lost: props.filters.status === 'lost',
  noTask: props.filters.only_no_task,
  overdue: props.filters.only_overdue,
})

const hiddenExpanded = ref(false)

// ── Product search (C2) ────────────────────────────────────────────────────────

const allProducts = ref<ProductDto[]>([])
const productSearch = ref(props.filters.product_q ?? '')
const productDropdownOpen = ref(false)

// Load products on mount
catalogApi.getProducts({ active_only: true, per_page: 200 }).then((res) => {
  allProducts.value = res.data
}).catch(() => {
  // non-critical; filter works via text search too
})

const filteredProducts = computed(() => {
  const q = productSearch.value.toLowerCase().trim()
  if (!q) return allProducts.value.slice(0, 30)
  return allProducts.value.filter((p) => p.name.toLowerCase().includes(q)).slice(0, 30)
})

let productDebounce: ReturnType<typeof setTimeout> | null = null

function onProductSearchInput() {
  if (productDebounce) clearTimeout(productDebounce)
  productDropdownOpen.value = true
  productDebounce = setTimeout(() => {
    localFilters.product_q = productSearch.value
  }, 200)
}

function selectProduct(prod: ProductDto) {
  productSearch.value = prod.name
  localFilters.product_q = prod.name
  productDropdownOpen.value = false
}

// Close product dropdown when clicking outside
function onProductBlur() {
  // short delay so mousedown on option fires first
  setTimeout(() => {
    productDropdownOpen.value = false
  }, 150)
}

// Sync when parent filters change
watch(() => props.filters, (next) => {
  Object.assign(localFilters, {
    ...next,
    countries: next.countries ?? (next.country ? [next.country] : []),
  })
  productSearch.value = next.product_q ?? ''
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

  // Backend now supports countries[] (whereIn). Pass full array; keep country as first value
  // for backward-compat with saved-views and any consumers still reading the single field.
  const selectedCountries = localFilters.countries ?? []
  const firstCountry = selectedCountries[0] ?? ''

  emit('apply', {
    ...localFilters,
    countries: selectedCountries,
    country: firstCountry,
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
    countries: [],
    city: '',
    budget_from: null,
    budget_to: null,
    tags: [],
    status: null,
    only_mine: false,
    only_no_task: false,
    only_overdue: false,
  })
  productSearch.value = ''
  productDropdownOpen.value = false
  localPresets.open = false
  localPresets.mine = false
  localPresets.won = false
  localPresets.lost = false
  localPresets.noTask = false
  localPresets.overdue = false
  emit('reset')
  // F3: auto-close filter panel on reset
  emit('close')
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

// ── Unified control height (F1) ──────────────────────────────────────────────
// All filter inputs/selects normalised to 38px so every field row is identical
// height. Applied via .filter-overlay__control on each PrimeVue component root
// plus deep override for their inner input elements.
$_filter-h: 38px;

.filter-overlay__control {
  // PrimeVue InputText / InputNumber / DatePicker — the root IS the input element
  height: $_filter-h !important;
  min-height: $_filter-h !important;

  // PrimeVue MultiSelect / Select — root is a wrapper div; inner button is the height ref
  :deep(.p-multiselect),
  :deep(.p-select),
  :deep(.p-multiselect-label-container),
  :deep(.p-select-label-container) {
    height: $_filter-h !important;
    min-height: $_filter-h !important;
  }
  :deep(.p-inputnumber-input) {
    height: $_filter-h !important;
    min-height: $_filter-h !important;
  }
}

// When the component IS the MultiSelect/Select root (class on component tag)
:deep(.p-multiselect.filter-overlay__control),
:deep(.p-select.filter-overlay__control) {
  height: $_filter-h !important;
  min-height: $_filter-h !important;
  align-items: center;
}

// DatePicker wrapper
:deep(.p-datepicker.filter-overlay__control .p-datepicker-input) {
  height: $_filter-h !important;
  min-height: $_filter-h !important;
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
  height: $_filter-h;
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

// Product search field (C2)
.filter-overlay__product-wrap {
  position: relative;
}

.filter-overlay__product-icon {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  font-size: $font-size-sm;
  color: $surface-400;
  pointer-events: none;
  z-index: 1;
}

.filter-overlay__product-input {
  padding-left: 30px !important;
}

.filter-overlay__product-dropdown {
  position: absolute;
  top: calc(100% + $space-1);
  left: 0;
  right: 0;
  background: $surface-card;
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  box-shadow: $shadow-elevated;
  z-index: 100;
  max-height: 200px;
  overflow-y: auto;
  scrollbar-width: none;

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-300);
  }
}

.filter-overlay__product-option {
  padding: $space-2 $space-3;
  font-size: $font-size-sm;
  color: $surface-700;
  cursor: pointer;

  .app-dark & {
    color: var(--p-surface-200);
  }

  &:hover,
  &--selected {
    background: var(--p-surface-100);

    .app-dark & {
      background: var(--p-surface-200);
    }
  }
}

.filter-overlay__product-empty {
  padding: $space-2 $space-3;
  font-size: $font-size-xs;
  color: $surface-400;
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
