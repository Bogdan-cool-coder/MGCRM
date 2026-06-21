import { appTheme } from '../config'

type FlatVars = Record<string, string | number>

function addPalette(prefix: string, palette: Record<string | number, string>, target: FlatVars) {
  Object.entries(palette).forEach(([key, value]) => {
    target[`${prefix}-${key}`] = value
  })
}

// Surface palette steps that PrimeVue manages — --p-surface-N switches on .app-dark.
// We alias --app-surface-N → var(--p-surface-N) so all SCSS vars and components
// that consume --app-surface-* are automatically reactive to dark mode.
const SURFACE_STEPS = [0, 50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950] as const

export function createAppCssVariables(): FlatVars {
  const vars: FlatVars = {}

  // Common brand colors
  Object.entries(appTheme.common).forEach(([key, value]) => {
    vars[key.replace(/[A-Z]/g, (char) => `-${char.toLowerCase()}`)] = value
  })

  // Palette shades
  addPalette('primary', appTheme.palette.primary, vars)
  // Surface shades: alias to PrimeVue --p-surface-N (reactive to dark mode).
  // Static hex from surfacePalette deliberately NOT used here — PrimeVue owns these.
  SURFACE_STEPS.forEach((step) => {
    vars[`surface-${step}`] = `var(--p-surface-${step})`
  })
  addPalette('red', appTheme.palette.red, vars)
  addPalette('orange', appTheme.palette.orange, vars)
  addPalette('blue', appTheme.palette.blue, vars)
  addPalette('green', appTheme.palette.green, vars)
  addPalette('teal', appTheme.palette.teal, vars)
  addPalette('monochrome', appTheme.palette.monochrome, vars)

  // Semantic surface — mapped to reactive PrimeVue surface tokens.
  // surface-page  = surface-100 (light: #F1F2F3, dark: #444547)
  // surface-card  = p-card-background — реактивный токен PrimeVue Card (light: #fff, dark: surface.0 dark)
  //                 НЕ var(--p-surface-0) напрямую, т.к. в dark surface-0 = #000 (surface.950).
  //                 Используем --p-card-background, который теперь = {surface.0} из colorScheme.
  // surface-overlay = surface-0
  // surface-muted = surface-50  (light: #F9FAFB, dark: inverted by PrimeVue)
  // surface-hover = surface-200
  vars['surface-page'] = 'var(--p-surface-100)'
  vars['surface-card'] = 'var(--p-card-background)'
  vars['surface-overlay'] = 'var(--p-card-background)'
  vars['surface-muted'] = 'var(--p-surface-50)'
  vars['surface-hover'] = 'var(--p-surface-200)'

  // Semantic text — mapped to PrimeVue text tokens.
  vars['text-primary'] = 'var(--p-text-color)'
  vars['text-secondary'] = 'var(--p-text-muted-color)'
  vars['text-muted'] = 'var(--p-text-muted-color)'
  vars['text-inverse'] = appTheme.semantic.text.inverse  // white — always static

  // Semantic border — mapped to PrimeVue content border token.
  vars['border-default'] = 'var(--p-content-border-color)'
  vars['border-strong'] = 'var(--p-surface-300)'
  vars['border-focus'] = appTheme.semantic.border.focus    // brand #172747 — static
  vars['border-accent'] = appTheme.semantic.border.accent  // brand accent — static

  // Brand
  vars['primary-color'] = appTheme.semantic.brand.primary
  vars['secondary-color'] = appTheme.semantic.brand.secondary

  // Status
  const statusKeys = ['success', 'danger', 'warning', 'info'] as const
  statusKeys.forEach((status) => {
    const s = appTheme.semantic.status[status]
    vars[`status-${status}-bg`] = s.bg
    vars[`status-${status}-border`] = s.border
    vars[`status-${status}-text`] = s.text
    vars[`status-${status}-solid`] = s.solid
  })

  // Actions — primary stays brand hex (always dark); secondary uses surface tokens (reactive)
  vars['action-primary-bg'] = appTheme.semantic.action.primary.bg
  vars['action-primary-hover'] = appTheme.semantic.action.primary.hover
  vars['action-primary-active'] = appTheme.semantic.action.primary.active
  vars['action-primary-text'] = appTheme.semantic.action.primary.text
  vars['action-secondary-bg'] = 'var(--p-surface-700)'
  vars['action-secondary-hover'] = 'var(--p-surface-800)'
  vars['action-secondary-active'] = 'var(--p-surface-900)'
  vars['action-secondary-text'] = appTheme.semantic.action.secondary.text

  // Input — delegate to PrimeVue input tokens (reactive to dark mode)
  vars['input-bg'] = 'var(--p-inputtext-background)'
  vars['input-border'] = 'var(--p-inputtext-border-color)'
  vars['input-hover-border'] = 'var(--p-inputtext-hover-border-color)'
  vars['input-focus-border'] = 'var(--p-inputtext-focus-border-color)'
  vars['input-text'] = 'var(--p-inputtext-color)'

  // Radius
  vars['radius-sm'] = appTheme.radius.sm
  vars['radius-md'] = appTheme.radius.md
  vars['radius-lg'] = appTheme.radius.lg
  vars['radius-xl'] = appTheme.radius.xl
  vars['border-radius'] = appTheme.radius.md
  vars['card-border-radius'] = appTheme.radius.lg

  // Shadows
  vars['shadow-sm'] = appTheme.shadows.sm
  vars['shadow-card'] = appTheme.shadows.card
  vars['shadow-md'] = appTheme.shadows.md
  vars['shadow-lg'] = appTheme.shadows.lg

  // Motion
  vars['duration-fast'] = appTheme.motion.duration.fast
  vars['duration-normal'] = appTheme.motion.duration.normal
  vars['duration-slow'] = appTheme.motion.duration.slow
  vars['transition-fast'] = appTheme.motion.transition.fast
  vars['transition-normal'] = appTheme.motion.transition.normal

  // Layout
  vars['header-height'] = appTheme.layout.headerHeight
  vars['sidebar-width'] = appTheme.layout.sidebarWidth
  vars['sidebar-rail-width'] = appTheme.layout.sidebarRailWidth

  // Typography
  vars['font-family-sans'] = appTheme.typography.fontFamily.sans
  Object.entries(appTheme.typography.size).forEach(([key, value]) => {
    vars[`font-size-${key}`] = value
  })
  Object.entries(appTheme.typography.weight).forEach(([key, value]) => {
    vars[`font-weight-${key}`] = value
  })
  Object.entries(appTheme.typography.lineHeight).forEach(([key, value]) => {
    vars[`line-height-${key}`] = value
  })

  // Z-index
  Object.entries(appTheme.zIndex).forEach(([key, value]) => {
    vars[`z-${key.replace(/[A-Z]/g, (char) => `-${char.toLowerCase()}`)}`] = value
  })

  return vars
}

export function applyAppCssVariables(target: HTMLElement = document.documentElement) {
  const vars = createAppCssVariables()
  Object.entries(vars).forEach(([key, value]) => {
    target.style.setProperty(`--app-${key}`, String(value))
  })
}
