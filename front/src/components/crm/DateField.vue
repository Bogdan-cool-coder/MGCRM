<!-- DateField — auto-format ДД.ММ.ГГГГ + calendar popover. §9 of DealCard spec.
     Click on any part of the field activates input AND opens calendar.
     Emits 'update:modelValue' with ISO date string (YYYY-MM-DD) or null. -->
<template>
  <div
    ref="rootRef"
    class="date-field"
    :class="{ 'date-field--active': isOpen }"
    @click="onFieldClick"
  >
    <input
      ref="inputRef"
      v-model="displayValue"
      class="date-field__input"
      :placeholder="placeholder"
      maxlength="10"
      inputmode="numeric"
      @input="onInput"
      @blur="onBlur"
      @keydown.esc="close"
      @keydown.enter.prevent="close"
    />
    <i class="pi pi-calendar date-field__icon" />

    <!-- Calendar popover -->
    <div
      v-if="isOpen"
      ref="calendarWrapRef"
      class="date-field__calendar-wrap"
      @click.stop
    >
      <DatePicker
        v-model="calendarValue"
        inline
        :show-button-bar="false"
        :show-other-months="true"
        date-format="dd.mm.yy"
        @date-select="onDateSelect"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import DatePicker from 'primevue/datepicker'

const props = withDefaults(
  defineProps<{
    modelValue?: string | null
    placeholder?: string
  }>(),
  {
    modelValue: null,
    placeholder: 'ДД.ММ.ГГГГ',
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
}>()

// ── State ─────────────────────────────────────────────────────────────────────

const rootRef = ref<HTMLElement | null>(null)
const calendarWrapRef = ref<HTMLElement | null>(null)
const inputRef = ref<HTMLInputElement | null>(null)
const isOpen = ref(false)

// ── Display value (ДД.ММ.ГГГГ) ───────────────────────────────────────────────

function isoToDisplay(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (isNaN(d.getTime())) return ''
  const dd = String(d.getDate()).padStart(2, '0')
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const yyyy = d.getFullYear()
  return `${dd}.${mm}.${yyyy}`
}

function displayToIso(display: string): string | null {
  const clean = display.replace(/\D/g, '')
  if (clean.length !== 8) return null
  const dd = clean.slice(0, 2)
  const mm = clean.slice(2, 4)
  const yyyy = clean.slice(4, 8)
  const d = new Date(`${yyyy}-${mm}-${dd}`)
  if (isNaN(d.getTime())) return null
  return `${yyyy}-${mm}-${dd}`
}

const displayValue = ref(isoToDisplay(props.modelValue))

// Calendar bound value (Date object)
const calendarValue = computed({
  get(): Date | null {
    const iso = displayToIso(displayValue.value)
    if (!iso) return null
    return new Date(iso)
  },
  set(d: Date | null) {
    if (!d) {
      displayValue.value = ''
    } else {
      const dd = String(d.getDate()).padStart(2, '0')
      const mm = String(d.getMonth() + 1).padStart(2, '0')
      const yyyy = d.getFullYear()
      displayValue.value = `${dd}.${mm}.${yyyy}`
    }
  },
})

// Sync from parent
watch(
  () => props.modelValue,
  (v) => {
    const formatted = isoToDisplay(v)
    if (formatted !== displayValue.value) {
      displayValue.value = formatted
    }
  },
)

// ── Auto-format input ─────────────────────────────────────────────────────────

function onInput(e: Event) {
  const target = e.target as HTMLInputElement
  let raw = target.value.replace(/\D/g, '')
  if (raw.length > 8) raw = raw.slice(0, 8)

  let formatted = raw
  if (raw.length >= 3) formatted = raw.slice(0, 2) + '.' + raw.slice(2)
  if (raw.length >= 5) formatted = raw.slice(0, 2) + '.' + raw.slice(2, 4) + '.' + raw.slice(4)

  displayValue.value = formatted

  if (raw.length === 8) {
    const iso = displayToIso(formatted)
    if (iso) emit('update:modelValue', iso)
    isOpen.value = true
  }
}

function onBlur() {
  const iso = displayToIso(displayValue.value)
  if (displayValue.value === '') {
    emit('update:modelValue', null)
  } else if (iso) {
    emit('update:modelValue', iso)
  }
}

// ── Calendar ──────────────────────────────────────────────────────────────────

function onDateSelect(date: Date) {
  const dd = String(date.getDate()).padStart(2, '0')
  const mm = String(date.getMonth() + 1).padStart(2, '0')
  const yyyy = date.getFullYear()
  displayValue.value = `${dd}.${mm}.${yyyy}`
  const iso = `${yyyy}-${mm}-${dd}`
  emit('update:modelValue', iso)
  isOpen.value = false
}

function onFieldClick() {
  isOpen.value = true
  inputRef.value?.focus()
}

function close() {
  isOpen.value = false
}

// ── Click-outside (native, no global declarations) ────────────────────────────

function onDocClick(e: MouseEvent) {
  const root = rootRef.value
  if (root && !root.contains(e.target as Node)) {
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
.date-field {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  padding: 4px 8px;
  border: 1px solid var(--p-surface-300);
  border-radius: $radius-sm;
  background: var(--p-card-background);
  cursor: text;
  position: relative;
  transition: border-color var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-600);
  }

  &:hover {
    border-color: var(--p-primary-400);
  }

  &--active {
    border-color: var(--p-primary-color);
    box-shadow: $shadow-sm;
  }
}

.date-field__input {
  border: none;
  outline: none;
  background: transparent;
  font-size: $font-size-sm;
  color: $surface-800;
  width: 90px;
  cursor: text;
  padding: 0;

  .app-dark & {
    color: var(--p-surface-100);
  }

  &::placeholder {
    color: $surface-400;
  }
}

.date-field__icon {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
  pointer-events: none;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.date-field__calendar-wrap {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  z-index: 200;
  background: var(--p-card-background);
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  box-shadow: $shadow-lg;
  overflow: hidden;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}
</style>
