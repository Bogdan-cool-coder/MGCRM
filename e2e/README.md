# MGCRM — E2E (Playwright)

End-to-end browser tests for MACRO Global CRM. Standalone package (own `package.json`,
independent of `front/`). Tests drive the **already-running dev stack**.

## Prerequisites
The dev stack must be up:
```bash
docker compose -f docker-compose.dev.yml up -d
```
In dev the SPA is on Vite **:5173**, the API on nginx **:8080/api**. Seeded test users
(password `password`, 2FA off): `admin@`, `director@`, `lawyer@`, `manager1@mgcrm.test`.

## Install (one-time)
```bash
cd e2e
npm install
npx playwright install            # downloads browser binaries (chromium/firefox/webkit)
# or just chromium: npx playwright install chromium
```

## Run
```bash
cd e2e
npm test                 # headless, all projects
npm run test:chromium    # chromium only
npm run test:headed      # see the browser
npm run test:ui          # Playwright UI mode (watch/debug)
npm run report           # open the last HTML report
npm run codegen          # record a test by clicking
```

## Targeting another environment
```bash
E2E_BASE_URL=http://localhost:8080 E2E_API_URL=http://localhost:8080 npm test
```
- `E2E_BASE_URL` — UI origin the browser navigates (default `http://localhost:5173`).
- `E2E_API_URL`  — API origin for request-level checks (default `http://localhost:8080`).

## Layout
- `playwright.config.ts` — config (baseURL, reporters, projects).
- `tests/*.spec.ts` — specs. `auth.spec.ts` is the seed smoke (API login + UI login).

> Note: this is a **separate** test surface from the `qa-tester` agent (which drives a
> browser live via MCP). Playwright Test gives durable, CI-able regression coverage.
