import { ref } from 'vue'
import { defineStore } from 'pinia'

/**
 * Global interface density.
 * - `compact` — default, denser rows (matches `:root` spacing).
 * - `cozy` — roomier rows; applies `.mg-cozy` on <html> which relaxes density tokens.
 *
 * ОВ-4: this global density is intentionally separate from ContactsPage's page-local
 * density (compact/normal/comfortable). It does NOT touch that page.
 */
export type Density = 'compact' | 'cozy'

const HTML_COZY_CLASS = 'mg-cozy'

export const useDensityStore = defineStore(
  'density',
  () => {
    // ─── State ────────────────────────────────────────────────────────────
    const density = ref<Density>('compact')

    // ─── Actions ──────────────────────────────────────────────────────────
    function applyClass(value: Density): void {
      if (value === 'cozy') {
        document.documentElement.classList.add(HTML_COZY_CLASS)
      } else {
        document.documentElement.classList.remove(HTML_COZY_CLASS)
      }
    }

    function setDensity(value: Density): void {
      density.value = value
      applyClass(value)
    }

    /** Re-apply the persisted class on boot (persist restores the ref, not the DOM class). */
    function syncClass(): void {
      applyClass(density.value)
    }

    return {
      density,
      setDensity,
      syncClass,
    }
  },
  {
    persist: {
      pick: ['density'],
      key: 'mgcrm_density',
    },
  },
)
