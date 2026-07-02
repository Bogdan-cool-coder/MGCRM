/**
 * Echo singleton — Laravel Reverb via the Pusher protocol.
 *
 * Lifecycle:
 *   • initEcho(token)  — called once after successful login / on bootstrap when
 *                         a persisted token is available.
 *   • destroyEcho()    — called on logout to close the WS connection.
 *
 * Graceful-degradation strategy:
 *   • All VITE_REVERB_* vars must be present; when any is absent the module
 *     sets `reverbEnabled = false` and all exported helpers are no-ops.
 *   • The Pusher `enableStats: false` flag and a custom `errorHandler` suppress
 *     console noise when the server is unreachable.
 *   • Components consume `useEchoChannel` / `usePrivateChannel`; if `reverbEnabled`
 *     is false they get a no-op cleanup and no subscription is attempted.
 *   • The app never crashes — event callbacks simply never fire.
 */

import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import type { Channel } from 'pusher-js'

// Make Pusher available globally — Laravel Echo uses `window.Pusher` internally.
if (typeof window !== 'undefined') {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  ;(window as any).Pusher = Pusher
}

// ─── Feature flag (env guard) ─────────────────────────────────────────────────

const REVERB_KEY = import.meta.env.VITE_REVERB_APP_KEY as string | undefined
const REVERB_HOST = import.meta.env.VITE_REVERB_HOST as string | undefined
const REVERB_PORT = import.meta.env.VITE_REVERB_PORT as string | undefined

export const reverbEnabled: boolean = Boolean(REVERB_KEY && REVERB_HOST)

// ─── Singleton state ──────────────────────────────────────────────────────────

let echoInstance: Echo<'reverb'> | null = null

// ─── Init / destroy ───────────────────────────────────────────────────────────

/**
 * Initialise (or reinitialise) the Echo singleton with the given Sanctum
 * Bearer token. Safe to call multiple times — tears down the old instance
 * first if one exists.
 *
 * Must be called AFTER the user is authenticated (token available).
 */
export function initEcho(token: string): void {
  if (!reverbEnabled) return

  destroyEcho()

  // Silence Pusher's own debug logs; errors are handled by `errorHandler`.
  Pusher.logToConsole = false

  const wsPort = REVERB_PORT ? Number(REVERB_PORT) : 443
  const forceTLS = wsPort === 443

  echoInstance = new Echo({
    broadcaster: 'reverb',
    key: REVERB_KEY!,
    wsHost: REVERB_HOST!,
    wsPort,
    wssPort: wsPort,
    forceTLS,
    enabledTransports: ['ws', 'wss'],
    // Disable Pusher stats pings — reduces noise on non-prod setups.
    enableStats: false,
    authEndpoint: '/broadcasting/auth',
    auth: {
      headers: {
        Authorization: `Bearer ${token}`,
      },
    },
  })

  // Suppress Pusher connection errors from flooding the console.
  // The app degrades gracefully — channels simply never receive events.
  if (echoInstance.connector?.pusher) {
    echoInstance.connector.pusher.connection.bind('error', (err: unknown) => {
      if (import.meta.env.DEV) {
        console.warn('[Echo] Connection error (Reverb unreachable — app continues without live updates):', err)
      }
    })
  }
}

/**
 * Disconnect and destroy the Echo singleton. Called on logout.
 */
export function destroyEcho(): void {
  if (echoInstance) {
    try {
      echoInstance.disconnect()
    } catch {
      // ignore — socket may already be closed
    }
    echoInstance = null
  }
}

// ─── Channel subscription helpers ────────────────────────────────────────────

/**
 * Subscribe to a private channel and listen to the given events.
 * Returns an unsubscribe function — call it in `onUnmounted`.
 *
 * When Reverb is disabled or the instance is not initialised, returns a no-op.
 *
 * @param channelName  Channel WITHOUT the "private-" prefix (Echo adds it).
 * @param events       Map of event name → handler.
 */
export function subscribePrivate(
  channelName: string,
  events: Record<string, (payload: unknown) => void>,
): () => void {
  if (!reverbEnabled || !echoInstance) return () => {}

  let channel: ReturnType<typeof echoInstance.private> | null = null

  try {
    channel = echoInstance.private(channelName)
    for (const [event, handler] of Object.entries(events)) {
      channel.listen(`.${event}`, handler)
    }
  } catch (err) {
    if (import.meta.env.DEV) {
      console.warn(`[Echo] Failed to subscribe to private(${channelName}):`, err)
    }
    return () => {}
  }

  const capturedChannel = channel

  return () => {
    try {
      if (echoInstance) {
        echoInstance.leave(channelName)
      } else if (capturedChannel) {
        // Fallback: leaveChannel via Pusher directly
        const pusherChannel = capturedChannel as unknown as { unsubscribe?: () => void }
        pusherChannel.unsubscribe?.()
      }
    } catch {
      // ignore on cleanup
    }
  }
}

/**
 * Get the current Echo instance (or null when not initialised).
 * Use this only when you need direct Echo access beyond what `subscribePrivate` offers.
 */
export function getEcho(): Echo<'reverb'> | null {
  return echoInstance
}

// ─── Types re-exported for consumers ─────────────────────────────────────────

export type { Channel }
