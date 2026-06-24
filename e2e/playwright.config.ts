import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright config for MACRO Global CRM E2E tests.
 *
 * Tests run against the ALREADY-RUNNING dev stack (docker compose -f docker-compose.dev.yml).
 * In dev the SPA is served by Vite at :5173 and the API by nginx at :8080/api.
 * Override with env vars when running against another environment (e.g. a prod-like build
 * where nginx serves the SPA on :8080):
 *   E2E_BASE_URL  — UI origin Playwright navigates (default http://localhost:5173)
 *   E2E_API_URL   — API origin for request-level checks (default http://localhost:8080)
 */
const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:5173'

export default defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [['html', { open: 'never' }], ['list']],
  use: {
    baseURL: BASE_URL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    // Enable after `npx playwright install firefox webkit`:
    // { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    // { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],
})
