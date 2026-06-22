/**
 * Admin user management — entity types.
 * Matches AdminUserResource.php output.
 */
import type { UserRole } from '@/entities/user'

export interface AdminUserDto {
  id: number
  full_name: string
  email: string
  phone: string | null
  job_title: string | null
  department_id: number | null
  department_name: string | null
  role: UserRole | null
  is_active: boolean
  created_at: string
}

export interface CreateAdminUserPayload {
  full_name: string
  email: string
  phone?: string | null
  job_title?: string | null
  department_id?: number | null
  role?: UserRole | null
  password?: string | null
}

export interface GetAdminUsersParams {
  search?: string
  role?: UserRole
  department_id?: number
  is_active?: boolean
  per_page?: number
  page?: number
}

/** Flat department option for the Select dropdown. */
export interface DepartmentOption {
  id: number
  name: string
  parent_id: number | null
}
