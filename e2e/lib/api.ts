import { request as pwRequest, type APIRequestContext } from '@playwright/test'

/**
 * Shared API helpers for regression specs.
 * Tests run against the already-running dev stack. API origin defaults to nginx :8080.
 */
export const API_URL = process.env.E2E_API_URL ?? 'http://localhost:8080'

/** Seeded test users (password = "password", 2FA off). manager1 owns ~0 records → ideal leak probe. */
export const USERS = {
  admin: 'admin@mgcrm.test',
  director: 'director@mgcrm.test',
  lawyer: 'lawyer@mgcrm.test',
  manager1: 'manager1@mgcrm.test',
  manager2: 'manager2@mgcrm.test',
  manager3: 'manager3@mgcrm.test',
} as const

/** A fresh APIRequestContext (no cookies/auth) — dispose it when done. */
export async function apiContext(): Promise<APIRequestContext> {
  return pwRequest.newContext()
}

/** Log in and return the Sanctum bearer token. Throws on failure. */
export async function login(
  ctx: APIRequestContext,
  email: string,
  password = 'password',
): Promise<string> {
  const res = await ctx.post(`${API_URL}/api/login`, {
    headers: { Accept: 'application/json' },
    data: { email, password },
  })
  if (!res.ok()) throw new Error(`login failed for ${email}: HTTP ${res.status()}`)
  const body = await res.json()
  if (!body.token) throw new Error(`login for ${email} returned no token`)
  return body.token as string
}

/** Authorization headers for a bearer token. */
export function bearer(token: string): Record<string, string> {
  return { Authorization: `Bearer ${token}`, Accept: 'application/json' }
}

/** Current user (GET /api/me) for a token → the `data` object ({ id, role, ... }). */
export async function me(ctx: APIRequestContext, token: string): Promise<Record<string, unknown>> {
  const res = await ctx.get(`${API_URL}/api/me`, { headers: bearer(token) })
  if (!res.ok()) throw new Error(`GET /api/me failed: HTTP ${res.status()}`)
  const body = await res.json()
  return (body.data ?? body) as Record<string, unknown>
}

/**
 * Total count from a Laravel index response, tolerant of shapes:
 * { meta: { total } } | { total } | { data: [...] } | [...].
 */
export function totalOf(body: unknown): number {
  if (Array.isArray(body)) return body.length
  const b = body as Record<string, any>
  if (typeof b?.meta?.total === 'number') return b.meta.total
  if (typeof b?.total === 'number') return b.total
  if (Array.isArray(b?.data)) return b.data.length
  return 0
}

/** The row array from a Laravel index response ({ data: [...] } | [...]). */
export function rowsOf(body: unknown): any[] {
  if (Array.isArray(body)) return body
  const b = body as Record<string, any>
  return Array.isArray(b?.data) ? b.data : []
}
