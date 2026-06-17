/**
 * useNavHotkeys — global keyboard shortcuts for navigation.
 *
 * Sequences (≤1500ms timeout):
 *   g → d   Dashboard
 *   g → c   Contacts
 *   g → o   Companies
 *   g → s   Deals
 *   g → t   My Tasks
 *   g → f   Documents
 *   g → a   Approvals
 *   g → l   My Courses
 *
 * Single keys:
 *   ?       Open hotkeys cheatsheet dialog
 *
 * Muted when:
 *   - Focus is in input / textarea / [contenteditable]
 *   - Any [role=dialog][aria-modal="true"] is open
 */
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'

const SEQUENCE_TIMEOUT_MS = 1500
const INTERACTIVE_SELECTORS = ['INPUT', 'TEXTAREA', 'SELECT']

function isInteractiveFocus(): boolean {
  const el = document.activeElement
  if (!el) return false
  if (INTERACTIVE_SELECTORS.includes(el.tagName)) return true
  if ((el as HTMLElement).isContentEditable) return true
  return false
}

function isModalOpen(): boolean {
  return !!document.querySelector('[role="dialog"][aria-modal="true"]')
}

export interface NavHotkeyEntry {
  /** Display sequence, e.g. 'g → d' or '?' */
  keys: string
  /** i18n description key */
  descKey: string
  /** Route to push */
  route?: string
}

/** Full hotkeys map for cheatsheet display */
export const NAV_HOTKEY_ENTRIES: NavHotkeyEntry[] = [
  { keys: 'g → d', descKey: 'hotkeys.goToDashboard', route: '/dashboard' },
  { keys: 'g → c', descKey: 'hotkeys.goToContacts',  route: '/contacts'  },
  { keys: 'g → o', descKey: 'hotkeys.goToCompanies', route: '/companies' },
  { keys: 'g → s', descKey: 'hotkeys.goToDeals',     route: '/deals'     },
  { keys: 'g → t', descKey: 'hotkeys.goToTasks',     route: '/my-tasks'  },
  { keys: 'g → f', descKey: 'hotkeys.goToDocuments', route: '/documents' },
  { keys: 'g → a', descKey: 'hotkeys.goToApprovals', route: '/my-approvals' },
  { keys: 'g → l', descKey: 'hotkeys.goToCourses',   route: '/onboarding/my-courses' },
  { keys: '?',     descKey: 'hotkeys.showHelp'       },
  { keys: isMac() ? '⌘ K' : 'Ctrl K', descKey: 'hotkeys.openSearch' },
]

function isMac(): boolean {
  if (typeof navigator === 'undefined') return false
  return navigator.platform.includes('Mac') || navigator.userAgent.includes('Mac')
}

/** g→X mapping: second key → route */
const G_SEQUENCES: Record<string, string> = {
  d: '/dashboard',
  c: '/contacts',
  o: '/companies',
  s: '/deals',
  t: '/my-tasks',
  f: '/documents',
  a: '/my-approvals',
  l: '/onboarding/my-courses',
}

interface UseNavHotkeysOptions {
  onOpenCommandPalette: () => void
  onOpenCheatsheet: () => void
}

export function useNavHotkeys(options: UseNavHotkeysOptions) {
  const router = useRouter()

  // State for g→X sequence
  let gPending = false
  let gTimer: ReturnType<typeof setTimeout> | null = null

  function clearSequence() {
    gPending = false
    if (gTimer !== null) {
      clearTimeout(gTimer)
      gTimer = null
    }
  }

  function onKeydown(e: KeyboardEvent) {
    // Guard: muted if focus is in an input-like element
    if (isInteractiveFocus()) return

    // Guard: muted if a modal is open (except we let Esc through in modals —
    // but hotkeys below don't conflict with Esc)
    if (isModalOpen()) return

    const key = e.key
    const isMacOs = isMac()
    const cmdOrCtrl = isMacOs ? e.metaKey : e.ctrlKey

    // Ctrl/Cmd + K → command palette
    if (key === 'k' && cmdOrCtrl && !e.shiftKey && !e.altKey) {
      e.preventDefault()
      clearSequence()
      options.onOpenCommandPalette()
      return
    }

    // '?' → cheatsheet (only without modifiers)
    if (key === '?' && !cmdOrCtrl && !e.altKey) {
      e.preventDefault()
      clearSequence()
      options.onOpenCheatsheet()
      return
    }

    // g → start sequence
    if (key === 'g' && !cmdOrCtrl && !e.altKey && !e.shiftKey) {
      clearSequence()
      gPending = true
      gTimer = setTimeout(clearSequence, SEQUENCE_TIMEOUT_MS)
      return
    }

    // Second key of g→X sequence
    if (gPending) {
      const route = G_SEQUENCES[key.toLowerCase()]
      if (route) {
        e.preventDefault()
        clearSequence()
        void router.push(route)
      } else {
        clearSequence()
      }
      return
    }
  }

  onMounted(() => {
    window.addEventListener('keydown', onKeydown, { capture: true })
  })

  onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown, { capture: true })
    clearSequence()
  })
}

// ─── Cheatsheet state (hoisted so it's accessible from DefaultLayout) ─────────
export const cheatsheetOpen = ref(false)

export function openCheatsheet() {
  cheatsheetOpen.value = true
}

export function closeCheatsheet() {
  cheatsheetOpen.value = false
}
