import type { RouteRecordRaw } from 'vue-router'
import './types'

/**
 * Базовые маршруты MGCRM.
 *
 * Root `/` — НЕ static redirect. Должен быть component, иначе guard не
 * видит его в beforeEach и resolveNavigation не может подставить homePath.
 * Guard видит его и перенаправляет на getDefaultRoute(user.role).
 */
export const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'Root',
    // Fallback-компонент — никогда не рендерится (guard всегда редиректит)
    component: () => import('@/pages/DashboardPage'),
    meta: { requiresAuth: true },
  },
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/pages/LoginPage'),
  },
  {
    path: '/dashboard',
    name: 'Dashboard',
    component: () => import('@/pages/DashboardPage'),
    meta: { requiresAuth: true, title: 'nav.dashboard' },
  },
  // /profile is now handled by the redirect route in the Settings section below.
  // ─── CRM: Contacts / Companies ───────────────────────────────────────────
  {
    path: '/contacts',
    name: 'Contacts',
    component: () => import('@/pages/ContactsPage'),
    meta: { requiresAuth: true, title: 'nav.contacts' },
  },
  {
    path: '/contacts/:id',
    name: 'ContactDetail',
    component: () => import('@/pages/ContactPage'),
    meta: { requiresAuth: true },
  },
  {
    path: '/companies',
    name: 'Companies',
    // Shared list view — same as contacts page, type pre-set to company
    component: () => import('@/pages/ContactsPage'),
    meta: { requiresAuth: true, title: 'nav.companies' },
  },
  {
    path: '/companies/:id',
    name: 'CompanyDetail',
    component: () => import('@/pages/CompanyPage'),
    meta: { requiresAuth: true },
  },

  // ─── Catalog ──────────────────────────────────────────────────────────────────
  // Phase 2: /admin/products redirects into Settings shell; product detail stays standalone.
  {
    path: '/admin/products',
    redirect: { path: '/settings', query: { section: 'catalog' } },
  },
  {
    path: '/admin/products/:id',
    name: 'ProductDetail',
    component: () => import('@/pages/ProductPage'),
    meta: { requiresAuth: true },
  },
  // Phase 2: /admin/exchange-rates redirects into Settings shell.
  {
    path: '/admin/exchange-rates',
    redirect: { path: '/settings', query: { section: 'exchange-rates' } },
  },

  // ─── Sales: Deals ────────────────────────────────────────────────────────────
  {
    path: '/deals',
    name: 'Deals',
    component: () => import('@/pages/DealsPage'),
    meta: { requiresAuth: true, title: 'sales.deals.page.title' },
  },
  {
    path: '/deals/:id',
    name: 'DealDetail',
    component: () => import('@/pages/DealPage'),
    meta: { requiresAuth: true },
  },

  // ─── Activities: My Tasks ─────────────────────────────────────────────────
  {
    path: '/my-tasks',
    name: 'MyTasks',
    component: () => import('@/pages/MyTasksPage/index.vue'),
    meta: { requiresAuth: true, title: 'nav.tasks' },
  },

  // ─── Sales: Manager Cabinet ───────────────────────────────────────────────
  {
    path: '/manager-cabinet',
    name: 'ManagerCabinet',
    component: () => import('@/pages/ManagerCabinetPage'),
    // Sales-only cabinet: all /api/me/* endpoints 403 for non-sales roles, so
    // fail closed at the router (mirrors PipelineSettings) instead of rendering
    // a shell that fires error toasts. resolveNavigation (policy.ts) redirects
    // lawyer/accountant/cfo to their default route.
    meta: { requiresAuth: true, roles: ['admin', 'director', 'manager'], title: 'nav.managerCabinet' },
  },

  // ─── Settings (master-detail shell) ─────────────────────────────────────────
  // /settings?section=<key> is the canonical URL for all account/integration settings.
  // /profile and /profile?tab=* are redirected here (Phase 1 compatibility shim).
  {
    path: '/settings',
    name: 'Settings',
    component: () => import('@/pages/SettingsPage'),
    meta: { requiresAuth: true, title: 'nav.settings' },
  },
  // Phase 1: redirect /profile and /profile?tab=* to /settings?section=…
  // ProfilePage component stays in repo until Phase 2.
  {
    path: '/profile',
    redirect: (to) => {
      const tab = to.query['tab'] as string | undefined
      const sectionMap: Record<string, string> = {
        profile: 'profile',
        security: 'security',
        appearance: 'appearance',
        quickActions: 'appearance',
        telegram: 'channels',
        locale: 'language',
        system: 'profile',
        notifications: 'profile',
        calendar: 'profile',
        signature: 'profile',
        segments: 'profile',
      }
      const section = (tab && sectionMap[tab]) ?? 'profile'
      return { path: '/settings', query: { section } }
    },
  },
  {
    path: '/settings/pipeline',
    name: 'PipelineSettings',
    component: () => import('@/pages/PipelineSettingsPage'),
    meta: { requiresAuth: true, roles: ['admin', 'director'], title: 'nav.pipelineSettings' },
  },

  // ─── Documents: Workspace ────────────────────────────────────────────────
  {
    path: '/documents',
    name: 'Documents',
    component: () => import('@/pages/DocumentsPage'),
    meta: { requiresAuth: true, title: 'nav.documents' },
  },
  {
    path: '/documents/:id',
    name: 'DocumentDetail',
    component: () => import('@/pages/DocumentPage'),
    meta: { requiresAuth: true },
  },

  // ─── Documents: Admin ────────────────────────────────────────────────────
  {
    path: '/admin/templates',
    name: 'Templates',
    component: () => import('@/pages/TemplatesPage'),
    meta: { requiresAuth: true, roles: ['admin', 'lawyer', 'director'], title: 'nav.templates' },
  },
  {
    path: '/admin/templates/:id',
    name: 'TemplateDetail',
    component: () => import('@/pages/TemplatePage'),
    meta: { requiresAuth: true, roles: ['admin', 'lawyer', 'director'] },
  },
  {
    path: '/admin/template-variables',
    name: 'TemplateVariables',
    component: () => import('@/pages/TemplateVariablesPage'),
    meta: {
      requiresAuth: true,
      roles: ['admin', 'lawyer', 'director'],
      title: 'nav.templateVariables',
    },
  },
  {
    path: '/admin/approval-routes',
    name: 'ApprovalRoutes',
    component: () => import('@/pages/ApprovalRoutesPage'),
    meta: { requiresAuth: true, roles: ['admin', 'lawyer'], title: 'nav.approvalRoutes' },
  },
  {
    path: '/admin/message-templates',
    name: 'MessageTemplates',
    component: () => import('@/pages/MessageTemplatesPage'),
    // BE MessageTemplatePolicy.viewAny allows admin/lawyer/director/manager for reading;
    // CRUD actions are separately guarded by the policy (admin/lawyer only).
    meta: { requiresAuth: true, roles: ['admin', 'lawyer', 'director', 'manager'], title: 'nav.messageTemplates' },
  },
  {
    path: '/admin/licensor-entities',
    name: 'LicensorEntities',
    component: () => import('@/pages/LicensorEntitiesPage'),
    // BE LicensorPolicy.viewAny restricted to admin|lawyer|director.
    meta: { requiresAuth: true, roles: ['admin', 'lawyer', 'director'], title: 'nav.licensors' },
  },

  // ─── Approvals ───────────────────────────────────────────────────────────
  {
    path: '/my-approvals',
    name: 'MyApprovals',
    component: () => import('@/pages/MyApprovalsPage'),
    meta: { requiresAuth: true, title: 'nav.myApprovals' },
  },

  // ─── Onboarding: Admin / HR ──────────────────────────────────────────────
  {
    path: '/admin/onboarding/courses',
    name: 'OnboardingAdminCourses',
    component: () => import('@/pages/OnboardingAdminCoursesPage'),
    meta: { requiresAuth: true, roles: ['admin', 'director'], title: 'nav.onboardingAdmin' },
  },
  {
    path: '/admin/onboarding/courses/:id',
    name: 'CourseBuilder',
    component: () => import('@/pages/CourseBuilderPage'),
    meta: { requiresAuth: true, roles: ['admin', 'director'] },
  },
  {
    path: '/admin/onboarding/assignments',
    name: 'OnboardingAssignments',
    component: () => import('@/pages/OnboardingAssignmentsPage'),
    meta: { requiresAuth: true, roles: ['admin', 'director'], title: 'nav.onboardingAssignments' },
  },
  {
    path: '/admin/onboarding/progress',
    name: 'HrProgress',
    component: () => import('@/pages/HrProgressPage'),
    meta: { requiresAuth: true, roles: ['admin', 'director'], title: 'nav.hrProgress' },
  },

  // ─── Onboarding: Student ─────────────────────────────────────────────────
  {
    path: '/onboarding/my-courses',
    name: 'MyCourses',
    component: () => import('@/pages/MyCoursesPage'),
    meta: { requiresAuth: true, title: 'nav.myCourses' },
  },
  {
    path: '/onboarding/assignments/:id',
    name: 'CoursePlayer',
    component: () => import('@/pages/CoursePage'),
    meta: { requiresAuth: true },
  },
  {
    path: '/onboarding/my-certificates',
    name: 'MyOnboardingCertificates',
    component: () => import('@/pages/MyOnboardingCertificatesPage'),
    meta: { requiresAuth: true, title: 'nav.myCertificates' },
  },

  // ─── Automation Runs Journal ─────────────────────────────────────────────
  {
    path: '/admin/automation-runs',
    name: 'AutomationRuns',
    component: () => import('@/pages/AutomationRunsPage'),
    meta: { requiresAuth: true, roles: ['admin', 'director'], title: 'automation.runs.pageTitle' },
  },

  // ─── Users: Admin ────────────────────────────────────────────────────────
  {
    path: '/admin/users',
    name: 'AdminUsers',
    component: () => import('@/pages/UsersPage'),
    meta: {
      requiresAuth: true,
      roles: ['admin', 'director'],
      title: 'admin.users.title',
    },
  },

  // ─── Access Control: Доступ и оргструктура ───────────────────────────────
  // Settings → отделы / роли и права / видимость записей. Backend gated to
  // admin/director via can:admin-write; mirror that at the router (fail-closed).
  // The hub redirects to the Departments tab; tab subroutes all render the same
  // page (URL ↔ active-tab sync lives in the page-composable).
  {
    path: '/admin/access-control',
    name: 'AccessControl',
    redirect: '/admin/access-control/departments',
    meta: { requiresAuth: true, roles: ['admin', 'director'] },
  },
  {
    path: '/admin/access-control/departments',
    name: 'AccessControlDepartments',
    component: () => import('@/pages/AccessControlPage'),
    meta: { requiresAuth: true, roles: ['admin', 'director'], title: 'nav.accessControl' },
  },
  {
    path: '/admin/access-control/roles',
    name: 'AccessControlRoles',
    component: () => import('@/pages/AccessControlPage'),
    meta: { requiresAuth: true, roles: ['admin', 'director'], title: 'nav.accessControl' },
  },
  {
    path: '/admin/access-control/visibility',
    name: 'AccessControlVisibility',
    component: () => import('@/pages/AccessControlPage'),
    meta: { requiresAuth: true, roles: ['admin', 'director'], title: 'nav.accessControl' },
  },

  // ─── Directories: Admin ──────────────────────────────────────────────────
  // Phase 2: standalone /admin/* routes redirect into Settings shell.
  {
    path: '/admin/acquisition-channels',
    redirect: { path: '/settings', query: { section: 'acq-channels' } },
  },
  {
    path: '/admin/disconnect-reasons',
    redirect: { path: '/settings', query: { section: 'disc-reasons' } },
  },
  {
    path: '/admin/countries',
    redirect: { path: '/settings', query: { section: 'countries' } },
  },

  // ─── Inbox: Inbound message triage ───────────────────────────────────────
  // inbox.manage permission is granted to admin + director only (backend).
  // Manager gets 403 on all /api/inbox* endpoints — fail-closed at the router.
  // If inbox.manage is later extended to managers in RolePermissionSeeder, add
  // 'manager' back here in sync with that backend change.
  {
    path: '/inbox',
    name: 'Inbox',
    component: () => import('@/pages/InboxPage'),
    meta: {
      requiresAuth: true,
      roles: ['admin', 'director'],
      title: 'inbox.page.title',
    },
  },

  // ─── Public (anonymous) lead form ────────────────────────────────────────
  // Inbox S1.9: anonymous intake surface for GET/POST /api/forms/public/{slug}.
  // No requiresAuth/roles → the navigation guard passes it through for visitors
  // without a token (resolveNavigation returns true for public routes).
  {
    path: '/f/:slug',
    name: 'PublicLeadForm',
    component: () => import('@/pages/PublicLeadFormPage'),
    meta: { title: 'inbox.publicForm.title' },
  },

  // Catchall — редирект на дашборд
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
]
