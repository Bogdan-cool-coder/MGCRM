import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

/**
 * Reads the cross-tab `?activate=N` handoff produced by the mini-chat
 * widget's expand button (`window.open('/ai-chat?activate=N')`). Returns a
 * ref consumed exactly once by `useChatPage.initScope`.
 *
 * Why not history.state: `vue-router`'s own navigation lifecycle (including
 * the `router.replace` we run below to strip the query) calls
 * `window.history.replaceState`, which silently clobbers any custom state
 * written synchronously during setup. A module-local ref bypasses the
 * history layer entirely, so the value survives until `initScope` runs
 * from `onMounted`.
 *
 * The query param is stripped after read so a full page reload doesn't
 * replay the activation.
 *
 * Currently used by `useAiChatPage` (quick_qa scope); mini-chat always expands
 * to `/ai-chat`. `useChatPage.initScope` still guards activation against
 * scope-type mismatch, so this stays scope-agnostic.
 */
export const useActivateChatIdQueryParam = () => {
  const route = useRoute()
  const router = useRouter()
  const pendingActivateChatId = ref<number | null>(null)

  const raw = route.query.activate
  const candidate = Array.isArray(raw) ? raw[0] : raw
  if (typeof candidate === 'string') {
    const id = Number(candidate)
    if (Number.isFinite(id) && id > 0) {
      pendingActivateChatId.value = id

      // Strip the query — fire-and-forget. Even if vue-router clobbers
      // history.state during this replace, our ref isn't touched.
      const { activate: _drop, ...restQuery } = route.query
      void _drop
      void router.replace({ query: restQuery })
    }
  }

  return pendingActivateChatId
}
