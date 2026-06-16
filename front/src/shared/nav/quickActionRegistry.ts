/**
 * Unified quick-action registry.
 * Keys are the strings stored in user.nav_quick_actions (max 5).
 * Each entry defines: label i18n key, icon, and a handler factory
 * (receives { router, layoutStore, themeStore } on call).
 *
 * This is the single source of truth shared between:
 *   - CommandPalette  (action catalogue)
 *   - QuickActionsCluster (Orbita + sidebar footer)
 *   - ProfilePage / quick-actions picker
 */

export interface QuickActionDef {
  /** Unique stable key stored in user profile */
  key: string
  /** i18n key for label */
  labelKey: string
  /** PrimeIcons class */
  icon: string
  /** Route to push, or null if the action has no single route (e.g. theme toggle) */
  route?: string
}

/**
 * The full catalogue of available quick actions.
 * Order here is used as display order in the picker's "available" list.
 */
export const QUICK_ACTION_CATALOGUE: QuickActionDef[] = [
  // ─── Creation actions ────────────────────────────────────────────────────────
  {
    key: 'create_deal',
    labelKey: 'sales.deal.actions.create',
    icon: 'pi pi-briefcase',
    route: '/deals',
  },
  {
    key: 'create_contact',
    labelKey: 'contacts.actions.create',
    icon: 'pi pi-user-plus',
    route: '/contacts',
  },
  // ─── Navigation shortcuts ─────────────────────────────────────────────────────
  {
    key: 'go_dashboard',
    labelKey: 'nav.dashboard',
    icon: 'pi pi-home',
    route: '/dashboard',
  },
  {
    key: 'go_deals',
    labelKey: 'nav.deals',
    icon: 'pi pi-briefcase',
    route: '/deals',
  },
  {
    key: 'go_contacts',
    labelKey: 'nav.contacts',
    icon: 'pi pi-users',
    route: '/contacts',
  },
  {
    key: 'go_companies',
    labelKey: 'nav.companies',
    icon: 'pi pi-building',
    route: '/companies',
  },
  {
    key: 'go_tasks',
    labelKey: 'nav.myTasks',
    icon: 'pi pi-check-square',
    route: '/my-tasks',
  },
  {
    key: 'go_documents',
    labelKey: 'nav.documents',
    icon: 'pi pi-file-edit',
    route: '/documents',
  },
  {
    key: 'go_manager_cabinet',
    labelKey: 'nav.managerCabinet',
    icon: 'pi pi-id-card',
    route: '/manager-cabinet',
  },
  // ─── Toggle actions ───────────────────────────────────────────────────────────
  {
    key: 'toggle_theme',
    labelKey: 'quickActions.toggleTheme',
    icon: 'pi pi-moon',
  },
  {
    key: 'open_search',
    labelKey: 'quickActions.openSearch',
    icon: 'pi pi-search',
  },
]

/** Look up a definition by key */
export function getQuickActionDef(key: string): QuickActionDef | undefined {
  return QUICK_ACTION_CATALOGUE.find((a) => a.key === key)
}

/** Resolve an ordered list of definitions from stored keys (skips unknown keys) */
export function resolveQuickActions(keys: string[]): QuickActionDef[] {
  return keys.flatMap((k) => {
    const def = getQuickActionDef(k)
    return def ? [def] : []
  })
}
