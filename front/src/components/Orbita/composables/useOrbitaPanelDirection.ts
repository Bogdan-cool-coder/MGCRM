/**
 * useOrbitaPanelDirection — adapted 1-in-1 from Vizion useToolboxPanelDirection.
 * Resolves the direction in which OrbitaPanel expands relative to the toggle.
 */
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { ComputedRef, Ref } from 'vue'
import {
  getDefaultOrbitaPanelDirection,
  resolveOrbitaPanelDirection,
} from '../positioning'
import type { OrbitaOrientation, OrbitaPanelDirection, OrbitaPosition } from '../types'

interface UseOrbitaPanelDirectionOptions {
  collapsed: ComputedRef<boolean>
  currentOrientation: ComputedRef<OrbitaOrientation>
  currentPosition: ComputedRef<OrbitaPosition | null>
  panelRef: Ref<HTMLElement | null>
  orbitaRef: Ref<HTMLElement | null>
}

export function useOrbitaPanelDirection(options: UseOrbitaPanelDirectionOptions) {
  const panelDirection = ref<OrbitaPanelDirection>(
    getDefaultOrbitaPanelDirection(options.currentOrientation.value),
  )

  let syncFrameId: number | null = null

  const getOrbitaRect = () => {
    const element = options.orbitaRef.value
    if (!element || typeof window === 'undefined') return null
    return element.getBoundingClientRect()
  }

  const updatePanelDirection = () => {
    const orbitaRect = getOrbitaRect()
    const panel = options.panelRef.value

    if (!orbitaRect || !panel || typeof window === 'undefined') {
      panelDirection.value = getDefaultOrbitaPanelDirection(options.currentOrientation.value)
      return
    }

    panelDirection.value = resolveOrbitaPanelDirection({
      orientation: options.currentOrientation.value,
      currentDirection: panelDirection.value,
      orbitaRect,
      panelWidth: panel.offsetWidth,
      panelHeight: panel.offsetHeight,
      viewportWidth: window.innerWidth,
      viewportHeight: window.innerHeight,
    })
  }

  const scheduleDirectionSync = () => {
    void nextTick().then(() => {
      if (syncFrameId !== null) cancelAnimationFrame(syncFrameId)
      syncFrameId = requestAnimationFrame(() => {
        syncFrameId = null
        updatePanelDirection()
      })
    })
  }

  watch([options.collapsed, options.currentOrientation], () => {
    panelDirection.value = getDefaultOrbitaPanelDirection(options.currentOrientation.value)
    scheduleDirectionSync()
  })

  watch(options.currentPosition, () => { scheduleDirectionSync() }, { deep: true })

  onMounted(() => {
    window.addEventListener('resize', scheduleDirectionSync)
    scheduleDirectionSync()
  })

  onBeforeUnmount(() => {
    window.removeEventListener('resize', scheduleDirectionSync)
    if (syncFrameId !== null) cancelAnimationFrame(syncFrameId)
  })

  return {
    panelDirection,
    scheduleDirectionSync,
  }
}
