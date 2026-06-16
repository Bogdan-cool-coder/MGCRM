/**
 * Orbita types — adapted from Vizion Toolbox types.
 * Orientation: 'horizontal' (top) | 'vertical' (left)
 */

export type OrbitaOrientation = 'horizontal' | 'vertical'
export type OrbitaPanelDirection = 'start' | 'end' | 'up' | 'down'

export interface OrbitaPosition {
  top: number
  left: number
}

/** Overlay control interface exposed by overlay sub-components */
export interface OrbitaOverlayControl {
  syncPopover: (open: boolean, event?: MouseEvent | null) => void
  realign: () => void
}

/** Nav item shape consumed by OrbitaPanel */
export interface OrbitaNavItem {
  key: string
  /** Full route path, e.g. '/dashboard' */
  route: string
  /** PrimeIcons class, e.g. 'pi pi-home' */
  icon: string
  /** Translated aria label */
  ariaLabel: string
  /** True when this nav item is currently active */
  isActive: boolean
}
