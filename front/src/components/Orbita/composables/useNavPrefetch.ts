/**
 * useNavPrefetch — prefetch route chunks on nav item hover/focus.
 *
 * Strategy: dynamically import the route's lazy component to warm
 * the JS chunk. Conservative: errors are silently swallowed, dedup
 * via a Set of already-prefetched routes.
 *
 * Uses router.resolve() to find the matched route record, then calls
 * the lazy component factory if available.
 */
import { useRouter } from 'vue-router'

// Module-level dedup: routes already prefetched this session
const prefetchedRoutes = new Set<string>()

export function useNavPrefetch() {
  const router = useRouter()

  function prefetch(routePath: string): void {
    if (prefetchedRoutes.has(routePath)) return

    try {
      const resolved = router.resolve(routePath)
      if (!resolved.matched.length) return

      for (const record of resolved.matched) {
        const componentFactory = record.components?.default
        if (typeof componentFactory === 'function') {
          // Lazy component factory — call it to trigger chunk fetch
          void (componentFactory as () => Promise<unknown>)().catch(() => {
            // Silently ignore prefetch errors (network offline, chunk not found)
          })
        }
      }

      prefetchedRoutes.add(routePath)
    } catch {
      // Silently ignore — prefetch is best-effort
    }
  }

  return { prefetch }
}
