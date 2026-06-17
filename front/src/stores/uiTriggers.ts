/**
 * Global UI-trigger bus.
 *
 * Used to decouple action sources (QuickActionsCluster, CommandPalette)
 * from page-level drawers/dialogs that can only be controlled from within
 * their own page component.
 *
 * Pattern:
 *   - Caller sets `pendingDrawer` to a key, e.g. 'deal_create'.
 *   - The owning page watches `pendingDrawer`, opens its drawer,
 *     then calls `clearDrawer()` to reset the trigger.
 *
 * Pinia client-state only — NOT persisted.
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'

export type DrawerTrigger = 'deal_create' | 'contact_create' | null

export const useUiTriggersStore = defineStore('uiTriggers', () => {
  const pendingDrawer = ref<DrawerTrigger>(null)

  function triggerDrawer(key: DrawerTrigger): void {
    pendingDrawer.value = key
  }

  function clearDrawer(): void {
    pendingDrawer.value = null
  }

  return { pendingDrawer, triggerDrawer, clearDrawer }
})
