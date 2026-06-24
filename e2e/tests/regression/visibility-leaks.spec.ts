import { test, expect, type APIRequestContext } from '@playwright/test'
import {
  apiContext,
  login,
  bearer,
  me,
  totalOf,
  rowsOf,
  API_URL,
  USERS,
} from '../../lib/api'

/**
 * REGRESSION LOCK — data-visibility blockers (PII / cross-tenant leaks + RBAC).
 *
 * Audit sources:
 *   - docs/audit/domains/crm-contacts.md  (BLOCKER #1 list leak, BLOCKER #2 export, NEW-5)
 *   - docs/audit/domains/crm-companies.md (BLOCKER #1 list+export leak, NEW-5)
 *   - docs/audit/domains/iam.md           (NEW-5 — /api/admin/* readable by manager)
 *
 * Endpoint paths confirmed against src/routes/api.php:
 *   GET  /api/contacts            (api.php:172, apiResource index)
 *   GET  /api/companies           (api.php:226, apiResource index)
 *   POST /api/contacts/export     (api.php:170)
 *   POST /api/companies/export    (api.php:224)
 *   GET  /api/admin/company-types (api.php:380, apiResource index) — admin directory, NEW-5
 *
 * REGRESSION-LOCK CONVENTION:
 *   Each test asserts the CORRECT/DESIRED post-fix behaviour and marks itself
 *   `test.fail()` on the first line, so the suite stays green while the bug is
 *   live (the test reports as an "expected failure"). When the fix lands the
 *   test PASSES, Playwright flags "expected to fail but passed" → that is the
 *   signal to delete the matching `test.fail()` line and lock the fix in.
 *
 * Runtime is READ-ONLY: only HTTP GET, the login POST, and the read-only export
 * POST. No business data is created / updated / deleted. All preconditions are
 * discovered live — no hardcoded ids or counts.
 */

let ctx: APIRequestContext
let adminToken: string
let mgrToken: string

test.beforeAll(async () => {
  ctx = await apiContext()
  adminToken = await login(ctx, USERS.admin)
  mgrToken = await login(ctx, USERS.manager1)
})

test.afterAll(async () => {
  await ctx?.dispose()
})

test.describe('Data-visibility leaks — regression lock', () => {
  // ---------------------------------------------------------------------------
  // 1) AUDIT crm-contacts#0 — GET /api/contacts must be owner-scoped.
  //    crm-contacts.md BLOCKER #1: ContactService::list has no owner-scope, so
  //    manager1 (owns ~0) sees the whole table (live: 3 contacts, all owner_id=1).
  // ---------------------------------------------------------------------------
  test('AUDIT crm-contacts#0 — GET /api/contacts is owner-scoped (manager sees fewer than admin)', async () => {
    test.fail(
      true,
      'AUDIT crm-contacts#0: GET /api/contacts has no owner-scope (BLOCKER #1, PII leak). ' +
        'RED until fixed — when this starts PASSING, remove the test.fail() line to lock the fix.',
    )

    const adminRes = await ctx.get(`${API_URL}/api/contacts?per_page=200`, {
      headers: bearer(adminToken),
    })
    expect(adminRes.ok(), `admin GET /api/contacts → HTTP ${adminRes.status()}`).toBeTruthy()
    const adminTotal = totalOf(await adminRes.json())

    const mgrRes = await ctx.get(`${API_URL}/api/contacts?per_page=200`, {
      headers: bearer(mgrToken),
    })
    expect(mgrRes.ok(), `manager1 GET /api/contacts → HTTP ${mgrRes.status()}`).toBeTruthy()
    const mgrTotal = totalOf(await mgrRes.json())

    // Precondition: there must be contacts in the system for the scope test to mean anything.
    test.skip(adminTotal === 0, 'No contacts in the system — nothing to scope-check.')

    // DESIRED: a manager who owns ~0 contacts must NOT see the whole table.
    expect(
      mgrTotal,
      `manager1 sees ${mgrTotal} contacts vs admin's ${adminTotal} — a manager owning ~0 must see strictly fewer`,
    ).toBeLessThan(adminTotal)
  })

  // ---------------------------------------------------------------------------
  // 2) AUDIT crm-companies#0 — GET /api/companies must be owner-scoped.
  //    crm-companies.md BLOCKER #1: CompanyService.list applies no visibility
  //    scope; live manager1 (owns 0) sees all 13, owners=[1].
  // ---------------------------------------------------------------------------
  test('AUDIT crm-companies#0 — GET /api/companies is owner-scoped (manager sees fewer than admin)', async () => {
    test.fail(
      true,
      'AUDIT crm-companies#0: GET /api/companies has no visibility scope (BLOCKER #1, cross-tenant leak). ' +
        'RED until fixed — when this starts PASSING, remove the test.fail() line to lock the fix.',
    )

    const adminRes = await ctx.get(`${API_URL}/api/companies?per_page=200`, {
      headers: bearer(adminToken),
    })
    expect(adminRes.ok(), `admin GET /api/companies → HTTP ${adminRes.status()}`).toBeTruthy()
    const adminTotal = totalOf(await adminRes.json())

    const mgrRes = await ctx.get(`${API_URL}/api/companies?per_page=200`, {
      headers: bearer(mgrToken),
    })
    expect(mgrRes.ok(), `manager1 GET /api/companies → HTTP ${mgrRes.status()}`).toBeTruthy()
    const mgrTotal = totalOf(await mgrRes.json())

    test.skip(adminTotal === 0, 'No companies in the system — nothing to scope-check.')

    // DESIRED: a manager who owns ~0 companies must NOT see the whole table.
    expect(
      mgrTotal,
      `manager1 sees ${mgrTotal} companies vs admin's ${adminTotal} — a manager owning ~0 must see strictly fewer`,
    ).toBeLessThan(adminTotal)
  })

  // ---------------------------------------------------------------------------
  // 3) AUDIT crm-contacts#1 — POST /api/contacts/export with empty selection
  //    as manager1 must NOT dump all PII.
  //    crm-contacts.md BLOCKER #2: export() has no authorize(); empty contact_ids
  //    → buildXlsx dumps every row (live: manager1 {} → 200, 6566-byte xlsx).
  //    Export POST is read-only; allowed at runtime.
  // ---------------------------------------------------------------------------
  test('AUDIT crm-contacts#1 — POST /api/contacts/export empty as manager1 is forbidden (403)', async () => {
    test.fail(
      true,
      'AUDIT crm-contacts#1: POST /api/contacts/export has no authz; empty ids dumps all PII (BLOCKER #2). ' +
        'RED until fixed — when this starts PASSING, remove the test.fail() line to lock the fix.',
    )

    const res = await ctx.post(`${API_URL}/api/contacts/export`, {
      headers: bearer(mgrToken),
      data: {},
    })

    // DESIRED: empty/unscoped export by a low-privilege role is rejected.
    expect(
      res.status(),
      `manager1 POST /api/contacts/export {} → HTTP ${res.status()} (must be 403: no full-table PII dump)`,
    ).toBe(403)
  })

  // ---------------------------------------------------------------------------
  // 4) AUDIT crm-companies#0 (export) — POST /api/companies/export empty as
  //    manager1 must NOT dump the whole client base.
  //    crm-companies.md BLOCKER #1: CompanyExportService.buildXlsx with empty
  //    company_ids → whole table (live by code: {} → 200, 6980-byte xlsx).
  // ---------------------------------------------------------------------------
  test('AUDIT crm-companies#0 — POST /api/companies/export empty as manager1 is forbidden (403)', async () => {
    test.fail(
      true,
      'AUDIT crm-companies#0 (export): POST /api/companies/export has no authz; empty ids dumps all companies (BLOCKER #1). ' +
        'RED until fixed — when this starts PASSING, remove the test.fail() line to lock the fix.',
    )

    const res = await ctx.post(`${API_URL}/api/companies/export`, {
      headers: bearer(mgrToken),
      data: {},
    })

    // DESIRED: empty/unscoped export by a low-privilege role is rejected.
    expect(
      res.status(),
      `manager1 POST /api/companies/export {} → HTTP ${res.status()} (must be 403: no whole-base dump)`,
    ).toBe(403)
  })

  // ---------------------------------------------------------------------------
  // 5) NEW-5 (iam) — an /api/admin/* directory endpoint must be admin/director
  //    gated. iam.md NEW-5 + crm-*.md NEW-5: manager1 GETs /api/admin/company-types
  //    (and sources/countries/etc.) → 200. These index/show routes call no
  //    authorize(). Confirmed path: GET /api/admin/company-types (api.php:380).
  // ---------------------------------------------------------------------------
  test('AUDIT iam#NEW-5 — GET /api/admin/company-types is admin-gated (manager1 → 403)', async () => {
    test.fail(
      true,
      'AUDIT iam#NEW-5: /api/admin/* directory index/show have no authorize() — readable by manager (200). ' +
        'RED until fixed — when this starts PASSING, remove the test.fail() line to lock the fix.',
    )

    // Sanity: confirm the probing user is actually a non-admin/non-director role,
    // so a 403 here genuinely reflects a gate (and not the user being privileged).
    const mgr = await me(ctx, mgrToken)
    const role = String(mgr.role ?? '').toLowerCase()
    test.skip(
      role === 'admin' || role === 'director',
      `probe user resolved to "${role}" — not a low-privilege role, cannot assert the gate.`,
    )

    // Baseline: admin/director MUST keep read access to the directory.
    const adminRes = await ctx.get(`${API_URL}/api/admin/company-types`, {
      headers: bearer(adminToken),
    })
    expect(
      adminRes.ok(),
      `admin GET /api/admin/company-types → HTTP ${adminRes.status()} (admin must keep access)`,
    ).toBeTruthy()
    // Touch the payload so the admin baseline exercises the same read path.
    expect(Array.isArray(rowsOf(await adminRes.json()))).toBeTruthy()

    const mgrRes = await ctx.get(`${API_URL}/api/admin/company-types`, {
      headers: bearer(mgrToken),
    })

    // DESIRED: a non-admin/non-director may NOT read the admin directory.
    expect(
      mgrRes.status(),
      `manager1 GET /api/admin/company-types → HTTP ${mgrRes.status()} (must be 403: admin-only directory)`,
    ).toBe(403)
  })
})
