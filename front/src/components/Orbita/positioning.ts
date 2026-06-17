/**
 * Orbita positioning utilities — adapted 1-in-1 from Vizion Toolbox positioning.ts.
 * Orientation mapping: 'horizontal' → top placement, 'vertical' → left placement.
 */

import type { OrbitaOrientation, OrbitaPanelDirection, OrbitaPosition } from './types'

export const ORBITA_VIEWPORT_GAP = 8
export const ORBITA_CLAMP_PADDING = 8
export const ORBITA_PANEL_VIEWPORT_PADDING = 8

interface ClampOrbitaPositionOptions {
  position: OrbitaPosition
  rect: DOMRect
  viewportWidth: number
  viewportHeight: number
}

interface ResolveOrbitaPanelDirectionOptions {
  orientation: OrbitaOrientation
  currentDirection: OrbitaPanelDirection
  orbitaRect: DOMRect
  panelWidth: number
  panelHeight: number
  viewportWidth: number
  viewportHeight: number
}

export function getDefaultOrbitaPanelDirection(
  orientation: OrbitaOrientation,
): OrbitaPanelDirection {
  return orientation === 'horizontal' ? 'start' : 'up'
}

export function clampOrbitaPosition({
  position,
  rect,
  viewportWidth,
  viewportHeight,
}: ClampOrbitaPositionOptions): OrbitaPosition {
  const minLeft = ORBITA_VIEWPORT_GAP + ORBITA_CLAMP_PADDING
  const maxLeft = viewportWidth - rect.width - ORBITA_VIEWPORT_GAP - ORBITA_CLAMP_PADDING
  const minTop = ORBITA_VIEWPORT_GAP + ORBITA_CLAMP_PADDING
  const maxTop = viewportHeight - rect.height - ORBITA_VIEWPORT_GAP - ORBITA_CLAMP_PADDING

  return {
    top: Math.min(Math.max(position.top, minTop), Math.max(minTop, maxTop)),
    left: Math.min(Math.max(position.left, minLeft), Math.max(minLeft, maxLeft)),
  }
}

export function resolveOrbitaPanelDirection({
  orientation,
  currentDirection,
  orbitaRect,
  panelWidth,
  panelHeight,
  viewportWidth,
  viewportHeight,
}: ResolveOrbitaPanelDirectionOptions): OrbitaPanelDirection {
  const availableLeft = orbitaRect.left
  const availableRight = viewportWidth - orbitaRect.right
  const availableTop = orbitaRect.top
  const availableBottom = viewportHeight - orbitaRect.bottom
  const canOpenStart = availableLeft >= panelWidth + ORBITA_PANEL_VIEWPORT_PADDING
  const canOpenEnd = availableRight >= panelWidth + ORBITA_PANEL_VIEWPORT_PADDING
  const canOpenUp = availableTop >= panelHeight + ORBITA_PANEL_VIEWPORT_PADDING
  const canOpenDown = availableBottom >= panelHeight + ORBITA_PANEL_VIEWPORT_PADDING

  if (orientation === 'horizontal') {
    if (currentDirection === 'end') {
      if (!canOpenEnd && canOpenStart) return 'start'
      if (!canOpenEnd) return availableRight > availableLeft ? 'end' : 'start'
      return 'end'
    }
    if (!canOpenStart && canOpenEnd) return 'end'
    if (!canOpenStart) return availableRight > availableLeft ? 'end' : 'start'
    return 'start'
  }

  // vertical orientation
  if (currentDirection === 'down') {
    if (!canOpenDown && canOpenUp) return 'up'
    if (!canOpenDown) return availableBottom > availableTop ? 'down' : 'up'
    return 'down'
  }
  if (!canOpenUp && canOpenDown) return 'down'
  if (!canOpenUp) return availableBottom > availableTop ? 'down' : 'up'
  return 'up'
}
