/**
 * Users API — minimal, for responsible/owner selects.
 */
import { apiClient } from '@/api/client'

export interface UserOptionDto {
  id: number
  full_name: string
  email: string
  avatar_path: string | null
  department_id: number | null
  role: string
}

export const usersApi = {
  async getUsers(): Promise<UserOptionDto[]> {
    const res = await apiClient.get<{ data: UserOptionDto[] }>('/api/users')
    return res.data.data
  },
}
