/**
 * useOrbitaDrag — Slice 3 upgrade.
 * - Drag anywhere (pointer, no grip restriction)
 * - Magnet snap to nearest edge (threshold 56px) with CSS transition
 * - Keyboard nudge: Arrow keys 16px when grip has focus; Enter/Space commit
 * - Live position during drag (no store write); commit on pointerup / keyboard commit
 * - Clamp within viewport (ORBITA_VIEWPORT_GAP)
 * - setPointerCapture for reliable move even if pointer leaves element fast
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { ComputedRef, Ref } from 'vue'
import { clampOrbitaPosition } from '../positioning'
import type { OrbitaOrientation, OrbitaPosition } from '../types'

const SNAP_THRESHOLD = 56     // px: distance from viewport edge to trigger magnetic snap
const KEYBOARD_STEP  = 16     // px per arrow key press
const SNAP_DURATION  = 200    // ms: CSS transition for magnet animation

export interface UseOrbitaDragOptions {
  collapsed:          ComputedRef<boolean>
  currentOrientation: ComputedRef<OrbitaOrientation>
  currentPosition:    ComputedRef<OrbitaPosition | null>
  orbitaRef:          Ref<HTMLElement | null>
  /** Committed store setter — called on pointerup / keyboard commit */
  setPosition:        (value: OrbitaPosition | null) => void
}

export function useOrbitaDrag(options: UseOrbitaDragOptions) {
  // Live position: updated on every pointermove WITHOUT writing to store.
  // Reads from store when not dragging; decouples UI from store during drag.
  const livePos = ref<OrbitaPosition | null>(null)
  const isDragging = ref(false)
  const isSnapping  = ref(false)  // true briefly during CSS transition after drop

  const dragState = ref<{
    pointerId: number
    offsetX:   number
    offsetY:   number
    gripEl:    HTMLElement | null
  } | null>(null)

  // ─── Computed style ────────────────────────────────────────────────────────
  const orbitaStyle = computed(() => {
    const pos = livePos.value ?? options.currentPosition.value
    if (!pos) return undefined
    return {
      top:    `${pos.top}px`,
      left:   `${pos.left}px`,
      right:  'auto',
      bottom: 'auto',
      transition: isSnapping.value
        ? `top ${SNAP_DURATION}ms ease-out, left ${SNAP_DURATION}ms ease-out`
        : undefined,
    }
  })

  // ─── Helpers ───────────────────────────────────────────────────────────────
  const getRect = (): DOMRect | null => {
    const el = options.orbitaRef.value
    if (!el || typeof window === 'undefined') return null
    return el.getBoundingClientRect()
  }

  const clamp = (pos: OrbitaPosition): OrbitaPosition => {
    const rect = getRect()
    if (!rect || typeof window === 'undefined') return pos
    return clampOrbitaPosition({
      position: pos,
      rect,
      viewportWidth:  window.innerWidth,
      viewportHeight: window.innerHeight,
    })
  }

  /** Magnetic snap to nearest viewport edge (horizontal only for H; full for V) */
  const snapToEdge = (pos: OrbitaPosition): OrbitaPosition => {
    if (typeof window === 'undefined') return pos
    const rect = getRect()
    if (!rect) return pos

    const vw = window.innerWidth
    const vh = window.innerHeight
    let { top, left } = pos

    const distLeft   = left
    const distRight  = vw - (left + rect.width)
    const distTop    = top
    const distBottom = vh - (top + rect.height)

    if (options.currentOrientation.value === 'horizontal') {
      // Snap left or right edge only
      if (distLeft < SNAP_THRESHOLD) left = 8
      else if (distRight < SNAP_THRESHOLD) left = vw - rect.width - 8
    } else {
      // Vertical: snap all four edges
      const minH = Math.min(distLeft, distRight)
      const minV = Math.min(distTop, distBottom)
      if (minH <= minV) {
        // snap horizontal
        if (distLeft < distRight) left = 8
        else left = vw - rect.width - 8
      } else {
        // snap vertical
        if (distTop < distBottom) top = 8
        else top = vh - rect.height - 8
      }
    }
    return clamp({ top, left })
  }

  /** Write live position to store and trigger snap-to-edge with transition */
  const commitPosition = (raw: OrbitaPosition) => {
    const snapped = snapToEdge(raw)
    isSnapping.value = true
    livePos.value = snapped
    options.setPosition(snapped)
    setTimeout(() => { isSnapping.value = false }, SNAP_DURATION + 50)
  }

  // ─── Pointer drag ──────────────────────────────────────────────────────────
  const onPointerMove = (e: PointerEvent) => {
    if (!dragState.value || dragState.value.pointerId !== e.pointerId) return
    const newPos = clamp({
      left: e.clientX - dragState.value.offsetX,
      top:  e.clientY - dragState.value.offsetY,
    })
    livePos.value = newPos   // live update, no store write
  }

  const onPointerUp = (e: PointerEvent) => {
    if (!dragState.value || dragState.value.pointerId !== e.pointerId) return
    const currentLive = livePos.value ?? options.currentPosition.value
    if (currentLive) commitPosition(currentLive)
    dragState.value = null
    isDragging.value = false
  }

  const startDrag = (e: PointerEvent) => {
    const rect = getRect()
    if (!rect || e.button !== 0) return
    const el = options.orbitaRef.value
    if (!el) return
    // capture pointer so move events continue even outside the element
    try { (e.target as HTMLElement).setPointerCapture(e.pointerId) } catch { /* ok */ }

    // Initialise live pos from current store value
    livePos.value = options.currentPosition.value
      ? { ...options.currentPosition.value }
      : { top: rect.top, left: rect.left }

    dragState.value = {
      pointerId: e.pointerId,
      offsetX:   e.clientX - rect.left,
      offsetY:   e.clientY - rect.top,
      gripEl:    e.target as HTMLElement | null,
    }
    isDragging.value = true
  }

  // ─── Keyboard nudge (on drag grip element) ─────────────────────────────────
  const onGripKeyDown = (e: KeyboardEvent) => {
    if (!['ArrowUp','ArrowDown','ArrowLeft','ArrowRight','Enter',' '].includes(e.key)) return
    e.preventDefault()
    const current = options.currentPosition.value ?? { top: 0, left: 0 }
    if (e.key === 'Enter' || e.key === ' ') {
      // commit current (snap)
      commitPosition(current)
      return
    }
    const delta = {
      ArrowUp:    { top: -KEYBOARD_STEP, left: 0 },
      ArrowDown:  { top:  KEYBOARD_STEP, left: 0 },
      ArrowLeft:  { top: 0, left: -KEYBOARD_STEP },
      ArrowRight: { top: 0, left:  KEYBOARD_STEP },
    }[e.key] ?? { top: 0, left: 0 }

    const newPos = clamp({ top: current.top + delta.top, left: current.left + delta.left })
    livePos.value = newPos
    // Each key step commits immediately (no pending state)
    options.setPosition(newPos)
  }

  // ─── Re-clamp on layout changes ────────────────────────────────────────────
  const scheduleClamp = () => {
    void nextTick().then(() => {
      requestAnimationFrame(() => {
        const pos = options.currentPosition.value
        if (pos) {
          const clamped = clamp(pos)
          livePos.value = null  // let computed fall back to store value
          options.setPosition(clamped)
        }
      })
    })
  }

  watch([options.collapsed, options.currentOrientation], () => { scheduleClamp() })

  onMounted(() => {
    window.addEventListener('pointermove',   onPointerMove)
    window.addEventListener('pointerup',     onPointerUp)
    window.addEventListener('pointercancel', onPointerUp)
    window.addEventListener('resize',        scheduleClamp)
    scheduleClamp()
  })

  onBeforeUnmount(() => {
    window.removeEventListener('pointermove',   onPointerMove)
    window.removeEventListener('pointerup',     onPointerUp)
    window.removeEventListener('pointercancel', onPointerUp)
    window.removeEventListener('resize',        scheduleClamp)
  })

  return {
    startDrag,
    onGripKeyDown,
    orbitaStyle,
    isDragging,
  }
}
