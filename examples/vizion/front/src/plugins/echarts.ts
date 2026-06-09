/**
 * Global ECharts setup for Vizion.
 *
 * Tree-shaken registration: import only the charts / components / renderers we
 * actually use to keep the bundle small. New chart types must be added here
 * explicitly — `use([...])` is the only way to make them available.
 *
 * Side-effect module: importing it in `main.ts` registers everything globally.
 * Components import the global `<v-chart>` (vue-echarts) and pass
 * `theme="vizion"` to pick up the custom premium palette.
 */
import { use, registerTheme } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { BarChart, LineChart, PieChart } from 'echarts/charts'
import {
  GridComponent,
  TooltipComponent,
  LegendComponent,
  TitleComponent,
  DataZoomComponent,
} from 'echarts/components'

// ---------------------------------------------------------------------------
// Core registration. CanvasRenderer is lighter than SVGRenderer and renders
// large datasets faster — preferred for production. Pie covers both pie and
// doughnut (doughnut = pie with inner-radius via `radius: [inner, outer]`).
// ---------------------------------------------------------------------------
use([
  CanvasRenderer,
  BarChart,
  LineChart,
  PieChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  TitleComponent,
  DataZoomComponent,
])

// ---------------------------------------------------------------------------
// Palette — MACRO brand colours. Led by the corporate blues (Primary Light is
// the dominant first hue, Primary the second) to set the "macro" mood, then
// distinct accents — secondary grey, the status hues (info / success / warning
// / danger) and mid greys — ordered so adjacent series stay easy to tell apart
// (saturated and muted tones alternate; no two near-identical blues in a row).
//
// Deliberately excludes the very light greys (#F1F2F3 / #E3E4E6 / #D5D6D8) and
// white: as series fills on a white card they vanish. Those stay reserved for
// backgrounds / grid / axis lines below.
//
// Stable order: indexed by category / dataset position so the same value gets
// the same hue across renders.
// ---------------------------------------------------------------------------
export const VIZION_ECHARTS_PALETTE: readonly string[] = Object.freeze([
  '#2B4987', // Primary Light — dominant brand blue (leads)
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

const TEXT_PRIMARY = '#475569' // slate-600
const TEXT_MUTED = '#94a3b8' // slate-400
const AXIS_LINE = 'rgba(148, 163, 184, 0.18)' // slate-400 alpha 18 %
const SPLIT_LINE = 'rgba(148, 163, 184, 0.12)'
const TOOLTIP_BG = 'rgba(15, 23, 42, 0.94)' // slate-900 alpha 94 %

// ---------------------------------------------------------------------------
// Theme — registered once, opt-in per chart via `theme="vizion"`. ECharts
// merges this with the chart's `option`, so per-widget overrides still win.
// ---------------------------------------------------------------------------
const vizionTheme = {
  color: [...VIZION_ECHARTS_PALETTE],
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

  // Cartesian axes — soft, sparse, premium dashboard look.
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
    splitLine: { show: true, lineStyle: { color: SPLIT_LINE, type: 'dashed' } },
  },
  logAxis: {
    axisLine: { show: false },
    axisTick: { show: false },
    axisLabel: { color: TEXT_MUTED, fontSize: 11, fontFamily: FONT_FAMILY },
    splitLine: { show: true, lineStyle: { color: SPLIT_LINE, type: 'dashed' } },
  },
  timeAxis: {
    axisLine: { show: true, lineStyle: { color: AXIS_LINE } },
    axisTick: { show: false },
    axisLabel: { color: TEXT_MUTED, fontSize: 11, fontFamily: FONT_FAMILY },
    splitLine: { show: false },
  },

  // Tooltip — dark, rounded, no caret, soft shadow.
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

  // Legend — bottom, centred, circle markers.
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

  // Per-series defaults. ECharts merges these with widget-level series options.
  bar: {
    itemStyle: { borderRadius: [6, 6, 0, 0] },
    barMaxWidth: 48,
  },
  line: {
    smooth: true,
    symbol: 'circle',
    symbolSize: 6,
    lineStyle: { width: 2.5 },
    itemStyle: { borderWidth: 2, borderColor: '#ffffff' },
  },
  pie: {
    itemStyle: { borderColor: '#ffffff', borderWidth: 2 },
    label: {
      color: TEXT_PRIMARY,
      fontFamily: FONT_FAMILY,
      fontSize: 12,
    },
  },
}

registerTheme('vizion', vizionTheme)
