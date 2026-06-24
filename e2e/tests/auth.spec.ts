import { test, expect, request as pwRequest } from '@playwright/test'

/**
 * Seed auth smoke tests. They assume the dev stack is up and the seeded
 * test users exist (password = "password", 2FA off):
 *   admin@mgcrm.test (admin), director@mgcrm.test (director),
 *   lawyer@mgcrm.test (lawyer), manager1@mgcrm.test (manager)
 */

const API_URL = process.env.E2E_API_URL ?? 'http://localhost:8080'

test.describe('Auth — API level', () => {
  test('POST /api/login returns a bearer token for a valid user', async () => {
    const ctx = await pwRequest.newContext()
    const res = await ctx.post(`${API_URL}/api/login`, {
      headers: { Accept: 'application/json' },
      data: { email: 'admin@mgcrm.test', password: 'password' },
    })
    expect(res.ok(), `login should be 2xx, got ${res.status()}`).toBeTruthy()
    const body = await res.json()
    expect(body.token, 'response should carry a token').toBeTruthy()
    expect(body.data?.email).toBe('admin@mgcrm.test')
    await ctx.dispose()
  })

  test('POST /api/login rejects a wrong password', async () => {
    const ctx = await pwRequest.newContext()
    const res = await ctx.post(`${API_URL}/api/login`, {
      headers: { Accept: 'application/json' },
      data: { email: 'admin@mgcrm.test', password: 'definitely-wrong' },
    })
    expect(res.status(), 'wrong password must not authenticate').toBeGreaterThanOrEqual(400)
    await ctx.dispose()
  })
})

test.describe('Auth — UI level', () => {
  test('login page renders the email + password fields', async ({ page }) => {
    await page.goto('/login')
    await expect(page.locator('#login-email')).toBeVisible()
    await expect(page.locator('#login-password')).toBeVisible()
    await expect(page.locator('.login-form button[type="submit"]')).toBeVisible()
  })

  test('a manager can log in through the form and leaves /login', async ({ page }) => {
    await page.goto('/login')
    await page.locator('#login-email').fill('manager1@mgcrm.test')
    // PrimeVue <Password> puts the id on the wrapper div; the real input is nested.
    await page.locator('#login-password input').fill('password')

    const [loginResp] = await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('/api/login') && r.request().method() === 'POST',
      ),
      page.locator('.login-form button[type="submit"]').click(),
    ])
    expect(loginResp.status()).toBe(200)

    // 2FA is off for seeded users → app should route away from the login screen.
    await expect(page).not.toHaveURL(/\/login/, { timeout: 15_000 })
  })
})
