<template>
  <div class="np-calendar">
    <!-- Weekday header (Mon-first) -->
    <div class="np-calendar__weekdays">
      <span
        v-for="(wd, i) in weekdayLabels"
        :key="wd"
        class="np-calendar__weekday"
        :class="{ 'np-calendar__weekday--weekend': i >= 5 }"
      >
        {{ wd }}
      </span>
    </div>

    <!-- Day grid -->
    <div class="np-calendar__grid">
      <!-- Leading blanks so day 1 lands under its weekday -->
      <span v-for="n in leadingBlanks" :key="`b${n}`" class="np-calendar__blank" aria-hidden="true" />

      <div
        v-for="cell in cells"
        :key="cell.day"
        v-tooltip.top="tileTooltip(cell)"
        class="np-calendar__tile"
        :class="{
          'np-calendar__tile--weekend': cell.is_weekend,
          'np-calendar__tile--today': cell.day === todayDay,
          'np-calendar__tile--has-squeeze': cell.squeeze_base_kopecks > 0,
        }"
      >
        <span class="np-calendar__day-num">{{ cell.day }}</span>
        <span class="np-calendar__day-fact">{{ shortMoney(dayAmount(cell)) }}</span>
        <span class="np-calendar__day-cumulative">{{ shortMoney(cell.cumulative_fact_base_kopecks) }}</span>
        <span
          v-if="cell.squeeze_base_kopecks > 0"
          class="np-calendar__squeeze-dot"
          aria-hidden="true"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/utils/chartFormatters'
import { formatMkMoney } from '@/utils/motivation'
import type { IncomeScheduleDay } from '@/entities/reports'

const props = defineProps<{
  days: IncomeScheduleDay[]
  year: number
  month: number
  baseCurrency: string
}>()

const { t, locale } = useI18n()

const weekdayLabels = computed<string[]>(() => [
  t('dashboard.schedule.wd_mon'),
  t('dashboard.schedule.wd_tue'),
  t('dashboard.schedule.wd_wed'),
  t('dashboard.schedule.wd_thu'),
  t('dashboard.schedule.wd_fri'),
  t('dashboard.schedule.wd_sat'),
  t('dashboard.schedule.wd_sun'),
])

const cells = computed<IncomeScheduleDay[]>(() => props.days)

// Leading blank tiles: weekday of day-1 (Mon=0 … Sun=6) so the grid aligns.
const leadingBlanks = computed<number>(() => {
  const first = new Date(props.year, props.month - 1, 1)
  return (first.getDay() + 6) % 7
})

// Highlight «today» only when the shown month is the current one.
const todayDay = computed<number | null>(() => {
  const now = new Date()
  if (now.getFullYear() === props.year && now.getMonth() + 1 === props.month) {
    return now.getDate()
  }
  return null
})

// Amount booked on a day = fact recognised + expected promised for that day.
const dayAmount = (cell: IncomeScheduleDay): number =>
  cell.fact_base_kopecks + cell.expected_base_kopecks

/** Compact money for a tile: «120 тыс ₽» (abbreviated, blank for 0). */
const shortMoney = (kopecks: number): string => {
  if (kopecks <= 0) return '—'
  return formatMoney(kopecks, locale.value, props.baseCurrency)
}

const tileTooltip = (cell: IncomeScheduleDay): string => {
  const lines = [
    `${t('dashboard.schedule.legend_fact')}: ${formatMkMoney(cell.fact_base_kopecks, props.baseCurrency)}`,
    `${t('dashboard.schedule.tt_expected')}: ${formatMkMoney(cell.expected_base_kopecks, props.baseCurrency)}`,
  ]
  if (cell.squeeze_base_kopecks > 0) {
    lines.push(`${t('dashboard.schedule.legend_slippage')}: ${formatMkMoney(cell.squeeze_base_kopecks, props.baseCurrency)}`)
  }
  return lines.join('\n')
}
</script>

<style lang="scss" scoped>
.np-calendar {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.np-calendar__weekdays,
.np-calendar__grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: $space-2;
}

// $surface-* aliases to --p-surface-* which PrimeVue inverts on .app-dark, so
// surface fills/borders/text are theme-reactive from the BASE rule — no dark
// override needed. Only the red/primary accents differ per theme.
.np-calendar__weekday {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  color: $surface-500;
  text-align: center;
  text-transform: uppercase;
  letter-spacing: 0.04em;

  &--weekend {
    color: var(--p-red-400);
  }
}

.np-calendar__blank {
  aspect-ratio: 1 / 1;
}

.np-calendar__tile {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: $space-1;
  aspect-ratio: 1 / 1;
  min-height: 64px;
  padding: $space-2;
  border-radius: $radius-sm;
  border: 1px solid $surface-200;
  background: $surface-card;
  // Clip money strings to the tile so a wide amount never spills onto the
  // neighbouring tile on narrow layouts (audit L1).
  overflow: hidden;

  // Weekend one step dimmer than a normal tile. Light: $surface-100 (#F1F2F3) is
  // dimmer than the white card. Dark navy: card is $surface-100 (#111E38) so a
  // deeper $surface-50 (#0F1F3D) is needed to read as "dimmer" — the light and
  // dark deltas point opposite ways, so a dark override is required here.
  &--weekend {
    background: $surface-100;

    .app-dark & {
      background: $surface-50;
    }
  }

  // $primary-color stays brand navy (does NOT invert) → unreadable on dark navy;
  // use the theme-reactive --p-primary-color in dark.
  &--today {
    border-color: $primary-color;
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    box-shadow: inset 0 0 0 1px $primary-color;

    .app-dark & {
      border-color: var(--p-primary-color);
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      box-shadow: inset 0 0 0 1px var(--p-primary-color);
    }
  }
}

.np-calendar__day-num {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-700;
}

.np-calendar__day-fact {
  max-width: 100%;
  font-size: $font-size-xs;
  font-variant-numeric: tabular-nums;
  color: $surface-800;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.np-calendar__day-cumulative {
  max-width: 100%;
  font-size: $font-size-2xs;
  font-variant-numeric: tabular-nums;
  color: $surface-500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.np-calendar__squeeze-dot {
  position: absolute;
  top: $space-2;
  right: $space-2;
  width: 8px;
  height: 8px;
  border-radius: $radius-circle;
  background: var(--p-red-500);

  .app-dark & {
    background: var(--p-red-400);
  }
}
</style>
