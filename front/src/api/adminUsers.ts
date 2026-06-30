/**
 * Admin user-management API.
 * Endpoints: GET/POST /api/admin/users, PATCH/DELETE /api/admin/users/{id},
 * GET /api/admin/departments.
 */
import { apiClient } from '@/api/client'
import type {
  AdminUserDto,
  CreateAdminUserPayload,
  DepartmentOption,
  GetAdminUsersParams,
  UpdateAdminUserPayload,
} from '@/entities/adminUser'

export interface PaginatedAdminUsers {
  data: AdminUserDto[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export const adminUsersApi = {
  async getUsers(params: GetAdminUsersParams = {}): Promise<PaginatedAdminUsers> {
    // strip undefined so axios does not send empty query params
    const clean: Record<string, unknown> = {}
    for (const [k, v] of Object.entries(params)) {
      if (v !== undefined && v !== null && v !== '') {
        clean[k] = v
      }
    }
    const res = await apiClient.get<PaginatedAdminUsers>('/api/admin/users', { params: clean })
    return res.data
  },

  async createUser(payload: CreateAdminUserPayload): Promise<AdminUserDto> {
    const res = await apiClient.post<{ data: AdminUserDto }>('/api/admin/users', payload)
    return res.data.data
  },

  async updateUser(id: number, payload: UpdateAdminUserPayload): Promise<AdminUserDto> {
    const res = await apiClient.patch<{ data: AdminUserDto }>(`/api/admin/users/${id}`, payload)
    return res.data.data
  },

  /** Soft-deactivate a user (no hard delete). Returns the updated row. */
  async deactivateUser(id: number): Promise<AdminUserDto> {
    const res = await apiClient.delete<{ data: AdminUserDto }>(`/api/admin/users/${id}`)
    return res.data.data
  },

  async getDepartments(): Promise<DepartmentOption[]> {
    const res = await apiClient.get<{ data: DepartmentOption[] }>('/api/admin/departments')
    return res.data.data ?? []
  },

  /**
   * Generate and return a new password for the user.
   * The plaintext password is returned ONCE — not stored server-side.
   * Gate: admin-write. Cannot reset own account or service accounts (→ 422).
   */
  async resetUserPassword(userId: number): Promise<{ user_id: number; password: string }> {
    const res = await apiClient.post<{ data: { user_id: number; password: string } }>(
      `/api/admin/users/${userId}/reset-password`,
    )
    return res.data.data
  },
}
