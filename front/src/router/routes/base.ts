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

  // Catchall — редирект на дашборд
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
]
