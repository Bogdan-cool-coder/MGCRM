import { appTheme } from '../config'

type FlatVars = Record<string, string | number>

function addPalette(prefix: string, palette: Record<string | number, string>, target: FlatVars) {
  Object.entries(palette).forEach(([key, value]) => {
    target[`${prefix}-${key}`] = value
  })
}

export function createAppCssVariables(): FlatVars {
  const vars: FlatVars = {}

  Object.entries(appTheme.common).forEach(([key, value]) => {
    vars[key.replace(/[A-Z]/g, (char) => `-${char.toLowerCase()}`)] = value
  })

  addPalette('primary', appTheme.palette.primary, vars)
  addPalette('surface', appTheme.palette.surface, vars)
  addPalette('red', appTheme.palette.red, vars)
  addPalette('orange', appTheme.palette.orange, vars)
  addPalette('blue', appTheme.palette.blue, vars)
  addPalette('green', appTheme.palette.green, vars)
  addPalette('monochrome', appTheme.palette.monochrome, vars)

  vars['surface-page'] = appTheme.semantic.surface.page
  vars['surface-card'] = appTheme.semantic.surface.card
  vars['surface-overlay'] = appTheme.semantic.surface.overlay
  vars['surface-muted'] = appTheme.semantic.surface.muted
  vars['surface-hover'] = appTheme.semantic.surface.hover
  vars['text-primary'] = appTheme.semantic.text.primary
  vars['text-secondary'] = appTheme.semantic.text.secondary
  vars['text-muted'] = appTheme.semantic.text.muted
  vars['text-inverse'] = appTheme.semantic.text.inverse
  vars['border-default'] = appTheme.semantic.border.default
  vars['border-strong'] = appTheme.semantic.border.strong
  vars['border-focus'] = appTheme.semantic.border.focus
  vars['border-accent'] = appTheme.semantic.border.accent
  vars['primary-color'] = appTheme.semantic.brand.primary
  vars['secondary-color'] = appTheme.semantic.brand.secondary
  vars['status-success-bg'] = appTheme.semantic.status.success.bg
  vars['status-success-border'] = appTheme.semantic.status.success.border
  vars['status-success-text'] = appTheme.semantic.status.success.text
  vars['status-success-solid'] = appTheme.semantic.status.success.solid
  vars['status-danger-bg'] = appTheme.semantic.status.danger.bg
  vars['status-danger-border'] = appTheme.semantic.status.danger.border
  vars['status-danger-text'] = appTheme.semantic.status.danger.text
  vars['status-danger-solid'] = appTheme.semantic.status.danger.solid
  vars['status-warning-bg'] = appTheme.semantic.status.warning.bg
  vars['status-warning-border'] = appTheme.semantic.status.warning.border
  vars['status-warning-text'] = appTheme.semantic.status.warning.text
  vars['status-warning-solid'] = appTheme.semantic.status.warning.solid
  vars['status-info-bg'] = appTheme.semantic.status.info.bg
  vars['status-info-border'] = appTheme.semantic.status.info.border
  vars['status-info-text'] = appTheme.semantic.status.info.text
  vars['status-info-solid'] = appTheme.semantic.status.info.solid
  vars['action-primary-bg'] = appTheme.semantic.action.primary.bg
  vars['action-primary-hover'] = appTheme.semantic.action.primary.hover
  vars['action-primary-active'] = appTheme.semantic.action.primary.active
  vars['action-primary-text'] = appTheme.semantic.action.primary.text
  vars['action-secondary-bg'] = appTheme.semantic.action.secondary.bg
  vars['action-secondary-hover'] = appTheme.semantic.action.secondary.hover
  vars['action-secondary-active'] = appTheme.semantic.action.secondary.active
  vars['action-secondary-text'] = appTheme.semantic.action.secondary.text
  vars['input-bg'] = appTheme.semantic.input.bg
  vars['input-border'] = appTheme.semantic.input.border
  vars['input-hover-border'] = appTheme.semantic.input.hoverBorder
  vars['input-focus-border'] = appTheme.semantic.input.focusBorder
  vars['input-text'] = appTheme.semantic.input.text
  vars['radius-sm'] = appTheme.radius.sm
  vars['radius-md'] = appTheme.radius.md
  vars['radius-lg'] = appTheme.radius.lg
  vars['border-radius'] = appTheme.radius.md
  vars['card-border-radius'] = appTheme.radius.md
  vars['shadow-sm'] = appTheme.shadows.sm
  vars['shadow-md'] = appTheme.shadows.md
  vars['shadow-lg'] = appTheme.shadows.lg
  vars['shadow-card'] = appTheme.shadows.card
  vars['duration-fast'] = appTheme.motion.duration.fast
  vars['transition-fast'] = appTheme.motion.transition.fast
  vars['header-height'] = appTheme.layout.headerHeight
  vars['sidebar-width'] = appTheme.layout.sidebarWidth
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
