/**
 * Access Control entities — types for departments, roles/permissions, visibility config.
 * Matches backend: DepartmentResource, RolePermissionsResource, VisibilityConfigResource.
 */

import type { UserRole } from '@/entities/user'

// ─── Departments ──────────────────────────────────────────────────────────────

/** Flat DTO from GET /api/admin/departments */
export interface DepartmentDto {
  id: number
  name: string
  parent_id: number | null
  manager_id: number | null
  manager_name: string | null
  members_count: number
}

/** Member (user) within a department */
export interface DepartmentMemberDto {
  id: number
  full_name: string
  role: UserRole | null
  job_title: string | null
}

/** TreeNode shape for PrimeVue Tree component */
export interface DeptTreeNode {
  key: string
  label: string
  data: DepartmentDto
  children: DeptTreeNode[]
  /** UI-only: depth level (0 = root children) */
  depth: number
}

export interface CreateDepartmentPayload {
  name: string
  parent_id?: number | null
  manager_id?: number | null
}

export interface UpdateDepartmentPayload {
  name?: string
  parent_id?: number | null
  manager_id?: number | null
}

export interface AddDepartmentMembersPayload {
  user_ids: number[]
}

// ─── Roles & Permissions ──────────────────────────────────────────────────────

/**
 * Map of role → array of permission strings.
 * Matches GET /api/admin/roles/permissions response.
 */
export type RolePermissionsMap = Record<UserRole, string[]>

export interface UpdateRolePermissionsPayload {
  permissions: string[]
}

/** Permission group definition (FE-only constant) */
export interface PermissionGroup {
  key: string
  labelKey: string
  permissions: string[]
}

export const PERMISSION_GROUPS: PermissionGroup[] = [
  {
    key: 'crm',
    labelKey: 'accessControl.roles.groupCrm',
    permissions: ['crm.view', 'crm.manage'],
  },
  {
    key: 'sales',
    labelKey: 'accessControl.roles.groupSales',
    permissions: ['sales.view', 'sales.manage'],
  },
  {
    key: 'contracts',
    labelKey: 'accessControl.roles.groupContracts',
    permissions: ['contracts.view', 'contracts.manage'],
  },
  {
    key: 'users',
    labelKey: 'accessControl.roles.groupUsers',
    permissions: ['users.view', 'users.manage'],
  },
  {
    key: 'automation',
    labelKey: 'accessControl.roles.groupAutomation',
    permissions: ['automation.manage'],
  },
  {
    key: 'analytics',
    labelKey: 'accessControl.roles.groupAnalytics',
    permissions: ['analytics.view', 'settings.manage'],
  },
  {
    key: 'finance',
    labelKey: 'accessControl.roles.groupFinance',
    permissions: [
      'finance.view',
      'finance.entry',
      'finance.posting',
      'finance.journals.manual',
      'finance.payments.approve',
      'finance.period.close',
      'finance.settings.manage',
      'finance.reports.management',
    ],
  },
  {
    key: 'system',
    labelKey: 'accessControl.roles.groupSystem',
    permissions: ['admin-write', 'dedup-scan-all', 'view-manager-cabinet', 'system-reset'],
  },
]

// ─── Visibility Config ────────────────────────────────────────────────────────

export type VisibilityScope = 'all' | 'department' | 'own'

/** Map of role → visibility scope from GET /api/admin/visibility-config */
export type VisibilityConfigMap = Record<UserRole, VisibilityScope>

export interface VisibilityConfigRow {
  role: UserRole
  scope: VisibilityScope
}

export type UpdateVisibilityConfigPayload = Partial<Record<UserRole, VisibilityScope>>

/** Build tree nodes from a flat department list */
export function buildDeptTree(
  departments: DepartmentDto[],
  parentId: number | null = null,
  depth = 0,
): DeptTreeNode[] {
  return departments
    .filter((d) => d.parent_id === parentId)
    .map((d) => ({
      key: String(d.id),
      label: d.name,
      data: d,
      depth,
      children: buildDeptTree(departments, d.id, depth + 1),
    }))
}

/** Compute the maximum depth of a dept tree */
export function maxTreeDepth(nodes: DeptTreeNode[]): number {
  if (nodes.length === 0) return 0
  return Math.max(...nodes.map((n) => 1 + maxTreeDepth(n.children)))
}
