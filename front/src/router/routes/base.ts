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
  {
    path: '/profile',
    name: 'Profile',
    component: () => import('@/pages/ProfilePage'),
    meta: { requiresAuth: true, title: 'nav.profile' },
  },
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
  {
    path: '/admin/products',
    name: 'Products',
    component: () => import('@/pages/ProductsPage'),
    meta: { requiresAuth: true, title: 'catalog.products.page.title' },
  },
  {
    path: '/admin/products/:id',
    name: 'ProductDetail',
    component: () => import('@/pages/ProductPage'),
    meta: { requiresAuth: true },
  },
  {
    path: '/admin/exchange-rates',
    name: 'ExchangeRates',
    component: () => import('@/pages/ExchangeRatesPage'),
    meta: { requiresAuth: true, title: 'catalog.exchangeRates.page.title' },
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
    meta: { requiresAuth: true, title: 'nav.managerCabinet' },
  },

  // ─── Settings ────────────────────────────────────────────────────────────────
  // /settings → hub (ProfilePage with no ?tab)
  {
    path: '/settings',
    redirect: '/profile',
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
    meta: { requiresAuth: true, roles: ['admin', 'lawyer'], title: 'nav.messageTemplates' },
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

  // ─── Directories: Admin ──────────────────────────────────────────────────
  {
    path: '/admin/acquisition-channels',
    name: 'AcquisitionChannels',
    component: () => import('@/pages/AcquisitionChannelsPage'),
    meta: {
      requiresAuth: true,
      roles: ['admin', 'director'],
      title: 'admin.acquisitionChannels.title',
    },
  },
  {
    path: '/admin/disconnect-reasons',
    name: 'DisconnectReasons',
    component: () => import('@/pages/DisconnectReasonsPage'),
    meta: {
      requiresAuth: true,
      roles: ['admin', 'director'],
      title: 'admin.disconnectReasons.title',
    },
  },

  // Catchall — редирект на дашборд
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
]
