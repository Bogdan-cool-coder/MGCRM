import type { ToolboxPanelDirection, ToolboxPlacement, ToolboxPosition } from './types'

export const TOOLBOX_VIEWPORT_GAP = 8
export const TOOLBOX_CLAMP_PADDING = 8
export const TOOLBOX_PANEL_VIEWPORT_PADDING = 8

interface ClampToolboxPositionOptions {
  position: ToolboxPosition
  rect: DOMRect
  viewportWidth: number
  viewportHeight: number
}

interface ResolveToolboxPanelDirectionOptions {
  placement: ToolboxPlacement
  currentDirection: ToolboxPanelDirection
  toolboxRect: DOMRect
  panelWidth: number
  panelHeight: number
  viewportWidth: number
  viewportHeight: number
}

export function getDefaultToolboxPanelDirection(
  placement: ToolboxPlacement,
): ToolboxPanelDirection {
  return placement === 'top' ? 'start' : 'up'
}

export function clampToolboxPosition({
  position,
  rect,
  viewportWidth,
  viewportHeight,
}: ClampToolboxPositionOptions): ToolboxPosition {
  const minLeft = TOOLBOX_VIEWPORT_GAP + TOOLBOX_CLAMP_PADDING
  const maxLeft = viewportWidth - rect.width - TOOLBOX_VIEWPORT_GAP - TOOLBOX_CLAMP_PADDING
  const minTop = TOOLBOX_VIEWPORT_GAP + TOOLBOX_CLAMP_PADDING
  const maxTop = viewportHeight - rect.height - TOOLBOX_VIEWPORT_GAP - TOOLBOX_CLAMP_PADDING

  return {
    top: Math.min(Math.max(position.top, minTop), Math.max(minTop, maxTop)),
    left: Math.min(Math.max(position.left, minLeft), Math.max(minLeft, maxLeft)),
  }
}

export function resolveToolboxPanelDirection({
  placement,
  currentDirection,
  toolboxRect,
  panelWidth,
  panelHeight,
  viewportWidth,
  viewportHeight,
}: ResolveToolboxPanelDirectionOptions): ToolboxPanelDirection {
  const availableLeft = toolboxRect.left
  const availableRight = viewportWidth - toolboxRect.right
  const availableTop = toolboxRect.top
  const availableBottom = viewportHeight - toolboxRect.bottom
  const canOpenStart = availableLeft >= panelWidth + TOOLBOX_PANEL_VIEWPORT_PADDING
  const canOpenEnd = availableRight >= panelWidth + TOOLBOX_PANEL_VIEWPORT_PADDING
  const canOpenUp = availableTop >= panelHeight + TOOLBOX_PANEL_VIEWPORT_PADDING
  const canOpenDown = availableBottom >= panelHeight + TOOLBOX_PANEL_VIEWPORT_PADDING

  if (placement === 'top') {
    if (currentDirection === 'end') {
      if (!canOpenEnd && canOpenStart) {
        return 'start'
      }

      if (!canOpenEnd) {
        return availableRight > availableLeft ? 'end' : 'start'
      }

      return 'end'
    }

    if (!canOpenStart && canOpenEnd) {
      return 'end'
    }

    if (!canOpenStart) {
      return availableRight > availableLeft ? 'end' : 'start'
    }

    return 'start'
  }

  if (currentDirection === 'down') {
    if (!canOpenDown && canOpenUp) {
      return 'up'
    }

    if (!canOpenDown) {
      return availableBottom > availableTop ? 'down' : 'up'
    }

    return 'down'
  }

  if (!canOpenUp && canOpenDown) {
    return 'down'
  }

  if (!canOpenUp) {
    return availableBottom > availableTop ? 'down' : 'up'
  }

  return 'up'
}
