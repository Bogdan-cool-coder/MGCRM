/**
 * Global ECharts setup for MACRO Global CRM.
 *
 * Tree-shaken registration: import only the charts / components / renderers we
 * actually use to keep the bundle small. New chart types must be added here
 * explicitly — `use([...])` is the only way to make them available.
 *
 * Side-effect module: importing it in `main.ts` registers everything globally.
 * Components import the global `<v-chart>` (vue-echarts) and pass
 * `theme="macro-crm"` to pick up the custom premium palette.
 *
 * Dark mode: call `rebuildMacroCrmTheme(isDark)` when dark mode toggles.
 * `useMacroCrmEchartsTheme` composable handles this reactively.
 */
import { use, registerTheme } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { BarChart, LineChart, PieChart, FunnelChart } from 'echarts/charts'
import {
  GridComponent,
  TooltipComponent,
  LegendComponent,
  TitleComponent,
  DataZoomComponent,
} from 'echarts/components'

// ---------------------------------------------------------------------------
// Core registration
// ---------------------------------------------------------------------------
use([
  CanvasRenderer,
  BarChart,
  LineChart,
  PieChart,
  FunnelChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  TitleComponent,
  DataZoomComponent,
])

// ---------------------------------------------------------------------------
// Palette — MACRO brand colours (same as Vizion, MACRO Global palette).
// ---------------------------------------------------------------------------
export const MACRO_ECHARTS_PALETTE: readonly string[] = Object.freeze([
  '#2B4987', // Primary Light — dominant brand blue
  '#172747', // Primary — deep brand navy
  '#8DD9FF', // Info — bright sky blue accent
  '#6C757D', // Secondary — neutral slate
  '#A7EFAA', // Success — green
  '#FF5A44', // Danger — red
  '#ABB5BE', // Secondary Light — pale slate
  '#FFB38A', // Warning — peach/orange
  '#7E7F82', // Mid grey
  '#9B9C9F', // Light-mid grey
])

const FONT_FAMILY =
  "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif"

// ---------------------------------------------------------------------------
// Light theme constants
// ---------------------------------------------------------------------------
const TEXT_PRIMARY_LIGHT = '#444547' // surface.800 (MACRO Gray-800)
const TEXT_MUTED_LIGHT = '#9B9C9F' // surface.500 (MACRO Gray-500)
const AXIS_LINE_LIGHT = 'rgba(209, 213, 219, 0.4)'
const SPLIT_LINE_LIGHT = 'rgba(209, 213, 219, 0.25)'
const TOOLTIP_BG_LIGHT = 'rgba(23, 39, 71, 0.92)' // brand-primary dark

// ---------------------------------------------------------------------------
// Dark theme constants
// ---------------------------------------------------------------------------
const TEXT_PRIMARY_DARK = '#E3E4E6' // surface.200
const TEXT_MUTED_DARK = '#7E7F82' // surface.600
const AXIS_LINE_DARK = 'rgba(97, 98, 99, 0.4)' // surface.700
const SPLIT_LINE_DARK = 'rgba(97, 98, 99, 0.25)'
const TOOLTIP_BG_DARK = 'rgba(39, 40, 41, 0.96)' // surface.900

// ---------------------------------------------------------------------------
// Theme builder
// ---------------------------------------------------------------------------
export const buildMacroCrmTheme = (isDark: boolean) => {
  const TEXT_PRIMARY = isDark ? TEXT_PRIMARY_DARK : TEXT_PRIMARY_LIGHT
  const TEXT_MUTED = isDark ? TEXT_MUTED_DARK : TEXT_MUTED_LIGHT
  const AXIS_LINE = isDark ? AXIS_LINE_DARK : AXIS_LINE_LIGHT
  const SPLIT_LINE = isDark ? SPLIT_LINE_DARK : SPLIT_LINE_LIGHT
  const TOOLTIP_BG = isDark ? TOOLTIP_BG_DARK : TOOLTIP_BG_LIGHT

  return {
    color: [...MACRO_ECHARTS_PALETTE],
    backgroundColor: 'transparent',

    textStyle: {
      fontFamily: FONT_FAMILY,
      color: TEXT_PRIMARY,
      fontWeight: 500,
    },

    title: {
      left: 'center',
      textStyle: {
        fontFamily: FONT_FAMILY,
        color: TEXT_PRIMARY,
        fontSize: 14,
        fontWeight: 600,
      },
      subtextStyle: {
        fontFamily: FONT_FAMILY,
        color: TEXT_MUTED,
        fontSize: 12,
      },
    },

    categoryAxis: {
      axisLine: { show: true, lineStyle: { color: AXIS_LINE } },
      axisTick: { show: false },
      axisLabel: {
        color: TEXT_MUTED,
        fontSize: 11,
        fontFamily: FONT_FAMILY,
      },
      splitLine: { show: false, lineStyle: { color: SPLIT_LINE } },
    },

    valueAxis: {
      axisLine: { show: false },
      axisTick: { show: false },
      axisLabel: {
        color: TEXT_MUTED,
        fontSize: 11,
        fontFamily: FONT_FAMILY,
      },
      splitLine: { show: true, lineStyle: { color: SPLIT_LINE, type: 'dashed' as const } },
    },

    logAxis: {
      axisLine: { show: false },
      axisTick: { show: false },
      axisLabel: { color: TEXT_MUTED, fontSize: 11, fontFamily: FONT_FAMILY },
      splitLine: { show: true, lineStyle: { color: SPLIT_LINE, type: 'dashed' as const } },
    },

    timeAxis: {
      axisLine: { show: true, lineStyle: { color: AXIS_LINE } },
      axisTick: { show: false },
      axisLabel: { color: TEXT_MUTED, fontSize: 11, fontFamily: FONT_FAMILY },
      splitLine: { show: false },
    },

    tooltip: {
      backgroundColor: TOOLTIP_BG,
      borderColor: 'transparent',
      borderWidth: 0,
      padding: [10, 14],
      textStyle: {
        color: '#f8fafc',
        fontFamily: FONT_FAMILY,
        fontSize: 12,
        fontWeight: 500,
      },
      extraCssText:
        'border-radius: 10px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.25);',
    },

    legend: {
      bottom: 0,
      left: 'center',
      itemGap: 18,
      itemWidth: 8,
      itemHeight: 8,
      icon: 'circle',
      textStyle: {
        color: TEXT_PRIMARY,
        fontFamily: FONT_FAMILY,
        fontSize: 12,
        fontWeight: 500,
      },
    },

    bar: {
      itemStyle: { borderRadius: [6, 6, 0, 0] },
      barMaxWidth: 48,
    },

    line: {
      smooth: true,
      symbol: 'circle',
      symbolSize: 6,
      lineStyle: { width: 2.5 },
      itemStyle: { borderWidth: 2, borderColor: isDark ? '#2C2C2C' : '#ffffff' },
    },

    pie: {
      itemStyle: { borderColor: isDark ? '#2C2C2C' : '#ffffff', borderWidth: 2 },
      label: {
        color: TEXT_PRIMARY,
        fontFamily: FONT_FAMILY,
        fontSize: 12,
      },
    },
  }
}

// ---------------------------------------------------------------------------
// Theme-aware token getters — widgets must read series-level colours from here
// instead of hard-coding hex so charts adapt to light/dark and stay on-palette.
// ---------------------------------------------------------------------------

/** Primary brand bar colour (palette[0]) — used for single-series bar charts. */
export const macroCrmBarColor = (): string => MACRO_ECHARTS_PALETTE[0] as string

/** Muted text colour for series data-labels, adapts to dark mode. */
export const macroCrmMutedText = (isDark: boolean): string =>
  isDark ? TEXT_MUTED_DARK : TEXT_MUTED_LIGHT

// ---------------------------------------------------------------------------
// Re-register helper — called by useMacroCrmEchartsTheme when dark mode changes
// ---------------------------------------------------------------------------
export const rebuildMacroCrmTheme = (isDark: boolean): void => {
  registerTheme('macro-crm', buildMacroCrmTheme(isDark))
}

// Initial registration (light by default)
registerTheme('macro-crm', buildMacroCrmTheme(false))
