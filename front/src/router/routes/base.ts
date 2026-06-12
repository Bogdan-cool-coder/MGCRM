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

  // Catchall — редирект на дашборд
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
]
