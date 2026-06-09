import { authApi } from '@/api/auth'
import { mapUserDtoToUser, type User } from '@/entities/user'
import type { LoginRequest } from '@/api/types'

export interface AuthSessionPayload {
  token: string
  user: User
}

export class AuthService {
  async login(data: LoginRequest): Promise<AuthSessionPayload> {
    const response = await authApi.login(data)

    return {
      token: response.token,
      user: mapUserDtoToUser(response.user),
    }
  }

  async logout(): Promise<void> {
    await authApi.logout()
  }

  async loginWithIframeToken(token: string): Promise<AuthSessionPayload> {
    const response = await authApi.iframeAuth({ token })

    return {
      token: response.token,
      user: mapUserDtoToUser(response.user),
    }
  }
}
