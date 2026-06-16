/**
 * useOrbitaDrag — adapted 1-in-1 from Vizion useToolboxDrag.
 * Handles pointer-based drag of the Orbita panel.
 * Position is committed to layoutStore on pointerup.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { ComputedRef, Ref } from 'vue'
import { clampOrbitaPosition } from '../positioning'
import type { OrbitaOrientation, OrbitaPosition } from '../types'

interface UseOrbitaDragOptions {
  collapsed: ComputedRef<boolean>
  currentOrientation: ComputedRef<OrbitaOrientation>
  currentPosition: ComputedRef<OrbitaPosition | null>
  orbitaRef: Ref<HTMLElement | null>
  setPosition: (value: OrbitaPosition | null) => void
}

export function useOrbitaDrag(options: UseOrbitaDragOptions) {
  const dragState = ref<{
    pointerId: number
    offsetX: number
    offsetY: number
  } | null>(null)

  const orbitaStyle = computed(() => {
    const position = options.currentPosition.value
    if (!position) return undefined
    return {
      top: `${position.top}px`,
      left: `${position.left}px`,
      right: 'auto',
      bottom: 'auto',
    }
  })

  const getOrbitaRect = () => {
    const element = options.orbitaRef.value
    if (!element || typeof window === 'undefined') return null
    return element.getBoundingClientRect()
  }

  const isInteractiveElement = (target: EventTarget | null) => {
    if (!(target instanceof Element)) return false
    return Boolean(
      target.closest(
        'button, a, input, select, textarea, summary, [role="button"], [role="link"], .p-button',
      ),
    )
  }

  const syncOrbitaPosition = (position: OrbitaPosition) => {
    const rect = getOrbitaRect()
    if (!rect || typeof window === 'undefined') return
    options.setPosition(
      clampOrbitaPosition({
        position,
        rect,
        viewportWidth: window.innerWidth,
        viewportHeight: window.innerHeight,
      }),
    )
  }

  const scheduleClamp = () => {
    void nextTick().then(() => {
      requestAnimationFrame(() => {
        const position = options.currentPosition.value
        if (position) syncOrbitaPosition(position)
      })
    })
  }

  const stopDrag = () => {
    dragState.value = null
  }

  const onPointerMove = (event: PointerEvent) => {
    const currentDragState = dragState.value
    if (!currentDragState) return
    syncOrbitaPosition({
      left: event.clientX - currentDragState.offsetX,
      top: event.clientY - currentDragState.offsetY,
    })
  }

  const onPointerUp = (event: PointerEvent) => {
    if (dragState.value?.pointerId === event.pointerId) stopDrag()
  }

  const startDrag = (event: PointerEvent) => {
    const rect = getOrbitaRect()
    if (!rect || event.button !== 0 || isInteractiveElement(event.target)) return
    dragState.value = {
      pointerId: event.pointerId,
      offsetX: event.clientX - rect.left,
      offsetY: event.clientY - rect.top,
    }
  }

  watch([options.collapsed, options.currentOrientation], () => {
    scheduleClamp()
  })

  onMounted(() => {
    window.addEventListener('pointermove', onPointerMove)
    window.addEventListener('pointerup', onPointerUp)
    window.addEventListener('pointercancel', onPointerUp)
    window.addEventListener('resize', scheduleClamp)
    scheduleClamp()
  })

  onBeforeUnmount(() => {
    window.removeEventListener('pointermove', onPointerMove)
    window.removeEventListener('pointerup', onPointerUp)
    window.removeEventListener('pointercancel', onPointerUp)
    window.removeEventListener('resize', scheduleClamp)
  })

  return {
    startDrag,
    orbitaStyle,
  }
}
