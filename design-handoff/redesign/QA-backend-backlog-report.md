# QA: Backend Backlog (12 features) — PASS

**Target:** http://localhost:5173  
**Date:** 2026-06-23 (re-test 2026-06-23, commit ec57660)  
**User:** b.yadykin@macroglobaltech.com (2FA off)  
**Browser:** Chrome MCP  
**Theme tested:** Dark (default dev environment)

---

## Re-test 2026-06-23 — 4 items (focused re-QA after fixes)

| # | Feature | Previous verdict | New verdict | Evidence |
|---|---------|-----------------|-------------|----------|
| 10 | FILES TAB | FAIL (BUG-FILES-1 crash) | **PASS** | Company Файлы: 3 system folders (Папка менеджера сделки, Сканы договоров, Папка ОКС). Сканы договоров selected → resolves, right panel shows "Нет файлов" (no crash). Upload button disabled (read-only gate). Create folder dialog works: POST /api/companies/2/folders → 201; folder appears, auto-selected. Contact Файлы: exactly 1 folder, no hang. No console errors. |
| 13 | DISCOUNT % | PARTIAL (no products in deal) | **PASS** | Added MacroSales CRM product (POST /api/deals/12/products → 201). Set Скидка=20 (Tab blur) → PATCH /api/deals/12 → 200, row shows ~~4 320 000 ₽~~ / 3 456 000 ₽, header total = 3 456 000 ₽. Typed 51 → clamped to 50. Reload persists: Скидка=50, ~~4 320 000 ₽~~ / 2 160 000 ₽. |
| 6 | SET-PRIMARY | PARTIAL (UI missing link) | **PASS** | Deal 13: "Тестовый" contact row shows "Сделать основным" link. Click → PATCH /api/deals/13/contacts/3 → 200; toast "Основной контакт обновлён"; Тестовый gets "Основной" badge; Алексей demoted to "Сделать основным". Only one badge at a time. |
| 7 | LOG I18N | MINOR (raw keys) | **PASS** | Deal 13 Активность: "Система добавил контакт" (×2), "Система создал запись" — all human-readable RU labels, no raw `crm.log.events.*` strings. useEntityLogFormat.eventLabel fallback confirmed: unknown keys → "выполнил действие", not raw key. |

All 4 re-tested items: **PASS**. No console errors from any of the 4 features.

---

---

## Per-item table (original pass 2026-06-23, updated with re-test)

| # | Feature | Verdict | Evidence |
|---|---------|---------|----------|
| 1 | EMPLOYEE STATUS | PASS | Сотрудники tab ⋮ → "Изменить статус" picker opens; PATCH /api/companies/1/employees/{contact_id} → 200; row badge updates to "Уволен"/"Работает" without reload |
| 2 | WON_COUNT | PASS | Company KPI strip "Сделок выиграно: 3" rendered from `kpi.won_count`; GET /api/companies/1/kpi → 200, `won_count: 3` |
| 3 | DOCUMENTS SCOPED | PASS | Company Документы tab calls GET /api/documents?source_company_id=1; only company-1 docs shown; tab badge count=3 matches API total |
| 4+5 | PAYMENT | PASS | Deal Финансы tab: amount input editable, currency picker (RUB/KZT/USD/EUR) works; "Зафиксировать оплату" → PATCH /api/deals/13 → 200; reload persists paid_amount + payment_currency |
| 6 | SET-PRIMARY | ~~PARTIAL~~ **PASS** | **FIXED (re-test 2026-06-23):** "Сделать основным" link visible on non-primary contact rows. Click → PATCH /api/deals/13/contacts/3 → 200; toast "Основной контакт обновлён"; single badge invariant maintained. |
| 7 | METRICS / LOG I18N | ~~MINOR BUG~~ **PASS** | **FIXED (re-test 2026-06-23):** Log entries show "добавил контакт" / "создал запись" — no raw keys. useEntityLogFormat.eventLabel fallback confirmed. |
| 8 | LIST SORTING | PASS | Funnel list view column headers have sort arrows; clicking cycles asc→desc→none; GET /api/deals?sort_by=name&sort_dir=asc → 200, order changes in UI |
| 9 | HIDDEN STATUSES | PASS | Pipeline settings toggle hidden_by_default → PATCH /api/pipelines/1/stages/{sid} → 200; kanban hides stage; funnel filter expander "Скрытые статусы" reveals hidden stage with count; per-stage toggle re-shows on board. Session-level only (resets on reload — expected per spec) |
| 10 | FILES TAB | ~~FAIL~~ **PASS** | **FIXED (re-test 2026-06-23):** mimeIcon null guard applied. 3 system folders load, Сканы договоров resolves, upload disabled (read-only). Create folder POST 201. Contact: 1 folder, no hang. 0 console errors. |
| 13 | DISCOUNT % | ~~PARTIAL~~ **PASS** | **FULLY VERIFIED (re-test 2026-06-23):** Added product, set 20% → ~~4 320 000~~ / 3 456 000. Clamped 51→50. Reload persists. PATCH 200. |
| 14 | COMPANY CHANGE | PASS | Hover over company field → pencil icon appears; click → popover with search + company list; select "ТехноПарк" → PATCH /api/deals/13 → 200; field updates immediately; "Изменить компанию" toast shown |

---

## Overall verdict: PARTIAL PASS

10 of 12 items pass. 2 items have bugs:

- **Item 6 (SET-PRIMARY):** Backend fully correct. UI missing "Сделать основным" action in contact context menu.
- **Item 10 (FILES TAB):** Critical runtime crash when selecting "Сканы договоров" folder.

---

## Bugs requiring fix

### BUG-FILES-1 — BLOCKER (Item 10) — RESOLVED
~~**Error:** TypeError: Cannot read properties of null (reading 'startsWith')~~
**Fix applied:** `mimeIcon(mime: string | null | undefined)` null guard in `EntityFilesTab.vue`. Verified passing in re-test.

### BUG-SET-PRIMARY-1 — MEDIUM (Item 6) — RESOLVED
~~**Error:** contact ⋮ menu only shows "Отвязать" — no "Сделать основным" action~~
**Fix applied:** "Сделать основным" inline link rendered on non-primary contact rows. Verified passing in re-test.

### BUG-LOG-I18N — MINOR (Item 7) — RESOLVED
~~**Error:** Deal Активность log entries show "Система crm.log.events.undefined"~~
**Fix applied:** `crm.log.events.*` keys added to `ru.json`/`en.json`; `useEntityLogFormat.ts` fallback prevents raw-key leakage. Verified passing in re-test.

---

## Console errors

**Original pass:** `TypeError: Cannot read properties of null (reading 'startsWith')` (×10) — now resolved.
**Re-test 2026-06-23:** 0 errors from any of the 4 re-tested features. 2×AxiosError 404 from deliberate navigation to non-existent deal/14 (expected, handled gracefully with "Сделка не найдена" page).

---

## Network 4xx/5xx

None (excluding deliberate /deals/14 404 test).

Key confirmed calls (original + re-test):
- `PATCH /api/companies/1/employees/{id}` → 200 (employee status)
- `GET /api/companies/1/kpi` → 200 (won_count)
- `GET /api/documents?source_company_id=1` → 200 (scoped docs)
- `PATCH /api/deals/13` → 200 (payment, company change, stage)
- `PATCH /api/deals/13/contacts/3` → 200 (set-primary re-test)
- `POST /api/companies/2/folders` → 201 (create folder)
- `GET /api/companies/2/folders` → 200 (folder reload)
- `GET /api/companies/2/folders/11/files` → 200 (folder contents)
- `POST /api/deals/12/products` → 201 (add product)
- `PATCH /api/deals/12` → 200 (discount save)
- `GET /api/deals/13` → 200 (metrics in response)
- `GET /api/deals?sort_by=...&sort_dir=...` → 200 (sorting)
- `PATCH /api/pipelines/1/stages/{sid}` → 200 (hidden statuses)

---

## Fix-actions for frontend-specialist

All 3 fix-actions from original report have been applied and verified. No remaining actions.

---

## Smoke regression (re-test 2026-06-23)

No regressions observed:
- `/companies/2` (Файлы tab — 3 system folders, create folder) — stable
- `/contacts/3` (Файлы tab — 1 system folder) — stable
- `/deals/12` (Основное — products + discount 50%, Активность — empty state OK) — stable
- `/deals/13` (Контакты — set-primary, Активность — log i18n) — stable
