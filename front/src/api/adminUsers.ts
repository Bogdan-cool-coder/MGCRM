/**
 * Admin user-management API.
 * Endpoints: GET /api/admin/users, POST /api/admin/users, GET /api/admin/departments.
 */
import { apiClient } from '@/api/client'
import type {
  AdminUserDto,
  CreateAdminUserPayload,
  DepartmentOption,
  GetAdminUsersParams,
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

  async getDepartments(): Promise<DepartmentOption[]> {
    const res = await apiClient.get<{ data: DepartmentOption[] }>('/api/admin/departments')
    return res.data.data ?? []
  },
}
