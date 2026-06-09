import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { ComputedRef, Ref } from 'vue'
import { clampToolboxPosition } from '../positioning'
import type { ToolboxPlacement, ToolboxPosition } from '../types'

interface UseToolboxDragOptions {
  collapsed: ComputedRef<boolean>
  currentPlacement: ComputedRef<ToolboxPlacement>
  currentPosition: ComputedRef<ToolboxPosition | null>
  toolboxRef: Ref<HTMLElement | null>
  setPositionByPlacement: (placement: ToolboxPlacement, value: ToolboxPosition | null) => void
}

export function useToolboxDrag(options: UseToolboxDragOptions) {
  const dragState = ref<{
    pointerId: number
    offsetX: number
    offsetY: number
  } | null>(null)

  const toolboxStyle = computed(() => {
    const position = options.currentPosition.value

    if (!position) {
      return undefined
    }

    return {
      top: `${position.top}px`,
      left: `${position.left}px`,
      right: 'auto',
      bottom: 'auto',
    }
  })

  const getToolboxRect = () => {
    const element = options.toolboxRef.value

    if (!element || typeof window === 'undefined') {
      return null
    }

    return element.getBoundingClientRect()
  }

  const isInteractiveElement = (target: EventTarget | null) => {
    if (!(target instanceof Element)) {
      return false
    }

    return Boolean(
      target.closest(
        'button, a, input, select, textarea, summary, [role="button"], [role="link"], .p-button',
      ),
    )
  }

  const syncToolboxPosition = (position: ToolboxPosition) => {
    const rect = getToolboxRect()

    if (!rect || typeof window === 'undefined') {
      return
    }

    options.setPositionByPlacement(
      options.currentPlacement.value,
      clampToolboxPosition({
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

        if (position) {
          syncToolboxPosition(position)
        }
      })
    })
  }

  const stopDrag = () => {
    dragState.value = null
  }

  const onPointerMove = (event: PointerEvent) => {
    const currentDragState = dragState.value

    if (!currentDragState) {
      return
    }

    syncToolboxPosition({
      left: event.clientX - currentDragState.offsetX,
      top: event.clientY - currentDragState.offsetY,
    })
  }

  const onPointerUp = (event: PointerEvent) => {
    if (dragState.value?.pointerId === event.pointerId) {
      stopDrag()
    }
  }

  const startDrag = (event: PointerEvent) => {
    const rect = getToolboxRect()

    if (!rect || event.button !== 0 || isInteractiveElement(event.target)) {
      return
    }

    dragState.value = {
      pointerId: event.pointerId,
      offsetX: event.clientX - rect.left,
      offsetY: event.clientY - rect.top,
    }
  }

  watch([options.collapsed, options.currentPlacement], () => {
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
    toolboxStyle,
  }
}
