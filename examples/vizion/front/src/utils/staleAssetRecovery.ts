const STALE_ASSET_RELOAD_KEY = 'vizion:stale-asset-reload'

const isBrowser = typeof window !== 'undefined'
let isPreloadErrorListenerRegistered = false

const STALE_ASSET_PATTERNS = [
  'Failed to fetch dynamically imported module',
  'Unable to preload CSS',
  'Importing a module script failed',
  'Loading CSS chunk',
  'Loading chunk',
  'ChunkLoadError',
]

const isStaleAssetMessage = (value: unknown): boolean => {
  if (typeof value !== 'string') return false

  return STALE_ASSET_PATTERNS.some((pattern) => value.includes(pattern))
}

export const isStaleAssetError = (error: unknown): boolean => {
  if (error instanceof Error) {
    return isStaleAssetMessage(error.message)
  }

  if (typeof error === 'string') {
    return isStaleAssetMessage(error)
  }

  return false
}

export const reloadOnceForStaleAssets = (): boolean => {
  if (!isBrowser) return false

  const hasReloaded = window.sessionStorage.getItem(STALE_ASSET_RELOAD_KEY) === '1'
  if (hasReloaded) {
    window.sessionStorage.removeItem(STALE_ASSET_RELOAD_KEY)
    return false
  }

  window.sessionStorage.setItem(STALE_ASSET_RELOAD_KEY, '1')
  window.location.reload()
  return true
}

export const clearStaleAssetReloadFlag = (): void => {
  if (!isBrowser) return
  window.sessionStorage.removeItem(STALE_ASSET_RELOAD_KEY)
}

export const registerStaleAssetPreloadRecovery = (): void => {
  if (!isBrowser || isPreloadErrorListenerRegistered) return

  window.addEventListener('vite:preloadError', (event) => {
    const preloadEvent = event as Event & { payload?: unknown }

    if (isStaleAssetError(preloadEvent.payload)) {
      event.preventDefault()
      reloadOnceForStaleAssets()
    }
  })

  isPreloadErrorListenerRegistered = true
}
