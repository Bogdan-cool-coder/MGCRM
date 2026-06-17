// API types for auth endpoints
// Контракт: src/app/Http/Controllers/Auth/AuthController.php
//           src/app/Http/Controllers/Auth/TwoFactorController.php
//           src/app/Http/Resources/UserResource.php

export interface LoginRequest {
  email: string
  password: string
}

export interface UserData {
  id: number
  email: string
  full_name: string
  role: string
  telegram_user_id: string | null
  avatar_path: string | null
  department_id: number | null
  manager_id: number | null
  is_active: boolean
  locale: string | null
  totp_enabled: boolean
  created_at: string
  nav_quick_actions: string[]
}

/**
 * POST /api/login — 2FA выключена
 * { data: UserData, two_factor_required: false, token: string }
 */
export interface LoginResponseFull {
  data: UserData
  two_factor_required: false
  token: string
}

/**
 * POST /api/login — 2FA включена
 * { data: UserData, two_factor_required: true, temp_token: string }
 */
export interface LoginResponseTwoFactor {
  data: UserData
  two_factor_required: true
  temp_token: string
}

export type LoginResponse = LoginResponseFull | LoginResponseTwoFactor

/**
 * POST /api/2fa/validate
 * { data: UserData, token: string }
 */
export interface TwoFactorValidateRequest {
  totp_code?: string
  backup_code?: string
}

export interface TwoFactorValidateResponse {
  data: UserData
  token: string
}

/**
 * POST /api/2fa/setup
 * { secret: string, otpauth_uri: string }
 */
export interface TwoFactorSetupResponse {
  data: {
    secret: string
    otpauth_uri: string
  }
}

/**
 * POST /api/2fa/verify-setup
 */
export interface TwoFactorVerifySetupRequest {
  secret: string
  totp_code: string
}

export interface TwoFactorVerifySetupResponse {
  two_factor_enabled: boolean
  backup_codes: string[]
}

/**
 * GET /api/me
 */
export interface MeResponse {
  data: UserData
}
