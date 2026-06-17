/**
 * Unified navigation source.
 * Both sidebar and Orbita pull items from here.
 *
 * Prototype set (2026-06-16): only Sales module items.
 * Full list is commented below — uncomment when modules are ready.
 */

export interface NavItemBadge {
  /**
   * Dot notation path to a reactive store getter.
   * Sidebar / Orbita read this and display a badge (expanded)
   * or a dot indicator (collapsed).
   */
  source:
    | 'activityStore.myOpenCount'
    | 'approvalsStore.pendingCount'
    | 'onboardingStore.overdueCount'
  variant: 'warning' | 'danger'
}

export interface NavItem {
  /** Unique slug used as key / aria labels */
  key: string
  /** Router path */
  route: string
  /** PrimeIcons class, e.g. 'pi pi-home' */
  icon: string
  /** i18n key, e.g. 'nav.dashboard' */
  labelKey: string
  badge?: NavItemBadge
  /** If set — item is visible only for these roles */
  roles?: string[]
  /** Shorthand: visible only for ['admin', 'director'] */
  adminOnly?: boolean
}

// ─── Prototype set: Sales module (5 items) ───────────────────────────────────
export const prototypeNavItems: NavItem[] = [
  {
    key: 'dashboard',
    route: '/dashboard',
    icon: 'pi pi-home',
    labelKey: 'nav.dashboard',
  },
  {
    key: 'contacts',
    route: '/contacts',
    icon: 'pi pi-users',
    labelKey: 'nav.contacts',
  },
  {
    key: 'deals',
    route: '/deals',
    icon: 'pi pi-briefcase',
    labelKey: 'nav.deals',
  },
  {
    key: 'my-tasks',
    route: '/my-tasks',
    icon: 'pi pi-check-square',
    labelKey: 'nav.myTasks',
    badge: { source: 'activityStore.myOpenCount', variant: 'warning' },
  },
  {
    key: 'manager-cabinet',
    route: '/manager-cabinet',
    icon: 'pi pi-id-card',
    labelKey: 'nav.managerCabinet',
  },
]

// ─── Full set (for future use after module cleanup) ───────────────────────────
export const allNavItems: NavItem[] = [
  {
    key: 'dashboard',
    route: '/dashboard',
    icon: 'pi pi-home',
    labelKey: 'nav.dashboard',
  },
  {
    key: 'contacts',
    route: '/contacts',
    icon: 'pi pi-users',
    labelKey: 'nav.contacts',
  },
  {
    key: 'companies',
    route: '/companies',
    icon: 'pi pi-building',
    labelKey: 'nav.companies',
  },
  {
    key: 'deals',
    route: '/deals',
    icon: 'pi pi-briefcase',
    labelKey: 'nav.deals',
  },
  {
    key: 'my-tasks',
    route: '/my-tasks',
    icon: 'pi pi-check-square',
    labelKey: 'nav.myTasks',
    badge: { source: 'activityStore.myOpenCount', variant: 'warning' },
  },
  {
    key: 'manager-cabinet',
    route: '/manager-cabinet',
    icon: 'pi pi-id-card',
    labelKey: 'nav.managerCabinet',
  },
  {
    key: 'products',
    route: '/admin/products',
    icon: 'pi pi-box',
    labelKey: 'nav.catalog',
  },
  {
    key: 'documents',
    route: '/documents',
    icon: 'pi pi-file-edit',
    labelKey: 'nav.documents',
  },
  {
    key: 'my-approvals',
    route: '/my-approvals',
    icon: 'pi pi-check-square',
    labelKey: 'nav.myApprovals',
    badge: { source: 'approvalsStore.pendingCount', variant: 'warning' },
  },
  {
    key: 'my-courses',
    route: '/onboarding/my-courses',
    icon: 'pi pi-book',
    labelKey: 'nav.myCourses',
    badge: { source: 'onboardingStore.overdueCount', variant: 'danger' },
  },
  {
    key: 'my-certificates',
    route: '/onboarding/my-certificates',
    icon: 'pi pi-award',
    labelKey: 'nav.myCertificates',
  },
]

export const adminNavItems: NavItem[] = [
  {
    key: 'pipeline-settings',
    route: '/settings/pipeline',
    icon: 'pi pi-sliders-h',
    labelKey: 'nav.pipelineSettings',
    adminOnly: true,
  },
  {
    key: 'templates',
    route: '/admin/templates',
    icon: 'pi pi-file-edit',
    labelKey: 'nav.templates',
    adminOnly: true,
  },
  {
    key: 'template-variables',
    route: '/admin/template-variables',
    icon: 'pi pi-list',
    labelKey: 'nav.templateVariables',
    adminOnly: true,
  },
  {
    key: 'approval-routes',
    route: '/admin/approval-routes',
    icon: 'pi pi-sitemap',
    labelKey: 'nav.approvalRoutes',
    adminOnly: true,
  },
  {
    key: 'message-templates',
    route: '/admin/message-templates',
    icon: 'pi pi-envelope',
    labelKey: 'nav.messageTemplates',
    adminOnly: true,
  },
  {
    key: 'onboarding-courses',
    route: '/admin/onboarding/courses',
    icon: 'pi pi-graduation-cap',
    labelKey: 'nav.onboardingAdmin',
    adminOnly: true,
  },
  {
    key: 'onboarding-assignments',
    route: '/admin/onboarding/assignments',
    icon: 'pi pi-users',
    labelKey: 'nav.onboardingAssignments',
    adminOnly: true,
  },
  {
    key: 'hr-progress',
    route: '/admin/onboarding/progress',
    icon: 'pi pi-chart-bar',
    labelKey: 'nav.hrProgress',
    adminOnly: true,
  },
  {
    key: 'automation-runs',
    route: '/admin/automation-runs',
    icon: 'pi pi-clock',
    labelKey: 'nav.automationRuns',
    adminOnly: true,
  },
]

/**
 * Filter nav items by user role.
 * @param items  Array of NavItem
 * @param role   User role string (from userStore.getUserRole)
 */
export function filterNavByRole(items: NavItem[], role: string | null): NavItem[] {
  const isAdmin = role === 'admin' || role === 'director'
  return items.filter((item) => {
    if (item.adminOnly) return isAdmin
    if (item.roles && item.roles.length > 0) {
      return role !== null && item.roles.includes(role)
    }
    return true
  })
}
