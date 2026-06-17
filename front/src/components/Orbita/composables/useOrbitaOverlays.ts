/**
 * useOrbitaOverlays — adapted 1-in-1 from Vizion useToolboxOverlays.
 * Manages mutual exclusion between overlay sub-components (profile, notifications).
 */
import { ref, watch, type Ref } from 'vue'
import type { RouteLocationNormalizedLoaded } from 'vue-router'
import type { OrbitaOverlayControl } from '../types'

export type OrbitaOverlayName = 'profile' | 'notifications'

interface UseOrbitaOverlaysOptions {
  route: RouteLocationNormalizedLoaded
  controls: Record<OrbitaOverlayName, Ref<OrbitaOverlayControl | null>>
  closeWhen?: Ref<boolean>
}

export function useOrbitaOverlays(options: UseOrbitaOverlaysOptions) {
  const openOverlay = ref<OrbitaOverlayName | null>(null)
  const overlayTriggerEvent = ref<Partial<Record<OrbitaOverlayName, MouseEvent>>>({})
  const overlayNames = Object.keys(options.controls) as OrbitaOverlayName[]

  const syncOverlayState = () => {
    overlayNames.forEach((name) => {
      options.controls[name].value?.syncPopover(
        openOverlay.value === name,
        overlayTriggerEvent.value[name] ?? null,
      )
    })
  }

  const handleOverlayToggle = (name: OrbitaOverlayName, event: MouseEvent) => {
    overlayTriggerEvent.value[name] = event
    openOverlay.value = openOverlay.value === name ? null : name
  }

  const handleOverlayVisibility = (name: OrbitaOverlayName, visible: boolean) => {
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
      if (shouldClose) closeAllOverlays()
    })
  }

  return {
    handleOverlayToggle,
    handleOverlayVisibility,
    closeAllOverlays,
  }
}
