# Regression locks — confirmed audit blockers (2026-06-24)

These specs **lock the confirmed blockers** from the full system audit (`docs/audit/00-MASTER.md`)
so a fix can be proven and the bug cannot silently return.

## How the lock works (read before touching these)

Each test asserts the **correct / desired** behaviour (what it should be *after* the fix) and is
marked expected-to-fail on its first body line:

```ts
test('AUDIT <domain>#<idx> — <short>', async () => {
  test.fail(true, 'AUDIT <domain>#<idx>: <title>. RED until fixed — remove this line when it starts passing.')
  // ...assertions of the CORRECT behaviour...
})
```

- **While the bug is live** the assertions fail → Playwright reports an **expected failure** → the
  suite stays green and CI is usable.
- **When the fix lands** the test starts passing → Playwright reports **"expected to fail but passed"**
  → the suite turns red. That is the signal: **remove the `test.fail()` line** to permanently lock the fix.

So the lifecycle of every lock is: `expected-fail (bug live)` → `unexpected-pass (fix landed, remove annotation)` → `pass (locked)`.

`test.skip(...)` is used only when the precondition data genuinely can't be found at runtime
(e.g. empty DB, no discounted deal) — never to hide a real failure.

## Specs

| Spec | Audit IDs | Locks |
|---|---|---|
| `visibility-leaks.spec.ts` | crm-contacts#0, crm-companies#0, crm-contacts#1, crm-companies#0 (export), iam NEW-5 | List/export of contacts & companies must be owner-scoped (manager sees fewer than admin; export forbidden without scope); `/api/admin/*` directories must be admin/director-gated |
| `money-discount.spec.ts` | sales-deals#0 | Deal-level `discount_percent` must be folded into `deals.amount` (discounted deal's amount < gross) |
| `onboarding-lesson.spec.ts` | onboarding#0 | Student lesson payload must include non-empty lesson content (text/video/pdf body) |

## Running

```bash
cd e2e
npm run test:chromium                 # whole suite
npx playwright test tests/regression  # just the locks
```

Requires the dev stack up. UI baseURL = Vite `:5173`, API = nginx `:8080` (override via `E2E_BASE_URL` / `E2E_API_URL`).
Data-driven: no hardcoded IDs — tests discover counts/records at runtime via the API (`../../lib/api.ts`).
