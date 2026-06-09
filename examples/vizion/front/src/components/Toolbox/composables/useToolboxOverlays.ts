import { ref, watch, type Ref } from 'vue'
import type { RouteLocationNormalizedLoaded } from 'vue-router'
import type { ToolboxOverlayControl, ToolboxOverlayName } from '../types'

interface UseToolboxOverlaysOptions {
  route: RouteLocationNormalizedLoaded
  controls: Record<ToolboxOverlayName, Ref<ToolboxOverlayControl | null>>
  closeWhen?: Ref<boolean>
}

export function useToolboxOverlays(options: UseToolboxOverlaysOptions) {
  const openOverlay = ref<ToolboxOverlayName | null>(null)
  const overlayTriggerEvent = ref<Partial<Record<ToolboxOverlayName, MouseEvent>>>({})
  const overlayNames = Object.keys(options.controls) as ToolboxOverlayName[]

  const syncOverlayState = () => {
    overlayNames.forEach((name) => {
      options.controls[name].value?.syncPopover(
        openOverlay.value === name,
        overlayTriggerEvent.value[name] ?? null,
      )
    })
  }

  const handleOverlayToggle = (name: ToolboxOverlayName, event: MouseEvent) => {
    overlayTriggerEvent.value[name] = event
    openOverlay.value = openOverlay.value === name ? null : name
  }

  const handleOverlayVisibility = (name: ToolboxOverlayName, visible: boolean) => {
    if (!visible && openOverlay.value === name) {
      openOverlay.value = null
    }
  }

  const closeAllOverlays = () => {
    openOverlay.value = null
  }

  watch(openOverlay, () => {
    syncOverlayState()
  })

  overlayNames.forEach((name) => {
    watch(options.controls[name], () => {
      syncOverlayState()
    })
  })

  watch(
    () => options.route.fullPath,
    () => {
      closeAllOverlays()
    },
  )

  if (options.closeWhen) {
    watch(options.closeWhen, (shouldClose) => {
      if (shouldClose) {
        closeAllOverlays()
      }
    })
  }

  return {
    handleOverlayToggle,
    handleOverlayVisibility,
    closeAllOverlays,
  }
}
