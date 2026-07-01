/**
 * Global UI-trigger bus.
 *
 * Used to decouple action sources (QuickActionsCluster, CommandPalette)
 * from page-level drawers/dialogs that can only be controlled from within
 * their own page component.
 *
 * Wave 4 note: creation flows (deal_create, contact_create) now navigate
 * to full-card routes directly — they no longer go through this bus.
 * The bus remains available for future drawer patterns.
 *
 * Pinia client-state only — NOT persisted.
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'

/** Reserved slot for future page-level drawer triggers */
export type DrawerTrigger = null

export const useUiTriggersStore = defineStore('uiTriggers', () => {
  const pendingDrawer = ref<DrawerTrigger>(null)

  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  function triggerDrawer(_key: string): void {
    // No-op — kept for API compatibility while existing drawer references are cleaned up
  }

  function clearDrawer(): void {
    pendingDrawer.value = null
  }

  return { pendingDrawer, triggerDrawer, clearDrawer }
})
