import { apiClient } from '@/api/client'
import type { MeResponse, UserData } from '@/api/types/auth'

export interface ChangePasswordRequest {
  current_password: string
  password: string
  password_confirmation: string
}

export interface UpdateProfileRequest {
  /** PATCH /api/me/profile — display name */
  full_name?: string
  /** PATCH /api/me/profile — account-level UI language (persisted) */
  locale?: string
  /** PATCH /api/me/profile — set quick actions; null clears, [] empties, omit to leave unchanged */
  nav_quick_actions?: string[] | null
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
   * PATCH /api/me/profile — обновить профиль (включая nav_quick_actions)
   */
  async updateProfile(data: UpdateProfileRequest): Promise<MeResponse> {
    const response = await apiClient.patch<MeResponse>('/api/me/profile', data)
    return response.data
  },

  /**
   * POST /api/profile/avatar — загрузить аватар (multipart/form-data).
   * BE отдаёт обновлённого пользователя (UserResource) → { data: UserData }.
   */
  async uploadAvatar(file: File): Promise<{ data: UserData }> {
    const form = new FormData()
    form.append('avatar', file)
    const response = await apiClient.post<{ data: UserData }>('/api/profile/avatar', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data
  },

  /**
   * DELETE /api/profile/avatar — удалить аватар. Возвращает обновлённого пользователя.
   */
  async removeAvatar(): Promise<{ data: UserData }> {
    const response = await apiClient.delete<{ data: UserData }>('/api/profile/avatar')
    return response.data
  },

  /**
   * POST /api/me/password — сменить пароль текущего пользователя.
   * 200 (пустое тело) при успехе; 422 { errors: { current_password: [...] } } при неверном пароле.
   */
  async changePassword(data: ChangePasswordRequest): Promise<void> {
    await apiClient.post('/api/me/password', data)
  },
}
