<template>
  <Popover ref="popoverRef" class="inbox-snooze">
    <div class="inbox-snooze__body">
      <div class="inbox-snooze__label">{{ t('inbox.snooze.title') }}</div>

      <!-- Presets -->
      <ul class="inbox-snooze__presets">
        <li v-for="preset in presets" :key="preset.key">
          <button type="button" class="inbox-snooze__preset" @click="choosePreset(preset)">
            <i :class="['pi', preset.icon, 'inbox-snooze__preset-icon']" />
            <span class="inbox-snooze__preset-text">{{ preset.label }}</span>
            <span class="inbox-snooze__preset-when">{{ preset.hint }}</span>
          </button>
        </li>
      </ul>

      <div class="inbox-snooze__divider" />

      <!-- Custom date-time -->
      <div class="inbox-snooze__custom">
        <span class="inbox-snooze__custom-label">{{ t('inbox.snooze.custom') }}</span>
        <DatePicker
          v-model="customDate"
          show-time
          hour-format="24"
          date-format="dd.mm.yy"
          :min-date="minDate"
          :placeholder="t('inbox.snooze.customPlaceholder')"
          class="inbox-snooze__datepicker"
          append-to="self"
        />
        <Button
          :label="t('inbox.snooze.apply')"
          size="small"
          :disabled="!customDate"
          class="inbox-snooze__custom-btn"
          @click="applyCustom"
        />
      </div>
    </div>
  </Popover>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Popover from 'primevue/popover'
import DatePicker from 'primevue/datepicker'
import Button from 'primevue/button'

const emit = defineEmits<{
  /** ISO-8601 timestamp in the future to snooze until. */
  snooze: [until: string]
}>()

const { t } = useI18n()

const popoverRef = ref<InstanceType<typeof Popover> | null>(null)
const customDate = ref<Date | null>(null)
const minDate = new Date()

interface Preset {
  key: string
  icon: string
  label: string
  hint: string
  resolve: () => Date
}

/** Next day at 09:00 (operational-friendly, browser-local). */
function tomorrowAt(hour: number): Date {
  const d = new Date()
  d.setDate(d.getDate() + 1)
  d.setHours(hour, 0, 0, 0)
  return d
}

/** Upcoming Monday at 09:00. If today is Monday, jump a full week ahead. */
function nextMondayAt(hour: number): Date {
  const d = new Date()
  const day = d.getDay() // 0=Sun … 1=Mon
  const delta = ((8 - day) % 7) || 7
  d.setDate(d.getDate() + delta)
  d.setHours(hour, 0, 0, 0)
  return d
}

const timeFmt = new Intl.DateTimeFormat('ru-RU', {
  weekday: 'short',
  hour: '2-digit',
  minute: '2-digit',
})

const presets = computed<Preset[]>(() => {
  const tomorrow = tomorrowAt(9)
  const monday = nextMondayAt(9)
  return [
    {
      key: 'tomorrow',
      icon: 'pi-sun',
      label: t('inbox.snooze.tomorrow'),
      hint: timeFmt.format(tomorrow),
      resolve: () => tomorrow,
    },
    {
      key: 'monday',
      icon: 'pi-calendar',
      label: t('inbox.snooze.nextWeek'),
      hint: timeFmt.format(monday),
      resolve: () => monday,
    },
  ]
})

function choosePreset(preset: Preset) {
  emit('snooze', preset.resolve().toISOString())
  close()
}

function applyCustom() {
  if (!customDate.value) return
  emit('snooze', customDate.value.toISOString())
  close()
}

function toggle(event: Event) {
  popoverRef.value?.toggle(event)
}

function close() {
  customDate.value = null
  popoverRef.value?.hide()
}

defineExpose({ toggle })
</script>

<style lang="scss" scoped>
.inbox-snooze__body {
  // 340px fits the append-to="self" DatePicker calendar panel (~330px) inside the
  // popover — was 260px, so the panel overflowed and could clip near the right edge.
  width: 340px;
  max-width: 90vw;
}

.inbox-snooze__label {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--p-text-muted-color);
  margin-bottom: $space-2;
}

.inbox-snooze__presets {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.inbox-snooze__preset {
  display: flex;
  align-items: center;
  gap: $space-2;
  width: 100%;
  height: 38px;
  padding: 0 $space-2;
  border: none;
  border-radius: $radius-sm;
  background: transparent;
  cursor: pointer;
  text-align: left;
  transition: background-color $transition-fast;

  &:hover {
    background: var(--mg-surface-hover);
  }
}

.inbox-snooze__preset-icon {
  font-size: $font-size-sm;
  color: var(--p-text-muted-color);
  flex-shrink: 0;
}

.inbox-snooze__preset-text {
  flex: 1;
  font-size: $font-size-sm;
  color: var(--p-text-color);
}

.inbox-snooze__preset-when {
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);
  white-space: nowrap;
}

.inbox-snooze__divider {
  height: 1px;
  background: $surface-200;
  margin: $space-3 0;

  .app-dark & {
    background: var(--p-surface-300);
  }
}

.inbox-snooze__custom {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.inbox-snooze__custom-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--p-text-muted-color);
}

.inbox-snooze__datepicker {
  width: 100%;
}

.inbox-snooze__custom-btn {
  align-self: flex-end;
}
</style>
