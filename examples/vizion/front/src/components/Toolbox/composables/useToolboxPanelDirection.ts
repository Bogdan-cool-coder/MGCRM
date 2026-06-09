import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { ComputedRef, Ref } from 'vue'
import {
  getDefaultToolboxPanelDirection,
  resolveToolboxPanelDirection,
} from '../positioning'
import type { ToolboxPanelDirection, ToolboxPlacement, ToolboxPosition } from '../types'

interface UseToolboxPanelDirectionOptions {
  collapsed: ComputedRef<boolean>
  currentPlacement: ComputedRef<ToolboxPlacement>
  currentPosition: ComputedRef<ToolboxPosition | null>
  panelRef: Ref<HTMLElement | null>
  toolboxRef: Ref<HTMLElement | null>
}

export function useToolboxPanelDirection(options: UseToolboxPanelDirectionOptions) {
  const panelDirection = ref<ToolboxPanelDirection>(
    getDefaultToolboxPanelDirection(options.currentPlacement.value),
  )

  let syncFrameId: number | null = null

  const getToolboxRect = () => {
    const element = options.toolboxRef.value

    if (!element || typeof window === 'undefined') {
      return null
    }

    return element.getBoundingClientRect()
  }

  const updatePanelDirection = () => {
    const toolboxRect = getToolboxRect()
    const panel = options.panelRef.value

    if (!toolboxRect || !panel || typeof window === 'undefined') {
      panelDirection.value = getDefaultToolboxPanelDirection(options.currentPlacement.value)
      return
    }

    panelDirection.value = resolveToolboxPanelDirection({
      placement: options.currentPlacement.value,
      currentDirection: panelDirection.value,
      toolboxRect,
      panelWidth: panel.offsetWidth,
      panelHeight: panel.offsetHeight,
      viewportWidth: window.innerWidth,
      viewportHeight: window.innerHeight,
    })
  }

  const scheduleDirectionSync = () => {
    void nextTick().then(() => {
      if (syncFrameId !== null) {
        cancelAnimationFrame(syncFrameId)
      }

      syncFrameId = requestAnimationFrame(() => {
        syncFrameId = null
        updatePanelDirection()
      })
    })
  }

  watch([options.collapsed, options.currentPlacement], () => {
    panelDirection.value = getDefaultToolboxPanelDirection(options.currentPlacement.value)
    scheduleDirectionSync()
  })

  watch(
    options.currentPosition,
    () => {
      scheduleDirectionSync()
    },
    { deep: true },
  )

  onMounted(() => {
    window.addEventListener('resize', scheduleDirectionSync)
    scheduleDirectionSync()
  })

  onBeforeUnmount(() => {
    window.removeEventListener('resize', scheduleDirectionSync)

    if (syncFrameId !== null) {
      cancelAnimationFrame(syncFrameId)
    }
  })

  return {
    panelDirection,
    scheduleDirectionSync,
  }
}
