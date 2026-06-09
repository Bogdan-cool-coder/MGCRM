import type { User, UserRole } from '@/entities/user'
import { DOCUMENTS_FEATURE_ENABLED } from '@/shared/featureFlags'

export const AI_ROLES: UserRole[] = ['superadmin', 'admin', 'analyst']
export const COMPANY_ACCESS_ROLES: UserRole[] = ['superadmin', 'admin']
type RoleLike = UserRole | string | null | undefined

export const hasRoleAccess = (userRole: UserRole, allowedRoles?: UserRole[]): boolean => {
  if (!allowedRoles?.length) {
    return true
  }

  return allowedRoles.includes(userRole)
}

export const canAccessCompanySection = (role: RoleLike): boolean => {
  return COMPANY_ACCESS_ROLES.includes(role as UserRole)
}

export const canUseAi = (role: RoleLike): boolean => {
  return AI_ROLES.includes(role as UserRole)
}

/**
 * Whether the user can open the Toolbox mini-chat overlay.
 *
 * Mirrors `canUseAi` today (viewer is excluded), but is kept as a separate
 * capability so the mini-chat surface can be tightened later (e.g. disable
 * for analysts on certain plans) without touching the full-page AI flows.
 */
export const canUseMiniChat = (role: RoleLike): boolean => {
  return AI_ROLES.includes(role as UserRole)
}

export const canManageCompanies = (role: RoleLike): boolean => {
  return role === 'superadmin'
}

/**
 * Whether the user can read and edit per-company MacroData ID mappings
 * (e.g. mapping the company-specific finance-type IDs that distinguish
 * "sale", "booking" etc. in MACRO CRM). Mirrors the backend ACL on the
 * /api/companies/{id}/macrodata-mappings endpoints (admin + superadmin).
 * Viewer and analyst do not see the section at all.
 */
export const canManageCompanyMacrodataMappings = (role: RoleLike): boolean => {
  return COMPANY_ACCESS_ROLES.includes(role as UserRole)
}

/**
 * Whether the user can customise a Dashboard layout — drag tiles, resize them,
 * toggle widget visibility, persist the layout. Viewer role sees dashboards
 * read-only (no drag handles, no widget multi-select). System dashboards are
 * read-only for everyone (clone first); that check is done at the call site
 * with `!dashboard.isSystem`.
 */
export const canManageDashboardLayout = (role: RoleLike): boolean => {
  return AI_ROLES.includes(role as UserRole)
}

/**
 * Whether the user can create / edit / delete personal dashboards (and clone
 * system / published ones). Viewer is read-only — sees system + published
 * dashboards but cannot create or modify. Mirrors the backend ACL on the
 * `/api/dashboards` write endpoints.
 */
export const canManageDashboards = (role: RoleLike): boolean => {
  return AI_ROLES.includes(role as UserRole)
}

/**
 * Whether the user can create / edit / delete personal widgets (and use the
 * widget-generation AI modal). Viewer is read-only. Mirrors the backend ACL on
 * the `/api/widgets` write endpoints.
 */
export const canManageWidgets = (role: RoleLike): boolean => {
  return AI_ROLES.includes(role as UserRole)
}

/**
 * Whether the user can publish / unpublish a dashboard to the whole company.
 * Mirrors the backend ACL (admin + superadmin). System dashboards are always
 * rejected on the backend — guard with `!isSystem` at the call site.
 */
export const canPublishDashboard = (role: RoleLike): boolean => {
  return COMPANY_ACCESS_ROLES.includes(role as UserRole)
}

/**
 * Whether the user can publish / unpublish a widget to the whole company.
 * Mirrors the backend ACL (admin + superadmin). System widgets are always
 * rejected on the backend — guard with `!isSystem` at the call site.
 */
export const canPublishWidget = (role: RoleLike): boolean => {
  return COMPANY_ACCESS_ROLES.includes(role as UserRole)
}

/**
 * Whether the user can open the Toolbox mini-chat in dashboard scope. Mirrors
 * `canUseMiniChat` — kept distinct so the dashboard mini-chat surface can be
 * tightened later without touching the report / general flows.
 */
export const canUseDashboardMiniChat = (role: RoleLike): boolean => {
  return AI_ROLES.includes(role as UserRole)
}

/**
 * Whether the user can delete a dashboard. Mirrors the backend ACL:
 *   - superadmin / admin — any non-system dashboard in the active company
 *   - analyst — only their own dashboards
 *   - viewer — never
 * `isSystem` always denies (backend 403).
 */
export const canDeleteDashboard = (
  role: RoleLike,
  isOwner: boolean,
  isSystem: boolean,
): boolean => {
  if (isSystem) return false
  if (role === 'superadmin' || role === 'admin') return true
  if (role === 'analyst') return isOwner
  return false
}

/**
 * Whether the user can delete a widget entity (not just detach from a
 * dashboard). Same shape as `canDeleteDashboard`. The backend additionally
 * returns 409 when the widget is still referenced by dashboards — the UI
 * surfaces that as a confirm prompt, this gate only controls visibility.
 */
export const canDeleteWidget = (
  role: RoleLike,
  isOwner: boolean,
  isSystem: boolean,
): boolean => {
  if (isSystem) return false
  if (role === 'superadmin' || role === 'admin') return true
  if (role === 'analyst') return isOwner
  return false
}

export const canManageUserIframe = (
  actorRole: RoleLike,
  targetRole: RoleLike,
): boolean => {
  return actorRole === 'superadmin' && !!targetRole && targetRole !== 'superadmin'
}

export const canDeleteUser = (
  actor: Pick<User, 'id' | 'role'> | null | undefined,
  target: Pick<User, 'id' | 'role'>,
): boolean => {
  if (!actor || actor.id === target.id) {
    return false
  }

  if (target.role === 'superadmin') {
    return actor.role === 'superadmin'
  }

  return actor.role === 'superadmin' || actor.role === 'admin'
}

/**
 * Whether the user can publish / unpublish a custom report. Mirrors the
 * backend ACL on `POST /api/reports/{id}/{publish,unpublish}` (admin +
 * superadmin only). System reports are always rejected on the backend, so
 * callers should additionally guard with `!isSystem` before showing the
 * action item.
 */
export const canManageReportPublication = (role: RoleLike): boolean => {
  return COMPANY_ACCESS_ROLES.includes(role as UserRole)
}

/**
 * Whether the user can publish / unpublish a dashboard to the whole company.
 * Mirrors the backend ACL on `POST /api/dashboards/{id}/{publish,unpublish}`
 * (admin + superadmin only). System dashboards are always rejected on the
 * backend, so callers should additionally guard with `!isSystem` before
 * showing the action item. Naming mirrors `canManageReportPublication`;
 * functionally identical to `canPublishDashboard`.
 */
export const canManageDashboardPublication = (role: RoleLike): boolean => {
  return COMPANY_ACCESS_ROLES.includes(role as UserRole)
}

/**
 * Whether the user can delete a custom report. Mirrors the backend ACL:
 *   - superadmin / admin — any report in the active company (system reports excluded)
 *   - analyst — only their own reports
 *   - viewer — never
 *
 * `isSystem` always denies (system reports cannot be deleted at all, the
 * backend returns 403). `isOwner` is the result of comparing `report.user_id`
 * to the current user's id at the call site.
 */
export const canDeleteReport = (
  role: RoleLike,
  isOwner: boolean,
  isSystem: boolean,
): boolean => {
  if (isSystem) return false
  if (role === 'superadmin' || role === 'admin') return true
  if (role === 'analyst') return isOwner
  return false
}

/**
 * Whether the user can see the report actions menu (the `…` button on the
 * report page) at all. Everyone except `viewer` sees it — viewer gets no
 * menu, not even the read-only info block. Non-owners / non-admins still see
 * the menu but with only the info block (no action buttons) — that finer gate
 * lives inside `ReportActionsMenu` via the individual action capabilities.
 */
export const canSeeReportActionsMenu = (role: RoleLike): boolean => {
  return role !== 'viewer'
}

/**
 * Whether the user can open the "edit with AI" modal for a report. Only the
 * report's owner may edit it through the AI chat, and only for custom (non-
 * system) reports — system reports have `user_id = null`, so `isOwner` is
 * already false for them, but `!isSystem` is kept explicit for clarity.
 *
 * Role is intentionally not a factor: an owner of any role (e.g. analyst) may
 * edit their own report. Viewers never reach this check because they don't see
 * the actions menu at all (see `canSeeReportActionsMenu`).
 *
 * Callers must additionally confirm the report has a `chat_id` to resume —
 * older reports without a pinned chat can't be edited this way yet.
 */
export const canEditReportWithAI = (isOwner: boolean, isSystem: boolean): boolean => {
  return isOwner && !isSystem
}

/**
 * Whether the user can create / edit document templates (incl. the AI
 * generation flow). Mirrors the backend ACL on the `/api/documents` write
 * endpoints — analyst and up. Viewer is read-only (download / set discount
 * only). System templates are read-only for everyone (clone first); that check
 * is done at the call site with `!isSystem`.
 */
export const canManageDocuments = (role: RoleLike): boolean => {
  return AI_ROLES.includes(role as UserRole)
}

/**
 * Whether the user can publish / unpublish a document template to the whole
 * company. Mirrors the backend ACL (admin + superadmin). System templates are
 * always rejected on the backend — guard with `!isSystem` at the call site.
 */
export const canManageDocumentPublication = (role: RoleLike): boolean => {
  return COMPANY_ACCESS_ROLES.includes(role as UserRole)
}

/**
 * Whether the user can delete a document template. Mirrors the backend ACL and
 * the shape of `canDeleteReport`:
 *   - superadmin / admin — any non-system template in the active company
 *   - analyst — only their own templates
 *   - viewer — never
 * `isSystem` always denies (backend 403).
 */
export const canDeleteDocument = (
  role: RoleLike,
  isOwner: boolean,
  isSystem: boolean,
): boolean => {
  if (isSystem) return false
  if (role === 'superadmin' || role === 'admin') return true
  if (role === 'analyst') return isOwner
  return false
}

/**
 * Whether the user can edit the company branding (logo, palette, fonts,
 * header / footer, requisites). Mirrors the backend ACL on
 * `PUT /api/companies/{id}/branding` (admin + superadmin). Analyst / viewer get
 * read-only branding (needed to render proposals) but no editor.
 */
export const canManageBranding = (role: RoleLike): boolean => {
  return COMPANY_ACCESS_ROLES.includes(role as UserRole)
}

/**
 * Whether the user can CRUD promotions (discount presets). Mirrors the backend
 * ACL on the `/api/promotions` write endpoints (admin + superadmin). Analyst /
 * viewer can only apply an existing promo's discount within its range (see
 * `canSetDiscount`).
 */
export const canManagePromotions = (role: RoleLike): boolean => {
  return COMPANY_ACCESS_ROLES.includes(role as UserRole)
}

/**
 * Whether the user can set a discount within a promotion's allowed range when
 * generating a proposal. Available to every role — viewers may not edit promos
 * but may apply one while producing a document for a client.
 */
export const canSetDiscount = (_role: RoleLike): boolean => {
  return true
}

/**
 * Whether the Documents section is reachable AT ALL in this build. This is the
 * single gate every Documents entry point must consult — the nav item, the
 * `/documents` + `/documents/:id` route guard, the AI document-generation modal
 * mount, and the `redirect_to_document_generation` action-marker CTA.
 *
 * Driven purely by the build-time feature flag `DOCUMENTS_FEATURE_ENABLED`
 * (env `VITE_FEATURE_DOCUMENTS`, default ON). It deliberately ignores role:
 * when the section is OFF nobody — not even superadmin — can reach Documents;
 * when it is ON the existing per-role gates (`canManageDocuments`,
 * `canDeleteDocument`, `canManageDocumentPublication`) still apply inside the
 * section. Role is accepted for signature symmetry with the other capabilities
 * and for a future per-plan tightening.
 */
export const canUseDocuments = (_role?: RoleLike): boolean => {
  return DOCUMENTS_FEATURE_ENABLED
}
