import type { UserDto } from './users'

export interface LoginRequest {
  email: string
  password: string
}

export interface IframeAuthRequest {
  token: string
}

export interface LoginResponse {
  token: string
  user: UserDto
}
