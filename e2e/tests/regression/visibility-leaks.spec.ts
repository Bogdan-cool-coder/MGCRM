import { test, expect, type APIRequestContext } from '@playwright/test'
import { inflateRawSync } from 'zlib'
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
 *   Each test asserts the CORRECT/DESIRED post-fix behaviour. Tests whose bugs are
 *   fixed carry no test.fail() — they are hard locks. Tests for still-open issues
 *   carry test.fail() so the suite stays green while the bug is live.
 */

/**
 * Extract a named file from a ZIP buffer (handles deflate compression, method 8).
 * Returns null if the entry is not found.
 */
function extractZipEntry(buf: Buffer, targetName: string): Buffer | null {
  let i = 0
  while (i < buf.length - 4) {
    // Local file header signature: PK\x03\x04
    if (buf[i] === 0x50 && buf[i + 1] === 0x4b && buf[i + 2] === 0x03 && buf[i + 3] === 0x04) {
      const compression = buf.readUInt16LE(i + 8)
      const compressedSize = buf.readUInt32LE(i + 18)
      const fnLen = buf.readUInt16LE(i + 26)
      const extraLen = buf.readUInt16LE(i + 28)
      const fn = buf.subarray(i + 30, i + 30 + fnLen).toString('utf8')
      const dataStart = i + 30 + fnLen + extraLen
      if (fn === targetName) {
        const compressed = buf.subarray(dataStart, dataStart + compressedSize)
        // compression 0 = stored, 8 = deflate
        return compression === 8 ? inflateRawSync(compressed) : compressed
      }
      i = dataStart + compressedSize
    } else {
      i++
    }
  }
  return null
}

/**
 * Count data rows (r >= 2) in an xlsx binary.
 * An xlsx is a ZIP containing xl/worksheets/sheet1.xml (deflate-compressed).
 * Row 1 is always the header; data rows start at r="2".
 */
function xlsxDataRows(buf: Buffer): number {
  const sheet = extractZipEntry(buf, 'xl/worksheets/sheet1.xml')
  if (!sheet) return 0
  const xml = sheet.toString('utf8')
  const matches = [...xml.matchAll(/<row\b[^>]+\br="(\d+)"/g)]
  return matches.filter(m => parseInt(m[1], 10) > 1).length
}

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
  //    FIXED: ContactService::list applies VisibilityResolver.applyScope().
  // ---------------------------------------------------------------------------
  test('AUDIT crm-contacts#0 — GET /api/contacts is owner-scoped (manager sees fewer than admin)', async () => {
    const adminRes = await ctx.get(`${API_URL}/api/contacts?per_page=100`, {
      headers: bearer(adminToken),
    })
    expect(adminRes.ok(), `admin GET /api/contacts → HTTP ${adminRes.status()}`).toBeTruthy()
    const adminTotal = totalOf(await adminRes.json())

    const mgrRes = await ctx.get(`${API_URL}/api/contacts?per_page=100`, {
      headers: bearer(mgrToken),
    })
    expect(mgrRes.ok(), `manager1 GET /api/contacts → HTTP ${mgrRes.status()}`).toBeTruthy()
    const mgrTotal = totalOf(await mgrRes.json())

    // Precondition: there must be contacts in the system for the scope test to mean anything.
    test.skip(adminTotal === 0, 'No contacts in the system — nothing to scope-check.')

    // LOCKED: a manager who owns ~0 contacts must NOT see the whole table.
    expect(
      mgrTotal,
      `manager1 sees ${mgrTotal} contacts vs admin's ${adminTotal} — a manager owning ~0 must see strictly fewer`,
    ).toBeLessThan(adminTotal)
  })

  // ---------------------------------------------------------------------------
  // 2) AUDIT crm-companies#0 — GET /api/companies must be owner-scoped.
  //    FIXED: CompanyService::list applies VisibilityResolver.applyScope().
  // ---------------------------------------------------------------------------
  test('AUDIT crm-companies#0 — GET /api/companies is owner-scoped (manager sees fewer than admin)', async () => {
    const adminRes = await ctx.get(`${API_URL}/api/companies?per_page=100`, {
      headers: bearer(adminToken),
    })
    expect(adminRes.ok(), `admin GET /api/companies → HTTP ${adminRes.status()}`).toBeTruthy()
    const adminTotal = totalOf(await adminRes.json())

    const mgrRes = await ctx.get(`${API_URL}/api/companies?per_page=100`, {
      headers: bearer(mgrToken),
    })
    expect(mgrRes.ok(), `manager1 GET /api/companies → HTTP ${mgrRes.status()}`).toBeTruthy()
    const mgrTotal = totalOf(await mgrRes.json())

    test.skip(adminTotal === 0, 'No companies in the system — nothing to scope-check.')

    // LOCKED: a manager who owns ~0 companies must NOT see the whole table.
    expect(
      mgrTotal,
      `manager1 sees ${mgrTotal} companies vs admin's ${adminTotal} — a manager owning ~0 must see strictly fewer`,
    ).toBeLessThan(adminTotal)
  })

  // ---------------------------------------------------------------------------
  // 3) AUDIT crm-contacts#1 — POST /api/contacts/export scoped invariant.
  //    FIXED: ContactExportService::buildXlsx calls VisibilityResolver.applyScope()
  //    before the whereIn filter — empty contact_ids exports the actor's visible set,
  //    never the full table.
  //
  //    Scoped invariant: manager's empty-selection export must have FEWER data rows
  //    than admin's, and must equal the manager's visible list total (0 for manager1).
  // ---------------------------------------------------------------------------
  test('AUDIT crm-contacts#1 — POST /api/contacts/export empty as manager1 returns only visible rows', async () => {
    // Discover admin's visible list total first (ground truth).
    const adminListRes = await ctx.get(`${API_URL}/api/contacts?per_page=100`, {
      headers: bearer(adminToken),
    })
    expect(adminListRes.ok()).toBeTruthy()
    const adminListTotal = totalOf(await adminListRes.json())
    test.skip(adminListTotal === 0, 'No contacts in the system — nothing to scope-check.')

    // Discover manager1's visible list total (expected export row count).
    const mgrListRes = await ctx.get(`${API_URL}/api/contacts?per_page=100`, {
      headers: bearer(mgrToken),
    })
    expect(mgrListRes.ok()).toBeTruthy()
    const mgrListTotal = totalOf(await mgrListRes.json())

    // Admin export: empty ids → all visible = adminListTotal rows.
    const adminExportRes = await ctx.post(`${API_URL}/api/contacts/export`, {
      headers: { ...bearer(adminToken), 'Content-Type': 'application/json' },
      data: { contact_ids: [] },
    })
    expect(adminExportRes.ok(), `admin POST /api/contacts/export → HTTP ${adminExportRes.status()}`).toBeTruthy()
    const adminExportRows = xlsxDataRows(await adminExportRes.body())

    // Manager1 export: empty ids → only manager1's visible contacts.
    const mgrExportRes = await ctx.post(`${API_URL}/api/contacts/export`, {
      headers: { ...bearer(mgrToken), 'Content-Type': 'application/json' },
      data: { contact_ids: [] },
    })
    expect(mgrExportRes.ok(), `manager1 POST /api/contacts/export → HTTP ${mgrExportRes.status()}`).toBeTruthy()
    const mgrExportRows = xlsxDataRows(await mgrExportRes.body())

    // LOCKED — no PII leak: manager export must have fewer rows than admin.
    expect(
      mgrExportRows,
      `manager1 export has ${mgrExportRows} data rows vs admin's ${adminExportRows} — scoped export must contain fewer rows`,
    ).toBeLessThan(adminExportRows)

    // LOCKED — exact match with list total: export row count equals visible list total.
    expect(
      mgrExportRows,
      `manager1 export data rows (${mgrExportRows}) must equal manager1's list total (${mgrListTotal})`,
    ).toBe(mgrListTotal)
  })

  // ---------------------------------------------------------------------------
  // 4) AUDIT crm-companies#0 (export) — POST /api/companies/export scoped invariant.
  //    FIXED: CompanyExportService::buildXlsx calls VisibilityResolver.applyScope()
  //    — empty company_ids exports only the actor's visible companies.
  //
  //    Scoped invariant: manager's empty-selection export must have FEWER data rows
  //    than admin's, and must equal the manager's visible list total (2 for manager1).
  // ---------------------------------------------------------------------------
  test('AUDIT crm-companies#0 — POST /api/companies/export empty as manager1 returns only visible rows', async () => {
    // Discover admin's visible list total.
    const adminListRes = await ctx.get(`${API_URL}/api/companies?per_page=100`, {
      headers: bearer(adminToken),
    })
    expect(adminListRes.ok()).toBeTruthy()
    const adminListTotal = totalOf(await adminListRes.json())
    test.skip(adminListTotal === 0, 'No companies in the system — nothing to scope-check.')

    // Discover manager1's visible list total.
    const mgrListRes = await ctx.get(`${API_URL}/api/companies?per_page=100`, {
      headers: bearer(mgrToken),
    })
    expect(mgrListRes.ok()).toBeTruthy()
    const mgrListTotal = totalOf(await mgrListRes.json())

    // Admin export: empty ids → all visible companies.
    const adminExportRes = await ctx.post(`${API_URL}/api/companies/export`, {
      headers: { ...bearer(adminToken), 'Content-Type': 'application/json' },
      data: { company_ids: [] },
    })
    expect(adminExportRes.ok(), `admin POST /api/companies/export → HTTP ${adminExportRes.status()}`).toBeTruthy()
    const adminExportRows = xlsxDataRows(await adminExportRes.body())

    // Manager1 export: empty ids → only manager1's visible companies.
    const mgrExportRes = await ctx.post(`${API_URL}/api/companies/export`, {
      headers: { ...bearer(mgrToken), 'Content-Type': 'application/json' },
      data: { company_ids: [] },
    })
    expect(mgrExportRes.ok(), `manager1 POST /api/companies/export → HTTP ${mgrExportRes.status()}`).toBeTruthy()
    const mgrExportRows = xlsxDataRows(await mgrExportRes.body())

    // LOCKED — no leak: manager export must have fewer rows than admin.
    expect(
      mgrExportRows,
      `manager1 export has ${mgrExportRows} data rows vs admin's ${adminExportRows} — scoped export must contain fewer rows`,
    ).toBeLessThan(adminExportRows)

    // LOCKED — exact match: manager export rows equal manager's visible list count.
    expect(
      mgrExportRows,
      `manager1 export data rows (${mgrExportRows}) must equal manager1's list total (${mgrListTotal})`,
    ).toBe(mgrListTotal)
  })

  // ---------------------------------------------------------------------------
  // 5) NEW-5 (iam) — an /api/admin/* directory endpoint must be admin/director
  //    gated. iam.md NEW-5 + crm-*.md NEW-5: manager1 GETs /api/admin/company-types
  //    (and sources/countries/etc.) → 200. These index/show routes call no
  //    authorize(). Confirmed path: GET /api/admin/company-types (api.php:380).
  // ---------------------------------------------------------------------------
  test('AUDIT iam#NEW-5 — /api/admin/* directory: READ open to authed, WRITE admin-gated', async () => {
    // Corrected contract (re-audit Wave 4): the Wave-1 fix over-gated and 403'd READS
    // that managers legitimately need (filter dropdowns / labels). Reference-directory
    // index/show are now open to any authenticated user; only store/update/destroy are
    // admin-gated (can:admin-write). The security property is WRITE-gating.
    const mgr = await me(ctx, mgrToken)
    const role = String(mgr.role ?? '').toLowerCase()
    test.skip(
      role === 'admin' || role === 'director',
      `probe user resolved to "${role}" — not a low-privilege role, cannot assert the gate.`,
    )

    // Baseline: admin keeps full access.
    const adminRes = await ctx.get(`${API_URL}/api/admin/company-types`, {
      headers: bearer(adminToken),
    })
    expect(adminRes.ok(), `admin GET → HTTP ${adminRes.status()}`).toBeTruthy()
    expect(Array.isArray(rowsOf(await adminRes.json()))).toBeTruthy()

    // READ is open to any authenticated user (managers need reference directories).
    const mgrRead = await ctx.get(`${API_URL}/api/admin/company-types`, {
      headers: bearer(mgrToken),
    })
    expect(
      mgrRead.status(),
      `manager1 GET /api/admin/company-types → HTTP ${mgrRead.status()} (read must be open: 200)`,
    ).toBe(200)

    // WRITE remains admin-gated — the gate runs before validation, so a non-admin is 403'd.
    const mgrWrite = await ctx.post(`${API_URL}/api/admin/company-types`, {
      headers: bearer(mgrToken),
      data: { name: '__e2e_lock_probe_should_be_forbidden__' },
    })
    expect(
      mgrWrite.status(),
      `manager1 POST /api/admin/company-types → HTTP ${mgrWrite.status()} (write must be 403)`,
    ).toBe(403)
  })
})
