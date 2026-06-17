import { apiClient } from '@/api/client'
import type {
  LoginRequest,
  LoginResponse,
  TwoFactorValidateRequest,
  TwoFactorValidateResponse,
  TwoFactorSetupResponse,
  TwoFactorVerifySetupRequest,
  TwoFactorVerifySetupResponse,
  MeResponse,
} from '@/api/types/auth'

export const authApi = {
  /**
   * POST /api/login
   * 2FA off  → { data, two_factor_required: false, token }
   * 2FA on   → { data, two_factor_required: true, temp_token }
   */
  async login(data: LoginRequest): Promise<LoginResponse> {
    const response = await apiClient.post<LoginResponse>('/api/login', data)
    return response.data
  },

  /**
   * POST /api/logout
   */
  async logout(): Promise<void> {
    await apiClient.post('/api/logout')
  },

  /**
   * GET /api/me
   */
  async me(): Promise<MeResponse> {
    const response = await apiClient.get<MeResponse>('/api/me')
    return response.data
  },

  /**
   * POST /api/2fa/validate — финализировать логин (temp-токен → полный токен)
   */
  async validateTwoFactor(data: TwoFactorValidateRequest): Promise<TwoFactorValidateResponse> {
    const response = await apiClient.post<TwoFactorValidateResponse>('/api/2fa/validate', data)
    return response.data
  },

  /**
   * POST /api/2fa/setup — сгенерировать секрет + QR URI (ничего не сохраняет)
   */
  async setupTwoFactor(): Promise<TwoFactorSetupResponse> {
    const response = await apiClient.post<TwoFactorSetupResponse>('/api/2fa/setup')
    return response.data
  },

  /**
   * POST /api/2fa/verify-setup — подтвердить код, включить 2FA, получить backup codes
   */
  async verifySetup(data: TwoFactorVerifySetupRequest): Promise<TwoFactorVerifySetupResponse> {
    const response = await apiClient.post<TwoFactorVerifySetupResponse>(
      '/api/2fa/verify-setup',
      data,
    )
    return response.data
  },

  /**
   * POST /api/me/telegram-link — получить deeplink для привязки Telegram
   */
  async telegramLink(): Promise<{ link_url: string }> {
    const response = await apiClient.post<{ link_url: string }>('/api/me/telegram-link')
    return response.data
  },

  /**
   * DELETE /api/me/telegram — отвязать Telegram аккаунт
   */
  async telegramUnlink(): Promise<void> {
    await apiClient.delete('/api/me/telegram')
  },
}
