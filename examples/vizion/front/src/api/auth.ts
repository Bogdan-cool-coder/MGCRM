import { apiClient } from '@/api/client'
import type { IframeAuthRequest, LoginRequest, LoginResponse } from '@/api/types'

export interface AuthApi {
  login(data: LoginRequest): Promise<LoginResponse>
  logout(): Promise<void>
  iframeAuth(data: IframeAuthRequest): Promise<LoginResponse>
}

export const authApi: AuthApi = {
  async login(data: LoginRequest): Promise<LoginResponse> {
    const response = await apiClient.post<LoginResponse>('/api/login', data)
    return response.data
  },

  async logout(): Promise<void> {
    await apiClient.post('/api/logout')
  },

  async iframeAuth(data: IframeAuthRequest): Promise<LoginResponse> {
    const response = await apiClient.post<LoginResponse>('/api/iframe-auth', data)
    return response.data
  },
}

export type { IframeAuthRequest, LoginRequest, LoginResponse }
