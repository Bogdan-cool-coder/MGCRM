import 'vue-router'
import type { UserRole } from '@/entities/user'

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    requiresCompanyScope?: boolean
    roles?: UserRole[]
    /**
     * Gate the route behind a build-time feature flag. When the flag is OFF the
     * navigation guard (`resolveNavigation`) redirects to the user's default
     * route, so a direct URL / bookmark cannot reach the page. Currently only
     * `'documents'` (driven by `canUseDocuments`).
     */
    requiresFeature?: 'documents'
  }
}
