export type ToolboxPlacement = 'top' | 'left'
export type ToolboxPanelDirection = 'start' | 'end' | 'up' | 'down'

export interface ToolboxPosition {
  top: number
  left: number
}

export type ToolboxPositions = Partial<Record<ToolboxPlacement, ToolboxPosition | null>>

export type ToolboxOverlayName = 'company' | 'profile' | 'miniChat'

export interface ToolboxOverlayControl {
  syncPopover: (open: boolean, event?: MouseEvent | null) => void
  /**
   * Re-anchors the (already open) popover against its trigger button. The
   * Toolbox is draggable + placement-switchable; PrimeVue's Popover anchors
   * once at `show()` time and never recomputes unless the window scrolls or
   * resizes. When the Toolbox itself moves (drag, placement toggle), open
   * overlays drift away from their button until this is invoked.
   * No-op if the overlay is closed.
   */
  realign: () => void
}

export interface ToolboxNavItem {
  key: string
  path: string
  icon: string
  ariaLabel: string
}
