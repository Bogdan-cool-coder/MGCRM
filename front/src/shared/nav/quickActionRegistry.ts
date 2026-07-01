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

/** How this action is executed */
export type QuickActionType =
  /** Navigate to a route */
  | 'route'
  /**
   * Open a page-level drawer via uiTriggersStore.
   * @deprecated Wave 4: creation flows now use full-card routes.
   * Kept for backwards-compat with any legacy catalogue entries that
   * haven't been migrated yet; QuickActionsCluster maps drawerKey to
   * the corresponding full-card route (DRAWER_KEY_ROUTES).
   */
  | 'drawer'
  /** Handled inline by the execute() switch (theme toggle, palette open, etc.) */
  | 'inline'

export interface QuickActionDef {
  /** Unique stable key stored in user profile */
  key: string
  /** i18n key for label */
  labelKey: string
  /** PrimeIcons class */
  icon: string
  /** Execution mode (defaults to 'route' when route is set, 'inline' otherwise) */
  actionType: QuickActionType
  /** Route to push (only for actionType 'route') */
  route?: string
  /**
   * Drawer trigger key — legacy field; QuickActionsCluster now maps this to
   * a full-card route internally (DRAWER_KEY_ROUTES). See Wave 4.
   */
  drawerKey?: string
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
    actionType: 'route',
    route: '/deals/new',
  },
  {
    key: 'create_contact',
    labelKey: 'contacts.actions.create',
    icon: 'pi pi-user-plus',
    actionType: 'route',
    route: '/contacts/new',
  },
  // ─── Navigation shortcuts ─────────────────────────────────────────────────────
  {
    key: 'go_dashboard',
    labelKey: 'nav.dashboard',
    icon: 'pi pi-home',
    actionType: 'route',
    route: '/dashboard',
  },
  {
    key: 'go_deals',
    labelKey: 'nav.deals',
    icon: 'pi pi-briefcase',
    actionType: 'route',
    route: '/deals',
  },
  {
    key: 'go_contacts',
    labelKey: 'nav.contacts',
    icon: 'pi pi-users',
    actionType: 'route',
    route: '/contacts',
  },
  {
    key: 'go_companies',
    labelKey: 'nav.companies',
    icon: 'pi pi-building',
    actionType: 'route',
    route: '/companies',
  },
  {
    key: 'go_tasks',
    labelKey: 'nav.tasks',
    icon: 'pi pi-check-square',
    actionType: 'route',
    route: '/my-tasks',
  },
  {
    key: 'go_documents',
    labelKey: 'nav.documents',
    icon: 'pi pi-file-edit',
    actionType: 'route',
    route: '/documents',
  },
  {
    key: 'go_manager_cabinet',
    labelKey: 'nav.managerCabinet',
    icon: 'pi pi-id-card',
    actionType: 'route',
    route: '/manager-cabinet',
  },
  // ─── Toggle actions ───────────────────────────────────────────────────────────
  {
    key: 'toggle_theme',
    labelKey: 'quickActions.toggleTheme',
    icon: 'pi pi-moon',
    actionType: 'inline',
  },
  {
    key: 'open_search',
    labelKey: 'quickActions.openSearch',
    icon: 'pi pi-search',
    actionType: 'inline',
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
