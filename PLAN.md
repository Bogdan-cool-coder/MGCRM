# PLAN.md — Миграция MACRO Global CRM → Laravel 13 + PrimeVue

> **SSOT плана.** Переписываем CRM с FastAPI+Next.js (`./examples/contracts/`) на жёсткий стек Laravel+PrimeVue по эталону **Vizion** (`./examples/vizion/`), домен за доменом (strangler), milestone-темпом.
> `./examples/contracts/` = источник ТОЛЬКО бизнес-логики. `./examples/vizion/` (копия Vizion) = единственный эталон стека/конвенций. Пишем в корне репо (`src/` + `front/`).
>
> **Скелет + статусы.** Этот файл — скелет вех (M0…M12), Acceptance и **компактная таблица статусов** (§5.1). Хронологический changelog слайсов (Навигация, M2+/++/+++, DS-4..7, Bug Pack, M-batch, MSP) вынесен **2026-06-24** в vault `MG CRM 2026/3. Журнал/PLAN — архив changelog слайсов (до 2026-06-24).md`. Текущая правда о состоянии доменов — в `docs/audit/00-MASTER.md`.

---

## §0. Контекст и цель

**Источник (`./examples/contracts/`, macro-contracts):** монолит-замена AmoCRM. 140+ таблиц, 60+ роутеров / 300+ эндпоинтов, 60+ сервисов, 150+ страниц, 427 компонентов, 8 бизнес-доменов + Telegram-бот. Стек FastAPI + SQLAlchemy + Next.js + Tailwind. **Считаем нерабочим — переиспользуем только бизнес-логику.**

**Цель:** функциональный паритет на стеке Laravel 13 + PrimeVue, организованном по DDD, с дисциплиной пакетов и фронта строго по Vizion.

**Оценка объёма:** ~25–35 недель полного паритета. Поэтому — strangler-миграция, домен за доменом; `./examples/contracts/` остаётся точкой истины по фичам до полного переноса. Темп — **milestone-стиль**: каждый milestone имеет day/week-оценку, вертикальный срез (миграции→модели→сервисы→API→UI→тесты) и Acceptance-чеклист.

---

## §1. Источники и приоритет

| Источник | Роль | Что берём |
|---|---|---|
| **`./examples/contracts/`** (macro-contracts) | ТЗ по бизнес-логике | модели, поля, связи, эндпоинты, статус-машины, фичи, YAML-шаблоны договоров, поведение фронта |
| **`./examples/vizion/`** (копия Vizion) | **ЕДИНСТВЕННЫЙ ЭТАЛОН СТЕКА** | конвенции backend/frontend, структуру, конфиги, AI-каскады, PHPWord+Gotenberg, ECharts, vite/eslint/pint-конфиги — 1-в-1 |

**Конфликт стека → решает `./examples/vizion/` (Vizion) — единственный источник.** Конфликт бизнес-логики → решает `./examples/contracts/`.

> 🔁 **Рабочий цикл агента** (см. CLAUDE.md): бизнес-логику смотрим в `./examples/contracts/` (FastAPI/Next — код не копируем, копируем смысл) → технический паттерн в `./examples/vizion/` (Vizion) → делаем 1-в-1 в корне репо (`src/`/`front/`) с единственной поправкой на DDD `app/Domain/<Context>`.

---

## §2. Принципы миграции

1. **Strangler, вертикальными срезами.** Каждый milestone = миграции → модели → сервисы → API → UI → тесты, до рабочего состояния. Не «сначала весь backend».
2. **Паритет по поведению, не по коду.** Сверяем, что фича делает то же, что в `./examples/contracts/`. Код пишем заново по-вижновски.
3. **Жёсткий стек.** Любой пакет вне §3 — только по явной просьбе.
4. **Эталон прежде изобретения.** Перед новым паттерном — `grep` по `./examples/vizion/`.
5. **Перенос данных не нужен** (тестовые данные). `migration-specialist` — cutover + per-domain parity-чеклисты.
6. **DoD milestone'а — два уровня (см. §9):** «done-merged» (CI зелёный) ≠ «verified-live» (работает в проде). Milestone закрывается только после **verified-live**. Счётчики SQLite-тестов — НЕ метрика готовности (зелёный SQLite сосуществует с боевыми блокерами — см. аудит).

---

## §3. Стек (жёсткий, ограниченный)

### 3.0 Принцип выбора библиотек (library-first)
**Весь функционал — на готовых решениях, максимум библиотек, минимум своего кода** (см. ARCHITECTURE.md §0.1). Порядок: (а) уже подключённая в проекте библиотека → (б) библиотека, использованная у Vizion (`./examples/vizion/`) → (в) широко используемый maintained-пакет под наш стек (с аппрувом) → (г) свой код только если (а)–(в) не подошли. Если задачу закрывает уже доступная либа — **новую не ставим**. Список ниже — закрытый.

### 3.1 Backend
| Компонент | Версия | Примечание |
|---|---|---|
| Laravel | **13** | конвенции — Vizion |
| PHP | **8.5-fpm** | strict_types, enums, readonly, match |
| PostgreSQL | **16** | как `./examples/contracts/` (упрощает импорт данных + FTS) |
| Laravel Sanctum | 4.x | **Bearer personal access token (как Vizion)**; SPA хранит токен. 2FA-флоу: login → temp-токен → verify TOTP → полный токен |
| **TOTP 2FA** | — | `pragmarx/google2fa` + QR. **Точечное исключение** |
| **spatie/laravel-permission** | ^6.0 (v6.25.0) | **ЦЕЛЕВАЯ модель авторизации** (6 ролей admin/director/lawyer/manager/accountant/cfo + гранулярные права). ⚠️ **СЕЙЧАС НЕ ПОДКЛЮЧЕНА** — авторизация на role-enum Gates по `users.role`; таблицы spatie засеяны, но на guard `web` (Sanctum их не видит) = долг **IAM-1**. См. §4.4. |
| spatie/laravel-translatable | 6.x | jsonb-поля (на будущее EN; old RU-only) |
| spatie/laravel-backup | ^10.0 (v10.3.0) | ежедневный дамп БД (^10 поддерживает LV13) |
| **Prism** (prism-php) | ^0.100 | AI-каскады — конфиг `config/ai.php` 1-в-1 с Vizion (Anthropic-only) |
| **PHPWord** + **Gotenberg** | 1.x / 8 | генерация договоров docx→PDF — паттерн Vizion |
| Symfony ExpressionLanguage | 7.x | вычисляемые правила (как Vizion) |
| Redis | 7 | очереди/кэш/rate-limit. **NET-NEW** (у Vizion нет — он на database queue/cache). **БЕЗ Horizon** (`queue:work`) |
| **PhpSpreadsheet** | 1.x / 5.x | Excel-экспорт. Эталона у Vizion нет — паттерн из `./examples/contracts/` (`openpyxl`) + офиц. доки |
| **nutgram/nutgram** | — | Telegram-бот (замена aiogram). Бизнес-референс `./examples/contracts/` (`aiogram`) + офиц. доки nutgram |
| **wapmorgan/morphos** | — | склонение RU. ⚠️ для «суммы прописью» — отдельный маленький helper |
| **sentry/sentry-laravel** | ^4.26 | error+performance мониторинг; DSN из env; `send_default_pii=false`; `sql_bindings=false`; release = git-SHA из `rolling-restart.sh` |

### 3.2 Frontend

> **Бренд-ассеты** — `brand/`. **Визуал/токены — skill `macroglobal-design`** (перебивает vault-спеку и «визуальный» Vizion). Тема: styled Aura, `definePreset`, primary `#172747`, prefix `p`, darkModeSelector `.app-dark`, cssLayer true; токены в SCSS через `var(--p-*)`.

Vue **3.5** (Composition API, `<script setup>`) · TypeScript **~5.9** (strict, `noUncheckedIndexedAccess`) · Vite **7** (dev-proxy `/api`) · Pinia **3** · Vue Router **5** (guard в `policy.ts`) · **PrimeVue 4.5** · **Bootstrap 5.3 — ТОЛЬКО grid** (без utility-классов) · PrimeIcons **7** (`pi pi-*`) · **ECharts + vue-echarts** (6/8) · vue-i18n **10** (RU + EN-задел) · **vuedraggable** (Kanban, есть у Vizion) · **grid-layout-plus** (кастом-дашборды, есть у Vizion) · **@vue-flow/core** (node-полотно автоматизаций, аппрувнут) · axios (+ Sanctum Bearer) · **@sentry/vue + @sentry/vite-plugin** (error+tracing; Session Replay выключен — PII; sourcemaps через BuildKit secret-mount).

### 3.3 Сознательно НЕ используем
Tailwind · Inertia · Livewire · Filament · Chart.js · Horizon · VeeValidate/Zod · spatie/laravel-data (ручные API Resources как Vizion) · Fortify · Pest (тесты на **PHPUnit**).

### 3.4 Тулинг
PHPUnit + **SQLite :memory:** (force-override в `phpunit.xml` + guard в `TestCase`; финмодуль/FTS — отдельный PG-профиль при необходимости) · Laravel Pint · ESLint 10 + Prettier 3 · vue-tsc · GitHub Actions · `npm run lint:ds` (stylelint, design-system gate).

> **Системный урок (BUG-1, 2026-06-11):** SQLite-тесты пропустили PG-баг (`DB::raw` с двойными кавычками). Нормализация вынесена в PHP, SQL — `?`-биндинг. **Follow-up (backlog):** PG-профиль тестов (`phpunit.pg.xml` на postgres:16) для критичного raw-SQL/дедуп/FTS — особенно M9.

---

## §4. Архитектура

### 4.1 Монорепо (как Vizion: `src/` + `front/`; пишем в КОРНЕ репо)
```
macroglobalcrm/              ← корень репо (сам проект здесь)
├── src/                     ← Laravel API (PHP) — app/Domain/<Context>/{Models,Data,Enums,Services,Jobs,Policies}
├── front/                   ← Vue SPA (TS) — application/pages/components/stores/api/composables/entities/router/theme/locales/plugins
├── docker/  docker-compose.yml / docker-compose.dev.yml
├── examples/                ← {vizion/, contracts/} — эталоны (сносятся на M12/cutover)
└── .github/workflows/
```

### 4.2 DDD bounded contexts (`app/Domain/*`) — реальная раскладка
**14 контекстов в коде сегодня** (источник истины — `src/app/Domain/`):

| Context | Покрывает | Спринт | Состояние (см. §5.1) |
|---|---|---|---|
| `Iam` | User, роли, 2FA, API-токены, аудит, visibility | Фундамент | частично |
| `Org` | Department, графики, отпуска, календарь | Фундамент | каркас |
| `Crm` | Contact, Company, ContactPosition, дедуп, кастом-поля, гео-справочники | Продажи | частично |
| `Catalog` | Product, ProductPlan, цены, FxRate | Продажи | частично |
| `Sales` | Pipeline, Deal, DealProduct, KPI, dashboard, lost-reasons | Продажи | построено (зрелейший) |
| `Inbox` | InboundMessage, Channel, Form | Продажи | каркас (нет фронта) |
| `Activity` | Activity (call/meeting/task/note), задачи | Продажи | частично |
| `Notification` | Notification, Preference, Template, Broadcast (in-app + TG-бот) | Продажи | частично |
| `Contracts` | Template, Document, Approval, Revision, Licensor | Документы | каркас (генерация мертва) |
| `Onboarding` | Course, Lesson, Quiz, прогресс, AI-тьютор | Онбординг | backend построен, student-loop сломан |
| `Automation` | PipelineAutomation, Sequence, BulkTask | сквозной | каркас (built, never run) |
| `SalesPulse` | надзорный бот продаж (snapshots/metrics/announcer) | off-roadmap | прод LIVE / dev config-off |
| `Log` | EntityLog (append-only аудит-лог сущностей) | сквозной | частично (FE читает не те поля) |
| `Migration` | AMO ETL (external_refs, migration_maps, amo_product_mappings) | M12 | каркас (dormant, ETL не прогонялся) |

**Greenfield-контексты (папок ещё нет — создать при старте спринта):** `CustomerSuccess` (спринт CS), `Finance` (спринт Финансы).
**Folded (отдельных папок нет, работа вшита):** аналитика — внутри `Sales`/`Notification`; интеграции (TG/Google) — внутри `Inbox`/`Notification`. Отдельные `Domain/Analytics`/`Domain/Integration` появятся при старте соответствующих работ.

### 4.3 Frontend-структура (1-в-1 с Vizion)
`application/` (bootstrap, session, locale) · `pages/<Page>/{index.vue, composables/}` · `components/{base,cards,filters,forms,modals,tables,states,Orbita}` · `stores/` (Pinia) · `entities/` (DTO+типы) · `api/{client.ts, types/}` · `composables/async/{useAsyncResource,useMutation}` · `router/{index,policy,access}` · `theme/` (PrimeVue preset + SCSS-токены) · `locales/{ru,en}.json`.

### 4.4 RBAC: цель vs текущее состояние (долг IAM-1)
- **ЦЕЛЬ (canonical):** spatie/laravel-permission — 6 ролей + гранулярные права, проверка через Policy + `$user->can()` / permission-middleware на **sanctum**-guard.
- **СЕЙЧАС (IAM-1 + IAM-2 — 2026-06-28):** spatie работает на sanctum-guard. Domain-Policies полностью переведены на `$user->can('permission')` — 28 inline `in_array($user->role,…)`/`$user->role===` удалены из 22 файлов (Contracts 7, Catalog 3, Crm 2, Inbox 3, Sales 2 + ManagerKpiService, Automation 1 + ValidatesAutomationConfig, Onboarding 9 + LessonController + AiTutorController). Добавлено 12 domain-permissions в IAM-1 + 2 visibility-scope permissions в IAM-2 = **14 domain-permissions** в `RolePermissionSeeder::DOMAIN_PERMISSIONS`, каждое выдано точно тому набору ролей, что пропускал старый inline-чек. `PermissionAuthzOverSanctumTest` (13 тестов) + `VisibilityScopeOverSanctumTest` (7 тестов, включая 2 revocation-proof) подтверждают, что gate-authority — spatie, а не role-enum. 3322 PHPUnit зелёных.
- **IAM-2 (visibility-scope, DONE 2026-06-28):** 2 оставшихся scope-сайта переведены на spatie-permissions. `PipelineService::managesPipelines` → `$user->can('pipelines.view-all')` (admin, director); `PipelineService::canAccess` visible_role строковое совпадение → `$user->hasRole($role)`. `DocumentService::list` owner-scope → `$user->can('contracts.view-all')` (admin, lawyer, director). Поведение идентично старым inline-спискам — семантический паритет подтверждён ревью + тестами. **Prod-команда при выкатке:** `php artisan db:seed --class=RolePermissionSeeder && php artisan permission:cache-reset` (seeder идемпотентен; cache-reset обязателен — spatie кэш выдаст несвежий снимок без него).
- **Что ОСТАЛОСЬ (role-enum):** `DashboardRequest::passedValidation` — один inline `in_array($roleName,[Admin,Director])` для validation-only (422-путь, не 403; score-not-authz). Явно помечен как не-authz validation constraint; будет адресован при IAM-3 (DashboardRequest permission или `manager-cabinet.view-all`). Enum `users.role` — переходный двойной источник, будет удалён после IAM-3.
- **Prod-команда (при выкатке):** `php artisan db:seed --class=RolePermissionSeeder && php artisan permission:cache-reset` (идемпотентна: `firstOrCreate` + `syncPermissions`; пользовательские роли не трогает).

---

## §5. Поэтапная реализация (milestones)

### Порядок исполнения — спринтами (первичная система координат)
**Фундамент → Продажи → Документы → Онбординг → CS → Финансы** (+ сквозные: Automation/Integration/Bot; финал — Cutover).

| Спринт | Milestones (исторические ID) | Контексты |
|---|---|---|
| Фундамент | M0 + M1 | Iam, Org |
| Продажи | M2 + M3 + M4 + M6 | Crm, Catalog, Sales, Inbox, Activity, Notification |
| Документы | M5 | Contracts, Notification(TG) |
| Онбординг | Спринт «Онбординг» | Onboarding |
| CS | M8 | CustomerSuccess (greenfield) |
| Финансы | M9 + M10 | Finance (greenfield), Analytics (folded) |
| Сквозные | M7 + M11 + MSP | Automation, Integration, Bot, SalesPulse |
| Cutover | M12 | — (снос examples/) |

> **M-номера — только исторические milestone-ID**, маппятся на спринты выше. Статус домена выражается **состоянием** (§5.1), а не M-номером. Детальный спринт-роадмап — vault `MG CRM 2026/5. Планы/Master Roadmap.md`.

### §5.1 Компактная таблица статусов

**Status-enum:** `planned` · `in-progress` · `QA-fail` · `done-merged` (код смержен + SQLite зелёный) · **`verified-live`** (подтверждено живьём в проде). Источник правды по состоянию — `docs/audit/00-MASTER.md` (2026-06-24).

| Веха / трек | Спринт | Статус | Примечание (аудит) |
|---|---|---|---|
| **M0** Bootstrap (docker, login+2FA, роли, навигация) | Фундамент | done-merged | login+2FA — единственное **verified-live** ядро; CI/smoke (M0.7) — planned |
| M0.4 visibility-scope (`ResolveVisibility`) | Фундамент | **QA-fail (P0)** | заглушка штампует scope, никто не читает → PII-утечки CRM-1/2/3, DOC-1 |
| **M1** IAM / Org (user mgmt, departments, visibility) | Фундамент | in-progress | user mgmt = create+read+update+deactivate; **+#3 `POST /api/me/password` (self-service, current_password proof)**; **+#4 `POST /api/admin/users/{user}/reset-password` (admin-write gate, one-time plaintext, guards: no-self/no-service) + FE: `ResetPasswordResultDialog` (показ один раз, clipboard, очистка на hide; кнопка `pi pi-key` скрыта для себя) — uncommitted**; **+#8 `GET /api/admin/departments/{department}/members`** (was 404, now registered); нет throttle на /login (IAM-3); department-scope недостижим; Settings Ф1–Ф3 + dirty-guard done-merged |
| **M2** Контакты / Компании (CRUD, дедуп, справочники) | Продажи | done-merged | list/export скоупятся (волна 2); **CompanyChannel IDOR закрыт** (abort_if parent-binding, волна 4); holding-tree маскирует out-of-scope nodes (волна 4); contact/company merge re-parents все FK транзакционно (волна 4, one-current-requisite); `contact-channel-idor` (nested routes без scoped()) — открыто |
| M2+/++ Карточки 2.0 + Entity Card редизайн | Продажи | done-merged | M2++ задеплоен на прод; KPI/визуал live |
| M2+++ AMO-поля → нативные фичи (N1–N7) | Продажи | done-merged | бэкенд смержен; discount-в-amount открыт (см. M3); ДС-генерация мертва (см. M5) |
| **Wave-1 redesign follow-ups** | Продажи/CRM | done-merged (uncommitted) | Финансы semantic (POST fix-payment + PaymentFixed log); Contact KPI «оба числа» (open_tasks_count_deals); Холдинг role-select (parent/subsidiary/affiliate); Channel primary toggle (per-type, atomic unset); Реквизиты data-loss fix (nested bank_details + director_position/short); Файлы reconcile (inline DL/Delete задокументирован); Popover-добавление каналов. 93 PHPUnit зелёных. |
| **Wave-1.5 defect fixes + feed extension** | Продажи/Activity | done-merged (uncommitted) | D1 (kanban drag→POST /reschedule, overdue заблокирован как drop-target); D2 (ActivityService::applyListFilters — responsible_id/kind/status/priority/due_from/due_to/q, narrowing внутри visibility-scope; preset endpoint принимает те же фильтры; FE FilterPanel убраны дублирующие пилюли); D3 (activityOccurredAt/activityEffectiveAtSql — задача появляется в ленте в момент выполнения, не создания; ORDER per-source выровнен с merge-ключом); payment_fixed feed source (4-й источник DealFeedService, PaymentFixed EntityLog; bounded-fetch + capped total; FE pi-wallet зелёная строка; live refresh). 3209 PHPUnit зелёных. |
| **Backlog-remediation Wave 1-3 (medium/low)** | Продажи/Activity/Catalog/Automation/Onboarding/Notification | done-merged (uncommitted) | BE: DealFeed dedupe (from_stage_id NOT NULL); DealProductService price-tamper guard (server-snapshot always; DealPolicy::overridePrice → All-scope only; controllers honor override when authorized); countDealsWithoutTasks reuses nextTask predicate (single-source with list+KPI chip); DealKpiService::inWork COALESCE(company_id, -id) handles null-company deals; myBoard 'later' bucket (tasks >2 weeks out); AutomationScanner excludes terminal stages from time-based triggers; CreateTaskAction catches stage forbidden-kind ValidationException → ActionResult::skipped; ExchangeRate date_from/date_to filters + rate float cast; PriceImportService getValue() for amount cells; ProductService::update explicit field map; ContactsKpiService via VisibilityResolver::applyScope (Eloquent, not raw DB::table); NotifyAuthorListener adds in-app verdict notification (idempotent); LessonPolicy::useTutor added; AiTutorController::authorizeAssignment delegates to Gate::authorize; LessonController::streamPdf added (PDF streaming + redirect for URL-based lessons); QuizService::computeScore unrounded pass-gate ratio; QuizService eager-loads lesson for ai_generation_status N+1; SalesPulseDemoSeeder TZ idempotency fix. FE: DateField parseDateLocal (UTC off-by-one); parseDateLocal/localDateString utils; formatCurrency whole-units (Math.round, maxFractionDigits:0); EntityKpiStrip dark contrast (surface-800); DealFeedItem kind-palette tokens; WidgetDealsWithoutTasks status tokens; myTasksStore Pinia SSOT (list+board); 'later' kanban column; DealTabFinances re-sync watch+clear; DealAddProductDialog hasPriceForCurrency guard; notifications flyout digest/auto-close; RequisiteFormDialog gender_ending_oe + local dates; OpenTasksList overdue tz (operational); DealsListView country resolver; Automation sidebar nav; onboarding reorder. i18n: automation/later/noPriceHint/genderEndingOe RU+EN symmetric. 3302 PHPUnit зелёных (9442 assertions); vue-tsc 0; lint:ds 0. |
| **Документы — revival (ApprovalSummaryResource + TemplateVersionSeeder)** | Документы | done-merged (uncommitted) | ApprovalSummaryResource переписан: is_current_user_approver (pending Approval в активной стадии текущего пользователя), decision/comment, document_id, flat stage votes; ApprovalResource flat user_name; ApprovalService::getProgress threaded document_id; DocumentApprovalController passes user; TemplateVersionSeeder: durable committed seed.docx (master_skeleton_seed.docx + termination_agreement_seed.docx, 7-8 KB, pure text ${...} vars) — генерация разблокирована на dev/staging. QA-тест ApprovalSummaryApiTest (7 cases). Продовые follow-ups: seed TemplateVersionSeeder+ApprovalRouteSeeder; реальные ${...}-шаблоны после юр-ревью (Jinja2→PHPWord-vars). |
| **M3** Sales / Kanban / Дашборд | Продажи | done-merged | зрелейший домен; **discount_percent не сворачивается в amount** (open money-item, SALES#9); dashboard-export 500 (#10) |
| DS-4 Контакты (список) | Продажи | done-merged | uncommitted на ветке |
| **Contacts UI Wave 1 (PDF-фидбек)** | Продажи/CRM | **done (uncommitted, QA PASS x3)** | 1.1 ⋮ → `pi-ellipsis-v` (DealsToolbar-паттерн); 1.2 колонка «Теги» убрана → серые чипы `#тег` / `+N` в ячейке Название; 1.3 список в белую подложку (`$shadow-card`, `$radius-lg`, dark: `p-surface-100`); 5.1 drag-drop порядка колонок через `vuedraggable` в `ContactsColumnChooser`; 8.1 `ContactsDeleteDialog.vue` (самодостаточный, без ConfirmService-фантома) — list single+bulk + ContactPage card delete/detach. Доп: dead token `dialog.contentBackground` удалён из `overlays.ts`; `import Select` в ContactRelationsPanel (убран Vue-варн). i18n RU+EN: `contacts.page.delete.*` + `contacts_columns.*`. Долг Волны 3: `contacts_bulk.mergeHint` (MergeDialog). |
| **Contacts Wave 2a — поиск кириллицы + аудит фильтров (BE)** | Продажи/CRM | **done (uncommitted, QA PASS)** | BUG-2.1: поиск кириллицей (PG `LIKE` регистрозависим) → новые макросы `whereLikeCi`/`orWhereLikeCi` (`AppServiceProvider::registerLikeMacros`): PG — `ILIKE ? ESCAPE ?`, SQLite — `LOWER(col) LIKE LOWER(?) ESCAPE ?`; поддержка в `LikeEscape`; `ContactService` + `CompanyService` переведены `whereLike`→`whereLikeCi` на search-полях; `isset`→`!empty` guard (пустая строка = no-op). BUG-2.2: фильтр «Автор» (created_by_id) корректен на бэке; QA выявил рассинхрон подписи: колонка называется «АВТОР», но рендерит `owner` (Ответственный) — долг **CONTACTS-WAVE-5** (CRM-6.1). Тесты: `ContactSearchTest` +4 CI-cases; `ContactFilterTest` +4 (AND-логика, author null-guard, multi-owner/author/source/tags). Итого 3374 PHPUnit зелёных, Crm 632. Примечание: QA создал тест-контакты ids 13-17 в локальной dev БД (не в тестовой, норм). **Долг CONTACTS-WAVE-5 (CRM-6.1):** развести в таблице Контактов Автор (`created_by_id`) ↔ Ответственный (`owner_id`): (а) переименовать/добавить отдельные колонки, (б) фильтр Ответственного подписать корректно, (в) определить, что показывает колонка «АВТОР». |
| **Contacts+Deals+Tasks Wave 2b+2c — поиск кириллицы (Сделки+Задачи)** | Продажи/Sales/Activity | **done (uncommitted, QA PASS)** | Распространение фикса Wave 2a на оставшиеся модули. `DealService`: `q`/`product_q`/`city` переведены `whereLike`→`whereLikeCi`; guard пустой строки сохранён; поиск по city — через `whereHas('company', ...)` (не верхнеуровневый OR). `ActivityService`: `applyListFilters` (q) и `myBoard` (q) — `whereLikeCi`/`orWhereLikeCi` на title+body; обе точки поиска покрыты; guard `!== ''` сохранён. Тесты: `DealListFiltersTest` +7 (CI-ASCII, Cyrillic same-case q/city/product_q, AND-пересечение, empty-guard); `ActivityTaskSearchTest` (новый, 8 тестов: list q title/body CI-ASCII+Cyrillic+AND+empty, board q title+body+empty). Sales 527 PHPUnit зелёных, Activity 293 зелёных. Волна 2 (Контакты+Сделки+Задачи — поиск) **закрыта**. |
| **Contacts+Deals UI Волна 4 — Создание через полную карточку** | Продажи/CRM | **done (uncommitted, QA PASS, PM APPROVED)** | Замена мини-drawer'ов роутными create-страницами (EntityCreate-spec.md). Три новых роута `/contacts/new` / `/companies/new` / `/deals/new` перед `:id`. `ContactCreateForm.vue` / `CompanyCreateForm.vue` / `DealCreateForm.vue` — новые компоненты: инлайн-валидация (blur + submit), 422-обработка, `useMutation`. `isCreateMode = computed(() => route.name === '...')` в каждом Page. P0-фикс: `watch(route.params.id)` — переход create→detail в рамках одного компонент-инстанса (`router.replace` после POST 201); `bootstrapDeal()` для DealPage. `DealCreateDrawer.vue` удалён; quick-create `<Drawer>` в ContactsPage удалён; `useContactsPageActions.ts` очищен; `uiTriggers.ts` — no-op. Все 7 межмодульных точек входа мигрированы. Prefill: `company_id/company_name/pipeline_id/stage_id/contact_id` через query-params. i18n RU+EN: `contact.create.*` / `company.create.*` / `sales.deal.create.*`. DS: `$surface-card`/`var(--p-surface-100)` dark, `$surface-200`/`var(--p-surface-700)` border, `$red-500` req-star. |
| DS-5 Карточка сущности | Продажи | **QA-fail** | 2 dark-баги открыты (BUG-DARK-PANEL-HOVER, BUG-META-FS) |
| DS-5b Карточка сделки (exact-rebuild) | Продажи | **QA-fail** | 5 визуальных отклонений (title-fs, accent-dark, segmented-dark, tabs-dark, discount-order) |
| DS-6 Воронка (SalesFunnel 2.0) | Продажи | **QA-fail** | 11 дефектов (3 critical: empty-board, surface-card, list-chip) |
| DS-7 Wiring-ремонт фильтров/кнопок | Продажи | done-merged | uncommitted |
| Bug Pack 2 | Продажи | **QA-fail** | sort-backend-gap + 5 dark/fs-багов (CHANGES_REQUESTED) |
| M-batch (deal metrics + list sort + hidden-stage) | Продажи | done-merged | uncommitted |
| Навигация (Орбита + sidebar) Срезы 1–5 | Продажи | done-merged | QA PASS; pending merge в main |
| Уведомления in-app (#9) + bulk/export техдолг | Продажи | done-merged | колокольчик только в «Орбите» (notification#52) |
| **M5/N6** Генерация договоров (PHPWord→Gotenberg→PDF) | Документы | **QA-fail (P1, частично)** | **Генерация разблокирована на dev/staging** (`TemplateVersionSeeder` + committed seed.docx); Approval-summary API исправлен. **Волна 4:** download 401 закрыт (Blob/Bearer); template_id picker работает; signed_at принимается; resubmit gate only needs_rework; DocumentPolicy::view — active approvers (директор/CFO в approval route могут смотреть). **Открыто:** реальные production docx-шаблоны (юр-ревью); approver-юзеры в admin UI; country-code dropdown; attempt double-increment; IDOR DOC-2. |
| **M6** Активности / Задачи + Уведомления | Продажи | in-progress | задачная половина зрелая; report/FTM мертвы; status→done не complete |
| **M7** Стадийные гейты + Автоматизации | Продажи/сквозной | done-merged | Stage guardrails: `won_gate_amount_required` + `allow_stage_skip` на `pipeline_stages` (uncommitted, 3592 PHPUnit); Automation: **движок built, never run в проде** (0 правил); ретеншн-прун ломает идемпотентность (#48); **волна 4:** CreateTaskConfig/TgNotifyConfig FE прошиты на канонические ключи движка (body/responsible spec/recipient spec); ValidatesAutomationConfig валидирует правильные поля; pipeline-editor toggles для новых флагов — planned |
| FX-подсистема (Catalog) | Продажи | **QA-fail** | built, not wired: курсы не наполняются, `convert` всегда 422; **волна 4:** ImportResultResource `would_insert/would_update` на dry-run (preview counts); price-import template download через Blob/Bearer |
| Deal discount в деньгах | Продажи | **open (money-correctness)** | завышает выручку во всех агрегатах — не done |
| Company merge | Продажи | done-merged (uncommitted) | **волна 4:** `DedupService::mergeCompany/mergeContact` транзакционно re-parents все FK (8 таблиц компании + 6 таблиц контакта); one-current-requisite после merge; тесты CompanyMergeOrphansTest + ContactMergeOrphansTest |
| **MSP** SalesPulse (Slice 0–4) | off-roadmap | **verified-live (prod) / config-off (dev)** | прод LIVE; dev teams=[]; Slice 5 деплой — planned; баг `vacation()` kind |
| Лог сущностей (`Domain/Log`) | сквозной | **QA-fail** | FE EntityLogTab/MiniTimeline читают не те поля → всё «Система» (log-shell#53) |
| **M4** Inbox / Каналы / Формы | Продажи | planned (каркас) | backend интейка готов; публичной формы нет; route() не атомарен |
| **Онбординг** (курсы/квизы/прогресс) | Онбординг | **backend построен, student-loop сломан** | плеер пуст (content), quiz-edit 404, AI-черновики в score без ревью; **волна 4:** StoreQuizQuestionRequest принимает options; QuizQuestionService::create сохраняет их в JSON-колонку |
| **M8** Customer Success | CS | planned (greenfield) | `Domain/CustomerSuccess` — папки ещё нет |
| **M9** Финмодуль Ф0–Ф6 | Финансы | planned (greenfield) | `Domain/Finance` — папки ещё нет; самый объёмный шаг |
| **M10** Аналитика / Дашборды | Финансы | planned (folded) | отдельного `Domain/Analytics` нет |
| **M11** Интеграции + TG-бот | сквозной | planned (folded) | Google OAuth/Calendar/Drive, webhooks, OAuth2-провайдер |
| **AMO ETL** (`Domain/Migration`) | M12 | planned (dormant) | каркас засеян; ETL не прогонялся; mappings не читаются |
| **M12** Cutover | Cutover | planned | снос `examples/` + per-domain parity |

> **Главные блокеры к проду (P0–P1, см. `docs/audit/00-MASTER.md`):** дыра видимости (ResolveVisibility-заглушка → PII), мёртвая генерация договоров, сломанная петля онбординга, discount-в-amount, throttle на /login, IDOR документов, IAM-1 RBAC. До их закрытия ни один домен не «verified-live».

---

> **Метод каждого milestone (железно):** бизнес-логику смотрим в `./examples/contracts/` → технический паттерн копируем из `./examples/vizion/` 1-в-1 → делим по `app/Domain/<Context>`. Вертикальный срез (миграции→модели→сервисы→API→UI→тесты) + day/week-оценка + Acceptance. Каждый milestone завершается зелёным CI (done-merged) и закрывается только при **verified-live** (§9).

**🔵 M0. Bootstrap (Фундамент)** — каркас LV13+Vue/PrimeVue: docker, логин+2FA, роли, навигация, CI. M0.1–M0.3 (скелет/Docker, LV13, Sanctum+2FA) done-merged, login+2FA verified-live; **M0.4 `ResolveVisibility` — заглушка (QA-fail, P0)**; M0.5–M0.6 (frontend/layout/логин) done-merged; M0.7 CI/CD+smoke planned.
*Acceptance:* каркас одной командой; логин с 2FA; 6 ролей; навигация; **visibility-scope реально применяется** (не заглушка); CI+smoke PASS. verified-live.

**M1. IAM / Org (Фундамент)** — срез `Iam`+`Org`. (1) User CRUD + **edit/deactivate/role/dept** (сейчас create+read), профиль (пароль/2FA/SSO/API-токены), аудит-лог, **throttle на /login+/2fa/validate** (IAM-2); (2) **visibility-scope** all/department/personal — довести `ResolveVisibility` или трейт `BelongsToVisibility` (эталон `DealService::scopedQuery`); (3) Org: Department (дерево, заполнить `department_id`), графики, отпуска, календарь; (4) UI «Сотрудники/Отделы/Профиль» + guard в `policy.ts`; (5) тесты.
*Acceptance:* создание/увольнение сотрудника с передачей прав; дерево отделов; **visibility-scope ограничивает выдачу live**; профиль с 2FA+токеном. verified-live.

**M2. Контакты / Компании (Продажи)** — `Crm` (+ задел `Catalog`). Ядро (Contact/Company v2, справочники, CustomFieldDef, дедуп/merge) done-merged, **но не verified-live:** list/export не скоупятся (PII, P0), merge сиротит связи. TODO: **видимость на list/export**, **транзакц. merge** с re-parent, валидация custom-fields на company-пути.
*Acceptance:* CRUD с ИНН/КПП; кастом-поля; дедуп сливает; **списки/экспорт скоупятся по роли live**; merge не сиротит. verified-live.

**M3. Sales / Kanban (Продажи)** — `Sales`+`Catalog`. Зрелейший домен (done-merged): воронки, Kanban drag-drop, карточка сделки, дашборд (5 ECharts), KPI, bulk/export. TODO: **money-correctness (P1)** — единый `DealAmountCalculator` (line-sum − deal-discount, копейки) во всех агрегатах, **`discount_percent` сворачивать в `amount`**, фильтр бюджета ×100, итоги по валюте; dashboard-export через axios+Bearer (не `window.open`→500); pipeline-visibility wire-up или удалить. **ОТЛОЖЕН:** красная подсветка далёких дедлайнов (per-stage `warn_on_far_deadline`) — к задачнику (M6).
*Acceptance:* сделка с продуктами; Kanban drag-drop меняет стадию+пишет историю; lost-reason; list+фильтры; каталог в line-items; **агрегаты учитывают скидку live**. verified-live.

**M4. Inbox / Каналы / Формы (Продажи)** — `Inbox`. Каркас: backend интейка (12 эндпоинтов, `InboundRoutingService`→Company+Deal) готов. **Inbox triage UI DONE (uncommitted, 2026-06-28):** Gmail-style read/unread state (`inbound_messages.read_at` nullable+index); embedded channel{id,name,kind}+target_deal{id,title,stage} в ресурсе; index-фильтры q/has_deal/unread/date_from/date_to/channel_id/routing_status, per_page≤100; POST /inbox/{id}/read (idempotent), /unread, /reroute («Переобработать» — re-run InboundRoutingService на failed; dedup-guard не дублирует сделку), GET /inbox/unread-count; InboundMessagePolicy::manage (inbox.manage = admin+director only); 14 новых PHPUnit-тестов (InboundMessageTriageTest). FE: InboxPage (GM-style список+фильтрбар+детальный диалог+reprocess); api/inbox.ts; inboxStore (sidebar badge poll 60s); ChannelKindTag (оба темы); /inbox route + nav-badge (adminOnly; manager gets 403). *DEALS 2.0: Lead-сущности нет — входящий → Компания+Сделка в «Новые лиды»; `Lead`/`Counterparty` deprecated.* **Деферы M4 (phase 2):** Channels admin CRUD UI; Forms admin + конструктор форм; публичная `forms/public/{slug}` (UI нет, backend работает); атомарный авто-роутинг (транзакция+try/catch+202 анону); manager access к inbox = отдельный permission grant при необходимости.
*Acceptance:* входящее/форма → Компания+Сделка в «Новые лиды» live; публичная форма принимает заявки. verified-live.

**M5. Договоры (Документы)** — `Contracts`. **+#5 `POST /api/templates` re-enabled (по явной просьбе юзера):** `StoreTemplateRequest` (code unique `^[a-z][a-z0-9_]*$` + kind in docx/yaml/text + scopes), `TemplateService::create` (version=0, current_version_id=null), `TemplatePolicy::create` (gate `contracts.admin`); 8 новых тестов TemplateTest (→18 total). **FE-3 Create template UI DONE (uncommitted):** `CreateTemplateDialog.vue` (code regex + kind Select docx/yaml/text + title + opt scopes product_codes/country_codes; client-side validation + 422 field-errors including codeDuplicate i18n; success→toast→router.push('/admin/templates/:id')); `createTemplate()` в `api/templates.ts`; `CreateTemplatePayload` в `entities/template.ts`; кнопка «Создать шаблон» в TemplatesPage (PageHeader + empty-state) и в DirTabDocTemplates toolbar (v-if="pageRef?.canManage"); i18n-каскад: `templates.card.edit` преобразован из строки в объект {title, codesHint, placeholderCodes, codeDuplicate} → TemplateEditDialog и TemplatePage обновлены на `.title`; `text` kind добавлен в `documents.kinds`. *Комментарий «seeder-only» в `routes/api.php:739` и `TemplatePolicy:12` устарел — исторический контекст S2.1; фича активна.* **Генерация разблокирована на dev (Wave-1.5):** `TemplateVersionSeeder` дюрабл — копирует committed `resources/templates/<code>_seed.docx` (8 KB, валидные `${...}` vars) на documents-disk и создаёт TemplateVersion; `current_version_id` заполняется → генерация перестаёт 422. `ApprovalSummaryResource` переписан: `is_current_user_approver`, `document_id`, `decision`, `comment`, flat stage votes. **Prod seeder commands:** `php artisan db:seed --class=TemplateVersionSeeder && php artisan db:seed --class=ApprovalRouteSeeder`. **Открыто:** нужны реальные production-отформатированные docx-шаблоны (Jinja2→`${...}` конверсия + юр-ревью); **IDOR DOC-2** (`scopeBindings()`+проверка `child->document_id`); double-increment attempt (generation_seq vs approval_attempt); per-currency банк-счёт (`LicensorService` мёртв); UI лицензиаров; скоуп `documents.index`; реальные approver-пользователи через admin UI. WYSIWYG не делаем.
*Acceptance:* договор **генерится PHPWord→Gotenberg→PDF live**; согласование; статус-машина блокирует невалидные переходы; версии; нет IDOR. verified-live.

**M6. Активности / Задачи + Уведомления (Продажи)** — `Activity`+`Notification`. Задачная половина зрелая; in-app уведомления done-merged. **Wave-1.5 defect fixes DONE (2026-06-28):** D1 (kanban HTML5 drag→POST /reschedule; `overdue` не drop-target); D2 (ActivityService::applyListFilters — responsible_id/kind/status/priority/due_from/due_to/q, narrowing внутри visibility-scope; пресеты принимают те же параметры; FE FilterPanel убраны дублирующие пилюли, emits responsible_id); D3 (задача в ленте всплывает в момент completed_at, не created_at — `activityOccurredAt` + `activityEffectiveAtSql` синхронизированы; ORDER per-source выровнен с merge-ключом); payment_fixed (4-й источник DealFeedService: EntityLog PaymentFixed → FE pi-wallet зелёная строка + live refresh). 3209 PHPUnit. **M4/M5 Team Board (2026-07-02, uncommitted, 3592 PHPUnit):** `GET /api/activities/team-board` → `ActivityController::teamBoard` → `ActivityService::teamBoard()` — те же urgency-бакеты (overdue/today/tomorrow/this_week/next_week/later) через выделенный `bucketByDeadline()`, скоупированные на department-subtree вызывающего (fail-closed для dept-less director/manager; admin без dept — org-wide). Авторизация: `ActivityPolicy::viewTeamBoard` = `$user->can('view-manager-cabinet')` (admin/director/manager). FE: `useTeamBoard` composable (изолирован от myTasksStore), `TeamTasksFilterBar` (responsible_id + q), «Мои/Команда» segmented toggle в `TasksTopBar` (показывается только при `canSeeTeam = ['admin','director','manager']`). Фильтры: `responsible_id` и `q` через `applyListFilters`. Тесты: 9 случаев в `TeamTaskBoardTest` (dept isolation, subtree, admin-wide, forbidden 403, empty-dept fail-closed, filter narrowing). **Открытые follow-ups:** Wave 7 realtime (Reverb — ждёт OK от юзера); V5 KPI plan (ждёт ответов юзера); per-user restriction layer (M9-refinement); pipeline-editor toggles для новых stage-флагов. **Wave-1 redesign follow-ups DONE (2026-06-27):** `ActivityService::openTasksCountForDeals(array,User)` (single-query aggregated open-task count across a deal set; visibility-scoped через scopedQuery; short-circuit на empty set) + `CrmFeedService::visibleDealIdsForContact` promoted public для B-wiring в `ContactController::show` (KPI chip «+N по сделкам», поле `open_tasks_count_deals`). Тесты: 5 ContactKpiTest cases + 6 Unit/OpenTasksCountForDealsTest = 93 PHPUnit зелёных суммарно по Wave-1. **Task-management hardening DONE (2026-06-26):** B1–B6 + MINOR-8/10 закрыты: note_added/task_reopened/task_rejected лог; single-fire complete()/reopen()/reject под double-submit; targetVisible() Policy-гейт на mutation; contact-timeline bug (hardcoded 'deal'); «Выполненные» таб (completed preset); Dubai-TZ границы; ответственный-скоупинг; ActivityFormDialog jitter; /log auto-reload; server-side board buckets. 113 PHPUnit тестов зелёные. **Phase 1–4 latent-bug cleanup DONE (2026-06-26):** C8 (event-listener для EntityLog) + C9 (real status в feeds) + D11/D13 (scopeOpen final-status) + D14 (is_closed из ChangeStatusRequest убран) + A1 (completion fan-out deal→company/contacts) + A2/A5 (myOpenCount/openTasksCountForContact через scopedQuery) + F23 (countsByPreset 1 запрос) + E18 (stampDealContext visibility-scope) + E16/E17 (ownershipAllows унификация + assertResponsibleAssignable Own-scope) + A3/A4 (CrmFeed агрегация deal-активностей) + F27 (bounded-fetch + независимый capped total) + completed_at в ActivityCardResource + B31/B32 (TZ-хелперы FE) + F20/F22/F25 (prependLocal dedupe, updateActivityLocal reload, map pruning). 3147 PHPUnit тестов зелёные. **My Tasks redesign + orphaned-target authz DONE (2026-06-27):** полный редизайн страницы «Мои задачи» по Tasks-spec.md (QA PASS ×2): TasksTopBar + TasksBulkBar + TasksQuickCreate (новые), TasksKanbanBoard/TaskCard/MyTasksTable/MyTasksFilterPanel/MyTasksPresetTabs (перепиcаны); kanban 284px/bucket-colored/health-strip/auto-hide overdue/flex:0 0 auto; list 7-col/select-mode/null-safe avatar; bulk-режим (pin/reopen/delete); entity-picker autocomplete; scope-сегмент (день/неделя/месяц); view-switch kanban↔list с localStorage. ActivityPolicy::targetVisible() — orphaned-target hardening: null-target (deal удалена soft-delete) больше не блокирует мутации owned-активности; out-of-scope target по-прежнему блокирует; 7 новых PHPUnit-тестов (ActivityTargetVisibilityTest). Стабы: calendar-sync / CSV-export (toast «скоро»), EntityPicker работает с dev-данными. 2258 PHPUnit тестов зелёные. TODO: колокольчик в sidebar-режим (сейчас только «Орбита»), Telegram-link `deeplink`, Preferences/шаблоны/broadcast; MINOR-9 FTM-предикат (Sales-domain файлы). **Волна 4 Task-board bulk fix (2026-06-28):** board state lifted из `TasksKanbanBoard` в `index.vue` (SSOT); bulk pin/reopen/delete + select-all + BulkBar count работают в kanban-view; payment_fixed feed date форматирование; null/unknown activity-kind chip fallback. 3257 PHPUnit тестов зелёные.
*Acceptance:* активность в таймлайне; задача с дедлайном; уведомление in-app+email+TG по preferences live; колокольчик в обоих nav-режимах. verified-live.

**M7. Стадийные гейты + Автоматизации (Продажи/сквозной)** — `Sales`+`Automation`. **Stage guardrails (2026-07-02, uncommitted):** `pipeline_stages` получили два новых boolean-флага: `won_gate_amount_required` (default false, backfilled true на всех is_won-стадиях) и `allow_stage_skip` (default true); миграция `2026_07_02_100000_add_move_guardrails_to_pipeline_stages.php` обратима. `DealMoveService::move()` добавил два гейта: (a) move-to-won при `amount<=0` → 422 «Укажите сумму»; (b) forward-skip в стадию с `allow_stage_skip=false` → 422 (backward/adjacent/won/lost — всегда разрешены). `PipelineStageResource` выдаёт оба поля. Сидеры (`PipelineSeeder`, `AmoPipelineSeeder`) выставляют `won_gate_amount_required = is_won` в seed-данных. Тесты: `DealMoveTest` + `DealMoveServiceTest` (Unit) + `PipelineCrudTest` — 3592 PHPUnit зелёных. AmoCRM stage add/remove/reorder/recode не затронуты. **Pipeline-editor UI для новых флагов — отдельный follow-up (planned).** **Автоматизации (Automation):** движок построен end-to-end (done-merged), **но НИ РАЗУ не запускался в проде** (0 правил) — НЕ «закрыт целиком». Готово: P0–P4 + Фаза 1 (wizard/list/runs/dry-run) + node-полотно (@vue-flow). TODO: **прогнать в проде** (правило → trigger→action→audit-run live); **фикс идемпотентности** (ретеншн-прун освобождает слоты → дубль-фаер #48); FE↔BE config-дрейф (`change_owner` pool, `set_field` whitelist [notes,title]≠[title,tags]). *Вне MVP:* Sequences/BulkTask, SLA/escalation, PipelineTransition, field_value_changed/activity_completed, start_sequence/set_tags.
*Acceptance:* правило «вход в стадию → задача+TG» **срабатывает в проде** и пишется в audit-runs (verified-live, не dry-run); журнал runs с фильтрами; конструктор без поломки drag-reorder.

**Спринт «Онбординг»** — `Onboarding`. **Backend (авторская часть) построен; студенческая петля сломана end-to-end.** TODO: отдавать `content`/blocks в `AssignmentDetailResource` (плеер пуст); publish/is_draft-gate на студ-путях (AI-черновики идут в score без HR-ревью); реализовать вложенные quiz-роуты (edit вопроса 404); sync опций квиза; полировка прогресса.
*Acceptance:* студент проходит урок (плеер рендерит content), сдаёт квиз, прогресс трекается end-to-end live; неопубликованные/draft недоступны студенту. verified-live.

**M8. Customer Success (CS) — greenfield** — `CustomerSuccess` (**папки нет — создать `Domain/CustomerSuccess`**). Ведущий `cs-specialist`. ClientSubscription (B0–B6/A1–A6/C0), health-tier; модули+прогресс; ActivitySnapshot+KPI-снапшоты (cron); CS-аналитика, Excel, bulk-импорт реестра; тесты.
*Acceptance:* подписка с health-tier; чек-лист трекает прогресс; KPI-снапшот по cron; реестр импортируется; Excel. verified-live.

**🔴 M9. Финмодуль Ф0–Ф6 (Финансы) — greenfield, самый объёмный** — `Finance` (**папки нет — создать `Domain/Finance`**). Ведущий `finance-specialist`. Возможен суб-план `PLAN-finance.md` (M9.1–M9.6). Юрлица/GL-счета/кассы; операции+проводки (двойная запись)+главная книга; реестры платежей+согласования; счета-фактуры/акты; признание выручки+НДС+мультивалютность (**FxRate целочисленно, оживить наполнение**); закрытие периодов, per-entity права; отчёты P&L/Trial Balance/AR-AP/GL/VAT (ECharts+Excel). **Контракт от Продаж:** читать `Deal.amount`+`amount_locked` (авторитетный бюджет); `is_primary_deal=true`=новый клиент, `won && !is_primary_deal`=upsell. Деньги целочисленно; НДС РФ 20%; возможен PG-профиль финтестов.
*Acceptance:* операция → сбалансированные проводки; главная книга и Trial Balance сходятся; счёт/акт; НДС корректен; закрытие блокирует правки; per-entity права; P&L в ECharts. verified-live.

**M10. Аналитика / Дашборды / Excel (Финансы) — folded** — `Analytics` (**отдельной папки нет — вшито в Sales; выделить при старте**). Воронки (конверсии/длительность стадий); KPI-дашборд (цели/прогноз); когортный анализ; кастом-дашборды (drag-drop); commission-rules/salary-plans/team-targets; ECharts+Excel; тесты.
*Acceptance:* воронка с конверсиями в ECharts; KPI-дашборд считает прогресс; когорта; кастом-дашборд drag-drop; Excel. verified-live.

**M11. Интеграции + Telegram-бот (сквозной) — folded** — `Integration` (**отдельной папки нет — TG/Google вшиты в Inbox/Notification; выделить при старте**). Google OAuth (логин/Calendar/Drive), 2-way GCal-sync, Drive; API-токены (rate-limit Redis); webhooks (HMAC+retry); OAuth2-провайдер+SSO; **TG-бот** (nutgram): согласования, NL-команды, линковка; тесты.
*Acceptance:* вход через Google; событие CRM↔GCal; webhook с подписью+retry; rate-limit; TG-бот аппрувит. verified-live.

**MSP. SalesPulse — надзорный бот продаж (off-roadmap)** — `SalesPulse`. **В ПРОДЕ LIVE (verified-live), в dev config-off** (`SALESPULSE_TEAMS_JSON` не задан → teams=[]). Slice 0–4 смержены; Slice 5 (деплой) — по явной просьбе: контейнер `salespulse-bot`, env, погасить Python amo-bot. Баг `vacation()` kind (skip-мислейбл). Гейт к полному включению: живой `SALESPULSE_BOT_TOKEN` + user_id 5 менеджеров + chat_id команд.
*Acceptance:* `/startday` write-once; `/finishday` 6 метрик verbatim; `/dayresults` LLM-нарратив; планировщик Asia/Dubai; announcer дедуп meeting_done+success; `/skipday` блокирует. (Ядро verified-live в проде.)

**🧹 M12. Cutover** — `migration-specialist`+`product-manager`. **Перенос данных НЕ нужен** (тестовые). **AMO ETL** (`Domain/Migration`): подключить чтение `amo_product_mappings`/`migration_maps` (не читаются), прогнать ETL end-to-end (заполнить config-карты); сверка паритета с `./examples/contracts/` (per-domain чеклисты); полировка UI+i18n; **снести `./examples/`** (`vizion/`+`contracts/`) — проект уже в корне, обновить пути в CLAUDE/PLAN/ARCHITECTURE; активировать прод-деплой (по явной просьбе).
*Acceptance:* паритет подтверждён per-domain; `examples/` удалён; прод-деплой проходит. verified-live.

---

## §6. Глобальный Acceptance (паритет MVP)
Сводит ключевые пункты вех; галочка = **verified-live**, не просто merged.
- [ ] **M0–M1:** каркас одной командой; логин+2FA; 6 ролей; **visibility-scope применяется live**; throttle на входе; CRUD/дерево отделов; CI+smoke.
- [ ] **M2–M3:** CRUD контактов/компаний + дедуп/merge + **списки скоупятся по роли**; Kanban drag-drop + line-items + lost-reasons + **агрегаты учитывают скидку**.
- [ ] **M4–M6:** входящее/форма → Сделка в «Новые лиды»; **договор PHPWord→Gotenberg→PDF live** + согласование; активность+задача+уведомление in-app/email/TG (колокольчик в обоих nav-режимах).
- [ ] **M7 + Онбординг:** автоматизация **срабатывает в проде** (триггер→действие→audit-run)+sequence+bulk; **студенческая петля онбординга работает end-to-end** (плеер/квиз/прогресс).
- [ ] **M8–M11:** подписка с health-tier+чек-лист+KPI-снапшот; финмодуль (проводки/Trial Balance/счёт-акт/НДС/закрытие/P&L); воронка/KPI/когорты в ECharts+Excel; Google OAuth+GCal 2-way+webhook HMAC+TG-бот аппрувит.
- [ ] **M12:** паритет per-domain + cutover (`examples/` снесён).
- [ ] **Ни один домен не «закрыт» без живого прогона** (зелёный SQLite ≠ готовность); нет пакетов вне §3 без явной просьбы.

---

## §7. Конвенции (кратко; эталон — Vizion, `./examples/vizion/`)

### Backend
- PHP 8.5: `declare(strict_types=1)`, constructor promotion, readonly, enums, match.
- `env()` только в `config/`; проектные значения — `config/crm.php`.
- Eloquent: `$fillable`/`$hidden` свойства, касты через `casts()`. Translatable → jsonb.
- Сервисы в `app/Domain/<Context>/Services/`, constructor injection.
- API: префикс `/api`, `auth:sanctum`; **ручные API Resources** (НЕ spatie/data). Валидация — FormRequest.
- **Авторизация — через Policy/Gate** (целясь в spatie-permissions, см. §4.4); **никаких inline `if($user->role===...)`** в контроллерах/сервисах.
- Миграции обратимые, FK `->constrained()->cascadeOnDelete()`, деньги — целые (копейки), индексы на горячих путях. НДС РФ 20%.

### Frontend
- `<script setup lang="ts">`, Composition API. Данные — `useAsyncResource`/`useMutation` (НЕ голый fetch). Стейт — Pinia.
- UI — PrimeVue; сетка — bootstrap-grid; **никаких utility-классов Tailwind**. Цвета/размеры — токены дизайн-системы, `npm run lint:ds` зелёный.
- Per-page composables. Графики — ECharts. i18n — vue-i18n, ключи RU+EN.

### Тесты
- PHPUnit, SQLite :memory: (guard в TestCase). Feature на endpoint, Unit на сервисы. AI/HTTP — мокать. Финмодуль/FTS — PG-профиль при необходимости.
- **SQLite-зелёный — НЕ доказательство готовности** (см. §9, аудит): живой прогон обязателен перед закрытием.

---

## §8. CI/CD и Docker
- GitHub Actions: backend (sqlite-тесты `force` + pgsql migrate-smoke на `postgres:16-alpine` + **Pint блокирующий**) + frontend (vue-tsc + **eslint блокирующий** + **lint:ds** + build), раздельные jobs, PHP **8.5**.
- Docker Compose: postgres(PG16)+app(php-fpm)+nginx+frontend+gotenberg+queue-worker+scheduler (Vizion-база) + **redis (NET-NEW)**. Без Horizon.
- Деплой — rolling-restart, активируется `deploy-engineer` по явной просьбе. Прод-цель — `mgcrm.macroglobal.tech` (см. `docs/DEPLOY.md`).

---

## §9. Definition of Done (на любой milestone) — ДВА УРОВНЯ

**Уровень 1 — done-merged (код готов):**
1. Pint + ESLint/Prettier + **lint:ds** зелёные. 2. `vue-tsc` без ошибок. 3. PHPUnit зелёный (критпуть покрыт). 4. qa-tester визуальный/smoke PASS (если был UI). 5. `product-manager` сверил паритет с `./examples/contracts/` и обновил PLAN.md. 6. Нет пакетов вне §3.

**Уровень 2 — verified-live (milestone закрывается ТОЛЬКО здесь):**
7. **Фича проверена живьём в проде/dev-prod** под реальной ролью (как аудит опроверг «пустой дашборд» только живым прогоном). Богатый backend при отсутствующем/сломанном фронте = НЕ done. 8. Нет открытых blocker по `docs/audit/` для этого домена. 9. Счётчики SQLite-тестов в журнале — справочно, **не метрика завершённости**.

---

## §10. Риски
- **Объём.** 140 таблиц — поэтапно; не обещать сроки целиком.
- **Финмодуль (M9)** — отдельный крупный подпроект (4-6 недель), возможно свой суб-план.
- **Gotenberg** — внешний сервис; PHPWord-рендер сложных docx требует проверки на реальных шаблонах (сейчас docx-версии не загружены — генерация мертва).
- **«Готово, но не работает».** Корень — отсутствие live-verify гейта (исправлено §9) + дыра видимости + FE↔BE контрактный дрейф (~15 случаев). Минимизация: TS-типы из API Resources + контрактные тесты.
- **Telegram-бот** — на PHP вместо aiogram; long-polling в одном процессе.
- **Перенос данных** — не нужен (тестовые). Production-перенос — отдельная задача вне плана.
- **Tailwind→Bootstrap+SCSS** — дизайн old не 1-в-1; визуал собирается по skill `macroglobal-design`.
- **Cutover (M12)** — снос `./examples/` необратим; только после подтверждённого паритета + бэкапа.
