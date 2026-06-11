import { apiClient } from '@/api/client'
import type { MeResponse, UserData } from '@/api/types/auth'

export interface UpdateProfileRequest {
  full_name?: string
  locale?: string
  telegram_user_id?: string | null
}

export interface ChangePasswordRequest {
  current_password: string
  password: string
  password_confirmation: string
}

export const profileApi = {
  /**
   * GET /api/me — текущий пользователь
   */
  async me(): Promise<MeResponse> {
    const response = await apiClient.get<MeResponse>('/api/me')
    return response.data
  },

  /**
   * POST /api/profile/avatar — загрузить аватар (multipart/form-data)
   */
  async uploadAvatar(file: File): Promise<{ data: UserData }> {
    const form = new FormData()
    form.append('avatar', file)
    const response = await apiClient.post<{ data: UserData }>('/api/profile/avatar', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data
  },
}
