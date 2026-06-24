import { test, expect, type APIRequestContext } from '@playwright/test'
import { apiContext, login, bearer, rowsOf, API_URL, USERS } from '../../lib/api'

/**
 * REGRESSION LOCK — money-correctness blocker (deal-level discount_percent).
 *
 * Audit source: docs/audit/domains/sales-deals.md
 *   - B0 · blocker · BUG — deal-level `discount_percent` is IGNORED in
 *     `deals.amount`; every money aggregate (board/list/KPI/company/contact/
 *     export/FE cards) over-reports revenue for any discounted deal.
 *     Evidence: DealService::recalcAmount (src/.../DealService.php:1289-1303)
 *     sets deals.amount = SUM(deal_products.amount) WITHOUT folding in
 *     discount_percent; DealService::update never re-runs recalcAmount on a
 *     discount_percent change. The percent is applied ONLY in
 *     DealResource::discountedTotals() (src/.../DealResource.php:171-198) as a
 *     display-only `products_net_total`; the canonical `deals.amount` stays GROSS.
 *
 * Field names / units confirmed against the resources (do NOT guess):
 *   DealResource (src/app/Http/Resources/Sales/DealResource.php):
 *     - amount               : int kopecks (GROSS today — should be NET)        :19
 *     - discount_percent     : int 0..50, deal-level discount                   :23
 *     - amount_locked        : bool — when true amount is a fixed budget,       :26
 *                              NOT re-derived from line items (recalc skipped)
 *     - products[]           : DealProductResource (only whenLoaded — on SHOW)  :121
 *     - products_gross_total : int kopecks = Σ line.amount (== amount unlocked) :135/193
 *     - products_net_total   : int kopecks = Σ round(line.amount*(100-pct)/100) :135/195
 *   DealProductResource (src/app/Http/Resources/Sales/DealProductResource.php):
 *     - amount               : int kopecks, net of the per-line discount        :33
 *   recalcAmount (DealService.php:1289-1303): deals.amount = SUM(line.amount),
 *     discount_percent NOT applied → that is the bug being locked.
 *
 * What this file locks:
 *   sales-deals#0 — a deal whose discount_percent > 0 must report
 *   amount == round(Σ line.amount * (1 - pct/100)) (== products_net_total),
 *   i.e. amount < products_gross_total. Today amount stays gross → expected-fail.
 *
 * REGRESSION-LOCK CONVENTION:
 *   Each test asserts the CORRECT/DESIRED post-fix behaviour and calls
 *   `test.fail(...)` on its first line, so the suite stays green while the bug
 *   is live (reported as an "expected failure"). When the fix lands the test
 *   PASSES and Playwright flags "expected to fail but passed" → that is the
 *   signal to delete the matching `test.fail()` line and lock the fix in.
 *
 * Runtime is READ-ONLY: only the login POST + HTTP GET. No business data is
 * created/updated/deleted. The discounted deal is discovered live — no
 * hardcoded ids (seeded deals #12=30%, #13=50% are expected to exist).
 */

let ctx: APIRequestContext

test.beforeAll(async () => {
  ctx = await apiContext()
})

test.afterAll(async () => {
  await ctx?.dispose()
})

/** Round half-away-from-zero on non-negative kopecks, matching PHP round() in
 *  DealResource::discountedTotals (round($lineGross * (100 - $pct) / 100)). */
function lineNet(lineGross: number, pct: number): number {
  return Math.round((lineGross * (100 - pct)) / 100)
}

test('AUDIT sales-deals#0 — deal discount_percent folded into deals.amount', async () => {
  test.fail(
    true,
    'AUDIT sales-deals#0 (B0 blocker): deal-level discount_percent is ignored in deals.amount; ' +
      'recalcAmount sums gross line amounts and update() never re-runs recalc on a discount change, ' +
      'so every money aggregate over-reports a discounted deal. RED until fixed — when this starts ' +
      'PASSING, remove this test.fail() line to lock the fix.',
  )

  const token = await login(ctx, USERS.admin)

  // Discover deals live. per_page max = 100 (IndexDealRequest:107) ≥ 13 seeded deals.
  const listRes = await ctx.get(`${API_URL}/api/deals?view=list&per_page=100`, {
    headers: bearer(token),
  })
  expect(listRes.ok(), `GET /api/deals → HTTP ${listRes.status()}`).toBeTruthy()
  const rows = rowsOf(await listRes.json())
  expect(rows.length, 'expected at least one deal in the list').toBeGreaterThan(0)

  // First deal whose deal-level discount_percent > 0 and whose amount is
  // recalc-driven (amount_locked === false → amount is derived from line items;
  // a locked deal is a fixed budget by design and the discount fold does not apply).
  const discounted = rows.find(
    (d: any) => Number(d?.discount_percent ?? 0) > 0 && d?.amount_locked === false,
  )

  if (!discounted) {
    test.skip(
      true,
      'No unlocked deal with discount_percent>0 found at runtime (seeded #12=30%, #13=50% expected). ' +
        'Cannot probe the money-correctness blocker without one.',
    )
    return
  }

  const dealId = discounted.id
  const pct = Number(discounted.discount_percent)

  // Detail view loads the products relation → exposes products_gross_total /
  // products_net_total plus each line's net amount (kopecks).
  const detailRes = await ctx.get(`${API_URL}/api/deals/${dealId}`, { headers: bearer(token) })
  expect(detailRes.ok(), `GET /api/deals/${dealId} → HTTP ${detailRes.status()}`).toBeTruthy()
  const body = await detailRes.json()
  const deal = (body?.data ?? body) as any

  const amount = Number(deal.amount) // kopecks, canonical deal value
  const grossTotal = Number(deal.products_gross_total)
  const netTotal = Number(deal.products_net_total)
  const lines = Array.isArray(deal.products) ? deal.products : []

  // Sanity: this deal genuinely carries a discount and line items to discount.
  expect(pct, 'discovered deal must carry a deal-level discount').toBeGreaterThan(0)
  expect(lines.length, `deal #${dealId} must have line items to apply the discount to`).toBeGreaterThan(0)

  // Independent recompute from raw line items, using the SAME per-line rounding
  // the resource documents (round per line, then sum). Confirms the server's
  // products_net_total is the value `amount` ought to equal.
  const grossFromLines = lines.reduce((s: number, l: any) => s + Number(l.amount), 0)
  const expectedNet = lines.reduce((s: number, l: any) => s + lineNet(Number(l.amount), pct), 0)

  expect(grossFromLines, 'Σ line.amount should equal products_gross_total').toBe(grossTotal)
  expect(expectedNet, 'recomputed net should match server products_net_total').toBe(netTotal)
  // A real discount must actually reduce the total (guards against a 0%/empty edge).
  expect(netTotal, 'discounted net total must be below the gross total').toBeLessThan(grossTotal)

  // DESIRED post-fix behaviour: the canonical deals.amount equals the discounted
  // net (== products_net_total), within ±1 kopeck of rounding. Today amount stays
  // gross (== products_gross_total) → these assertions are expected to FAIL.
  expect(
    Math.abs(amount - expectedNet),
    `deal #${dealId} amount (${amount}) should equal discounted net (${expectedNet}) ±1 kopeck — ` +
      `currently it stays gross (${grossTotal})`,
  ).toBeLessThanOrEqual(1)

  expect(
    amount,
    `deal #${dealId} amount (${amount}) must be below gross subtotal (${grossTotal}) for a ${pct}% discount`,
  ).toBeLessThan(grossTotal)
})
