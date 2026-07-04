# Полный системный аудит + план оптимизации (2026-07-04)

**HEAD:** `3156529` · **Дата:** 2026-07-04 · **Прод:** `mgcrm.macroglobal.tech`
**Тип:** сплошной read-only суип 11 агентов + живой QA-смоук прода (обе темы).

> Этот документ — одновременно **аудит «ДО»** и **закон исполнения** (§6 — этапы Э1…Э12). После каждого этапа: полный PHPUnit-сьют + гейты (type-check / eslint / lint:ds / qa-tester где UI) → коммит. Колонка «после» в §8 заполняется в финальном отчёте.

---

## §1. Методика и охват

11 read-only агентов (никто не правил код), каждый со своим измерением:

| # | Агент/измерение | Скоуп |
|---|---|---|
| 1 | **beSales** | backend `Domain/Sales` + `Domain/SalesPulse` + `app/Support` (158 PHP / ~22 900 LOC) |
| 2 | **beCrm** | backend `Domain/Crm` + `Catalog` + `Org` + `Iam` + `Log` (108 PHP / ~12 450 LOC, 45 контроллеров) |
| 3 | **beDormant** | backend спящие/known-broken: `Activity` `Automation` `Contracts` `Inbox` `Migration` `Notification` `Onboarding` + `config/`+`routes/`+`bootstrap/`+`app/Console` (~232 PHP) |
| 4 | **fePages** | `front/src/pages/**` + page-composables/components (322 .vue + 126 .ts) — DS-конформность + баги/мёртвый код |
| 5 | **feArch** | `front/src` архитектура: api/entities/composables/stores/components/router/theme/locales/utils (370 .vue + 287 .ts) |
| 6 | **db** | 142 миграции / 105 таблиц / 91 модель / 37 сидеров; `$fillable`/`$casts` vs live-схема |
| 7 | **api** | 464 роута ↔ 383 FE call-site (30 api-клиентов) ↔ 4 docs/contracts |
| 8 | **perf** | кросс-стек: query-паттерны, агрегаты, кэш, экспорты, broadcast-fan-out; фронт-бандл/fetch/watch |
| 9 | **repoStruct** | корневые файлы, `docs/**`, `design-handoff/**`, `.claude/**`, `docker/`, `.github/`, `brand/` |
| 10 | **health-baseline** | сьют/type-check/lint/lint:ds — состояние «ДО» |
| 11 | **QA-смоук прода** | ~28 экранов на живом проде, обе темы, Playwright MCP |

Плюс отдельный **DS2-интейк** (`.result.ds2`) — дельта нового дизайн-экспорта.

Каждое измерение сверялось с baseline'ом прошлых аудитов (`docs/audit/*` + `docs/audit/domains/*` + `REMAINING.md`) — важная часть работы была **верификацией, закрыты ли прошлые блокеры в коде** (§3).

---

## §2. Health-baseline «ДО»

| Гейт | Результат |
|---|---|
| **PHPUnit** (`app php artisan test`, SQLite `:memory:`) | **PASS — 3944 passed / 0 failed / 0 skipped** (11 397 assertions, 280 s) |
| **vue-tsc** (`npm run type-check`) | PASS — 0 ошибок (~15.6 s) |
| **eslint** (`npm run lint`) | PASS — 0 warn/err (~5.3 s) |
| **lint:ds** (`npm run lint:ds`, stylelint) | PASS — 0 (~0.7 s) |
| **QA-смоук прода** | ⚠️ **PARTIAL PASS** — ~28 экранов, 2 FAIL (см. §4) |

### Итог по находкам (139 total)

| Severity | Кол-во | Новых (не из прошлых аудитов) | Известных |
|---|---|---|---|
| **critical** | **0** | — | — |
| **high** | **13** | 12 | 1 |
| **medium** | **42** | 39 | 3 |
| **low** | **84** | 62 | 22 |
| **ИТОГО** | **139** | **113** | 26 |

**Мёртвый код:** 77 позиций (54 безопасны к удалению сразу, 23 требуют tooling/product-решения).
**Quick wins:** 65 (в основном 1-строчники, замапленные на этапы §6).

> ⚠️ Расхождение с ТЗ: заявлено «medium 43» — фактически в JSON **42**. По severity: high 13 ✓, low 84 ✓, dead 77 ✓, новых 113 ✓ (12 high + 39 medium + 62 low). Ниже везде — 42.

### Хедлайн: **critical = 0.** Кор-код зрелый, прошлые блокеры закрыты (§3). Оставшееся — финансовая точность фактов (soft-delete/scope), token-lifecycle, транзакционные side-effects, dark-долг и мета-долг доков.

---

## §3. Сводка по измерениям

### 3.0 Закрытые прошлые блокеры (главная новость отчёта)

Аудиторы **верифицировали в текущем коде**, что headline-блокеры прошлых аудитов реально закрыты:

- **beSales:** B0 (deal-level `discount_percent` свёрнут в `deals.amount` через `DealAmountCalculator` + `recalcAmount`); dashboard-trend soft-delete bug (`computeTrendsFromPrev` через `baseQuery`); m6/m9/m10; SalesPulse vacation/announcer/snapshot-race — все с защитными комментариями и тестами; PollLock self-healing single-poller.
- **beCrm:** PII-листы мертвы (contact/company list+export через `VisibilityResolver::applyScope` + authorize на export); dedup-merge реально re-parent'ит 8+ FK-таблиц транзакционно; **FX-подсистема жива** (dual-provider, loud fail на `success:false`, 120 live-курсов, refresh+template роуты); login/2FA — failures-only throttle (429+Retry-After); 2FA disable/regenerate за вторым фактором; полный user edit/deactivate/reset; nested channel/relation/plan/price роуты с child-ownership guard; price-import `/preview`; Access-Control-правки в `entity_logs`. **17/17 блокеров скоупа закрыты.**
- **beDormant:** onboarding student-loop (content/quiz-routes/`is_draft`/publish-gate/AI-status); document child-IDOR через `abort_unless`; attempt double-increment; automation retention/pool/whitelist/catch-up; inbox `route()`-atomicity + channel-keyed throttle; activity FTM+report side-effects; migration mappings/rollback/verifier. **25+ known-item'ов подтверждены fixed.**
- **db:** `RolePermissionSeeder` идемпотентен (safe re-run на проде); `DemoDealsSeeder` больше не сидит fake-approved документы (только drafts); `TemplateVersionSeeder` идемпотентен (3 версии в dev).
- **api:** contact-channel IDOR закрыт in-controller; `ResolveVisibility` больше не vestigial (3 контроллера потребляют атрибут); 4 docs/contracts совпадают с реализацией.
- **feArch:** Inbox public-form UI существует (`/f/:slug` + `PublicLeadFormPage`); `iam#4 uploadAvatar` жив и wired; telegram-deeplink контракт fixed.
- **perf:** feed per_page clamps + bounded sources (F27); owner/author btree-индексы на `crm_contacts`/`crm_companies`; SQL-side dedup через `phone_normalized`; hub keep-alive; экспорты `chunkById 500`; broadcasts queued lean.

> **Но:** несколько прошлых записей `REMAINING.md` **стейл** — числятся open, а в коде закрыты (`contact-channel-idor`, `ResolveVisibility` vestigial). Правится в Э8.

### 3.1 beSales — 2 high · 4 medium · 13 low

| # | Title | file:line | fix | eff | risk | new? |
|---|---|---|---|---|---|---|
| H | Manager income fact считает soft-deleted **И** archived won-сделки (кабинет, MK-комиссии, team-gate) | `ManagerKpiService.php:262` (+`:313`) | +`whereNull(deleted_at)`+`archived_at` в обе; тест | S | low | ✓ |
| H | Plan-matrix / best-manager / stage-conversion / product-income facts включают soft-deleted | `PlanTargetService.php:365`, `BestManagerService.php:213`, `StageConversionService.php:128`, `ProductIncomeService.php:193` | +`whereNull(deleted_at)` ×4; извлечь `wonDealsBaseQuery()` | S | low | ✓ |
| M | Две конфликтующие revenue-recognition даты (`stage_changed_at` vs COALESCE effective-date) | `PlanTargetService.php:362` + ManagerKpi vs IncomeSchedule/BestManager/Conversion/ProductIncome | выбрать одну конвенцию → shared `WonDealDateExpr` | M | med | ✓ |
| M | `teamContributions()` игнорирует `$pipelineId` — MK team-gate/pool считает revenue со всех воронок | `WonDealsFactSource.php:32` | прокинуть `pipelineId` в `teamKpiBatch` | S | med | ✓ |
| M | R6 product-income суммирует `dp.amount` без deal-level `discount_percent` (2× при 50% скидке) | `ProductIncomeService.php:224` | `SUM(ROUND(dp.amount*(100-disc)/100))` | M | low | ✓ |
| M | Conversion report — 24+ COUNT/пару (N+1 по месяцам×сторонам); тот же паттерн в TaskMatrix | `ConversionReportService.php:131` | GROUP BY month; hoist pipeline pluck (см. Э9) | M | low | ✓ |

**Low (13):** dept-plan pct без FX-конвертации (latent, `MotivationCardService.php:341`); «Без даты оплаты» игнорит `product_group_id` (`ExpectedIncomeRegistryService.php:79`); residual M1 — restricted pipeline boardable через прямой API (`DealService.php:695`); SpaceCRM-gap «Общие» auto-conversion не реализован; R1 «Прямая/СБС» — tags-only эвристика (тег `sbs`); `lastTasksFor()` грузит все активности в память; Own/Dept/All scope hand-rolled в 5 report-сервисах вместо `VisibilityResolver`; `computeItemRow` lazy-load `$item->card` (N+1); `markPaid()` игнорит `$actor` (нет лога кто); exists-then-create race → 500; system-reset не покрывает новые planning/MK-таблицы; plan-matrix rows включают service-аккаунты (нет `is_service`); bulk-delete не транзакционен.

### 3.2 beCrm — 1 high · 5 medium · 15 low

| # | Title | file:line | fix | eff | risk | new? |
|---|---|---|---|---|---|---|
| H | Deactivate/reset-password не отзывают Sanctum-токены; нет `is_active`-чека на аутентиф. запросах | `UserService.php:114`, `PasswordService.php:40-67`, `Verify2FA.php:29` | `$user->tokens()->delete()` в deactivate/reset; опц. abort(403) в Verify2FA | S | low | ✓ |
| M | Contact-merge 500 на Postgres при related-контактах (UNIQUE/CHECK violation, SQLite скрывает) | `DedupService.php:694` | удалить collision-строки до re-parent; PG-shaped тесты | M | low | ✓ |
| M | Company-merge всё ещё orphan'ит company-activities и CRM files/folders | `DedupService.php:743` | +UPDATE activities/folders/files; +`acquisition_channel_history` в contact-merge | S | low | known |
| M | Companies KPI-бар считает dept-mates' компании, которых list/export/policy не показывают (drift reintro.) | `ContactsKpiService.php:125` | убрать `departmentColumn` из `applyCompanyScope` | S | low | ✓ |
| M | Удаление current-реквизита при siblings ломает one-current инвариант + stale denorm | `CompanyRequisiteService.php:161` | forbid delete-current (422) или auto-promote+re-mirror | S | low | ✓ |
| M | Company soft-delete разрешён при живых сделках — `deals.company_id` → trashed company | `CompanyService.php:333` | 409 если `Deal::where(company_id)->exists()` (как ProductService) | S | low | ✓ |

**Low (15):** FX default-провайдер trap (`access_key` vs `api_key`, `ExchangeRateService.php:157`); company `diffLoggedFields` без scalarize → phantom `data_changed` для `category_code`; bulk-log с actor NULL («Система»); merge phone-override оставляет `phone_normalized` stale; company-create не логируется (нет `LogAction::Created`); `expressCreate` broadcast'ит внутри транзакции (phantom при rollback); inline `$request->validate()` в bulk/convert (§7-blacklist); user-list hand-rolled LIKE в контроллерах; `DedupService::scanAll*`/`HoldingService` hand-roll Own-scope; Crm читает/пишет Sales-таблицы напрямую; custom-field `required`/`date` не enforced; disconnect fallback → 0 → FK violation; `findForDedup` phone — full-table PHP-скан; `mirrorToCompany` raw `DB::table` (no broadcast); pre-2FA temp-токен без TTL, TOTP replay в drift-окне.

### 3.3 beDormant — 1 high · 5 medium · 8 low

| # | Title | file:line | fix | eff | risk | new? |
|---|---|---|---|---|---|---|
| H | `on_create` automation claim'ится+queue'ится внутри незакоммиченной транзакции — action теряется навсегда | `DealService.php:1078` (createInbound) | вынести `DealCreated::dispatch` из транзакции / `afterCommit`; поправить docblock; sweep stale `pending` | S | low | ✓ |
| M | Систематика: queued side-effect джобы из sync-listener'ов внутри открытых транзакций (`after_commit=false`) | `ProgressService.php:124`, `ApprovalService.php:110/302/319/350/373/380` | `->afterCommit()` на 4 dispatch-сайта (не глобально) | S | low | ✓ |
| M | Approval-card idempotency-key никогда не сбрасывается — карты для stage 2+ и resubmit не шлются | `SendTelegramApprovalCardJob.php:64` | сброс `telegram_message_id` при выходе из `in_review`; ключ per-(attempt,stage) | S | low | ✓ |
| M | `on_create` срабатывает только для inbound-сделок — ручное/UI-создание не dispatch'ит `DealCreated` | `DealService.php:916` | dispatch post-commit из `create` (idempotency-index дедупит); проверить board-realtime | M | med | ✓ |
| M | `unsign()` обходит статус-машину: прямой status-write, нет lock/`canTransitionTo`/entity-log | `DocumentService.php:690` | Signed→Approved как явный edge + через `transition()`; или lock+`recordContractEvent` | S | med | ✓ |
| M | QA student-seeding-команда («dev only, never prod») без env-guard | `SeedQaStudentCommand.php:30` | `if (isProduction() && !force) fail` (как ResetCleanCommand) | S | low | ✓ |

**Low (8):** regeneration перезаписывает contract.docx/pdf in-place (revisions указывают на mutable-файлы); inbound owner-fallback = «first user by id» вместо «first admin/director» (`InboundRoutingService.php:221`); **VERIFIED-STATUS** ×5 (contracts generation dead в проде `template_versions=0` — код здоров; public-form без SPA-page; AMO ETL dormant но wired; notification deep-links здоровы, Telegram cold e2e; meeting-report/FTM code-complete но cold); stale docstring про per-run Retry.

### 3.4 fePages — 2 high · 7 medium · 9 low

| # | Title | file:line | fix | eff | risk | new? |
|---|---|---|---|---|---|---|
| H | Wrong-step `.app-dark` overrides → dark-on-dark текст на ВСЕХ страницах вне навигационного CRM/Sales-свипа (LIVE-CONFIRMED) | `TasksTopBar.vue:241` (+кластеры MyTasks/Inbox/Settings/DealPage-диалоги) | расширить navy-свип; правило: удалять wrong-step override поверх reactive-базы; whitelist inverse-contrast | L | med | ✓ |
| H | Document/attachment/revision downloads всё ещё no-Bearer `window.open` — 401 на каждый клик | `useDocumentPage.ts:221`, `DocumentAttachmentsTab.vue:225`, `DocumentRevisionsTab.vue:94/99`, `CompanyDocumentsTab.vue:143` | заменить на blob-хелперы; удалить deprecated | S | low | ✓ |
| M | 12 страниц монтируют локальный `<Toast>` поверх layout-глобала → каждый toast рендерится 2× (live) | `MyTasksPage/index.vue:143` (+11) | удалить page-local Toast'ы | S | low | ✓ |
| M | 14 файлов монтируют второй `<ConfirmDialog>` рядом с layout-глобалом → 2 диалога | `CustomFieldsPage/index.vue:141` (+13) | удалить page-local ConfirmDialog'и | S | low | ✓ |
| M | `:global(.app-dark) &` — 4-й МЁРТВЫЙ dark-селектор вне charter-закона (rules не в CSSOM, live-proven) | `LoginPage/index.vue:322` (14 в 10 файлах) | добавить в charter + lint; per-case delete/rewrite | M | low | ✓ |
| M | Motivation-builder помечает Settings dirty на LOAD карты (phantom unsaved-dialog) | `useMotivationBuilder.ts:242` | suspend dirty-tracking на hydration | S | low | ✓ |
| M | Тихий 30s-poll-fail флипает Motivation-таб с карты в error-state | `useMotivationTab.ts:117` | keep prior state на silent poll; pause на `hidden`/не-текущий-месяц | S | low | ✓ |
| M | `PIPELINE_OPTIONS` хардкодит id 1/2 + бренд-лейблы вместо `/api/pipelines` | `useMotivationBuilder.ts:92` | грузить из pipelines API | S | low | ✓ |
| M | Saved-views remnants: orphan-dropdown + no-op API-call на mount + unreachable `duplicates`-ветка | `ContactsPage/index.vue:925` | wire-or-delete | S | low | ✓ |

**Low (9):** hardcoded RU вне `t()` (TemplatesPage headers etc.); money-format дублирован 8× + нерунднутые дроби в kanban-футере; `TASK_KIND_COLORS`/`DS_STAGE_PALETTE` hex-литералы в JS (charter forbids); `#A7EFAA` won-stage hardcode; ~80 redundant same-step overrides (шум); ProfilePage — headless-контейнер после shim-removal; известные хвосты HANDOFF (`?tab=system`→profile mapping, red-fallback hex); baseline known-broken статус.

### 3.5 feArch — 1 high · 4 medium · 10 low

| # | Title | file:line | fix | eff | risk | new? |
|---|---|---|---|---|---|---|
| H | Анонимная public lead-form получает authenticated app-shell → 401-redirect на `/login` | `DefaultLayout/index.vue:76` | `showLayout` = `route.meta.requiresAuth`; guard `startPolling`/badge на `isAuthenticated` | S | low | ✓ |
| M | CommandPalette не role-фильтрует nav-items — admin-only страницы кликабельны всеми | `CommandPalette.vue:208` | `filterNavByRole()` на merged list | S | low | ✓ |
| M | ECharts dark re-register в leaf Dashboard-табе → HrProgress-чарты light в dark-режиме | `TabOverview.vue:101` | `useMacroCrmEchartsTheme()` в `App.vue` (как в docblock) | S | low | ✓ |
| M | Logout/401 чистит только `userStore` — 12 других сторов + persisted `recentRoutes` переживают | `AccountMenu.vue:157` | `resetAuthScopedStores(pinia)` из обоих хендлеров | M | low | ✓ |
| M | FE-authz зеркалит role-lists, backend = spatie granular permissions — систематический drift-канал | `useMotivationPermissions.ts:9` | `permissions[]` в `/api/me` + `can()` helper; инкремент. миграция | L | med | known |

**Low (10):** raw `apiClient`+hand-built URL в `useEntityFeed` (обходит api-слой); `meta.title` объявлен но `document.title` не меняется; orphan semantic-блоки (`success`→primary blue, `foundation.ts:193`); `tokens/semantic.ts` status/deal/contract-деревья orphaned (drift-канал); hardcoded RU в SearchPicker/DateField; `directoriesStore.fetchAll` возвращает сразу при in-flight → пустые данные; non-401 bootstrap-fail стрэндит token-holder на login; nav-определения дублированы (`prototypeNavItems` vs `allNavItems`); nav узче route/BE-policy для lawyer-поверхностей; channel-history feature молча потерян (orphan drawer+api, но restyled 2026-07-03).

### 3.6 db — 1 high · 3 medium · 10 low

| # | Title | file:line | fix | eff | risk | new? |
|---|---|---|---|---|---|---|
| H | 5 demo-сидеров query'ят удалённую `users.role` → полный `db:seed` крашится (42703) | `DemoDealsSeeder.php:59` (+Activities/Documents/OnboardingAssignment/Inbox) | заменить на spatie `User::role(...)` scope | S | low | ✓ |
| M | M9 dept-visibility активирована без backfill `deals.department_id` — 28/31 live-сделок невидимы новому scope | `2026_07_02_100000_promote_manager_visibility_to_department.php:40` | one-off data-migration (WIDENS visibility — нужен PM-nod) | S | med | ✓ |
| M | Partial-unique индексы guarded pg-only в 4 миграциях (SQLite поддерживает) — тест-паритет расходится | `2026_06_30_220000_fix_catalog_product_prices_unique_index.php:40` (+3) | создать те же partial-unique на SQLite | M | med | ✓ |
| M | `migrate:rollback` ломается — `down()` зовёт несуществующий `Blueprint::dropIndexIfExists` | `2026_06_30_230001_add_owner_and_author_indexes_to_crm_contacts.php:36` | `dropIndex(...)` + existence-guard | S | low | ✓ |

**Low (10):** inbox lead-source коды не в `crm_sources` (orphan source-значения); `deals.contract_id` — dead column + unsatisfiable stage-gate option; FK-колонки без btree (`plan_targets`); redundant дубли-индексы (write-amp); `cascadeOnDelete` на payroll/plan history FK (риск при hard-delete user); `sanctum:prune-expired` не scheduled (663 токена растут); `EXTRACT(YEAR)` фильтры бьют индексы + форк pg/sqlite; mixed timestamp/timestamptz + json/jsonb; 3 live-сделки с NULL `stage_changed_at`; baseline-сидеры пересоздают 6 test-аккаунтов с `password`.

### 3.7 api — 3 high · 2 medium · 5 low

| # | Title | file:line | fix | eff | risk | new? |
|---|---|---|---|---|---|---|
| H | DocumentItemsTab тянет несуществующий `/api/admin/products` — product-picker вечно пуст | `DocumentItemsTab.vue:248` | → `/api/catalog/products` | S | low | ✓ |
| H | Bearer-less `window.open` downloads в 5 documents-сайтах — каждый 401 (wave-4 fix неполон) | `useDocumentPage.ts:221` (+4, incl. `TerminationDocumentDrawer.vue:234`) | blob-хелперы; удалить deprecated | S | low | ✓ |
| H | Student PDF-уроки сломаны: FE игнорит `player_src`, iframe не несёт Bearer | `LessonView.vue:68` | `content.url` напрямую; `content.path` → `player_src` через blob | M | low | known |
| M | Нет brute-force защиты на `/2fa/disable`, `/2fa/regenerate-backup-codes`, `/me/password` (IAM-2 покрыл только login+validate) | `TwoFactorController.php:96/130`, `PasswordController.php:29` | `LoginThrottle` вокруг confirmSecondFactor; throttle на password | S | low | ✓ |
| M | `contact_id`/`company_id` не declared в `LinkContactCompanyRequest` — unvalidated FK + нет view-чека на linked-сущности | `LinkContactCompanyRequest.php:22` | +rules (exists) + authorize `view` на resolved Contact/Company | S | low | ✓ |

**Low (5):** inline `$request->validate()` в 7 местах (blacklist); 3 shipped report-export эндпоинта без FE-wiring (кнопка no-op'ит); Licensor CREATE недостижим из UI; stale REMAINING (IDOR fixed, ResolveVisibility consumed); aggregate-эндпоинты возвращают `response()->json(['data'=>array])` вместо `JsonResource`.

### 3.8 perf — 0 critical · 5 medium · 6 low (0 dead)

| # | Title | file:line | fix | eff | risk | new? |
|---|---|---|---|---|---|---|
| M | `board()` — 3 query/видимую стадию + 1/скрытую (≈3S+H на загрузку), усилено realtime full-refetch | `DealService.php:740` | batch scalar-агрегаты одним GROUP BY stage_id → S+2 | M | low | ✓ |
| M | Task-matrix — 1 GROUP BY на (kind×manager) + re-pluck всех pipeline-id каждый вызов | `TaskMatrixService.php:120` | hoist pluck в `build()`; collapse per-user loop | M | low | ✓ |
| M | Conversion report — 24 COUNT/пару (2 стороны × 12 мес), удвоено Excel-путём | `ConversionReportService.php:131` | GROUP-BY-month/сторону | M | low | ✓ |
| M | `buildMatrix()` — 1 fact GROUP BY на строку (per-manager) | `PlanTargetService.php:76` | добавить scope-колонку в grouped select, 1 query (как `ProductIncomeService::monthlyFactByGroup`) | M | low | ✓ |
| M | `getRate()` без per-request memo — 1 SELECT/конверсию внутри агрегат-циклов | `ExchangeRateService.php:70` | request-scoped memo `[from|to|date]` | S | low | ✓ |
| M | echarts-chunk фетчится+парсится на boot для каждого юзера (eager side-effect import) | `main.ts:5` | убрать из `main.ts`; import в chart-компоненты | S | low | ✓ |

**Low (6):** `resolvePlanTotal()` — 1 row/scoped-user в цикле; `findForDedup` phone — full-table PHP-скан (known M-4); users/pipelines directories re-fetch на почти каждый mount (singleton-кэш обходится); per-row progress N+1 в onboarding-ресурсах (`Lesson::find()` в цикле, known onboarding#12); `copyPreviousPeriod()` — 1 `exists()` на source-cell.

### 3.9 repoStruct — 2 high · 6 medium · 9 low

| # | Title | file:line | fix | eff | risk | new? |
|---|---|---|---|---|---|---|
| H | `PLAN.md` (SSOT плана) всё ещё объявляет Vizion «единственным эталоном стека» — конфликт с ARCHITECTURE/CLAUDE/backend-standard | `PLAN.md:3` (+`:25`,`:27`) | заменить формулу на актуальную цепочку (только .md → без деплоя) | S | low | ✓ |
| H | 15/19 агентов несут устаревший футер «Конфликт стека → vizion… копируй Vizion 1-в-1»; crm-backender внутренне противоречив | `crm-backender.md:104` (+14) | батч-замена футера (`.claude/**` → без деплоя) | M | low | ✓ |
| M | 4 агента описывают RBAC ДО закрытия IAM-1 («spatie засижен, но не подключён») — CLAUDE.md фиксирует обратное | `qa-tester.md:116` (+deploy/designer/frontend) | заменить RBAC-абзац на пост-IAM-1 формулу | S | low | ✓ |
| M | Корневой `README.md` — капсула bootstrap-эпохи (Vizion, «17 агентов», M0-квикстарт, несуществующий `backend-specialist`) | `README.md:3` | переписать | M | low | ✓ |
| M | В dev-compose нет сервиса `reverb` — realtime (LIVE в проде) молча мёртв в dev/QA | `docker-compose.dev.yml:1` | добавить reverb-сервис зеркально проду (аддитивно) | S | low | ✓ |
| M | Мёртвый Laravel-фронт-скелет в `src/` с **Tailwind** (жёсткий blacklist): `vite.config.js`+`package.json`+`resources/css,js`+welcome-роут | `src/vite.config.js:4` | удалить одним срезом + `/`→JSON + вычистить npm из composer-scripts | M | low | ✓ |
| M | `DEPLOY.md` не покрывает reverb-контейнер (0 упоминаний) — ранбук неполон | `docs/DEPLOY.md:1` | дописать Reverb-раздел (env/ws-route/грабли 2026-07-02) | S | low | ✓ |
| M | Cloud-sync: 6 стейл `.git/index [2-7]` + продолжающееся производство « 2»-дублей | `.git/index 2:1` | удалить копии; вынести из-под облака / cleaner | S | low | known |

**Low (9):** `deploy.yml:3` шапка «Manual trigger only» ⊥ собственному `on.push`; нет ignore-паттернов для « 2»-дублей; `front/.stylelintrc.json` — мёртвый конфиг (правила утроены, SETUP.md врёт); `CLAUDE.md:86` «Playwright MCP нет» ⊥ `.mcp.json`+qa-tester; 2 июльских дока ссылаются на волатильный `~/Downloads`; `HANDOFF.md:4` стейл-путь импорта; `REMAINING.md:7` стейл-маркеры «uncommitted»; QA-отчёт в `design-handoff/` (перекрыт docs/audit); 3 лишних `.claude`-корня фрагментируют agent-memory (Orbita-корень — живая память deploy-engineer).

---

## §4. QA-смоук прода (обе темы)

**~28 экранов, вердикт ⚠️ PARTIAL PASS.** 2 FAIL + 2 косметики. Всё остальное (сделки/канбан/карточки, контакты/компании + CRUD-пробы, задачи + CRUD, 8 разделов Настроек, онбординг, кабинет менеджера, уведомления) — PASS, console 0, network 200. Оба brand-инварианта подтверждены пиксель-в-пиксель: header dark `#111E38`, light `#172747`; sidebar light `#172747`.

**FAIL-1 — `BUG-PLAN-TOTALS-ZERO`** (Dashboard → Планы): колонка **«Всего»** = 0 ₽ построчно для всех сотрудников, и футер ИТОГО = 0 ₽ по всем 12 месяцам — при реальных суммах по месяцам. Воспроизведено 3× (incl. полный reload) в обеих темах.
- Ключевое сужение: у той же строки столбец **«Год» в конце показывает корректно** (Иванов 20 247 000 ₽ = 5 847 000 июн + 14 400 000 июл). → баг **изолирован в 2 вычисляемых полях** («Всего» построчно + футер ИТОГО на Поступления), не в движке сумм.
- Косвенно: Dashboard→График «План месяца» = 0 ₽ при Факт 14 400 000 ₽.
- Возможная регрессия (`BUG-ИТОГО-ZERO` ранее считался fixed 2026-07-03 — фикс мог покрыть не все сценарии). **Диагностика → Э1.**

**FAIL-2 — `BUG-DARK-SETTINGS-SUBTITLE`** (Настройки → Справочники, все вкладки): subtitle «Управление системными справочниками» — computed `rgb(58,79,120)` на `rgb(17,30,56)` = контраст **2.03:1** (WCAG AA требует 4.5:1). Системный на всех вкладках Справочников. → **Э6.**

**Косметика (не блокирует):** заголовок списка сделок (`?view=list`) «MACRO Global · 0 сделок · 0 ₽» вместо реальных 33/22 160 000 ₽ (KPI-полоса и футер считают верно) → **Э7**. Алиас `?section=access` не резолвится (реальный slug `access-control`) → **Э7**.

**Не проверено:** SalesPulse (нет браузерного UI — Telegram-дайджест); чистый anon-pass (сессия стартовала авторизованной); dark для части list-экранов (проверенные пары чистые).

---

## §5. DS2-дельта (design-system export 2)

DS2 — **следующее поколение** той же линии, что интейк 2026-07-03 (`MSales-package-delta-2026-07.md`): **суперсет по дизайну, стейл по спекам**. Источник-путь — волатильный (`~/Downloads/...`), поэтому в репо тащим по `intakeActions`, а не ссылкой (см. §3.9-low про самодостаточность).

### Новое и ценное
1. **3 hi-fi мокапа** (`design-handoff/redesign/`):
   - `manager-cabinet.html` — «облегчённый» кабинет v2: `ResultsHero` (104px МК%-ring), `MoodHead` (SVG-смайлик + ротация фраз), `SecStat`-строки, `TeamList` с градиент-барами, тихий `CcyNote` вместо оранжевой плашки; Motivation-таб — полиш Mk* (pct+ЗП-факт inline в свёрнутой строке, gradient DeptPlan-бар, компактный header).
   - `dashboard.html` — widget-grid дашборд: drag/resize/`localStorage`/edit-mode, `FunnelWidget` со сквозной конверсией, `ForecastWidget` (hero + stacked HOT/Warm/Trial + `CcyPopover` валютной разбивки), `NoTaskWidget` (список сделок без задач + inline «+Задача»).
   - `mail.html` — Gmail-стиль двухпанельный Inbox-триаж (11 папок + 5 канал-чипов + date-range).
2. **`tokens/surface.css`** — light-значения `--c-*` page-моста (+ новые `--c-board`/`--c-muted2`); + `styles.css` `@import`. Обе локации (`design-handoff/tokens/` + skill).
3. **18 новых компонентов** (jsx+d.ts): DataTable, StatCard, NotificationBadge, AvatarGroup, EmptyState, Skeleton, Toast, Switch, PageHeader, Pagination, SegmentedControl, Tabs, Stepper, CommandPalette, Dialog, Menu, Tooltip, Tree (было 9).
4. **`templates/`** — 5 canvas-стартеров (crm-shell, crm-page, kanban-board, data-table-page, settings).
5. **Token-дельты:** `--mg-orange-600` (light `#E8821E` / dark `#DE8A3A`, warning-strong для бейджей), `--mg-pink-300` fix (`#F4A6D7`→`#F6C4E1`), `--mg-stage-amber-ink #6B4A00`.
6. **Общие мокапы** (contacts/deal-card/entity-card/sales-funnel/tasks) — dark мигрирует с legacy-серой (`#272829`) на **navy** (delegated в `dark.css`-мост) + dark-акцент `#4C7DF0`.
7. **canvas-инфра** — `_ds_manifest.json`, `_ds_bundle.js` (833KB), `support.js`, `Canvas.dc.html`; ui_kits/index.html теперь iframe'ит redesign-прототипы (retire Shell/DealsView/ContactsView/TasksView).

### Конфликты/скипы (важно)
- **⚠️ Литералы мокапов ≠ runtime-тема.** Новые мокапы (и DS2 contacts/deal-card/entity-card) хардкодят navy `#12213E`/`#243358`/`#E8EDF6`, отличный и от shipped runtime-темы, и от собственного DS2 `dark.css`-моста (`#111E38`/`#27395C`/`#EAF0FA`) — **внутренняя designer-неконсистентность**. **Реализация читает `front/src/theme` (это закон значений); литералы мокапа — reference-only.**
- **SKIP as-built спеки:** `HANDOFF.md`, `Contacts-spec.md`, `DealCard-spec.md`, `EntityCard-spec.md`, `Tasks-spec.md` из DS2 — **пре-as-built оригиналы** (0 as-built-пометок vs 47+ в репо; Contacts-spec даже реверсит на неверный `pages/crm/`-путь). Копирование уничтожит as-built-истину — тот же вердикт, что прошлый интейк.
- **SKIP** корневой `access-section.jsx` (stale canvas-вариант), идентичные файлы; **rm** локальный Finder-мусор (`components/{crm,data,forms} 2`).

`intakeActions` (17 действий) и `applyTasks` (5 экранов) — исполняются в **Э10/Э11** (+§7 отложенное для Mail).

---

## §6. План этапов (закон исполнения)

> После КАЖДОГО этапа: полный PHPUnit-сьют (цель 3944+/0) + type-check + eslint + lint:ds; где UI — гейт `qa-tester` (computed-styles обе темы). Регрессионный тест на каждый бизнес-фикс. Коммит по завершении.

### Э1 — Финансовая корректность фактов (BE) · критично
Все raw `DB::table('deals')` fact-запросы обходят SoftDeletes → soft-deleted/archived won-сделки надувают KPI/MK/план (9 из 13 live-сделок были soft-deleted).
- `ManagerKpiService.php:262/313` — +`whereNull(deleted_at)`+`archived_at` (кабинет score, WonDealsFactSource, team-gate).
- `PlanTargetService.php:365`, `BestManagerService.php:213`, `StageConversionService.php:128`, `ProductIncomeService.php:193` — +`whereNull(deleted_at)`; извлечь общий **`wonDealsBaseQuery()`** хелпер (анти-регресс на будущее).
- `teamContributions()` (`WonDealsFactSource.php:32`) — прокинуть `pipelineId` в `teamKpiBatch`.
- R6 deal-скидка (`ProductIncomeService.php:224`) — `SUM(ROUND(dp.amount*(100-disc)/100))`.
- registry `noDateDeals` (`ExpectedIncomeRegistryService.php:79`) — +`productGroupId` when-clause.
- `computeItemRow` (`MotivationCardService.php:428`) — передать `$card->user_id` (убрать N+1 + user_id-0 fallback).
- `is_service`-фильтр в `PlanTargetService::scopedUserIds` (mirrors BestManager).
- **QA-баг `BUG-PLAN-TOTALS-ZERO`** (§4 FAIL-1) — диагностировать «Всего»-построчно + футер ИТОГО на Поступления (баг изолирован в этих 2 полях, «Год» считает верно); фикс FE/BE по диагнозу.
- Регрессионные тесты на каждый пункт.

### Э2 — IAM / Security (BE)
- Отзыв Sanctum-токенов: `UserService::deactivate` (`$user->tokens()->delete()`), `PasswordService::resetByAdmin` (все), `change` (все кроме текущего); опц. `is_active` abort(403) в `Verify2FA`.
- Throttle (reuse `LoginThrottle` failures-only) на `/2fa/disable`, `/2fa/regenerate-backup-codes`, `/me/password`.
- `LinkContactCompanyRequest` — +rules `contact_id`/`company_id` (required, integer, exists) + authorize `view` на resolved-сущность.
- Prod-guard + `--force` в `SeedQaStudentCommand` (как ResetCleanCommand).

### Э3 — Надёжность side-effects (BE)
- `->afterCommit()` на 4 queued-dispatch: `GenerateCertificateJob` (`ProgressService`), `SendTelegramApprovalCardJob`/`SendTelegramDmJob` (`ApprovalService`), automation-job.
- `DealCreated::dispatch` вынести из транзакции `createInbound` **+** dispatch post-commit из `DealService::create` (ручное создание сделок — `on_create` теперь fires; проверить board-realtime).
- Сброс `documents.telegram_message_id` при выходе из `in_review` (needs_rework/rejected/approved) — stage-2/resubmit карты снова шлются.
- `unsign()` через статус-машину (Signed→Approved edge) + `recordContractEvent` (entity-log).
- Role-фильтр inbound owner-fallback → admin/director (`InboundRoutingService.php:221`).
- Поправить false-docblock `RunOnCreateAutomations`.

### Э4 — CRM-целостность (BE)
- PG-краш contact-merge (`DedupService.php:694`) — удалить collision-relations до re-parent; PG-shaped тесты.
- Company-merge orphans (`DedupService.php:743`) — +activities/folders/files; +`acquisition_channel_history` в contact-merge.
- Company soft-delete при живых сделках (`CompanyService.php:333`) — 409-guard (как ProductService).
- Requisite-инвариант (`CompanyRequisiteService.php:161`) — forbid-current или auto-promote+re-mirror.
- KPI-vs-list drift (`ContactsKpiService.php:125`) — убрать `departmentColumn`.
- Quick wins: bulk-actor в 8 call-site (`BulkContact/CompanyService`); `LogAction::Created` для компаний; `scalarize()` в `diffLoggedFields`; `findForDedup` → `phone_normalized`; FX-конфиг default (`fxratesapi.com` + `.env.example`).

### Э5 — БД
- 5 demo-сидеров `users.role` → spatie `User::role(...)` (unbreak `db:seed`).
- Backfill `deals.department_id` из owner-department (28 строк) — **WIDENS visibility, нужен PM-nod**; + backfill `stage_changed_at=created_at` (3 строки).
- `dropIndexIfExists` → `dropIndex`+guard (rollback-compliance).
- Partial-unique на SQLite в 4 миграциях (тест-паритет).
- `sanctum:prune-expired --hours=24` daily.
- Одна миграция дропа 8 дублей-индексов (reuse salary_plans-прецедент).
- Insert-seed 7 inbox lead-source кодов в `crm_sources`.

### Э6 — FE dark-долг · гейт qa-tester
- Navy-свип ВСЕХ оставшихся страниц (MyTasks/Inbox/Settings-секции/DealPage-диалоги). **Правило:** wrong-step override поверх reactive-базы (`$surface-N`/`var(--p-*)`) → **DELETE**; где нужен distinct-dark → canon-steps (text→800/900, muted→500/600, hover-bg→200, card→100); **whitelist** документированные inverse-contrast (seg-btn--active, MyTasks light-panel — решить с designer).
- Снять 12 page-local `<Toast>` + 14 `<ConfirmDialog>` (layout-синглтоны обслуживают app-wide).
- 4-й мёртвый вариант `:global(.app-dark) &` — per-case delete/rewrite (не «оживлять» wrong-step значения) + дополнить charter-закон + grep-lint.
- QA-FAIL `BUG-DARK-SETTINGS-SUBTITLE` (Справочники subtitle 2.03:1 → `$surface-500`+).
- **Гейт:** qa-tester computed-styles обе темы.

### Э7 — FE функциональные · гейт qa-tester (где UI)
- Public-form без app-shell (`DefaultLayout:76` `showLayout`=`requiresAuth`) + guard `startPolling`/badge на `isAuthenticated`.
- CommandPalette role-фильтр (`filterNavByRole`).
- ECharts dark в App-root (`useMacroCrmEchartsTheme()` в `App.vue`).
- Очистка сторов на logout (`resetAuthScopedStores` + `recentRoutes`).
- 5 Bearer-less downloads → blob-хелперы (documents + `TerminationDocumentDrawer`); удалить deprecated URL-helpers.
- `/api/admin/products` → `/api/catalog/products` (`DocumentItemsTab:248`).
- Student PDF через `player_src` blob (`content.url` напрямую / `content.path` → blob).
- Motivation-builder: phantom-dirty hydration-guard; silent poll-error keep-state; `PIPELINE_OPTIONS` из `/api/pipelines`.
- Заголовок списка сделок (total при view=list); алиас `?section=access`→`access-control`; saved-views remnants (wire-or-delete); i18n-мелочи (TemplatesPage headers, `вкл.`, `✓`→pi-check, `#A7EFAA`→`var(--p-green-300)`).

### Э8 — Мёртвый код + мета-долг · только .md/.claude/локальное → без прод-деплоя (кроме BE dead-code среза)
- **BE:** dead-методы (`HoldingService::buildChildrenFromMap`/`ancestors`, `EntityLogService::fieldChangesForSubject`, `ExchangeRateService::latestRates`, `ProductService::getPriceForCurrency`); `KpiType` enum / `countFact()` stub; `GenerateContractJob`; orphan `GET /api/automations/{id}` show; `InboundRoutingService::channelKinds()`; `->with('stages')` в StageConversion; stale docblocks.
- **FE:** 8 orphan-файлов (SavedViewsDropdown, TeamTasksFilterBar, DealDatesGroup, DealTabStats, DealKeyActionsBar, DealAddChannelDialog, useCompanyActivities, useDocumentAttachments) + ChannelHistoryDrawer/TaskQuickForm/uiTriggers/lessonProgress/realtime-index/api-types-index/Orbita-locale/_compact-control/legacy-useSystemReset; orphan-токены темы (`success`→primary blue).
- **Локальное (git не трогает):** Finder «2»-дубли + `.git/index [2-7]` + 76 root-PNG + docx-фикстуры + `.playwright-mcp/` + `qa_screenshots_s3/` + `4_active/` + `resources/`; +`.gitignore` ignore-паттерны `* 2`/`* 3`.
- **Tailwind-скелет в `src/`** (§3.9-M) — удалить одним срезом + `/`→JSON + composer-scripts.
- **Канон-долг (только .md/.claude):** `PLAN.md` Vizion-tiebreaker → актуальная цепочка; футеры 15 агентов; RBAC-абзац 4 агентов (пост-IAM-1); `README.md` переписать; `DEPLOY.md` +reverb; `REMAINING.md` снять стейл-«uncommitted» + обновить IDOR/ResolveVisibility статусы; `CLAUDE.md:86` Playwright-строка; `HANDOFF.md:4` путь; `deploy.yml:3` шапка.
- **dev-compose +reverb-сервис** (§3.9-M).

### Э9 — Performance (behavior-preserving)
- Kanban `board()` — batch scalar-агрегаты одним GROUP BY stage_id (3S+H → S+2).
- `TaskMatrix`/`ConversionReport`/`buildMatrix` — GROUP BY вместо per-row/per-month циклов; hoist pipeline-pluck.
- Memo `ExchangeRateService::getRate` (`[from|to|date]`).
- echarts lazy (убрать из `main.ts`; import в chart-компоненты).
- In-flight promise в `directories`/`useUsersCache` (не early-return при загрузке; adopt singleton в Deals/Dashboard/Cabinet).

### Э10 — DS2-интейк + Кабинет менеджера · гейт qa-tester
- **intakeActions** (§5): 3 мокапа + navy-dark общих эталонов + `surface.css` + token-дельты + 18 компонентов + templates + canvas-инфра в skill+design-handoff; секция «Обновление 2026-07-04 — DS2» в `HANDOFF.md`; SKIP as-built спек; rm Finder-мусор.
- **manager-cabinet.html Overview:** `ResultsHero`/`MoodHead`/`TeamList`/объединённая шапка (backend-impact НУЛЕВОЙ — данные в API кабинета); MoodHead-фразы = новый i18n RU+EN.
- **Motivation-таб:** полиш Mk* (pct+ЗП-факт inline, gradient DeptPlan, компактный header) — контракт B-1…B-5 покрывает всё.
- **Токены — репо-переменные** (`$surface-*`/`var(--p-*)`), НЕ литералы мокапа (`#12213E`-семейство ≠ прод `#111E38`; прод-тема главнее; SPEC v1.1 dark-закон).

### Э11 — Dashboard widget-grid Фаза 1 (БЕЗ бэк-изменений) · гейт qa-tester
- drag/resize/`localStorage`/reset поверх существующих виджетов.
- «Сквозная конверсия» в шапке `FunnelWidget` (из stage-строк).
- Рестайл `ForecastWidget` (hero+stacked-bar+legend).
- `NoTaskWidget` → превью-список (данные через deals-index `only_no_task`) + inline «+Задача» (activity-create).
- *(возможно поле days-without-task в deals-index для точного «N дн.» — вынести в Фазу 2, см. §7).*

### Э12 — (опционально по состоянию) Mail слайс A · гейт qa-tester
Двухпанельный Inbox (список+читалка вместо Dialog), unread-точки/тогглы, ChannelDot, фильтр-панель Входящие/Не разобрано/В сделках + канал-чипы + failed-баннер «Создать сделку» (reroute), Raw payload, плотность. **Без новых полей БД** (срез A поддержан текущим бэком). Скоуп папок — сначала PM-решение.

---

## §7. Отложено (сознательно, с причинами)

Список сверен с фактическим исполнением трека (2026-07-04, HEAD `b1bbb34`). Всё, что закрыто этапами Э1–Э12, убрано; ниже — только сознательно отложенное (11 пунктов: **2 medium** — revenue-recognition + FE-permission-матрица — плюс сопутствующие low и forward-scope DS2-срезы), с причиной и необходимым триггером.

| Пункт | Причина / что требуется для снятия |
|---|---|
| Унификация revenue-recognition конвенции (`stage_changed_at` vs COALESCE effective-date, §3.1-M) | Нужно **продуктовое решение**, какая дата каноническая; сдвинет факты на месяц на части экранов. (Факт-скоуп soft-delete/archived уже вычищен в Э1 — расходится только дата.) |
| FE-матрица прав через granular permissions в `/api/me` (§3.5-M, `useMotivationPermissions`) | Крупная FE-миграция (L); есть **отдельный план в vault**; backend остаётся authoritative — риск только видимости меню, не безопасности. |
| SpaceCRM-gap «Общие» — general auto-conversions (§3.1-low) | Не реализованный старый механизм авто-конвертации; требует продуктового скоупа — включать ли и в каком виде. |
| `deals.deal_type` — тип сделки (R1 «Прямая/СБС» сейчас tags-only эвристика, тег `sbs`, §3.1-low) | Введение колонки/enum вместо тег-эвристики — продуктовое решение + миграция; текущая эвристика рабочая. |
| Excel-лейауты (детальное форматирование серверных экспортов) | Косметика экспорта; данные корректны, не блокирует. |
| **Mail слайсы B/C** (звёзды/важные/snooze/date-range/per-folder counts; Отправленные/Черновики/Спам) + failed-фикстура для QA | Слайс B — новые поля/эндпоинты; слайс C — **отсутствующего домена исходящей почты** (большой). Нужен бэк/спринт. Отдельно: **QA-фикстура failed-письма** (нет живого failed-inbound для проверки reroute-баннера среза A). |
| **Dashboard Фаза 2** — валютный `CcyPopover` (разбивка прогноза по валютам + «N сделок без курса») | Расширение `DashboardResponse` (per-currency состав weighted-прогноза) — API-изменение. Фаза 1 (grid/layout/honest-conversion/no-task) реализована в Э11. |
| Контракт «линейки по сотрудникам» (снятый невалидный разрез product-income) | Разрез был математически невалиден (double-count скидки на сотрудника) — **снят** в Э1 (`f079246`), а не починен. Возврат — только по валидному продуктовому контракту. |
| Доступ менеджеров к `/inbox` (RBAC-вопрос продукту) | Сейчас `/inbox` виден шире, чем должна позволять роль менеджера — **вопрос к продукту**, расширять или ограничить видимость; не баг безопасности (данные scoped). |
| Channel-history UX (orphan drawer+api, потерян визуально в Entity Card 2.0, §3.5-low) | Фича молча потеряна при редизайне EC 2.0; drawer/api живы, но точки входа нет. **Записано в `REMAINING.md`** — восстановление UX требует дизайн-решения. |
| `deals.contract_id` решение (dead column + unsatisfiable stage-gate, §3.6-low) | Требует продуктового решения (drop vs wire); пока в dead-code. |

---

## §8. Метрики «до/после»

| Метрика | ДО (2026-07-04) | ПОСЛЕ (2026-07-04, HEAD `b1bbb34`) |
|---|---|---|
| PHPUnit-сьют | 3944 / 0 (11 397 assertions, 280 s) | **4044 / 0** (11 747 assertions, ~300 s) — **+100 тестов** |
| Находки **high** | 13 | **0** — все 13 закрыты |
| Находки **medium** | 42 | **40 закрыто** (Э1–Э9); **2 отложено** (revenue-recognition конвенция + FE-permission-матрица, §7) |
| Мёртвый код (позиций) | 77 | **~0** — BE −539 строк (22 файла) + FE ~2000 строк (17 файлов) + Tailwind-скелет удалён |
| QA FAIL (прод) | 2 | **0** — оба исходных FAIL закрыты; +1 app-краш пойман гейтом до пуша |
| Запросов на kanban-load | ~3S+H (≈29 fact-запросов при 7-9 стадий) | **3** (18 total/request) |
| i18n-пропуски (hardcoded RU вне `t()`) | ~10 файлов | **0** |

**Гейты трека:** vue-tsc / eslint / lint:ds — **0** на протяжении всех этапов. QA-раундов ~12 (2 исходных прод-FAIL закрыты; 1 app-краш `storeReset` пойман браузерным гейтом ДО пуша).
**Объём трека:** 22 коммита (`3156529..b1bbb34`), 405 файлов, +51546 / −8492.

### Итог трека

**12/12 этапов исполнено.** Закрыто: **все 13 high** (финансовая точность фактов, IAM/токен-lifecycle, side-effect-надёжность, CRM-целостность, БД-крэши сидеров, api-мисматчи, dark-долг, public-form-shell) + оба исходных прод-QA-FAIL. **Medium:** из 42 находок **40 закрыто** этапами Э1–Э9, **2 сознательно отложено** (revenue-recognition конвенция §3.1-M + FE-permission-матрица §3.5-M — требуют продуктового решения / крупной FE-миграции). Отдельно §7 суммирует **всё отложенное** (эти 2 medium + сопутствующие low + forward-scope DS2-срезы), всего 11 пунктов. **Мёртвый код** вычищен полностью (BE +FE + Tailwind-скелет), **DS2** полностью в репо, **кабинет менеджера v2 + Dashboard v2 (Фаза 1) + Mail v2 (срез A)** реализованы (Э10–Э12). Тест-сьют вырос 3944 → 4044 (+100 регресс-тестов на бизнес-фиксы).

**Инцидент `storeReset`:** при DS2-интейке (Э10) storeReset-плагин клонировал computed-геттеры сторов → app-краш на bootstrap. Пойман браузерным QA-гейтом **до пуша** (коммит-фикс `75a0da3`). Введено правило: **front-коммит только после прохождения QA-гейта** (не «код зелёный по vue-tsc/lint» — обязателен браузерный smoke обеих тем).

---

## Источники и оговорки

**Сырые данные:**
- JSON-суип 11 агентов: `/private/tmp/claude-501/-Users-bogdanadykin-Claude-MGCRM/2833ff45-d874-4544-a322-207654d75947/tasks/wp36cnbta.output` (`.result.{ds2, health, audits:{beSales,beCrm,beDormant,fePages,feArch,db,api,perf,repoStruct}}`).
- QA-смоук прода: `/private/tmp/claude-501/-Users-bogdanadykin-Claude-MGCRM/2833ff45-d874-4544-a322-207654d75947/scratchpad/qa-prod-audit-smoke.md`.

**Известные «спящие» домены — ОПЕРАЦИОННЫЕ действия, не код (в план §6 НЕ входят, требуют явного решения юзера):**
- **Contracts generation** — код здоров, но dead в проде: `template_versions=0`. Оживление = one-off прод-сиды (`TemplateVersionSeeder`, `ApprovalRouteSeeder`, `RolePermissionSeeder`) + смоук генерации. Это **прод-операция**, не изменение кода.
- **Automation / Inbox / Migration engine** — wired, но никогда не запускались в проде (`/automation` не в nav; AMO `external_refs=0`). Включение = операционное решение + прогон, не код.

> Данные операционные действия выполняются **только по явной прямой просьбе юзера** и в §6-этапы не включены.
