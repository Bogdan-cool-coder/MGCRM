/**
 * Access Control API — departments, roles/permissions, visibility config.
 *
 * Endpoints:
 *   GET    /api/admin/departments
 *   POST   /api/admin/departments
 *   PATCH  /api/admin/departments/{id}
 *   DELETE /api/admin/departments/{id}
 *   POST   /api/admin/departments/{id}/members
 *   DELETE /api/admin/departments/{id}/members/{userId}
 *   GET    /api/admin/roles/permissions
 *   PUT    /api/admin/roles/{role}/permissions
 *   GET    /api/admin/visibility-config
 *   PATCH  /api/admin/visibility-config
 */
import { apiClient } from '@/api/client'
import type {
  AddDepartmentMembersPayload,
  CreateDepartmentPayload,
  DepartmentDto,
  DepartmentMemberDto,
  RolePermissionsMap,
  UpdateDepartmentPayload,
  UpdateRolePermissionsPayload,
  UpdateVisibilityConfigPayload,
  VisibilityConfigMap,
} from '@/entities/accessControl'
import type { UserRole } from '@/entities/user'

export const accessControlApi = {
  // ─── Departments ────────────────────────────────────────────────────────────

  async getDepartments(): Promise<DepartmentDto[]> {
    const res = await apiClient.get<{ data: DepartmentDto[] }>('/api/admin/departments')
    return res.data.data ?? []
  },

  async createDepartment(payload: CreateDepartmentPayload): Promise<DepartmentDto> {
    const res = await apiClient.post<{ data: DepartmentDto }>('/api/admin/departments', payload)
    return res.data.data
  },

  async updateDepartment(id: number, payload: UpdateDepartmentPayload): Promise<DepartmentDto> {
    const res = await apiClient.patch<{ data: DepartmentDto }>(
      `/api/admin/departments/${id}`,
      payload,
    )
    return res.data.data
  },

  async deleteDepartment(id: number): Promise<void> {
    await apiClient.delete(`/api/admin/departments/${id}`)
  },

  async getDepartmentMembers(id: number): Promise<DepartmentMemberDto[]> {
    const res = await apiClient.get<{ data: DepartmentMemberDto[] }>(
      `/api/admin/departments/${id}/members`,
    )
    return res.data.data ?? []
  },

  async addDepartmentMembers(
    id: number,
    payload: AddDepartmentMembersPayload,
  ): Promise<DepartmentMemberDto[]> {
    const res = await apiClient.post<{ data: DepartmentMemberDto[] }>(
      `/api/admin/departments/${id}/members`,
      payload,
    )
    return res.data.data ?? []
  },

  async removeDepartmentMember(deptId: number, userId: number): Promise<void> {
    await apiClient.delete(`/api/admin/departments/${deptId}/members/${userId}`)
  },

  // ─── Roles & Permissions ────────────────────────────────────────────────────

  async getRolesPermissions(): Promise<RolePermissionsMap> {
    const res = await apiClient.get<{
      data: { groups: unknown[]; roles: { role: string; permissions: string[] }[] }
    }>('/api/admin/roles/permissions')
    // BE returns { groups:[...], roles:[{role, permissions}] } — map to Record<UserRole, string[]>
    const map: RolePermissionsMap = {
      admin: [],
      director: [],
      lawyer: [],
      manager: [],
      accountant: [],
      cfo: [],
    }
    for (const entry of res.data.data.roles ?? []) {
      if (entry.role in map) {
        map[entry.role as UserRole] = entry.permissions ?? []
      }
    }
    return map
  },

  async updateRolePermissions(
    role: UserRole,
    payload: UpdateRolePermissionsPayload,
  ): Promise<void> {
    await apiClient.put(`/api/admin/roles/${role}/permissions`, payload)
  },

  // ─── Visibility Config ──────────────────────────────────────────────────────

  async getVisibilityConfig(): Promise<VisibilityConfigMap> {
    const res = await apiClient.get<{ data: VisibilityConfigMap }>('/api/admin/visibility-config')
    return res.data.data
  },

  async updateVisibilityConfig(payload: UpdateVisibilityConfigPayload): Promise<VisibilityConfigMap> {
    const res = await apiClient.patch<{ data: VisibilityConfigMap }>(
      '/api/admin/visibility-config',
      payload,
    )
    return res.data.data
  },
}
