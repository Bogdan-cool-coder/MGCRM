import type { RouteRecordRaw } from 'vue-router'
import type { UserRole } from '@/entities/user'

export const routes: RouteRecordRaw[] = [
  // The root path is resolved dynamically by `resolveNavigation` (policy.ts):
  // an authenticated user is redirected to their personal `homePath`
  // (default `/reports`). We must NOT use a static `redirect` here — that is
  // applied during route resolution *before* `beforeEach` runs, so the guard
  // would never see `/` and could not consult the user's home page. The
  // `component` below is a never-rendered fallback (the guard always
  // redirects away for both authenticated and unauthenticated visitors).
  {
    path: '/',
    name: 'Root',
    component: () => import('@/pages/ReportsPage'),
    meta: { requiresAuth: true },
  },
  { path: '/login', name: 'Login', component: () => import('@/pages/LoginPage') },
  {
    path: '/reports',
    name: 'Reports',
    component: () => import('@/pages/ReportsPage'),
    meta: {
      requiresAuth: true,
      requiresCompanyScope: true,
    },
  },
  {
    path: '/reports/:id',
    name: 'ReportDetail',
    component: () => import('@/pages/ReportPage'),
    meta: {
      requiresAuth: true,
      requiresCompanyScope: true,
    },
  },
  {
    path: '/dashboards',
    name: 'Dashboards',
    component: () => import('@/pages/DashboardsPage'),
    meta: {
      requiresAuth: true,
      requiresCompanyScope: true,
    },
  },
  {
    path: '/dashboards/:id',
    name: 'DashboardDetail',
    component: () => import('@/pages/DashboardPage'),
    meta: {
      requiresAuth: true,
      requiresCompanyScope: true,
    },
  },
  {
    path: '/documents',
    name: 'Documents',
    component: () => import('@/pages/DocumentsPage'),
    meta: {
      requiresAuth: true,
      requiresCompanyScope: true,
      requiresFeature: 'documents',
    },
  },
  {
    path: '/documents/:id',
    name: 'Document',
    component: () => import('@/pages/DocumentPage'),
    meta: {
      requiresAuth: true,
      requiresCompanyScope: true,
      requiresFeature: 'documents',
    },
  },
  {
    path: '/company',
    name: 'Company',
    component: () => import('@/pages/CompanyPage'),
    meta: {
      requiresAuth: true,
      requiresCompanyScope: true,
      roles: ['superadmin', 'admin'] as UserRole[],
    },
  },
  {
    path: '/ai-chat',
    name: 'AiChat',
    component: () => import('@/pages/AiChatPage'),
    meta: {
      requiresAuth: true,
      requiresCompanyScope: true,
      roles: ['superadmin', 'admin', 'analyst'] as UserRole[],
    },
  },
  { path: '/:pathMatch(.*)*', redirect: '/reports' },
]
