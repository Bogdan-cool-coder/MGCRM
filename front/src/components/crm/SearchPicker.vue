<!-- SearchPicker — trigger (value + chevron) → popover with search + options list.
     Selected option has --mg-primary-100 bg + pi-check. §9 of DealCard spec. -->
<template>
  <div class="search-picker" :class="{ 'search-picker--open': isOpen }">
    <!-- Trigger -->
    <button
      class="search-picker__trigger"
      type="button"
      @click="toggle"
    >
      <slot name="trigger-content">
        <span class="search-picker__trigger-value">
          {{ displayValue || placeholder }}
        </span>
      </slot>
      <i class="pi pi-chevron-down search-picker__chevron" />
    </button>

    <!-- Popover -->
    <div
      v-if="isOpen"
      ref="popoverRef"
      class="search-picker__popover"
    >
      <!-- Search -->
      <div class="search-picker__search-wrap">
        <i class="pi pi-search search-picker__search-icon" />
        <input
          ref="searchRef"
          v-model="query"
          class="search-picker__search-input"
          :placeholder="t('common.search_placeholder')"
        />
      </div>

      <!-- Options -->
      <div class="search-picker__options">
        <div
          v-for="opt in filteredOptions"
          :key="String(getOptionValue(opt))"
          class="search-picker__option"
          :class="{ 'search-picker__option--selected': isSelected(opt) }"
          @click="selectOption(opt)"
        >
          <i v-if="isSelected(opt)" class="pi pi-check search-picker__check" />
          <div class="search-picker__option-content">
            <slot name="option" :option="opt">
              <span class="search-picker__option-label">{{ getOptionLabel(opt) }}</span>
            </slot>
          </div>
        </div>
        <div v-if="filteredOptions.length === 0" class="search-picker__empty">
          {{ t('common.no_results') }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'

interface PickerOption {
  [key: string]: unknown
}

const props = withDefaults(
  defineProps<{
    modelValue?: unknown
    options: PickerOption[]
    optionLabel?: string
    optionValue?: string
    placeholder?: string
    displayLabel?: string
  }>(),
  {
    modelValue: undefined,
    optionLabel: 'name',
    optionValue: 'id',
    placeholder: 'Выберите…',
    displayLabel: undefined,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: unknown]
  select: [option: PickerOption]
}>()

const { t } = useI18n()

const isOpen = ref(false)
const query = ref('')
const searchRef = ref<HTMLInputElement | null>(null)
const popoverRef = ref<HTMLElement | null>(null)

function getOptionLabel(opt: PickerOption): string {
  return String(opt[props.optionLabel] ?? '')
}

function getOptionValue(opt: PickerOption): unknown {
  return opt[props.optionValue]
}

function isSelected(opt: PickerOption): boolean {
  return getOptionValue(opt) === props.modelValue
}

const displayValue = computed(() => {
  if (props.displayLabel !== undefined) return props.displayLabel
  if (props.modelValue == null) return ''
  const found = props.options.find((o) => getOptionValue(o) === props.modelValue)
  return found ? getOptionLabel(found) : ''
})

const filteredOptions = computed(() => {
  if (!query.value.trim()) return props.options
  const q = query.value.toLowerCase()
  return props.options.filter((o) => getOptionLabel(o).toLowerCase().includes(q))
})

function toggle() {
  if (isOpen.value) {
    close()
  } else {
    isOpen.value = true
    query.value = ''
    nextTick(() => searchRef.value?.focus())
  }
}

function close() {
  isOpen.value = false
  query.value = ''
}

function selectOption(opt: PickerOption) {
  emit('update:modelValue', getOptionValue(opt))
  emit('select', opt)
  close()
}

// ── Click-outside ─────────────────────────────────────────────────────────────

function onDocClick(e: MouseEvent) {
  const target = e.target as Node
  const pop = popoverRef.value
  if (pop && !pop.contains(target)) {
    close()
  }
}

onMounted(() => {
  document.addEventListener('click', onDocClick, true)
})

onUnmounted(() => {
  document.removeEventListener('click', onDocClick, true)
})
</script>

<style lang="scss" scoped>
.search-picker {
  position: relative;
  display: inline-flex;
}

.search-picker__trigger {
  display: flex;
  align-items: center;
  gap: $space-1;
  padding: 4px 8px;
  border: 1px solid var(--p-surface-300);
  border-radius: $radius-sm;
  background: var(--p-card-background);
  cursor: pointer;
  font-size: $font-size-sm;
  color: $surface-700;
  transition: border-color var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-600);
    color: var(--p-surface-200);
  }

  &:hover {
    border-color: var(--p-primary-400);
  }
}

.search-picker__trigger-value {
  min-width: 60px;
  text-align: left;
}

.search-picker__chevron {
  font-size: $font-size-3xs;
  color: $surface-400;
  transition: transform var(--app-transition-fast);
  flex-shrink: 0;

  .search-picker--open & {
    transform: rotate(180deg);
  }
}

.search-picker__popover {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  z-index: 200;
  min-width: 220px;
  background: var(--p-card-background);
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  box-shadow: $shadow-lg;
  overflow: hidden;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

.search-picker__search-wrap {
  display: flex;
  align-items: center;
  gap: $space-1;
  padding: $space-2 $space-3;
  border-bottom: 1px solid var(--p-surface-200);

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

.search-picker__search-icon {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
}

.search-picker__search-input {
  flex: 1;
  border: none;
  outline: none;
  background: transparent;
  font-size: $font-size-sm;
  color: $surface-800;
  min-width: 0;

  .app-dark & {
    color: var(--p-surface-100);
  }

  &::placeholder {
    color: $surface-400;
  }
}

.search-picker__options {
  max-height: 220px;
  overflow-y: auto;
  padding: $space-1;
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }
}

.search-picker__option {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  border-radius: $radius-sm;
  cursor: pointer;
  font-size: $font-size-sm;
  color: $surface-700;
  transition: background var(--app-transition-fast);

  .app-dark & {
    color: var(--p-surface-200);
  }

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-700);
    }
  }

  &--selected {
    background: var(--p-primary-50);
    color: var(--p-primary-color);

    .app-dark & {
      background: var(--p-primary-900);
    }
  }
}

.search-picker__check {
  font-size: $font-size-xs;
  color: var(--p-primary-color);
  flex-shrink: 0;
}

.search-picker__option-content {
  flex: 1;
  min-width: 0;
}

.search-picker__option-label {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.search-picker__empty {
  padding: $space-3;
  text-align: center;
  font-size: $font-size-sm;
  color: $surface-400;
}
</style>
