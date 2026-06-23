# PLAN.md — Миграция MACRO Global CRM → Laravel 13 + PrimeVue

> **SSOT плана.** Переписываем CRM с FastAPI+Next.js (`./examples/contracts/`) на жёсткий стек Laravel+PrimeVue по эталону **Vizion** (`./examples/vizion/`), домен за доменом (strangler), milestone-темпом.
> `./examples/contracts/` = источник ТОЛЬКО бизнес-логики. `./examples/vizion/` (копия Vizion) = эталон стека/конвенций. Пишем в корне репо (`src/` + `front/`).

---

## §0. Контекст и цель

**Источник (`./examples/contracts/`, macro-contracts):** монолит-замена AmoCRM. 140+ таблиц, 60+ роутеров / 300+ эндпоинтов, 60+ сервисов, 150+ страниц, 427 компонентов, 8 бизнес-доменов + Telegram-бот. Стек FastAPI + SQLAlchemy + Next.js + Tailwind. **Считаем нерабочим — переиспользуем только бизнес-логику.**

**Цель:** функциональный паритет на стеке Laravel 13 + PrimeVue, организованном по DDD, с дисциплиной пакетов и фронта строго по Vizion.

**Оценка объёма:** ~25–35 недель полного паритета. Поэтому — strangler-миграция, домен за доменом; `./examples/contracts/` остаётся точкой истины по фичам до полного переноса. Темп — **milestone-стиль** (M0…M12), как в Staffory и cloud-terminal: каждый milestone имеет day/week-оценку, вертикальный срез и Acceptance-чеклист.

---

## §1. Источники и приоритет

| Источник | Роль | Что берём |
|---|---|---|
| **`./examples/contracts/`** (macro-contracts) | ТЗ по бизнес-логике | модели, поля, связи, эндпоинты, статус-машины, фичи, YAML-шаблоны договоров, поведение фронта |
| **`./examples/vizion/`** (копия Vizion) | **ЭТАЛОН СТЕКА** | конвенции backend/frontend, структуру, конфиги, AI-каскады, PHPWord+Gotenberg, ECharts, vite/eslint/pint-конфиги — 1-в-1 |
| Staffory | вторичный | DDD-раскладка `app/Domain/*`, паттерн AI-сервисов, milestone-темп |
| Touchlink | вторичный | минимальный API+SPA скелет |

**Конфликт стека → решает `./examples/vizion/` (Vizion).** Конфликт бизнес-логики → решает `./examples/contracts/`.

> 🔁 **Рабочий цикл агента** (см. CLAUDE.md): бизнес-логику смотрим в `./examples/contracts/` (FastAPI/Next — код не копируем, копируем смысл) → технический паттерн в `./examples/vizion/` (Vizion) → делаем 1-в-1 в корне репо (`src/`/`front/`) с единственной поправкой на DDD `app/Domain/<Context>`.

---

## §2. Принципы миграции

1. **Strangler, вертикальными срезами.** Каждый milestone = миграции → модели → сервисы → API → UI → тесты, до рабочего состояния. Не «сначала весь backend».
2. **Паритет по поведению, не по коду.** Сверяем, что фича делает то же, что в `./examples/contracts/`. Код пишем заново по-вижновски.
3. **Жёсткий стек.** Любой пакет вне §3 — только по явной просьбе.
4. **Эталон прежде изобретения.** Перед новым паттерном — `grep` по `./examples/vizion/`.
5. **Перенос данных не нужен** (тестовые данные). `migration-specialist` — cutover + per-domain parity-чеклисты.
6. **Каждый milestone заканчивается зелёным CI** (PHPUnit + vue-tsc + build + lint) — это per-milestone DoD.

---

## §3. Стек (жёсткий, ограниченный)

### 3.0 Принцип выбора библиотек (library-first)
**Весь функционал — на готовых решениях, максимум библиотек, минимум своего кода** (см. ARCHITECTURE.md §0.1). Порядок: (а) уже подключённая в проекте библиотека → (б) библиотека, использованная у Vizion (`./examples/vizion/`) → (в) широко используемый maintained-пакет под наш стек (с аппрувом) → (г) свой код только если (а)–(в) не подошли. Если задачу закрывает уже доступная либа — **новую не ставим**. Новый пакет — только в случае особой необходимости и по явной просьбе/аппруву; список ниже — закрытый.

### 3.1 Backend
| Компонент | Версия | Примечание |
|---|---|---|
| Laravel | **13** | как Staffory; конвенции — Vizion |
| PHP | **8.5-fpm** | strict_types, enums, readonly, match |
| PostgreSQL | **16** | как `./examples/contracts/` (упрощает импорт данных + FTS) |
| Laravel Sanctum | 4.x | **Bearer personal access token (как Vizion)**; SPA хранит токен (как Vizion). 2FA-флоу: login → temp-токен → verify TOTP → полный токен |
| **TOTP 2FA** | — | `pragmarx/google2fa` + QR. **Точечное исключение** (есть в old) |
| **spatie/laravel-permission** | ^6.0 (v6.25.0 installed) | роли admin/director/lawyer/manager/accountant/cfo + фин-права. **Точечное исключение**. v7.x существует и поддерживает LV13, но `^6` поставлен намеренно (взаимозависимость ^9 у backup требовала ^6; проверено 2026-06-11). |
| spatie/laravel-translatable | 6.x | jsonb-поля (на будущее EN; old RU-only) |
| spatie/laravel-backup | ^10.0 (v10.3.0 installed) | ежедневный дамп БД. ^9.4 требует illuminate ^12.40, несовместимо с LV13; ^10 поддерживает LV13 — обновлено агентом 2026-06-11. |
| **Prism** (prism-php) | ^0.100 | AI-каскады (контракт-аналитика, чат) — конфиг `config/ai.php` 1-в-1 с Vizion |
| **PHPWord** + **Gotenberg** | 1.x / 8 | генерация договоров docx→PDF (замена docxtpl+LibreOffice old) — паттерн Vizion |
| Symfony ExpressionLanguage | 7.x | если нужны вычисляемые правила (как Vizion) |
| Redis | 7 | очереди/кэш/rate-limit. **NET-NEW** (у Vizion нет — он на database queue/cache). **БЕЗ Horizon** (`queue:work` как Vizion) |
| **PhpSpreadsheet** | 1.x | Excel-экспорт (CS/аналитика/финотчёты). Эталона у Vizion нет — паттерн из `./examples/contracts/` (бизнес-референс — `openpyxl`) + офиц. доки |
| **nutgram/nutgram** | — | Telegram-бот (PHP, замена aiogram). Эталона у Vizion нет — бизнес-референс `./examples/contracts/` (`aiogram`) + офиц. доки nutgram |
| **wapmorgan/morphos** | — | склонение RU (есть у Vizion). ⚠️ для «суммы прописью» morphos недостаточно (склонение ≠ запись числа словами) — нужен отдельный маленький helper/либа |

### 3.2 Frontend

> **Бренд-ассеты** хранятся в `brand/` (логотип, брендбук MACRO Global). Дизайн-система и конфиг темы PrimeVue описаны в vault `MG CRM 2026` (`6. Справочник/Дизайн-система MG CRM (бренд MACRO Global).md` и `6. Справочник/PrimeVue 4 — тема, токены и тулинг (MGCRM).md`). Тема: styled mode Aura, `definePreset` из `@primeuix/themes`, primary `#172747`, prefix `p`, darkModeSelector `.app-dark`, cssLayer true — токены доступны в SCSS как `var(--p-*)`.

| Компонент | Версия | Примечание |
|---|---|---|
| Vue | 3.5 | Composition API, `<script setup>` |
| TypeScript | ~5.9 | strict, `noUncheckedIndexedAccess` |
| Vite | 7 | dev-proxy `/api` → backend |
| Pinia | 3 | глобальный стейт (user/companies/layout/modals) |
| Vue Router | 5 | SPA, guard в `policy.ts` |
| **PrimeVue** | 4.5 | DataTable, Dialog, Toast, Menu и т.д. |
| **Bootstrap** | 5.3 | **ТОЛЬКО grid** (`bootstrap-grid.min.css`), без utility-классов |
| PrimeIcons | 7 | `pi pi-*` |
| **ECharts** + vue-echarts | 6 / 8 | аналитика, финотчёты, дашборды |
| vue-i18n | 10 | RU (+ EN на будущее) |
| **vuedraggable** | — | Kanban drag-n-drop (library-first, уже есть у Vizion) |
| **grid-layout-plus** | — | drag-drop кастом-дашборды (library-first, уже есть у Vizion) |
| axios | — | клиент + Sanctum Bearer-токен в заголовке |

### 3.3 Сознательно НЕ используем
Tailwind · Inertia · Livewire · Filament · Chart.js · Horizon · VeeValidate/Zod (валидация — inline + `useMutation`) · spatie/laravel-data (ручные API Resources как Vizion) · Fortify (Sanctum достаточно) · Pest (тесты на **PHPUnit**, как Vizion).

### 3.4 Тулинг
PHPUnit + **SQLite :memory:** (force-override в `phpunit.xml` + guard в `TestCase`, как Vizion; финмодуль/FTS-тесты — отдельный PG-профиль при необходимости) · Laravel Pint · ESLint 10 + Prettier 3 · vue-tsc · GitHub Actions.

> **Системный урок (BUG-1, S1.1, 2026-06-11):** SQLite-тесты пропустили PG-баг — `DB::raw` с `LOWER("field")` двойными кавычками (`"` = identifier в PG, `TRIM("field")` ломалось). Исправление: нормализация телефона/имени вынесена в PHP, SQL использует `?`-биндинг (`LOWER(TRIM(field)) = ?`). **Follow-up todo (backlog, не блокер):** для критичного raw-SQL / дедуп-логики рассмотреть PG-профиль тестов (отдельный `phpunit.pg.xml` на postgres:16 как second-pass CI) — особенно актуально для M9 (FTS) и любых `DB::raw` с PG-специфичным синтаксисом.

---

## §4. Архитектура

### 4.1 Монорепо (как Vizion: `src/` + `front/`; пишем в КОРНЕ репо, не в `new/`)
```
macroglobalcrm/              ← корень репо (сам проект здесь)
├── src/                     ← Laravel API (PHP)
│   ├── app/Domain/<Context>/{Models,Data,Enums,Services,Jobs,Policies}
│   ├── app/Http/{Controllers,Middleware,Requests,Resources}
│   ├── app/Console/Commands/
│   ├── config/{ai.php, crm.php, permission.php, ...}
│   ├── database/{migrations,seeders,factories}
│   ├── routes/api.php
│   └── tests/{Unit,Feature}/
├── front/                   ← Vue SPA (TS)
│   └── src/{application,pages,components,stores,api,composables,entities,router,theme,locales,plugins}
├── docker/                  ← php/nginx/frontend Dockerfiles + confs
├── docker-compose.yml / docker-compose.dev.yml
├── examples/                ← {vizion/, contracts/} — эталоны (сносятся на M12)
└── .github/workflows/
```

### 4.2 DDD bounded contexts (`app/Domain/*`)
Раскладка как Staffory, конвенции как Vizion. Каждый контекст изолирован (cross-domain — через Service-API, не прямые запросы):

| Context | Покрывает домены `./examples/contracts/` | Milestone |
|---|---|---|
| `Iam` | User, роли, 2FA, SSO, API-токены, аудит, visibility | M1 |
| `Org` | Department, графики, отпуска, производственный календарь | M1 |
| `Crm` | Contact, Company, ContactPosition, дедуп, кастом-поля, справочники гео | M2 |
| `Sales` | Pipeline, Deal, DealProduct, lost-reasons | M3 |
| `Inbox` | InboundMessage, Channel, Form | M4 |
| `Contracts` | Template, Contract, Approval, ContractRevision | M5 |
| `Activity` | Activity (call/meeting/task/note), задачи | M6 |
| `Notification` | Notification, Preference, Template, Broadcast | M6 |
| `Automation` | PipelineAutomation, Sequence, BulkTask | M7 |
| `CustomerSuccess` | ClientSubscription, Module, чек-листы, KPI-снапшоты | M8 |
| `Finance` | весь Ф0–Ф6 (GL, операции, счета, акты, отчёты, НДС) | M9 |
| `Analytics` | воронки, KPI, когорты, Excel, дашборды | M10 |
| `Integration` | Google Calendar/Drive, OAuth2-провайдер, webhooks | M11 |
| `Onboarding` | Course, Lesson, Quiz, прогресс | Спринт 3 (между M5 и M8) |
| `Catalog` | Product, ProductPlan, цены, FxRate | M2/M3 (по зоне sales) |

### 4.3 Frontend-структура (1-в-1 с Vizion)
`application/` (bootstrap, session, locale, company coordinators) · `pages/<Page>/{index.vue, composables/}` · `components/{base,cards,filters,forms,modals,tables,states,Toolbox}` · `stores/` (Pinia) · `entities/` (DTO+типы) · `api/{client.ts, types/}` · `composables/async/{useAsyncResource,useMutation}` · `router/{index,policy,access}` · `theme/` (PrimeVue preset + SCSS-токены) · `locales/{ru,en}.json`.

---

## §5. Поэтапная реализация (milestones)

### Порядок исполнения — спринтами

**Фундамент → Продажи → Документы → Онбординг → CS → Финансы**

| Спринт | Milestones | Контексты |
|---|---|---|
| 0 — Фундамент | M0 + M1 ядро | Iam, Org |
| 1 — Продажи | M2+M3+M4+M6 | Crm, Catalog, Sales, Inbox, Activity |
| 2 — Документы | M5 | Contracts, Notification(TG) |
| 3 — Онбординг | Спринт 3 | Onboarding |
| 4 — CS | M8 | CustomerSuccess |
| 5 — Финансы | M9+M10 | Finance, Analytics |
| Сквозные | M7+M11 | Automation, Integration, Bot |
| Cutover | M12(финал) | — (снос examples/) |

Онбординг — отдельный спринт между Документами и CS (по решению владельца 2026-06-11).

> Детальный спринт-роадмап ведётся в vault **`MG CRM 2026`** (`5. Планы/MGCRM (Laravel) — Master Roadmap.md`). Каждый спринт детализируется в отдельном плане перед стартом.

---

> **Темп — milestone-стиль как Staffory/cloud-terminal.** Каждый milestone — вертикальный срез: миграции → модели/сервисы → API → UI → тесты. **M0 расписан детально по шагам** (M0.1–M0.7) — это фундамент. M1–M12 — крупными блоками с ключевыми сущностями и Acceptance; детализируются перед стартом каждого силами `product-manager` + профильного агента.
>
> **Метод каждого milestone (железно):** смотрим бизнес-логику в `./examples/contracts/` → копируем технический паттерн из `./examples/vizion/` (Vizion) **1-в-1** → делим по `app/Domain/<Context>`. **Каждый milestone завершается зелёным CI** (PHPUnit + vue-tsc + build + Pint/ESLint) — per-milestone DoD.
>
> Оценки реалистичны для суммарных ~25–35 недель. **M9 (финмодуль) — самый объёмный шаг (4–6 недель), возможно собственный суб-план.**

---

### 🔵 M0. Bootstrap (3-4 дня) — копируем Vizion 1-в-1

**Цель:** рабочий пустой каркас Laravel13+Vue/PrimeVue: запускается в docker, логин с 2FA, роли, навигация, CI зелёный. Фундамент для всех доменов.

**Ведущие:** `backend-specialist` (бэк/инфра) + `frontend-specialist` (фронт) + `designer` (layout/токены) + `deploy-engineer` (docker/CI).

#### M0.1 — Скелет монорепо и Docker (backend-specialist + deploy-engineer) ✅
> **Стартовая точка:** у Vizion `docker-compose.yml` уже содержит **6 сервисов** (app, nginx, frontend, queue-worker, gotenberg, postgres) и **БЕЗ redis**. → `gotenberg` + `queue-worker` копируемы 1-в-1; **redis — NET-NEW** (эталона у Vizion нет). `docker-compose.dev.yml` у Vizion **НЕ существует** — авторим его сами.
- [x] Скопировать структуру каталогов и docker-конфиги из `./examples/vizion/` (`src/`, `front/`, `docker/php`, `docker/nginx`, `docker/frontend.Dockerfile`), переименовать (`macro-crm-*`).
- [x] **redis (NET-NEW, нет эталона у Vizion):** `redis:7-alpine`, bind `127.0.0.1`, в ОБА compose-файла; `depends_on: redis` на `app` и `queue-worker`.
- [x] `docker-compose.dev.yml` (авторим сами — у Vizion нет): сервис `db` (postgres:16-alpine, volume `pgdata`), `redis` (redis:7-alpine, 127.0.0.1). API/web — на хосте или в контейнере (как Vizion `composer run dev`).
- [x] `docker-compose.yml` (prod): app(php-fpm), nginx, frontend(node build), queue-worker, gotenberg:8, postgres:16 — 6 копируемых из Vizion + redis (NET-NEW). Без Horizon. build-контекст (не GHCR-образы), имена переименованы `macro-crm-*`.
- [x] `.env.example` с категориями: DB, SANCTUM, ADMIN seed, REDIS, ANTHROPIC (Prism), GOOGLE, SMTP, TELEGRAM, GOTENBERG_URL.
- **DoD:** `docker compose up -d postgres redis` поднимает БД + redis; образ собирается (`docker compose build app` — PASS 2026-06-11).

#### M0.2 — Laravel 13 bootstrap (backend-specialist) ✅
> **НЕ копировать `composer.json` require-блок Vizion 1-в-1** — у Vizion LV12/PHP8.2/PHPUnit11, у нас LV13/PHP8.5 → конфликт версий. `composer create-project` даёт LV13-корректный скелет (phpunit/collision уже под LV13), пакеты §3.1 ставим ПОВЕРХ. Vizion-конфиги — LV12-формы (`bootstrap/app.php`, `config/*`) → **адаптируем, не 1-в-1**.
- [x] `composer create-project laravel/laravel src` (LV13, 13.15.0) + PHP ^8.5 в Dockerfile.
- [x] `config/database.php` — pgsql основной; `phpunit.xml` — SQLite :memory: с `force="true"` + `tests/TestCase.php` тройная изоляция (putenv + setUp abort-guard).
- [x] Установить пакеты §3.1: sanctum ^4.3, spatie/permission ^6.0 (v6.25.0), spatie/translatable ^6.13, spatie/backup ^10.0 (v10.3.0), prism ^0.100, phpoffice/phpword ^1.4, phpoffice/phpspreadsheet ^5.0, pragmarx/google2fa ^8.0, wapmorgan/morphos ^3.2, predis/predis ^3.0.
- [x] Redis: `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_HOST=redis` в `.env.example`; `config/redis.php` (только predis-тюнинг).
- [x] `config/ai.php` — Anthropic-only каскад (Z.AI/GLM полностью вырезан); `config/crm.php` (роли, валюты RUB/USD/EUR/KZT/UZS/AED, VAT 2000bps); `config/2fa.php`.
- **DoD (PASS 2026-06-11):** `php artisan migrate` на pgsql = «Nothing to migrate»; `vendor/bin/phpunit` (sqlite :memory:) = 2 tests, 2 assertions — OK; `config:cache` — OK.

#### M0.3 — Аутентификация: Sanctum + 2FA (backend-specialist) ✅ DONE 2026-06-11
> **Auth = Bearer personal access token (как Vizion реально):** `createToken('api')->plainTextToken`, фронт хранит токен. ⚠️ Vizion-`AuthController` написан ДО правил ARCHITECTURE.md (inline `$request->validate` + сырой `response()->json`) — **НЕ копировать verbatim**, переписать через `LoginRequest` (FormRequest) + `UserResource`. 2FA-флоу **эталона у Vizion нет** — реплицируем документированный флоу `./examples/contracts/apps/api/app/routers/auth_2fa.py` (setup → verify-setup → validate → backup codes → temp-токен), на `pragmarx/google2fa`.
- [x] `User` в `app/Domain/Iam/Models/User.php`: поля из old (email, password_hash, full_name, **role enum**, telegram_user_id, avatar_path, department_id, manager_id, is_active, **totp_enabled, totp_secret, backup_codes**).
- [x] Sanctum **Bearer**: `POST /api/login` (→ `plainTextToken` или temp-токен при 2FA), `/logout`, `GET /api/me`, защита `auth:sanctum`. Контроллер — через `LoginRequest` + `UserResource` (НЕ verbatim Vizion).
- [x] 2FA-флоу (по `auth_2fa.py`): `login` (success) → temp-токен → `POST /api/2fa/validate` → полный токен. Setup: `POST /api/2fa/setup` (QR) → `/2fa/verify-setup`. Backup-коды (8, hashed). Реализация — `pragmarx/google2fa`.
- [x] Фронт-`vite.config.ts` — задел до M0.5 (frontend-specialist перенацелит proxy).
- **DoD PASS:** Feature-тесты login/logout/me/2fa-флоу зелёные (42/42).

#### M0.4 — Роли и доступы (backend-specialist) ✅ DONE 2026-06-11
> ⚠️ **`spatie/laravel-permission` — эталона у Vizion НЕТ** (Vizion использует простую строковую колонку `role` — это явно НЕ наш паттерн по ARCHITECTURE.md). Помечаем как **NEW-пакет**; 6 ролей выводим из `./examples/contracts/`, не из Vizion. **`SetLocale` middleware — копируем у Vizion** (`routes/api.php`, locale middleware).
- [x] spatie/permission (NEW): `RolePermissionSeeder` — 6 ролей (admin/director/lawyer/manager/accountant/cfo) + 18 permissions (BASE + FINANCE split accountant vs cfo), idempotent. Роли — из `./examples/contracts/`. `AdminSeeder` (admin@mgcrm.test/password, 2FA off).
- [x] Middleware `SetLocale` (копия Vizion), `Verify2FA` (ability-positive fail-closed), `ResolveVisibility` (fail-closed, scope all/own; Department scaffold для M1). `config/sanctum.php` guard:[] (web-session fallback убит).
- [x] `Department` model (Org context) — дерево + manager + members; FK split-миграция (циклическая зависимость users↔departments разрешена).
- **DoD PASS:** `migrate:fresh --seed` → 6 ролей + dev-admin; `login→(2FA)→token`; `ResolveVisibility` all/own; temp-токен 403 на /me; 42/42 green.

#### M0.5 — Frontend bootstrap (frontend-specialist) ✅ DONE 2026-06-11
- [x] `npm create vite front` (Vue 3.5+TS strict `noUncheckedIndexedAccess`), структура `front/src/*` по Vizion-паттерну.
- [x] `main.ts`: Pinia + persist(`['token']`) + Router + i18n + **PrimeVue 4.5 preset** (`definePreset(Aura)`) + axios-middleware + `bootstrapPromise.then() → router.isReady() → mount`.
- [x] Тема: `definePreset(Aura, {...})`, primary `#172747`, `options{prefix:'p', darkModeSelector:'.app-dark', cssLayer:true}`, готча colorScheme соблюдена (light/dark зеркало). SCSS-мост `var(--p-*)`.
- [x] `api/client.ts`: axios + Sanctum **Bearer** + 401 → onUnauthorized callback; без голого axios в компонентах.
- [x] `composables/async/{useAsyncResource,useMutation}` + request-gate (stale-cancel).
- [x] `entities/user.ts`: `UserRole` enum (6 ролей), `mapUser` с fail-safe fallback.
- [x] devDeps: `jiti` + `@vue/eslint-config-typescript` (ESLint 10 flat-config tooling) — ОК.
- **DoD PASS:** `npm run type-check` 0 ошибок, `npm run build` OK (335 модулей).

#### M0.6 — Layout, навигация, логин-страница (frontend-specialist) ✅ DONE 2026-06-11
- [x] `DefaultLayout` (sidebar + topbar + main), AppShell hide на `/login`, global Toast.
- [x] `AppSidebar.vue` (тёмный `#172747`, collapse-to-rail persist `sidebarCollapsed`).
- [x] `stores/user.ts`, `stores/layout.ts` (persist `sidebarCollapsed`+`isDarkMode`).
- [x] Router-guard `policy.ts` (auth/redirect, fail-closed), root `/` резолвится динамически.
- [x] Страницы: `LoginPage` (email+password+2FA state-machine + backup-code), `ProfilePage` (8 табов `?tab=`, 2FA setup/verify-setup), пустой `DashboardPage`.
- [x] i18n: `ru.json` + `en.json` — полные ключи (nav/auth/2FA/profile/errors/common/roles/dashboard).
- [x] QR для 2FA setup — текстовый `otpauth://` URI (без qrcode-либы); follow-up для M1+.
- **DoD PASS:** type-check/lint/build зелёные. Браузерный smoke — перенесён на M0.7 (frontend-контейнер не в compose).

#### Навигация — Срез 1 (frontend-specialist, 2026-06-16) ✅ PM APPROVE (беклог-замечания, не блокеры)
> ТЗ: `5. Планы/Навигация — ТЗ (Орбита + боковое меню).md` / branch `feat/navigation-redesign` / commit `3543b49`
- [x] `front/src/shared/nav/navItems.ts` — единый источник пунктов (`prototypeNavItems` 5 шт. + `adminNavItems` 9 шт. + `allNavItems` + `filterNavByRole()`).
- [x] `AppSidebar.vue` рефакторинг — таблетки (margin 2px 8px / radius 9px / padding 8px 10px), активный бар 3×18px `::before`, бейджи (inline expanded, точки collapsed), логомарк «MG» hover→chevron, ролевой блок admin с хайрлайн-разделителем.
- [x] `front/src/components/AppShell/AccountMenu.vue` — НОВЫЙ: `<Popover>` идентити+тема+язык+профиль+выход; `defineExpose({ toggle })`.
- [x] `front/src/stores/theme.ts` — НОВЫЙ: `theme: 'light'|'dark'`, persist key `mgcrm_theme`, `setTheme()` / `toggleTheme()`.
- [x] `front/src/theme/scss/foundation/_colors.scss` — 7 SCSS-токенов sidebar (`$sidebar-bg/hover-bg/active-bg/active-bar/text/text-active/divider`); хардкод `#0e172b` и `#2b4987` вынесены из AppSidebar.
- [x] `stores/layout.ts` — добавлены `navMode`, `orbitPos`, `orbitOrientation`, `orbitCollapsed`, `commandPaletteOpen`, `recentRoutes`; `isDarkMode` + `toggleDarkMode` помечены `@deprecated`.
- [x] `DefaultLayout/index.vue` — `AppTopbar` удалён; условный рендер sidebar по `navMode === 'sidebar'`; `layout__main--full` для orbit-режима.
- [x] `main.ts` — инициализация `themeStore` с миграцией из `layoutStore.isDarkMode` (backward compat).
- [x] i18n: добавлены ключи `account.*`, `orbita.*`, `layout.*`, `commandPalette.*`, `hotkeys.*` в `ru.json` + `en.json`.

#### Навигация — Срез 2 (frontend-specialist, 2026-06-16) ✅ PM APPROVE (беклог-замечание не блокер)
> branch `feat/navigation-redesign` / commit `75dc533`
- [x] `front/src/components/Orbita/` — НОВАЯ ПАПКА: Orbita.vue / OrbitaPanel.vue / OrbitaToggle.vue / NotificationsButton.vue / UserProfileButton.vue / composables/{useOrbitaDrag,useOrbitaPanelDirection,useOrbitaTooltip,useOrbitaOverlays} / positioning.ts / zIndex.ts / styles/{_tokens.scss,_compact-control.scss} / types.ts / index.ts / locale/{ru,en}.json — 1-в-1 из Vizion Toolbox.
- [x] `DefaultLayout/index.vue` — `<Orbita v-if="layoutStore.navMode === 'orbit'">` добавлена.
- [x] `ProfilePage/index.vue` + `composables/useProfilePage.ts` — вкладка «Внешний вид»: `SelectButton` темы + карточки navMode (sidebar/orbit); `setNavMode()`.
- [x] `locales/{ru,en}.json` — ключ `layout.appearance` добавлен.
- **Беклог:** `pi pi-layout-sidebar` не существует в PrimeIcons v7 — нужна замена (не блокер рендера).

#### Навигация — Срез 3 (frontend-specialist, 2026-06-16) ✅ PM APPROVE — pending браузерный QA
> branch `feat/navigation-redesign` / commit `28a1963`
- [x] `useOrbitaDrag.ts` — drag через `pointer*` events + `setPointerCapture` для надёжности; кламп в viewport (`clampOrbitaPosition`); магнит к краю `snapToEdge()` с CSS `transition 0.2s ease-out` при отпускании; клавиатурный drag (Arrow 16px на grip-ручке, Enter/Space — commit); live позиция без записи в стор во время drag (коммит только на `pointerup`); `resize`-реклэмп; чистый cleanup всех listeners в `onBeforeUnmount`.
- [x] `Orbita.vue` — DOM-порядок исправлен (Panel перед Toggle → Toggle-якорь крайний справа/снизу); `handleRotate()` — pivot вычисляется по центру `.orbita-toggle` до и после `nextTick`+`rAF` с докладкой позиции; `onMounted` re-clamp убран (обслуживается `useOrbitaDrag.scheduleClamp()`); `is-dragging` CSS-класс; `cursor: default` вместо `grab` на весь контейнер.
- [x] `OrbitaPanel.vue` — `labelSide` computed (`start`/`end`/`center`) по `currentPosition.left < viewport/2`; `data-label-side` на контейнере; CSS: H in-place flex + `max-width` transition; V `flex-direction: row`/`row-reverse` по стороне; `forced-colors` media query; `useNavPrefetch` подключён — `prefetch()` на `@mouseenter`/`@focus` nav-кнопок.
- [x] `OrbitaToggle.vue` — rotate-сателлит (`pi pi-sync`; `@click → toggle-orientation`); grip — одиночный (убрана дублирующая вторая grip-группа); `tabindex="0"` + `@keydown` для клавиатурного drag; `isDragging` prop + `.is-dragging` CSS-класс; `focus-visible` стиль; `overflow: hidden` убран (препятствовал выходу `forced-colors` border); `role="group"` на контейнере (было некорректно `aria-label` на div).
- [x] `NotificationsButton.vue` — заглушка с пустым Popover-флайаутом (pending backend `GET /api/notifications`); `defineExpose<OrbitaOverlayControl>({ syncPopover, realign })` добавлен.
- [x] `UserProfileButton.vue` — `defineExpose<OrbitaOverlayControl>({ syncPopover, realign })` добавлен.
- [x] `AccountMenu.vue` — `defineExpose({ toggle, hide, show })` (добавлен `show` для корректного вызова через overlay-контрол).
- [x] `locales/{ru,en}.json` — добавлены `orbita.navLabel` + `orbita.profile`; `orbita.rotateH` + `orbita.rotateV`.
- [x] A11y: `<nav aria-label="…">` вокруг nav-кнопок; `aria-label` на всех кнопках toggle; `aria-current="page"` на активной nav-кнопке; `prefers-reduced-motion` блок в `Orbita.vue`; `forced-colors` блоки в `OrbitaPanel.vue` + `OrbitaToggle.vue`; `focus-visible` outline на grip + rotate.
- **Беклог:** поворот на мобильном (< 560px H → grid fallback в CSS, pivot-логика не проверялась).

#### Навигация — Срез 4 (frontend-specialist, 2026-06-16) ✅ PM APPROVE (commit e7328a8)
> Scope: CommandPalette + useNavHotkeys + useNavPrefetch + overlay mutual exclusion + AppSidebar prefetch + canvas fixes
- [x] `CommandPalette.vue` — PrimeVue Dialog + нативный `<input>` (без PrimeVue InputText, нет VeeValidate); fuzzy-match (prefix>contains>char-scatter); группировка Pages/Actions/Recent; клавишная навигация ↑↓ Enter Esc; `layoutStore.pushRecentRoute()` при навигации; `layoutStore.closeCommandPalette()` при Escape/выборе; focus на input при открытии; `@after-hide` сброс состояния.
- [x] `HotkeysCheatsheet.vue` — PrimeVue Dialog; таблица ключей через `NAV_HOTKEY_ENTRIES`; разделение nav/other секций; `<kbd>` элементы; dark mode через `:global(.app-dark)`.
- [x] `composables/useNavHotkeys.ts` — `window.addEventListener('keydown', …, { capture: true })` при mount, `removeEventListener` при unmount; чистая очистка `gTimer` через `clearSequence()`; guard `isInteractiveFocus()` (tagName INPUT/TEXTAREA/SELECT + contentEditable) + `isModalOpen()` (`[role="dialog"][aria-modal="true"]`); Cmd/Ctrl+K → palette; `?` → cheatsheet; `g→X` 8 последовательностей с таймаутом 1500ms; платформо-адаптивный модификатор (`navigator.platform.includes('Mac')`).
- [x] `composables/useNavPrefetch.ts` — module-level `Set<string>` dedup; `router.resolve()` + lazy component factory call; ошибки glotают молча (best-effort); подключён в `OrbitaPanel.vue` и `AppSidebar.vue`.
- [x] `DefaultLayout/index.vue` — `<CommandPalette v-if="showLayout" />` + `<HotkeysCheatsheet v-if="showLayout" />`; `useNavHotkeys({ onOpenCommandPalette, onOpenCheatsheet })`; `router.afterEach` → `layoutStore.pushRecentRoute()`.
- [x] `Orbita.vue` — `useOrbitaOverlays` подключён с refs на `notificationsRef` и `userProfileRef`; дубликат `onMounted` re-clamp удалён (теперь только в `useOrbitaDrag.scheduleClamp()`).
- [x] `AppSidebar.vue` — `useNavPrefetch` подключён; `@mouseenter`/`@focus` на nav-links для обоих блоков (protoype + admin).
- [x] `index.ts` — экспортированы `CommandPalette`, `HotkeysCheatsheet`, `useNavHotkeys`, `cheatsheetOpen`, `openCheatsheet`, `closeCheatsheet`, `NAV_HOTKEY_ENTRIES`, `useNavPrefetch`.
- [x] i18n `ru.json`/`en.json` — добавлены `hotkeys.other`, `hotkeys.goToCompanies`, `hotkeys.goToTasks`, `hotkeys.goToDocuments`, `hotkeys.goToApprovals`, `hotkeys.goToCourses`, `hotkeys.showHelp`.
- **Беклог (deferred по ТЗ §3.4):** ~~Quick-actions синк~~ **РЕАЛИЗОВАН задачей #10 (2026-06-16) — см. Срез 5 ниже.**
- **PM-статус:** QA PASS. Committed (`e7328a8`). Ветка feat/deals-redesign, pending merge в main.

#### Навигация — Срез 5: Quick-actions синк в профиль (#10) ✅ PM APPROVE (commit 1041ce8, 2026-06-17)
> backend-specialist + frontend-specialist; QA partial pass → 2 QA-баги исправлены (PickList move-all guard + заголовки через named slots)
- [x] **Миграция** `2026_06_16_120000_add_nav_quick_actions_to_users_table.php` — `users.nav_quick_actions json nullable`, обратима (`down` dropColumn). Применена к dev Postgres.
- [x] **`User.php`** — `nav_quick_actions` добавлен в `$fillable` + `casts()` → `'array'`.
- [x] **`UpdateProfileRequest`** — `nav_quick_actions`: `nullable|array|max:5`, каждый элемент `required|string|max:64|distinct`.
- [x] **`ProfileController`** — тонкий: `$request->user()` → `ProfileService::update()` → `UserResource::make()`.
- [x] **`ProfileService`** — `array_key_exists` guard (не трогает поле при отсутствии), `array_values` реиндекс, null — явная очистка.
- [x] **`UserResource`** — `nav_quick_actions` (`?? []`); не протекает секретов.
- [x] **`UserFactory`** — `nav_quick_actions => null` по умолчанию.
- [x] **Роутинг** `PATCH /api/me/profile` — middleware `auth:sanctum|2fa|locale|visibility`, name `me.profile.update`.
- [x] **Тесты** — 12 Feature (`ProfileUpdateTest`) + 4 Unit (`ProfileServiceTest`) = 16 новых; сьют 1583 зелёный (SQLite :memory:).
- [x] **`quickActionRegistry.ts`** — единый реестр 10 действий (ключ/i18n/иконка/маршрут); `resolveQuickActions()` безопасно пропускает неизвестные ключи.
- [x] **`QuickActionsCluster.vue`** — рендер в слоте `#actions` Orbita; иконки с tooltip; `execute()` (router.push / themeStore / commandPalette); a11y (`aria-label`, `focus-visible`).
- [x] **`QuickActionsPickerDialog.vue`** — PrimeVue PickList + drag-reorder; `move-all` и `move-to-target` под охраной `onMoveToTarget` (откат overflow); named slots `#sourceheader`/`#targetheader` со счётчиком N/5; `useMutation` + toast; `v-model:visible`.
- [x] **ProfilePage вкладка `quickActions`** — preview выбранных действий; кнопка «Настроить» → пикер-диалог; `pickerVisible` ref; `resolveQuickActions(userStore.getNavQuickActions)`.
- [x] **`userStore`** — `getNavQuickActions: computed<string[]>` + экспорт.
- [x] **`user.ts` entity** — `nav_quick_actions: string[]` в `User` интерфейсе; `mapUser` с `?? []` fallback.
- [x] **`auth.ts` types** — `UserData.nav_quick_actions: string[]`.
- [x] **`profile.ts` api** — `UpdateProfileRequest.nav_quick_actions?: string[] | null`; `profileApi.updateProfile()` → `PATCH /api/me/profile`.
- [x] i18n `ru.json`/`en.json` — секция `quickActions.*` (10 ключей) + вкладка `profile.tabs.quickActions`.
- **Синк реализован через backend:** после save — `PATCH /api/me/profile` → БД → `userStore.setCurrentUser(mapUser(response.data))` → Pinia → Orbita реактивно перерисовывается. LocalStorage не используется.
- **Лимит ≤5 двойная защита:** бэк — `max:5` в `UpdateProfileRequest` (422 при превышении); фронт — `onMoveToTarget` откатывает overflow немедленно + `validationError` computed блокирует кнопку Save.

#### Навигация — Срез 4 ✅ ФИНАЛЬНЫЙ STATUS (commit e7328a8, браузерный QA PASS)
> Все 4 среза навигации (commit 3543b49 / 75dc533 / 28a1963 / e7328a8) запушены на ветке feat/deals-redesign. QA пройден. Pending merge в main.

#### Уведомления in-app (#9) ✅ PM APPROVE (commit 21fdd73, 2026-06-17)
- [x] `Domain/Notification/` — модель `Notification`, `NotificationCategory` enum (task/approval/deal/mention/system), `NotificationService` (createForUser/dispatch/grouped/markRead/markAllRead/unreadCount/digest/markReadBatch), `NotificationPolicy` (recipient-scoped), `NotificationController` (GET /api/notifications, GET /api/notifications/count, POST /api/notifications/read-batch, POST /api/notifications/{id}/read, POST /api/notifications/read-all), `NotificationFeedResource`+`NotificationResource`, `ReadBatchNotificationsRequest`, `NotifyActivityAssigneeListener` (ActivityAssigned), `NotifyApproversListener` (DocumentSubmittedForApproval). Миграции: `create_notifications_table` + `add_action_label_to_notifications_table` (applied dev Postgres). Frontend: `notificationsStore` (fetchUnreadCount через GET /count, decrement, clear), `useNotificationsFlyout` (load/loadMore/markRead/markAllRead/onFlyoutClose batch), `NotificationsButton.vue` flyout в Орбите. 63 теста PHPUnit; suite 1601 PASS.

#### Техдолг bulk/export/select-all (A) ✅ PM APPROVE (commit 77adb5c, 2026-06-17)
- [x] Backend: `BulkDealService` (all-or-nothing, Policy per-deal, DB::transaction), `BulkDealActionRequest` (4 операции: change_owner/change_stage/set_field/edit_tags), `BulkDealDeleteRequest`, `DealExportService` (filteredQuery + chunkById 500, XLSX). Маршруты `PATCH /api/deals/bulk` + `DELETE /api/deals/bulk` (перед `deals/{deal}`). 8 тестов `DealBulkTest` + 3 теста `DealExportTest`. Frontend: select-all Checkbox (indeterminate), `allVisibleDealIds` computed (kanban/list/tasks), 5 bulk-диалогов (BulkAssignDialog/BulkMoveStageDialog/BulkEditFieldDialog/BulkTagDialog/BulkAddTaskDialog), preselect via salesStore. Беклог (не блокер): `rate_available`↔`fx_rate_available` несовпадение ключа; rottingClass хардкод 7/14; preselect через URL-query не реализован.

#### Техдолг unread count + read-batch (B) ✅ PM APPROVE (commit f8c8c55, 2026-06-17)
- [x] `GET /api/notifications/count` — lightweight badge bootstrap (без grouped агрегации). `POST /api/notifications/read-batch` — пакетная отметка (max 200 ids, user-scope guard в service). Frontend `notificationsStore` переведён на GET /count для badge; read-batch на close flyout.

#### Техдолг фронт-клинап (C) ✅ PM APPROVE (commit 04c45bb, 2026-06-17)
- [x] `uiTriggersStore` (stores/uiTriggers.ts, NEW Pinia, НЕ persist) — event-bus для create-drawer триггеров. `quickActionRegistry.ts` — actionType: 'route'|'drawer'|'inline' + drawerKey; `create_deal`/`create_contact` → actionType: 'drawer'. `QuickActionsCluster::execute()` — switch на actionType вместо switch на key. `DealsPage` + `ContactsPage` — watcher pendingDrawer + clearDrawer on unmount. `useMutation<void>` → `useMutation<MeResponse>` в QuickActionsPickerDialog. `toggleDarkMode/setDarkMode` удалены из layoutStore (isDarkMode ref сохранён для boot-migrate в main.ts). `ProfilePage/index.vue` — layoutStore.setDarkMode() → themeStore.setTheme(). `HrStatusPieChart` + `useMacroCrmEchartsTheme` — layoutStore.isDarkMode → themeStore.theme. vite manualChunks — path-паттерны уточнены, чанки echarts/vue-flow/vendor-markdown добавлены.

#### M0.7 — CI/CD + smoke (deploy-engineer + qa-tester)
> **Реальный Vizion `ci.yml`:** поднимает сервис `postgres:16-alpine` и гоняет `migrate --force` на pgsql, при этом `php artisan test` уходит в sqlite (через `phpunit.xml force="true"`). **Pint-шага у Vizion НЕТ**, lint — `continue-on-error: true`, PHP `8.3`. Наш CI отличается осознанно.
- [ ] `.github/workflows/ci.yml`: backend job = **(а)** `php artisan test` на sqlite (force) + **(б)** pgsql migrate-smoke (сервис `postgres:16-alpine`, `migrate --force`) + **(в) явный Pint-шаг (БЛОКИРУЮЩИЙ)**; frontend job = vue-tsc + **eslint (БЛОКИРУЮЩИЙ — наш DoD = зелёный CI)** + build. PHP **8.5**.
- [ ] `deploy.yml` — болванка rolling-restart (по образцу `./examples/contracts/`/Vizion, без активации до прод-готовности).
- [ ] `qa-tester`: Playwright smoke — открыть `/login`, залогиниться, увидеть dashboard. PASS/FAIL.
- **DoD:** CI зелёный на первом PR.

**Acceptance M0:** каркас поднимается одной командой, логин с 2FA работает, 6 ролей есть, навигация рендерится, CI зелёный. **Только после этого — M1.**

---

### M1. IAM / Org (1-2 недели)

**Ведущие:** `backend-specialist` + `integration-specialist` (API-токены/SSO-линковка) + `frontend-specialist`.

Вертикальный срез по контекстам `Iam` + `Org`. Смотрим логику в `./examples/contracts/`, паттерн middleware/трейтов visibility — в `./examples/vizion/`.

- [ ] **Iam:** User CRUD (аватар, увольнение + передача прав), профиль (смена пароля, 2FA-setup, SSO-линковка, API-токены), аудит-лог.
- [ ] **Visibility-scope** all/department/personal — через middleware `ResolveVisibility` + трейты на моделях (паттерн Vizion).
- [ ] **Org:** Department (дерево, менеджер), графики работы, отпуска, производственный календарь.
- [ ] UI: страницы «Сотрудники», «Отделы», «Профиль» (PrimeVue DataTable + формы), guard по ролям в `policy.ts`.
- [ ] Тесты: Feature на каждый endpoint, Unit на visibility-scope.

**Acceptance M1:** создание/редактирование/увольнение сотрудника с передачей прав; дерево отделов; visibility-scope ограничивает выдачу (manager видит только свой отдел); профиль с 2FA и API-токеном. CI зелёный.

---

### M2. Контакты / Компании (2-3 недели)

**Ведущие:** `sales-specialist` + `frontend-specialist`. Контекст `Crm` (+ задел `Catalog`).

- [x] Миграции/модели: Contact v2, Company v2 (юрформа/реквизиты/категории-кэш), ContactPosition, M2M ContactCompanyLink. *(S1.1 backend, 2026-06-11)*
- [x] Справочники: CompanyType, ContactPosition, Source, Country (ISO2), City. *(S1.1 backend, 2026-06-11)*
- [x] **CustomFieldDef** — scope company/contact (deal-scope зарезервирован, в S1.3). *(S1.1 backend, 2026-06-11)*
- [x] Дедуп + merge контактов/компаний (DedupService: scan/merge/dismiss, DB::transaction, нормализация телефона в PHP — PG-совместимо). *(S1.1 backend, 2026-06-11)*
- [x] DedupService PG-фикс (BUG-1): двойные кавычки в raw SQL убраны; нормализация телефона/имени перенесена в PHP (LOWER/TRIM через `?`-биндинг), добавлен `scanAll` (global scan, visibility-scoped). *(S1.1 backend-fix, 2026-06-11)*
- [x] Тесты: CRUD, дедуп-логика (unit+feature), global scan, `?search` — **150/150 PHPUnit green**, Pint PASS. *(S1.1 backend-fix+search-test, 2026-06-11)*
- [x] UI: список Контакты/Компании (DataTable + type-switch + фильтры + пагинация + quick-create), карточка контакта (tabs + inline-edit + M2M компании), карточка компании (tabs + AutoComplete сотрудников + inline-edit + holding-stub + deals/tasks/files-stub), MergeDialog (3-step: scan→groups→merge с preview), directoriesStore, i18n RU+EN. *(S1.1 UI, 2026-06-11)*

**Acceptance M2:** CRUD контактов и компаний с ИНН/КПП; кастом-поля видны в карточке; дедуп находит дубли и сливает; гео-справочники подключены. CI зелёный.

---

### M2+. Контакты + Компании 2.0 (апгрейд до планки Сделок 2.0)

**Статус:** DONE (2026-06-19). PM-апрув получен, ветка feat/deals-redesign, ожидает пуша в main.
**Ведущие:** `crm-specialist` (backend) + `frontend-specialist` (UI) + `qa-tester`.
**ТЗ:** `MG CRM 2026 / 5. Планы / Контакты + Компании 2.0 — ТЗ (единая карточка + список).md`

**Суть:** апгрейд работающего M2, не стройка с нуля. Единая система компонентов (обобщить из Сделок 2.0), полноширинный layout (убрать правый рейл), новые данные.

**Решения PO (зафиксированы 2026-06-19):**
- Тёмная шапка `EntityInfoHeader` — `$brand-header-bg` (#172747) как в DealPage v2 (принято).
- Дефолтные пороги engagement: контакт 14/45 дней, компания 30/90 дней (config/crm.php, env-override).
- Таб Холдинг — всегда видим (не скрывать при пустой иерархии, empty-state).
- `RelationType` — 7 значений: partner/referrer/colleague/friend/investor/mentor/other (принято).

**Слайсы:**

- [x] **Слайс 1 — Backend (crm-specialist):** 3 миграции (crm_contact_relations, last_activity_at contacts/companies) + ContactRelation (B1) + EngagementService + touchForDeal + tierFor* (B2) + channels в ContactResource (B3) + ContactDealsController/CompanyDealsController — реальные сделки (B4) + HoldingService::buildTree/ancestors/setParent/detectParentConflict + HoldingController (B5) + DealTotalsDTO + DealService::aggregateForCompany (B6) + BulkContactService/BulkCompanyService/ContactExportService/CompanyExportService (B7) + Gate dedup-scan-all в AppServiceProvider (B9). 1719 тестов зелёные. Migrate dev-Postgres PASS (all 3 migrations ran).
- [x] **Слайс 2 — Список 2.0 (frontend-specialist):** ContactsToolbar + ContactsFilterOverlay + ContactsActiveFiltersBar + ContactsBulkToolbar + SavedViewsDropdown + EngagementChip (точка в колонке) + frozen первая колонка + inline-edit ответственного/тегов + 4 empty states + density/column-chooser. tsc+lint+build clean.
- [x] **Слайс 3 — Карточка компании (frontend-specialist):** EntityInfoHeader (тёмная шапка) + EntityActionMenu + EntityTabs + InfoPanel + EngagementChip (полный) + EmployeeRowCard + KeyFactsBlock + HoldingTree + MiniPipelinePanel + MultiCurrencyTotals + CompanyDocumentsPanel + CompanyDealsTab + layout col-12 (RightRail убран) + адаптив 4 брейкпоинта. tsc+lint+build clean.
- [x] **Слайс 4 — Карточка контакта (frontend-specialist):** EntityInfoHeader + ContactChannelsBlock + ContactRelationsPanel + панели Обзора (компании, связи, сделки, кастом-поля) + EntityActivitiesTab. tsc+lint+build clean.
- [x] **Слайс 5 — Компоненты (frontend-specialist):** `front/src/components/crm/entity/` — 9 компонентов (EntityInfoHeader, EntityActionMenu, EntityTabs, InfoPanel, EntityRow, KeyFactsBlock/Item, EngagementChip, CustomFieldRenderer, EntityActivitiesTab, EntityComposer) + composable useEntityFeed. i18n namespace `crm` (RU+EN). tsc+lint+build clean.
- [x] **Слайс 6 — QA (qa-tester):** браузерный smoke PASS. 0 console errors. Регрессия DealPage чистая.
- [x] **Слайс 7 — PM-ревью (product-manager):** PASS 2026-06-19. Беклог-хвосты зафиксированы.
- [x] **Интеграция engagement:** EngagementService::touch() мёртвым кодом больше не является. ActivityService вызывает touchTargetEngagement() на create()/complete(); DealService/DealMoveService вызывают touchEngagement(). 8 integration-тестов EngagementTouchTest. Circular DI исключён: ActivityService → EngagementService (через Crm), DealService → EngagementService; ActivityService НЕ инжектит DealService (circular-guard). Deal::engagementTargets() — единственный резолвер deal→{company_id, contact_ids}.

**Беклог (не блокеры, следующая сессия):**
- Engagement touch покрытие: ActivityTargetType::Contact теперь есть (добавлен в enum), но direct-contact touch в ActivityService::touchTargetEngagement() — только deal и company. Уточнить: нужно ли stampить contact.last_activity_at при создании Activity с target_type='contact' напрямую.
- HoldingTree многоуровневость > 2 уровней — PHP BFS без CTE, работает, но при больших деревьях (10+ уровней) N+1 (один DB::whereIn на уровень). Пометить для оптимизации при реальных данных.
- Saved Views backend (`crm_saved_views` таблица) — UI работает на localStorage-заглушке; backend TBD отдельным слайсом.
- `rate_available`↔`fx_rate_available` несовпадение ключа (беклог Сделок-борд, не этот спринт).

**Зависимости:** Финансы M9 для панели Платежи (плейсхолдер до тех пор); Договоры M5 для панели Документов (данные уже работают).

### M2++. Карточки компании+контакта — Редизайн (Entity Card 2.0)

**Статус:** DONE (2026-06-20). QA PASS. PM-APPROVE. Задеплоен на прод (commit `11da24f` + fix `7cc26d7`).
**ТЗ:** `MG CRM 2026 / 5. Планы / Карточки компании+контакта — редизайн ТЗ.md`
**Агенты:** `crm-specialist` (KPI backend) + `frontend-specialist` (UI + visual-fixes).

**Что сделано (6 фаз ТЗ + 9 визуал-фиксов):**

- [x] **EntityAvatar.vue** — детерминированный аватар 56×56, 8-цветная палитра по `entityId % 8`, белые инициалы (до 3 букв), размеры sm/md/lg.
- [x] **EntityKpiStrip.vue** — KPI-полоса между шапкой и табами: `KpiItem[]` (icon/label/value/accent/clickable), 5 Skeleton при загрузке, mobile horizontal-scroll, tablet flex-wrap. Тёмная тема: surface-100/surface-300 токены (исправлено fix `7cc26d7`).
- [x] **EntityNowStrip.vue** — «Сейчас» inline-полоса (label + dot-separated items). Готов к использованию.
- [x] **EntityMiniTimeline.vue** — последние N записей лога (dot+actor+event+relativeDate), empty-state, skeleton, «Открыть лог» callback. Использует `useEntityLogFormat`.
- [x] **useEntityLogFormat.ts** — shared composable: `EVENT_ICONS`, `eventIcon/Label/formatDate/relativeDate` вынесены из EntityLogTab.
- [x] **EntityInfoHeader.vue рефакторинг** — avatar-col (56×56) + info-col, tags prop (max 3 Tag + «+N»), avatarInitials override, meta label/value с opacity 0.35/0.75.
- [x] **EntityLogTab.vue** — рефакторинг на `useEntityLogFormat` (без изменения поведения).
- [x] **CompanyRequisitesPanel.vue** — 3 постоянных секции (Юр. данные/Банк/Контакты и сегментация) с labeled-dividers вместо toggle.
- [x] **CompanyEmployeesTab.vue** — удалена дублирующая кнопка «Добавить сотрудника».
- [x] **MiniPipelinePanel.vue** — hint под empty-state (`company.page.deals.emptyHint`).
- [x] **HoldingTree.vue** — `childrenCount` computed (recursive subtree), `:count` в InfoPanel-бейдж.
- [x] **ContactRelationsPanel.vue** — `relationTypeIcon()` маппинг, `sortedRelations` (partner→other), улучшенный empty-state с CTA.
- [x] **CompanyPage/index.vue** — `:tags`, `EntityKpiStrip` (5 метрик: open_deals/sum/employees/documents/last_activity), `EntityMiniTimeline` в overview.
- [x] **ContactPage/index.vue** — `:tags`, `EntityKpiStrip` (4 метрики: deals/last_contact/open_tasks/companies), `EntityMiniTimeline`.
- [x] **KPI backend (crm-specialist):** `ActivityService::openTasksCountForContact()` (single query), `CompanyController::show()` — 5 KPI агрегатов (open_deals_count/deals_sum/employees_count/documents_count/last_activity_at) + holding_company_count, `ContactController::show()` — 3 KPI (deals_count/last_touch_at/open_tasks_count). Все через `->additional(['kpi'=>...])` на Resource. 24 PHPUnit теста (CompanyKpiTest 13 + ContactKpiTest 11). Сьют 236 CRM-тестов зелёный.
- [x] **9 визуал-фиксов:** login dark-card (`:global(.app-dark)` overrides), DealPage dark panels, /my-tasks New badge dark, /my-tasks kanban overdue border, /profile SelectButton dark, дубль вкладки «Тема» удалён, EngagementChip neutral-cold (без истории → серый `pi-circle`), MonthStepper locale.value (реактивный), PipelineCanvas Controls/MiniMap ghost-skeleton guard + hint z-index, TemplateVariables i18n.
- [x] **i18n:** RU+EN — `company.kpi.*`, `company.requisites.section.*`, `company.page.deals.emptyHint/noWonDeals/holding.childrenCount`, `contact.kpi.*`, `crm.entity.nowStrip/miniTimeline/kpiStrip.*`.
- [x] **Deploy:** commit `11da24f` + fix `7cc26d7` (34 файла, ~2000 ins), push `origin/main`, rolling-restart прода, nginx reload, health-check PASS (200/401, все 9 контейнеров Up).

**Беклог (не блокеры):**
- `EntityNowStrip.vue` создан, но не вставлен в CompanyPage/ContactPage напрямую — доступен для будущего использования.
- KPI-поля (`kpi` object) null-safe — фронт работает без них (graceful degradation); связка с live CompanyResource стала полной.
- Avatar-col в EntityInfoHeader добавляет ~60px ширины — проверить на узких viewport при будущих правках layout.

---

### DS-4. Редизайн раздела Контакты (список)

**Статус:** DONE (2026-06-21). designer→frontend-specialist→qa-tester PASS. Uncommitted, ветка feat/amo-native-fields. PM APPROVE.
**ТЗ:** `design-handoff/redesign/Contacts-spec.md` + `contacts.html`.
**Агенты:** `frontend-specialist` (UI) + `backend-specialist` (KPI endpoint + position-filter).

**Что сделано:**

- [x] **KPI-лента (бэкенд):** `ContactsKpiService` + `ContactsKpiController` — `GET /api/contacts/kpi?entity=company|contact`. Aggregated counters через `DB::table()` (без Eloquent N+1). Visibility-scope: Admin/Director — всё; Manager — own records (`owner_user_id`/`owner_id`). Response `{ data: { entity, total, ... } }`. 25 PHPUnit тестов (ContactsKpiBarTest), 349 CRM-тестов зелёные.
- [x] **Position-filter (бэкенд):** `ContactService::list()` — фильтр `?position=` через `->where('position', 'like', '%value%')` (Eloquent-билдер, без raw). 4 теста B4 в ContactsKpiBarTest.
- [x] **CrmAvatar.vue** — новый `front/src/components/ui/CrmAvatar.vue`, инициалы (1–2 буквы), prop `square` (radius-md vs radius-circle), prop `size`, фон `$primary-900`.
- [x] **ContactsKpiBar.vue** — KPI-чипы (pill, 6 для компаний / 4 для физлиц), skeleton при загрузке, teal-вариант через `$teal-*` токены.
- [x] **ContactsPaginator.vue** — самостоятельный компонент, 50/100/200 per-page, дропдаун вверх, prev/next/first/last.
- [x] **ContactsToolbar** рефакторинг — segmented entity-switch (tablist/role=tab, a11y), search, filter-badge, More-menu, Create-button, плотность/column-chooser.
- [x] **ContactsFilterOverlay** рефакторинг — Должность (position) для физлиц, grid-3-col, segment-пресеты.
- [x] **ContactsPage/index.vue** — KPI через `useAsyncResource`, watch entityType → loadKpi(), `CrmAvatar` в ячейках, `position`-строка под именем.
- [x] **Teal-палитра:** `tealPalette` добавлена в `tokens/colors.ts` + `theme/config.ts` + `appVariables.ts` (addPalette 'teal') + `_colors.scss` (`$teal-100/700/900`). Токен готов для stylelint.
- [x] **Preset-оверрайды кнопок:** `theme/adapters/primevue/preset.ts` — BUG-DS4-4/6: filled-button brand-navy в обеих темах через `components.button.colorScheme.{light,dark}`.
- [x] **i18n:** `contacts.kpi.*` (total/clients/catL/catM/catS/active/noTouch/newWeek), `contacts.page.header.entitySwitch` — симметрично RU + EN (0 расхождений).
- [x] **Agent-patch:** `.claude/agents/frontend-specialist.md` — зафиксированы 2 dark-theme ловушки: идиома `.app-dark &` (не `:global()`), инверсия surface-шкалы в dark-режиме.
- [x] **QA:** frontend-specialist: tsc+lint:ds+build clean. qa-tester: функционал + визуал light/dark PASS.

**Беклог (не блокеры):**
- `initialType` в index.vue (строка 701): тернарный `route.name === 'Companies' ? 'company' : 'company'` — обе ветки возвращают `'company'`, мёртвый код. Поведение корректное (spec требует default=company), но выражение стоит упростить при следующем касании файла.
- `position` в `ContactListParams` есть (api/crm/contacts.ts), но не пробрасывается из `buildContactParams()` в `useContactsPageData.ts` — фильтр работает только на сервере (прямые запросы). UI-wire через overlayFilters добавить в следующем слайсе фильтров.

---

### DS-5. Редизайн карточки сущности (контакт + компания)

**Статус:** QA PARTIAL (2026-06-23). Round 3 — 2 medium-priority bugs remain open (BUG-DARK-PANEL-HOVER, BUG-META-FS). Uncommitted, ветка feat/amo-native-fields. Требует ещё 1 итерации frontend-specialist → QA.
**ТЗ:** `design-handoff/redesign/EntityCard-spec.md` + `entity-card.html` + `DealCard-spec.md §11/§12`.
**Агенты:** `backend-specialist` (KPI-агрегат контакта) + `frontend-specialist` (редизайн компонентов и страниц).

**Что сделано:**

- [x] **Бэкенд — `DealService::aggregateForContact(Contact)` (sales-specialist / DealService):** агрегат сделок контакта через `deal_contacts` (без N+1: одним `whereHas` + `->get(['id','amount','currency'])`). Группирует по валюте (int-копейки), конвертирует через `ExchangeRateService::convertAmount` (null если FX недоступен). Возвращает `DealTotalsDTO`: `per_currency`, `base_total` (int|null), `base_currency`, `open_count`. Деньги — строго int, float запрещён.
- [x] **Бэкенд — `ContactController::show()` расширен:** `DealService::aggregateForContact()` + `ActivityService::openTasksCountForContact()` + `DB::table('crm_contact_company_links')->count()` — три агрегата без N+1. KPI-блок: `deals_count`, `deals_sum` (int копейки|null), `deals_sum_currency`, `last_touch_at`, `open_tasks_count`, `companies_count` — через `->additional(['kpi' => ...])`. `ContactResource::toArray()` — `'kpi' => $this->additional['kpi'] ?? null` (null в index-ответе, без N+1 на списках).
- [x] **Бэкенд — `ContactResource`:** добавлены поля `source`, `created_at`, `updated_at` (ISO8601).
- [x] **Тесты — `ContactKpiTest.php`:** 19 PHPUnit тестов (51 assertion): deals_count с/без soft-deleted, last_touch_at, open_tasks_count (все task-like типы, исключение Note/Done), deals_sum (RUB-агрегат, soft-delete, null-корнер), deals_sum_currency тип, companies_count, kpi-структура, index без kpi. Сьют 357 CRM PASS.
- [x] **Фронт — переработаны компоненты `front/src/components/crm/entity/`:** `EntityInfoHeader.vue` (avatar-col 56×56, info-col с title/subtitle/meta-row/tags, кнопки назад+меню в top-row), `EntityKpiStrip.vue` (KPI-пилюли с accent-вариантами, loading-skeleton, mobile horizontal-scroll), `EntityActivitiesTab.vue` (фильтр-чипы Все/События/Изменения, feed bottom-up, OpenTasksList, EntityComposer), `EntityComposer.vue` (режимы Заметка/Задача), `EntityAvatar.vue`, `InfoPanel.vue`.
- [x] **Фронт — `ContactPage/index.vue`:** редизайн на EntityInfoHeader + EntityKpiStrip + Tabs (Обзор/Активность/Сделки/Файлы); правый рейл `ContactRightRail.vue` удалён; `ContactDealsTab.vue` (NEW) — DataTable сделок, link→DealPage, форматирование копеек; `ContactFilesTab.vue` (NEW) — graceful «скоро» (B-4); Обзор — одна колонка (`col-12`); i18n RU+EN.
- [x] **Фронт — `CompanyPage/index.vue`:** редизайн на EntityInfoHeader + EntityKpiStrip + Tabs (Обзор/Активность/Сотрудники/Сделки/Документы/Файлы/Холдинг); правый рейл `CompanyRightRail.vue` удалён; `CompanyFilesTab.vue` (NEW) — graceful «скоро» (B-4); `CompanyMiniDealsPanel.vue` (NEW); компоненты `CompanyActivitiesTab`, `CompanyDealsTab`, `CompanyEmployeesTab` рефакторинг.
- [x] **Фронт — пресет табов `theme/adapters/primevue/preset.ts`:** DS-5 добавил `tabs.activeBar.height=2px`, `tabs.activeBar.background='{primary.900}'`, `colorScheme.dark.tablist.background='{surface.100}'` — app-wide улучшение (затронул `DealInfoTabs.vue`: active-tab `font-weight: 600` через `:deep(.p-tab[aria-selected="true"])`).
- [x] **Фронт — `typography.ts`:** добавлен NB-комментарий о 14px-root (rem-шкала 14-based; xs=0.75rem рендерится ~10.5px, не 12px).
- [x] **i18n:** полная симметрия RU+EN (0 расхождений). Новые ключи: `crm.entity.*`, `contact.page.*`, `company.page.*`, `crm.contact.*`, `crm.company.*`, `crm.files.*`.
- [x] **QA:** tsc+lint:ds+build clean. qa-tester: 5/6 визуальных пунктов PASS; §1 мета 10.5px vs «12px» → BY-DESIGN (14px root, корректный токен `$font-size-xs`, задокументировано в `typography.ts`).

**QA Round 3 — открытые баги (2026-06-23):**
- **BUG-DARK-PANEL-HOVER (medium):** `InfoPanel.vue` — в dark-теме hover на `.info-panel__header` даёт `background: var(--p-surface-200)=#616263`, совпадающий с цветом `.info-panel__title` в dark (`color: var(--p-surface-200)=#616263`) — заголовок панели невидим на hover. Fix: изменить hover bg в dark на `var(--p-surface-300)` или title color на `var(--p-surface-400)`. Файл: `front/src/components/crm/entity/InfoPanel.vue` строки 119-153.
- **BUG-META-FS (low, перенесён из round 2):** мета-строка `entity-header__meta-row` рендерится на 10.5px (0.75rem × 14px база), spec требует 12px. Root: `$font-size-xs = 0.75rem`, base 14px. Fix: `front/src/theme/tokens/typography.ts` — `xs` с `'0.75rem'` на `'12px'` (literal). Файл: `typography.ts`.

**Беклог backend-gaps:**
- ~~**BG-1 (S):** DONE (S-batch 2026-06-23)~~ `PATCH /api/companies/{company}/employees/{contact}` реализован: `CompanyEmployeeController::update()` + `CompanyService::updateEmployee()` + `UpdateEmployeeLinkRequest`. 8 тестов green (433 CRM assertions). ✅
- ~~**BG-2 (S):** DONE (S-batch 2026-06-23)~~ `DocumentService::list()` теперь фильтрует по `source_company_id`. 2 новых теста green (289 Contracts). ✅
- ~~**BG-3 (S):** DONE (S-batch 2026-06-23)~~ `CompanyController::show()` KPI-блок включает `won_count` (через `DealService::countWonForCompany()`); FE `CompanyPage/index.vue` уже читал оба ключа через `open_deals_count ?? open_count` — алиас совместим. 5 новых тестов green. ✅
- ~~**BG-4 (L):** DONE (L-batch 2026-06-29)~~ Файловое API полностью реализовано: `CrmFolderController` + `CrmFileController`, маршруты `/contacts/{contact}/folders|files` + `/companies/{company}/folders|files`, диск `crm_files` (S3-swappable), виртуальная папка «Сканы договоров» (read-only, источник — Documents.source_company_id), ленивый сид системных папок, IDOR-гард, скачивание. Миграция `2026_06_29_100000` (sort_order + disk). FE: `filesApi` + `EntityFilesTab.vue` + `ContactFilesTab.vue` + `CompanyFilesTab.vue` — двухпанельный layout, полностью wired. 12/12 CrmFilesTest + 932 total CRM PASS. ✅
- **BG-5 (S):** `DealCreateDrawer` не смонтирован на `ContactPage` → кнопка «Создать сделку» в `ContactDealsTab` disabled. Только FE-работа — импорт + ref + монтирование (бэкенд `POST /api/deals` уже принимает `contact_id`).
- **BG-6 (S):** Меню «Добавить заметку» (Contact+Company) только переключает таб — не фокусирует `EntityComposer`. Нужны `defineExpose({ focusNote, focusTask })` в `EntityComposer.vue` + ref + вызов в `nextTick` из menu command. Только FE.
- **BG-7 (S):** Меню «Добавить связь» (Contact) только переключает таб — не открывает форму в `ContactRelationsPanel`. Нужны `defineExpose({ openAdd })` + ref + вызов. Только FE.
- **BG-8 (M):** Edit-кнопка для задач не реализована ни в `OpenTasksList`, ни в `EntityActivitiesTab`. `ActivityFormDialog.vue` существует. Только FE.
- **BG-9 (M):** «+Добавить в сделку» на ContactPage (overview, disabled, TODO B-CONTACT-DEALS) — требует `AddContactToDealDialog` (autocomplete deals + `POST /api/deals/{id}/contacts`). Только FE.

**Беклог (не блокеры, перенесено из предыдущих итераций):**
- **B-3:** кнопка «Добавить в сделку» в `ContactDealsTab` disabled — BG-5/BG-9.
- ~~**B-4:** DONE (L-batch 2026-06-29)~~ `ContactFilesTab` + `CompanyFilesTab` — полноценный двухпанельный layout через `EntityFilesTab.vue`, wired на `filesApi`. ✅
- **position-wire (DS-4 хвост):** фильтр position не пробрасывается из `buildContactParams()` — отдельный слайс фильтров.
- **CompanyPage menu TODO:** `open task/note` — BG-6; остальные (`call/email/export`) — заглушки.

---

### DS-5b. Карточка сделки — точное воспроизведение мокапа (DealPage exact-rebuild)

**Статус:** QA FAIL (2026-06-23) — 5 визуальных отклонений подтверждены computed-styles. Требует fix-итерации frontend-specialist → re-QA.
**ТЗ:** `design-handoff/redesign/DealCard-spec.md` + `redesign/deal-card.html`.
**Агенты:** `frontend-specialist` (rebuild) + `qa-tester` (3 functional, dark-mode visual).

**Что сделано (22 файла, uncommitted, ветка main):**

- [x] `DealPage/index.vue` — двухпанельный layout 420px/flex:1, mobile/tablet breakpoints, loading-skeleton, error-state.
- [x] `DealInfoHeader.vue` — navy шапка #172747, h2 название (line-clamp:2), ← + ⋮ справа, тег стадии кликабельный → MoveDealDialog, category badge (--mg-red-600), DealHealthChip, days-in-stage, теги-строка, прогресс-бар этапов, меню-поповер справа от кнопки (8 пунктов + разделители). Rename + Tags dialogs wired (useMutation).
- [x] `DealInfoTabs.vue` — PrimeVue Tabs: Основное/Документы/Финансы/Активность; hidden scrollbar на панелях.
- [x] `DealFieldGroup.vue` — аккордеон без шеврона, accent-mode (bg --c-sub), icon-плитка 24×24, count-badge, totalLabel, collapse/expand/localStorage persist, defineExpose.
- [x] `DealTabMain.vue` — быстрые поля grid 110px/1fr (Ответственный/Компания/Даты); группы Продукты/Контакты/Компания/Доп.поля.
- [x] `DealProductsGroup.vue` — сегмент Подписка/Вечная (bg --mg-gray-100), скидка% (inert TODO), product rows, header totalLabel.
- [x] `DealContactsGroup.vue` — контакты с ⋮ (Редактировать/Отвязать), inline-edit форма (каналы/должность/тумблер Основной), кнопка добавить.
- [x] `DealCompanyGroup.vue` — Телефон/Email/Сайт/Адрес/Тип (без БИН/Категории).
- [x] `DealTabDocuments.vue` — список документов, SearchPicker шаблонов, флоу согласования (Согласовать/Отклонить+причина/повторно), факт-дата.
- [x] `DealTabFinances.vue` — фиксация оплаты (paid_at WIRED), paid_amount/payment_currency (inert stub с TODO), график-заглушка (pi-lock + Skeleton).
- [x] `DealFeed.vue` — топбар: key-action чипы (scroll-to, не создают события) + pi-search; лента bottom-up (margin-top:auto); date-group коллапс.
- [x] `DealFeedItem.vue` — системные события (серый текст без карточки), заметки/задачи (карточка, тип-цвет кольцо+рамка).
- [x] `DealComposer.vue` — Заметка/Задача toggle (navy active), кнопка Добавить по центру, 3 поля задачи в ряд.
- [x] `OpenTasksList.vue` — Тип/Дата/Ответственный, 3-click delete, expand/collapse, TaskQuickForm mode=complete.
- [x] `MoveDealDialog.vue` — список стадий с цвет-dot + выбранная pl-check + --mg-primary-100.
- [x] `DealAddProductDialog.vue` — Период/Валюта/Продукт/Сумма(авто), без Тарифный план/Цена.
- [x] `DateField.vue` (NEW shared) — авто-формат ДД.ММ.ГГГГ + calendar popover, click-outside, ISO emit.
- [x] `SearchPicker.vue` (NEW shared) — trigger+chevron, popover+поиск, selected --mg-primary-100+pi-check, hidden scrollbar.
- [x] `ru.json` / `en.json` — все ключи `sales.deal.*` для rebuild.
- [x] Функциональный QA: 4 таба, collapse/expand, MoveDealDialog, DealAddProductDialog, AddContactDialog, Composer Note/Task, ⋮ меню (8 пунктов), DateField авто-формат, feed bottom-up, Активность-метрики, 44 API 200 OK, 0 console errors.

**Backend gaps:**
- ~~**BG-DC-1 (S):** DONE (S-batch 2026-06-23)~~ Миграция `2026_06_28_120010_add_payment_fields_to_deals_table` (additive, nullable); `Deal.$fillable` + casts; `UpdateDealRequest` + `DealResource` включают `paid_amount`/`payment_currency`. 2 новых теста green (310 Sales assertions). FE `DealTabFinances.vue` полностью wired. ✅
- **BG-DC-2 (L):** Graf платежей — нет модели DealPayment / таблицы / эндпоинтов. Полная разработка в Finance sprint.
- ~~**BG-DC-3 (S):** DONE (S-batch 2026-06-23)~~ `DealContactController::update()` + `DealContactService::setPrimary()` + `UpdateDealContactRequest` реализованы. Маршрут `PATCH /api/deals/{deal}/contacts/{dealContact}`. 4 новых теста green. ✅
- ~~**BG-DC-4 (M):** DONE (L-batch 2026-06-29)~~ Deal-level `discount_percent` (0..50): миграция `2026_06_29_120000` (unsignedTinyInteger, default 0), `Deal.$fillable`+casts, `UpdateDealRequest` (min:0, без max — сервис clamp), `DealService::clampDiscountPercent()`, `DealResource::discountedTotals()` (gross/net/per-line), FE `DealProductsGroup.vue` wired, `DealProductRow.vue` показывает зачёркнутую/нетто-цену. 9 новых тестов `DealDiscountAndCompanyChangeTest`. ✅
- ~~**BG-DC-5 (M):** DONE (L-batch 2026-06-29)~~ `company_id` добавлен в `UpdateDealRequest` (`exists:crm_companies,id`); `DealService::update()` re-resolve `company_requisite_id` + `department_id` при смене компании через `resolveCompanyDerivedData()`. 4 теста в том же файле. ✅
- **BG-DC-6 (M):** Метрики Активности (6 штук) — нет единого endpoint; собирать либо в DealResource show(), либо через DealMetricsController.

### Bug Pack 2 (2026-06-24). PM APPROVE, uncommitted.

**Агенты:** `backend-specialist` (CRM sort) + `frontend-specialist` (DealComposer/DateField/feed-search/contacts-list/company-card).

**Что сделано:**

- [x] **Backend — Contact/Company list sort (`sort_by`/`sort_dir`):** `IndexContactRequest::SORTABLE_COLUMNS` (name/company/phone/last_contact/open_deals/author/created) + `IndexCompanyRequest::SORTABLE_COLUMNS` (name/category/country/deals/last_contact/engagement/owner/created). Injection-safe: `Rule::in(SORTABLE_COLUMNS)` → 422 на невалидное значение. `prepareForValidation()` нормализует boolean flags + array filters. `ContactService::list()` + `CompanyService::list()` принимают `sort_by`/`sort_dir` в `$filters`. 16 PHPUnit: `ContactSortTest` (8 тестов: name asc/desc, last_contact, created, company LEFT JOIN, default, invalid 422) + `CompanySortTest` (8 тестов: name asc/desc, country, last_contact, owner, deals subquery, created, default, invalid 422). 830 total PASS. **⚠️ Хвост (открыт):** `ContactService::list()` и `CompanyService::list()` содержат только старую `$filters['sort']` ветку (legacy); фактический `applySort()` по `sort_by` в сервисах не реализован. Тесты прошли через IndexRequest validation → 422 для невалидных; happy-path sort-порядок зависит от БД-дефолта. Сортировка по новым ключам работает у контроллера через передачу `$filters` в сервис, но `list()` применяет sort только через legacy-ключ `$filters['sort']`, а не через `$filters['sort_by']`. Требует `applySort()` в сервисах (как у DealService).
- [x] **Backend — CompanyKpiTest:** 15 тестов (open_deals_count/won_count/deals_sum/employees_count/documents_count/last_activity_at/holding_company_count + structure) — CRM 422 PASS.
- [x] **Frontend — DealComposer.vue:** режим Заметка/Задача toggle (navy-active), кнопка «Добавить» centre, порядок полей задачи spec §11 (Тип→Дата→Ответственный), SearchPicker с цветными иконками типа задачи.
- [x] **Frontend — DateField.vue (shared):** авто-формат ДД.ММ.ГГГГ, calendar popover (PrimeVue DatePicker inline), min-prop clamp (C1), click-outside, ISO emit. Год < 2000 / > 2100 отклоняется. Прошлые даты при min=today отклоняются на blur (restore from modelValue).
- [x] **Frontend — FeedSearchOverlay.vue:** поиск по тексту + тип-чипы (7 типов: all/stage_change/field_change/note/task/call/meeting). Transition enter/leave 0.15s. Emits: search/filter/reset.
- [x] **Frontend — EntityComposer.vue (shared):** паритет с DealComposer (режимы Заметка/Задача, DateField, SearchPicker для типа/ответственного).
- [x] **Frontend — EntityLogTab.vue (shared):** 2-column metrics grid + лог-лента (actor / separator / eventLabel / old→new / description). Skeleton + empty state + load-more.
- [x] **Frontend — ContactsPage — колонки и сортировка:** `useContactsView.ts` — `contactColumnDefs` (6 default + 3 скрытых: engagement/position/open_deals_count) без колонки `id`; `companyColumnDefs` (8 default + employees_count) без колонки `id` и `company_type`. `COMPANY_REMOVED_COLS/CONTACT_REMOVED_COLS` чистят старый localStorage-кэш. `useContactsPageData.ts` — `CONTACT_SORT_MAP` / `COMPANY_SORT_MAP`, `onSort()` cycle-toggle (none→asc→desc→none), `buildContactParams()` / `buildCompanyParams()` передают `sort_by`/`sort_dir`.
- [x] **Frontend — ContactsPage — SavedViews удалены:** `SavedViewsDropdown.vue` убран из toolbar (UI работает на localStorage-заглушке; backend TBD отдельным слайсом, не меняется).
- [x] **Frontend — CompanyPage — RequisiteCard.vue:** Header: pi-id-card tile + label + «Основной» badge + pi-copy + pi-pencil + pi-ellipsis-v (Menu popup). Body: 2-column CSS grid. Note-строка. Копирование реквизитов в clipboard.
- [x] **Frontend — CompanyPage — CompanyMiniDealsPanel.vue:** 3-column row (название/этап/сумма). Stage Tag с `stage.color+'33'` bg + forced opaque text (E5 fix). `formatKopecks()` через Intl.NumberFormat.
- [x] **Frontend — EntityInfoHeader.vue:** single flex row (avatar-col/info-col/control-col), title-row (name + status slot + category Tag + EngagementChip), meta-row (author/company/source/created/updated), tags-row max 3+N.
- [x] **i18n:** RU+EN симметрия для новых ключей composables/log/feed.
- [x] **Build/lint:** deal build 8.88s 0 errors; contacts build 8.96s clean; company build 7.06s clean; lint:ds 0 violations (новые px с `// stylelint-disable-next-line`); vue-tsc 0 errors; eslint 0 warnings.

**Открытые хвосты:**
- **SORT-BACKEND-GAP (medium):** `ContactService::list()` и `CompanyService::list()` не имеют `applySort()` — legacy `$filters['sort']` ветка остаётся. Тесты проходят по validation-пути, но реальный sort в приложении не работает для новых ключей. Требует `applySort()` в обоих сервисах + интеграционные happy-path тесты.
- **BUG-DARK-PANEL-HOVER + BUG-META-FS** — открыты из DS-5 round3, не закрыты в этой итерации.
- [x] **D4 (countries/settings feature) DONE 2026-06-24** — Countries CRUD (`GET/POST/PATCH/DELETE /api/admin/countries`), additive seeder `ru`+`ae`, delete-guard (companies/requisites/cities), `code` immutable on update. FE: `CountriesPage` + `CountryDialog` + `useCountriesPage` composable. Все справочные разделы (Страны / Каналы привлечения / Причины отключения) объединены в группу «Справочники» на хабе `ProfilePage`. Маршрут `/admin/countries` + `adminNavItems` обновлены. RU+EN i18n. Build 8.32s, lint:ds 0, vue-tsc 0. 23 тестов `CountryDirectoryTest` (62 assertions). Полный сьют 515 PASS.

**Открытые баги QA (CHANGES_REQUESTED):**
- **BUG-TITLE-FONT-SIZE:** h2 в DealInfoHeader.vue — 15.75px (via $font-size-lg) vs spec 18px. Fix: `font-size: 18px` literal.
- **BUG-GROUP-ACCENT-DARK:** DealFieldGroup.vue accent header dark bg = var(--p-surface-800) → белый. Fix: `.app-dark &` → `var(--p-surface-100)`.
- **BUG-SEGMENTED-CONTAINER-DARK:** DealProductsGroup.vue segmented dark bg = var(--p-surface-700) → светлый. Fix: `.app-dark &` → `var(--p-surface-200)`.
- **BUG-TABS-DARK-ACTIVE:** DealInfoTabs.vue — active tab цвет/border = --p-primary-400 (#6f87bc) в dark vs spec #172747. Fix: `:deep(.p-tab[aria-selected='true']) { .app-dark & { color: #172747; border-bottom-color: #172747; } }`.
- **BUG-DISCOUNT-LABEL-ORDER:** DealProductsGroup.vue — DOM порядок [segmented, discount-field, discount-label] рендерит «0 % Скидка». Fix: переставить discount-label перед discount-field.

---

### DS-6. Редизайн воронки (SalesFunnel 2.0)

**Статус:** DONE (2026-06-22). backend-specialist→frontend-specialist→qa-tester PASS. Uncommitted, ветка feat/amo-native-fields. PM APPROVE.
**ТЗ:** `design-handoff/redesign/SalesFunnel-spec.md` + `sales-funnel.html`.

**Что сделано:**

- [x] **Бэкенд — `IndexDealRequest` (NEW):** полный FormRequest валидации фильтров воронки (14 измерений: view/per_page/pipeline_id/stage_ids/owner_ids/tags/status/only_mine/only_no_task/only_overdue/product_q/country/city/budget_from/budget_to/created_from/created_to). `prepareForValidation()` нормализует boolean flags из query-строк + coerce array-фильтры. `authorize()` — `can('viewAny', Deal::class)`. `DealController::index()/board()/export()` переведены на `IndexDealRequest::validated()` — неизвестные query-параметры отброшены.
- [x] **Бэкенд — `DealService::applyFilters()`:** полный набор фильтров воронки (owner_ids/stage_ids/only_mine/q/status/tags/product_q/country/city/budget_from/budget_to/created_from/created_to/only_no_task/only_overdue/archived). Все значения через query-builder bindings (no injection). tags — `whereJsonContains` (portable PG+SQLite). Visibility scope applied BEFORE filters (scopedQuery → applyFilters). Без N+1: whereHas/whereExists/subquery.
- [x] **Бэкенд — `DealService::list()`:** batched `stampLastContact` через `ActivityService::lastContactDatesForDeals()` (ROW_NUMBER PG / PHP-fallback SQLite). `DealResource` — поля `country`/`category`/`last_contact_at` (B1–B3).
- [x] **Бэкенд — `ActivityService::lastContactDatesForDeals()`:** батчевый запрос last completed event per deal, без N+1.
- [x] **Бэкенд — `ContactService::list()` + `CompanyService::list()`:** полный набор фильтров (owner_ids/author_ids/sources/tags/position/engagement_tier/created_from/created_to/last_touch_from/last_touch_to/open_deals_min/open_deals_max/only_mine/only_active/only_with_deals/only_no_task). LIKE-эскейп для `%` в тегах. Пресеты через `_auth_user_id` (передаётся контроллером, сервис не читает Auth напрямую). `whereExists` subquery для open_deals.
- [x] **Тесты:** `DealListFiltersTest` (23 теста), `DealListColumnsTest` (7 тестов), `ContactFilterTest`, `CompanyFilterTest`, `ContactKpiTest`. Scope-safety тест (manager не видит чужое при явном owner_ids). N+1 guard (query count < 20 для 12 сделок). 96/96 PASS.
- [x] **Фронт — воронка:** `DealsToolbar.vue` — pipeline-switcher `DealsPipelineMenu.vue` (NEW), filter-badge, view-toggle kanban/list. `DealsFilterOverlay.vue` — полная переработка: search + presets-chips + 4-col grid (owner_ids/stage_ids/product_q/region→country/city/budget/tags/dateRange). `DealsKpiChips.vue` (NEW) — KPI-полоска (inWork/catL/M/S/won/noTask/overdue) над доской. `DealsListView.vue` — redesign колонок (Название/Стадия/Компания/Страна/Категория/Посл.контакт/Бюджет/Ответственный).
- [x] **Фронт — composables:** `useDealsList.ts` / `useDealsBoard.ts` — `f.country` пробрасывается как `country` на сервер (исходно `f.region → country` mapping; поле переименовано в `country` в DS-6 хвостах 2026-06-22). `DealsFilters` — поле `country` (UI и API).
- [x] i18n RU+EN симметрия (0 расхождений).
- [x] **QA:** 23 контрольные точки — dark воронки, фильтры шлют параметры, мёртвых кнопок нет, 0 console/network ошибок. PASS.

**DS-6 QA FAIL (2026-06-23) — 11 дефектов, фикс-итерация требуется (frontend-specialist):**

Корневая причина кластера A (BUG-7, BUG-12): `color-mix()` в `DealsKanbanColumn.vue:179` и `DealsListView.vue:200` ссылается на `var(--p-surface-card)` — переменная не объявлена PrimeVue (есть только `--p-card-background` и `--app-surface-card`). Исправление: заменить `var(--p-surface-card)` на `var(--p-card-background)` в обоих местах.
Корневая причина кластера B (BUG-2, BUG-8): dark-оверрайды цвета текста в `DealsToolbar.vue` и `DealsKanbanColumn.vue` используют `var(--p-surface-50)` = `#272829` (near-black) — PrimeVue scale INVERTED в тёмной теме (низкие цифры = тёмные, высокие = светлые). Исправление: заменить на `var(--p-surface-900)`.

Список дефектов (frontend-specialist, ветка feat/amo-native-fields):
- **BUG-1 CRITICAL** — `DealsKanbanBoard.vue:17` `allColumnsEmpty` убирает ВСЕ колонки когда 0 сделок. Спек требует: колонки всегда видны, empty-state только когда `visibleColumns.length === 0`. Fix: убрать `v-else-if="!loading && allColumnsEmpty"`; empty-state показывать только по `visibleColumns.length === 0`.
- **BUG-7 CRITICAL** — `DealsKanbanColumn.vue:179` headerStyle: `var(--p-surface-card)` не определён → `color-mix()` → прозрачный фон шапки в обеих темах. Fix: `var(--p-card-background)`.
- **BUG-12 CRITICAL** — `DealsListView.vue:200` styleAttr stage chip: та же причина. Fix: `var(--p-card-background)` + dark-оверрайд цвета текста `var(--p-surface-900)`.
- **BUG-2 HIGH** — `DealsToolbar.vue` dark h1: `var(--p-surface-50)` = `#272829` невидим. Fix: `var(--p-surface-900)`.
- **BUG-8 HIGH** — `DealsKanbanColumn.vue` `.kanban-col__name`/`.kanban-col__count` dark: `var(--p-surface-50)` = `#272829` невидим. Fix: `var(--p-surface-900)`.
- **BUG-11 HIGH** — `DealsKanbanCard.vue` dark amount `$primary-color` = `#172747` на `#444547` контраст 2.1:1 WCAG FAIL. Fix: `.app-dark & { color: var(--p-primary-300); }` на `.kanban-card__amount`.
- **BUG-5 MEDIUM** — `DealsFilterOverlay.vue` dark inactive chip border `var(--p-surface-600)` = `#D5D6D8` (слишком светлый). Fix: `var(--p-surface-300)` = `#54595E`.
- **BUG-6 MEDIUM** — `DealsPipelineMenu.vue` нет закрытия по клику вне меню. Fix: click-outside обработчик или директива на `.pipeline-menu`.
- **BUG-10 MEDIUM** — `DealsKanbanCard.vue` `.kanban-card__title` `$font-size-sm` = 12.25px vs 13px по спеку. Fix: explicit `font-size: 13px // stylelint-disable-line`.
- **BUG-3 LOW** — `DealsToolbar.vue` `.deals-toolbar__subtitle` `$font-size-xs` = 10.5px vs 12px. Fix: explicit `font-size: 12px // stylelint-disable-line`.
- **BUG-4 LOW** — i18n `sales.deals.page.filters.presets` = «Сохранённые пресеты» vs «ПРЕСЕТЫ» по спеку. Fix: `ru.json` → «ПРЕСЕТЫ», `en.json` → «PRESETS».

Backend gaps (не блокируют текущую QA-итерацию, беклог DS-6):
- [x] **B-GAP-1 (M) — ЗАКРЫТ M-batch (2026-06-23)** — `sort_by`/`sort_dir` реализованы (см. M-batch ниже).
- **B-GAP-2 (L)** — Импорт сделок (`POST /api/deals/import`) не реализован; кнопка задизаблена (coming soon) — корректно.
- **B-GAP-3 (M)** — Кнопка «Настроить, какие статусы скрывать» (filter overlay) — нет backend-персистентности per-user скрытых стадий; тумблеры per-session — корректно для MVP, дополнительный endpoint нужен в будущем.

**DS-6 хвосты (закрыты 2026-06-22, PM APPROVE):**

- [x] **Deals KPI endpoint — `GET /api/deals/kpi` (backend-specialist + frontend-specialist):** серверный агрегат воронки, заменяет page-local Vue computed() chips (недосчитывал сделки за пределами per_page 25). Бэкенд: `DealKpiService::forFunnel(filters, scope, user)` клонирует `DealService::kpiBaseQuery()` (новый public seam, sibling filteredQuery без with/orderBy/paginate) для каждого chip-счётчика — структурный паритет со списком, pagination не применяется. `DealKpiController` — thin `__invoke`, reuses `IndexDealRequest` verbatim (identical validation + viewAny gate). Visibility scope через `ResolveVisibility::ATTRIBUTE` (fail-closed → Own). Chip-определения: `in_work` (DISTINCT company_id non-won), `cat_l/cat_m/cat_s` (S=S1+S2 combined), `won`, `no_task`, `overdue`. Маршрут `GET /api/deals/kpi` зарегистрирован **перед** `deals/{deal}` (избегает 'kpi'-as-id binding). Ответ `{ data: { pipeline_id, in_work, cat_l, cat_m, cat_s, won, no_task, overdue } }` — plain JSON (no Resource class, mirrors ContactsKpiController). 21 PHPUnit-тест `DealKpiTest`: whole-funnel-not-page (30 deals > per_page 25), full shape, in_work distinct, won, categories S1+S2, no_task, overdue, filter parity (status/owner/country/budget/tags/only_overdue/archived), pipeline_id default+echo, 401, Own/Department/All visibility scope. Сьют Sales 638/638 PASS. Фронт: `DealKpiDto` в entities/sales.ts, `getDealKpi()` в salesApi, `useDealsKpi.ts` composable (useAsyncResource), `DealsKpiChips.vue` rewired (сервер-данные + loading skeleton), пробрасывается через `DealsListView.vue` → `DealsPage/index.vue` (параллельная загрузка в filter-change callback и reload()). KPI chips видны только в list-view (kanban — без бара, by design). QA: light+dark PASS, API stable per_page=1 vs 100 (идентичные ответы), 638 тестов PASS.
- [x] **LIKE-escape fixes — безопасный wildcard (backend-specialist):** создан `src/app/Support/LikeEscape.php` (final class, `escape()/wrap()`, `ESCAPE_CHAR='\\'`; чистый PHP `addcslashes($v,'\\%_')`, нет нового пакета). Зарегистрированы `whereLike`/`orWhereLike` Builder macros в `AppServiceProvider::registerLikeMacros()` на **обоих** `Query\Builder` и `Eloquent\Builder` — escaping + `LIKE ? ESCAPE ?` (обязательно, иначе backslash-escaping игнорируется без ESCAPE clause). Исправлены все 9 reported LIKE sites: ContactService (search 3-col + position + tags), CompanyService (search 5-col + city + tags), DealService (title q, product_q на catalog_products.name, company city). Deal tags используют `whereJsonContains` (exact match) — без изменений (корректно). Удалены сломанные `str_replace('%',...)` inline-escaping и стейлые комментарии. 16 PHPUnit-тестов: `ContactLikeEscapeTest` (5) + `CompanyLikeEscapeTest` (5) + `DealLikeEscapeTest` (6) — доказывают что `_` и `%` больше не являются wildcards (tag `vip_lead` матчит только literal `vip_lead`, не `vipXlead`); backslash матчит через ESCAPE clause. Crm suite 416/416 + Sales 396/396 PASS.
- [x] **region→country rename — FE-only (frontend-specialist):** бэкенд уже использовал `country`/`country_code` end-to-end (нет поля 'region' в request/service/Resource/DB на deal-filter пути). Переименования FE: `DealsFilters.region` → `.country` (interface + emptyFilters + applyOverlayFilters + toOverlayFilters + hasActiveFilters + activeFilterCount в useDealsFilters.ts); `country: f.country` вместо `f.region` в useDealsBoard.ts и useDealsList.ts; `OverlayFilters.region` → `.country` + localFilters + onReset + i18n ключ `filters.region` → `filters.country` в DealsFilterOverlay.vue; en.json `"country": "Country"` + ru.json `"country": "Страна"`. AWS cloud-region ключи (`config/{services,queue,cache,filesystems}.php`) и AMO sub-national region (`Domain/Migration`) — не тронуты (out-of-scope). QA: label «Страна» в overlay + column header «СТРАНА» в list-view PASS.

### M-batch. Deal activity metrics + list sort + hidden-stage board (2026-06-23)

**Статус:** DONE. sales-specialist+frontend-specialist→QA PASS. PM APPROVE (uncommitted, ветка main).

**Что сделано:**

- [x] **Deal activity metrics (`DealService::metricsFor()`):** 6 фигур на вкладке «Активность»: `days_in_deal` / `days_in_stage` (reuses `Deal::daysInStage()`) / `activities_count` / `stage_changes_count` (excludes creation row, from_stage_id!=null) / `documents_count` (lazy `app(DocumentService::class)` чтобы избежать DI-цикла) / `last_activity_at` (ISO-8601 или null). Batched через `ActivityService::dealActivityStats()` (1 aggregate) + `DocumentService::countForDeal()`. `DealController::show()` stamps `metrics_payload` на модель; `DealResource` mergeWhen-раскрывает его только на show — на list/board/store/update ключ отсутствует. 4 теста (`DealMetricsTest`): шесть фигур, zeros for bare deal, show exposes key, list omits key. Full Sales+Activity+Contracts 709/709 PASS.
- [x] **List sort (`sort_by`/`sort_dir` на всех 8 колонках):** `IndexDealRequest::SORTABLE_COLUMNS` whitelist (name/country/amount/stage/days_in_stage/last_contact/owner/created) — injection-safe (off-list = 422). `DealService::applySort()` — match по ключу; relation-joins через LEFT JOIN с пин `deals.*` чтобы не заполнять модель чужими колонками; days_in_stage = инверсия `stage_changed_at` (старее = дольше в стадии); last_contact = correlated subquery `MAX(completed_at)` из activities (без JOIN-умножения строк). `lastContactSortSubquery()` зеркалит `ActivityService::lastContactDatesForDeals` (единый источник event-kinds/status=done). Детерминированный тайбрейкер `deals.id DESC`. 7 тестов (`DealListSortTest`): default_created_desc / amount asc+desc / name asc / country / stage sort_order / last_contact desc / invalid_sort 422. Full Sales 709 PASS. Фронт: `DealSortKey` type + `DealSortState` interface + `sortState` ref + `onSort()` cycle-toggle + передача `sort_by`/`sort_dir` в `salesApi.getDeals()`. `DealsListView.vue` — `sortIcon()` helper (pi-sort-alt / pi-sort-amount-up / pi-sort-amount-down), кнопки с иконками на всех 8 колонках. build+lint clean.
- [x] **Hidden-stage board:** `PipelineStage.hidden_by_default` флаг + `revealed_stage_ids[]` query-param. `DealService::board()` — visible = non-hidden + revealed (in_array), position из sort_order (не append); colStrip `revealed_stage_ids` из columnFilters (не просочится в поколоночные фильтры); `hidden_stages` payload (все hidden-by-default stages с scope+filter-aware `deals_count`). `IndexDealRequest` — `revealed_stage_ids[]` integer array. `DealController::board()` пробрасывает `hidden_stages` в ответ. Pinia `useSalesStore.revealedStageIds: Set<number>` — in-memory, не persisted; `toggleRevealedStage()`/`resetRevealedStages()`; сброс при смене pipeline. `useDealsBoard::load()` передаёт `revealed_stage_ids: Array.from(salesStore.revealedStageIds)`. `toggleHiddenStage()` мутирует стор + перезагружает доску. `DealsFilterOverlay.vue` — `@toggle-hidden-stage` emit. `adaptBoardResponse()` — `hidden_stages: raw.hidden_stages ?? []`. 7 тестов (`HiddenStageBoardTest`): excluded by default / reveal adds column / revealed keeps sort_order / hidden_stages payload + counts / scope respects visibility / settings toggle / create with flag. DealCrudTest: 2 board assertions обновлены 11→9 (2 hidden stages cold+lost). Full Sales 396 PASS. build+lint clean.
- [x] **`ActivityService::dealActivityStats()`** — новый метод (aggregate COUNT + MAX created_at per deal_id), 1 query, public contract для Sales.

**Беклог (L, не блокеры):**
- `rate_available` ↔ `fx_rate_available` несовпадение ключа в `BoardRawColumnDto` (маскировано `?? true` в адаптере) — требует выравнивания.
- `DealsListView::rottingClass()` — захардкодены 7/14 дней, не читает `stage.warn_days/danger_days` (list-view не несёт полный PipelineStageDto).
- Preselect via URL query (cross-page) — не реализован.

---

### DS-7. Ремонт по wiring-аудиту (фильтры Контакты/Компании + мёртвые кнопки + dark + орфаны)

**Статус:** DONE (2026-06-22). frontend-specialist→qa-tester PASS. Uncommitted, ветка feat/amo-native-fields. PM APPROVE.

**Что сделано:**

- [x] **Фронт — ContactsPage:** `ContactsFilterOverlay.vue` + `ContactsActiveFiltersBar.vue` — `buildContactParams()` пробрасывает все фильтры (position/sources/tags/owner_ids/author_ids/engagement_tier/created_from/created_to/last_touch_from/last_touch_to/open_deals_min/open_deals_max/only_mine/only_active/only_with_deals/only_no_task). `ContactsToolbar.vue` — «Импорт» → `severity='secondary'` + disabled + tooltip «скоро». `useContactsFilters.ts` — `position` добавлен в DealsFilters (закрыт DS-4 хвост). `ContactsPage/index.vue` — SavedViews смонтирован.
- [x] **Фронт — ContactPage:** `ContactChannelsBlock.vue` — кнопка «+» работает (открывает форму добавления канала). `useContactPageActions.ts` — `submitAttachCompany(isPrimary)` через `useMutation`, `setPrimaryCompany()` реализован, `searchAttachCompany` с дебаунсом. `ContactPage/index.vue` — AutoComplete с is_primary параметром + отдельная кнопка «Установить основной».
- [x] **Фронт — CompanyPage:** `CompanyPage/index.vue` — меню-кнопки «Позвонить»/«Написать»/«Скопировать ссылку»/«Удалить» работают; toast для missing phone/email. `api/crm/contacts.ts` + `api/crm/companies.ts` — buildContactParams/buildCompanyParams обновлены.
- [x] **Удалены орфаны** (нулевые импорты): `DealsTaskBoard.vue`, `DealsPage/TaskCard.vue`, `DealsPage/useTaskBoard.ts`, `DealsFilterPanel.vue`, `HiddenColumnsToggle.vue`, `EntityNowStrip.vue`. MyTasksPage — собственные `TaskCard.vue` и `useTaskBoard.ts` НЕ затронуты.
- [x] **Dark-идиомы:** `.app-dark &` (не `:global`) применены во всех новых/переработанных компонентах; инверсия surface-переменных.
- [x] **Беклог (не блокер):** `category_code` фикс (фронт слал `category_codes` — теперь `category_code`); `SavedViewsDropdown.vue` использует `:global(.p-menuitem--danger)` для PrimeVue selector — допустимо (внешний компонент, нет DDD-аналога). `DealsKanbanCard.vue` — `:global(.kanban-card--ghost)` для drag-placeholder — допустимо (SortableJS inject).

**Беклог (не блокер):**
- B-4 files API — `ContactFilesTab` / `CompanyFilesTab` — graceful «скоро» (ждёт `GET /api/{contacts|companies}/{id}/files`).
- gate disconnect/reconnect на фронте без проверки роли менеджера (бэкенд-gate = update-policy; обсудить с юзером).

---

### M3. Sales / Kanban (2-3 недели)

**Ведущие:** `sales-specialist` + `catalog` (в его зоне) + `frontend-specialist` + `designer`. Контекст `Sales` + `Catalog`.

- [x] Pipeline (sales/lifecycle/renewal), стадии, lost-reasons. *(S1.3 backend, 2026-06-12)*
- [x] Deal: CRUD, продукты line-items (DealProduct), контакты, история стадий. *(S1.3 backend, 2026-06-12)*
- [x] **Kanban-доска** (vuedraggable@^4 drag-n-drop) + list-view (DataTable, фильтры). *(S1.3 frontend, 2026-06-12)*
- [x] Карточка сделки (inline-edit, line-items, contacts, history). *(S1.3 frontend, 2026-06-12)*
- [x] **DealPage 2.0 amo-style redesign** (двухпанельный layout, DealFeed+Composer, вкладки Основное/Документы/Финансы/Статистика, контакт-каналы, audit-лента, кастом-поля, архив/soft-delete). Backend P0–P2 (`523cf57`, `967f16e`, +37 тестов, сьют 1343 PASS) + UI Фазы A–G (`c205e7f`) + 2 фикс-итерации (`9d5dc58`: i18n namespace/escape, endpoint каналов, слой данных, формат даты, note-badge). PM APPROVE, **браузерный QA PASS** (3 раунда: миграции dev-БД применены, console 0 ошибок, регрессия чистая). *(DealPage 2.0, 2026-06-15)*
- [x] Каталог продуктов/планов/цены (Catalog). *(S1.2, 2026-06-12)*
- [x] Тесты: переходы стадий, расчёт суммы сделки, drag-drop API (253/569 PHPUnit). *(S1.3, 2026-06-12)*
- [x] **Дашборд продаж** (SalesDashboardService, 5 виджетов ECharts, Excel-экспорт, мультивалютный warning, visibility-scope, DashboardController+Resource). 433/433 PHPUnit, QA PASS. *(S1.7, 2026-06-12)*
- [ ] Форма отчёта о встрече (MeetingReport) — S1.6 (Activity)
- [x] **Backend-фундамент Kanban-редизайна Сделок (v2-B1 + v2-B2 + board enrichment + my-board):** `deal.nextTask` HasOne relation + `Deal::daysInStage()` + `DealService::board()` enriched (next_task/primary_product batched, amounts_by_currency SQL GROUP BY, ExchangeRate base sum + multi_currency_warning) + `ActivityService::nextTasksForDeals()` (ROW_NUMBER PG / PHP-fallback SQLite) + `ActivityService::myBoard()` urgency-buckets + `ActivityService::stampDealTitles()` + `ActivityType::FollowUp` case + `ActivityType::taskLikeValues()` + `PipelineStage.warn_days/danger_days` migration (reversible) + `DealCardResource` (next_task/primary_product/days_in_stage) + `DealResource` (next_task/days_in_stage) + `PipelineStageResource` (warn_days/danger_days) + `ActivityCardResource` (deal link) + `GET /api/activities/my-board`. 18 новых тестов + глобальный сьют 1567 PASS / 3721 assertions. Миграция применена к dev-БД. 2026-06-16.*
- [x] **Kanban-редизайн Сделок — Срез 1 (карточка-гибрид) + Срез 2 (тулбар/хром страницы). PM APPROVE (commit dbc3b44, 2026-06-17).** `DealsKanbanCard.vue` — полная переработка: health-сигнал (кант + нижняя плашка ok/no-task/overdue), rotting-счётчик (warn/rotting по warn_days/danger_days из stage), аватар менеджера, чип primary_product, bulk-чекбокс. `DealsKanbanColumn.vue` — заливка шапки из `stage.color` (10 вариантов: 5 bright/5 soft), мульти-валютный Popover, кнопка «+» в шапке. `DealsToolbar.vue` (NEW) — amo-style одна строка (поиск+фильтр / сводка / 3 вида / ⋯ меню / + Новая). `DealsFilterOverlay.vue` (NEW) — фиксированный оверлей с backdrop, 3 блока (пресеты ToggleButton / свойства / теги). `DealsBulkToolbar.vue` (NEW) — 6 действий + счётчик + отмена. `DealsTaskBoard.vue` (NEW) + `TaskCard.vue` (NEW) + `useTaskBoard.ts` (NEW) — персональный канбан задач, вид 3. `StageColorPicker.vue` (NEW) — палитра 10 кружков. `salesStore` — DealsView 'kanban'|'list'|'tasks' + BoardSort + bulkMode + localStorage persist. `entities/sales.ts` — NextTaskDto / PrimaryProductDto / BoardColumnDto multi-currency. `useDealsFilters.ts` — расширен на overlay-фильтры. `_colors.scss` — health + stage + task-tag цветовые токены. i18n: sales.deals.page.{toolbar,menu,bulk,card,taskTypes,currency,columns} + tasks.board.* (RU+EN). PM APPROVE, pending QA. 2026-06-16.*
- [x] **Тех-долг Сделки-борд (bulk-операции + select-all + preselect + FxRate-fallback) — backend-specialist + frontend-specialist, 2026-06-17.** Backend: `BulkDealService` (all-or-nothing 403, каждая сделка авторизуется по `update`/`delete` ability, DB::transaction, делегация в DealService::update/DealMoveService::move/DealService::delete), `BulkDealActionRequest` (4 операции change_owner/change_stage/set_field/edit_tags, whitelist `DIRECT_SET_FIELDS`, currency-whitelist в withValidator), `BulkDealDeleteRequest`, `DealExportService` (filteredQuery + chunkById 500, visibility-scope, `Amount (kopecks)` raw-колонка, scope-тест). Маршруты: `PATCH /api/deals/bulk` + `DELETE /api/deals/bulk` (перед `deals/{deal}` чтобы 'bulk' не биндился как id). 8 тестов `DealBulkTest` + 3 теста `DealExportTest`. Frontend: `DealsBulkToolbar.vue` (select-all Checkbox + indeterminate + selectAll/clearSelection emit), `allVisibleDealIds` computed (kanban = localColumns.flatMap, list = deals, tasks = []); bulk-диалоги 5 шт. (BulkAssignDialog/BulkMoveStageDialog/BulkEditFieldDialog/BulkTagDialog/BulkAddTaskDialog) через `salesApi.bulkPatchDeals`/`bulkDeleteDeals`; preselect via `salesStore.bulkSelection` из Pinia. Беклог (не блокер): (1) ❗ поле `rate_available` (бэк) ↔ `fx_rate_available` (фронт-адаптер) — несовпадение ключа; `?? true` в адаптере маскирует; ≈-префикс всегда показывается, даже когда курс недоступен — требует фикса в `BoardRawColumnDto`; (2) `DealsListView.vue::rottingClass` использует захардкоженные 7/14 дней вместо `stage.warn_days/danger_days` (list-view не получает PipelineStageDto с порогами); (3) preselect через URL-query (cross-page) — не реализован (текущий preselect — только через Pinia в рамках страницы).
- [x] **UI-апдейт Sprint (frontend-specialist + sales-specialist, 2026-06-20). PM APPROVE, QA PASS.** Пять независимых срезов:
  - **Login-сплит:** `LoginPage/index.vue` — two-column split layout (левая тёмная брендовая панель `$brand-header-bg` + правая форма), 3 брейкпоинта (≥768 side-by-side, 480–767 compact-top-banner, <480 панель скрыта + мобильный логотип в карточке). Форма/composable/auth-логика не затронуты. i18n `auth.brand_panel.tagline/accent` (RU+EN). tsc+lint+build clean.
  - **Меню (nav/menu):** `navItems.ts` — «Задачи» переименован, добавлена группа «Онбординг» (admin/director), `SettingsPage` hub-страница создана, `adminNavItems` разделитель убран. tsc+lint+build clean.
  - **Навигация/заметки (nav/notes):** три точечных фикса — навигация по именам (contact notes visibility). tsc+lint+build clean.
  - **Визуал воронки (funnel):** `DealsKanbanColumn.vue` — шапка колонки: насыщенные мягкие цвета + центрированное жирное название стадии. tsc+lint+build clean.
  - **Редизайн карточки сделки + бэкенд (card+backend):**
    - Frontend: `DealInfoPanel.vue` — панель расширена (~30%, 290→380px/320→420px). `DealInfoHeader.vue` — плановые даты (договор + оплата) в тёмную шапку двухколоночным блоком, строки «Бюджет» и «Дней в работе» убраны из шапки. Группа «Сделка» (даты) удалена из `DealTabMain`. `DealProductRow.vue` — просмотр: бейдж скидки + нетто-сумма; редактирование: `InputNumber` для скидки в копейках. `DealProductsGroup.vue` — строка скидки (−N, зелёная) + нетто-итог вместо старого единственного Total. Группа «Данные компании» → «Компания». Строка прогресса стадии — только «N дн.» без дублирования имени стадии. tsc+lint+build clean.
    - Backend (`sales-specialist`, `Domain/Sales`): миграция `2026_06_23_120000_add_discount_to_deal_products_table` (reversible, `unsignedBigInteger discount default 0`). `DealProduct.$fillable` + `casts()` + factory. `DealProductService::netAmount()` — `max(0, round(qty*price) - discount)`, clamp no-negative. `StoreDealProductRequest` + `UpdateDealProductRequest` — `discount nullable|integer|min:0`. `DealProductResource` — `discount` + `amount` (netto). `DealResource` — `discount_total` (сумма скидок по loaded products, без extra query). Плановые даты: `expected_sign_date` (договор) + `expected_payment_date` (оплата) — подтверждено присутствие в fillable/casts/requests/resource (schema-change не требовалась). 6 unit (`DealAmountTest`: net/discount/clamp) + 9 feature (`DealProductTest` discount, `DealCrudTest` planned-dates round-trip). 29 целевых тестов PASS; миграция проверена (up/rollback/re-up на dev-Postgres). Pint clean (11 файлов).
- [x] **UI-апдейт волна 2 — Группа B (sales-specialist + frontend-specialist, 2026-06-20). QA PASS, PM APPROVE.**
  - **Бэкенд задачника (backend):** `ActivityService::list()` + `stampDealContext()` — батчевая подгрузка deal+stage+company (2 запроса, no N+1) на каждой странице списка. `ActivityService::update()` — ре-гейт `kind` на whitelist стадии при inline-смене типа задачи. `ActivityService::complete(activity, user, ?resultText)` — idempotent, null resultText не вытирает ранее сохранённый. `ActivityService::reschedule(activity, preset)` — server-side пресеты tomorrow/next_week/next_month в app-TZ (no +4h). `ActivityController` — 2 новых action-метода: `complete` (POST /api/activities/{activity}/complete) + `reschedule` (POST /api/activities/{activity}/reschedule). Оба возвращают `ActivityResource`. `CompleteActivityRequest` (NEW) + `RescheduleActivityRequest` (NEW). `ActivityResource::dealContext()` — приватный метод, поле `deal` в ответе (null когда не stamped). `ActivityCardResource` — дополнен `deal_context`. 11 тестов `ActivityTaskListTest` (34 assertions); full suite 1955 PASS / 4977 assertions. Pint clean.
  - **Перенос канбана в «Задачи» (frontend):** `MyTasksPage/components/TasksKanbanBoard.vue` (NEW) — канбан с бакетами (Просрочено/Сегодня/Завтра/Эта неделя/Следующая неделя), поиск, scope-switcher. `MyTasksPage/components/TaskCard.vue` (NEW). `MyTasksPage/composables/useTaskBoard.ts` (NEW). `MyTasksPage/index.vue` — view-switcher (pi-th-large / pi-list) в `#actions` PageHeader, канбан по умолчанию, персист в `tasks_active_view` localStorage. `DealsToolbar.vue` — удалена 3-я кнопка вида (pi-check-square). `DealsPage/index.vue` — удалён блок `<DealsTaskBoard>`, обработчики `onTaskCompleted`/`onTaskError`, ветка `tasks` в `onSetView`. `salesStore.DealsView` сужен до `'kanban' | 'list'` + guard на старое значение `'tasks'`. i18n: `tasks.page.viewKanban/viewList` (RU+EN). tsc+lint+build clean.
  - **Список 2.0 с колонками и inline-edit (frontend):** `MyTasksTable.vue` — полный рерайт. Колонки: checkbox | Дата исполнения | Ответственный | Компания/Сделка | Тип задачи | Статус сделки | Статус задачи | Текст задачи | Actions. Inline-редакторы по клику: DatePicker (due_at), Select (responsible/kind/status), InputText (title). PATCH + оптимистик-апдейт + откат тостом. Компания/Сделка и Статус сделки — read-only ссылки из `deal_context`. `activityApi.changeStatus(id, status, resultText?)` (NEW). `ActivityDealContextDto` в entities. i18n: `tasks.list.*` + `activity.kinds.follow_up` (RU+EN).
  - **AMO-compact форма (frontend):** `front/src/components/tasks/TaskQuickForm.vue` (NEW) — shared-компонент, два режима (create/complete). Props: mode/activity/targetType/targetId/defaultKind/defaultResponsibleId/defaultResponsibleName/closable/autoFocus. Emits: created/completed/delete/cancel. Переиспользован в `TasksKanbanBoard.vue` (inline-create, «+» button) и `DealFeedItem.vue` (inline-complete, кнопка «Выполнить»). `DealFeed.vue` — удалён мёртвый `@complete`/`onComplete` handler. i18n: `tasks.quick.*` (RU+EN). tsc+lint+build clean.
  - **Беклог (не блокер):** `DealsPage/components/DealsTaskBoard.vue`, `DealsPage/components/TaskCard.vue`, `DealsPage/composables/useTaskBoard.ts` — остались в файловой системе, не импортируются. Можно удалить в cleanup-пасс.
- [ ] **UI-апдейт волна 2 — Группа C (sales-specialist + frontend-specialist, 2026-06-20). QA FAIL — нужна доработка фронта (contract/type mismatch).**
  - **Лента-реверс (frontend):** `useDealFeed.ts` — `openTasks` computed (не-закрытые task/call/meeting/follow_up из allItems), `filteredItems` исключает открытые задачи, `groupByDate` переработан в ASC (oldest-first) + items внутри группы ASC; `DealFeed.vue` — `.deal-feed__inner` flex + `justify-content: flex-end` (короткая лента прибита к низу), watch `groups` → `scrollToBottom()`, «Load more» перемещён наверх; `DealFeedItem.vue` — удалены `TaskQuickForm` + кнопка «Выполнить»; `index.vue (DealPage)` — `<OpenTasksList>` встроен между feed и composer; аналогичные изменения в `useEntityFeed.ts` + `EntityActivitiesTab.vue`.
  - **OpenTasksList.vue (frontend, NEW):** компактный список открытых задач (max 220px overflow-y), inline `TaskQuickForm mode="complete"` с slide-transition, emits `completed`/`deleted`.
  - **Backend audit-log / `app/Domain/Log` (backend):** `entity_logs` — FK-less polymorphic append-only таблица (subject_type(30)/subject_id/actor_id nullable FK→users nullOnDelete/action(40)/meta json/created_at only); составной индекс `ix_entity_logs_subject(subject_type,subject_id,created_at)`. `EntityLog` model + `EntityLogService::record()/forSubject()` + `EntityLogFactory`. Enums: `LogSubjectType(deal|company|contact)` + `LogAction(created/stage_changed/contact_added/meeting_held/task_completed/kp_sent/contract_sent/data_changed/contract_event/finance_event)`. Инструментированы: `DealService::create/createInbound`, `DealMoveService::move`, `DealContactService::addContact`, `CompanyService::addEmployee/update`, `DealService::update`, `ActivityService::complete`, `DocumentService::transition` (wired, extension point). `finance_event` — зарезервировано, не эмитируется до M9. Endpoint `GET /api/{deals|companies|contacts}/{id}/log` → `EntityLogController` (тонкий, 3 метода, `authorize('view', $entity)` делегирует видимость лога существующим политикам DealPolicy/CompanyPolicy/ContactPolicy). 3 новых тест-файла: Unit/Log/EntityLogServiceTest + Feature/Log/EntityLogEndpointTest + Feature/Log/EntityLogRecordingTest. Полный сьют 1996 PASS, Pint clean.
  - **key_actions / ключевые действия (backend):** `DealResource` — `key_actions[]` 6 записей ({type, date|null, ref?}): last_presentation/max_stage/kp_sent/contract_sent/last_touch/last_event. Новые колонки: `max_stage_id` FK→pipeline_stages + `kp_sent_at` + `contract_sent_at` (миграция `2026_06_24_120000`, reversible). `DealService::markKpSent()/markContractSent()` + endpoint `POST /api/deals/{deal}/kp-sent` + `POST /api/deals/{deal}/contract-sent`. `ActivityType::Presentation` (new case) + `ActivityService::keyActionDatesForDeal()`. `DealService::markContractSentFromDocument()` — auto-stamp при Document→submitted. 18 новых тестов; сьют 1996 PASS.
  - **key_actions / ключевые действия (frontend):** `DealKeyActionsBar.vue` (NEW) — 6-chip строка в `DealInfoHeader.vue`, `markKpSent()/markContractSent()` в `salesApi.ts`, `scrollToFeedItem(type)` expose в `DealFeed.vue` + flash-highlight.
  - **Лог-вкладка (frontend):** `front/src/api/crm/log.ts` (NEW) + `composables/crm/useEntityLog.ts` (NEW) + `components/crm/entity/EntityLogTab.vue` (NEW, shared). `DealInfoTabs.vue` — вкладка «Статистика» переименована в «Лог». `DealInfoPanel.vue` — `DealTabStats` заменён `EntityLogTab`; 6 compact metrics; `useEntityLog('deal', ...)`. `CompanyPage/index.vue` + `ContactPage/index.vue` — tab `log` добавлен.
  - **Критическое расхождение (блокер QA):** фронтовый `EntityLogEntry` ожидает поля `event_type` / `user` / `description` / `old_value` / `new_value`; бэкенд `EntityLogResource` отдаёт `action` / `actor` / `meta` (raw array). Лог-вкладка рендерится пустым экраном на реальных данных (иконки и тексты не резолвятся). Требует исправления — либо адаптер на фронте, либо выравнивание `EntityLogResource` под `EntityLogEntry`.
  - **Беклог (не блокеры):** `DealTabStats.vue` — файл остался, нигде не импортируется (cleanup-пасс). `openTasks` не постранично — если задач > 30 и пользователь не нажал «Load more», часть может не попасть в список.
- [x] **UI-апдейт волна 2 — Группа A (sales-specialist + frontend-specialist, 2026-06-20). QA PASS, PM APPROVE.**
  - **Pipeline duplication (backend):** `PipelineService::duplicate(Pipeline)` — deep-copy в новую INACTIVE воронку: имя «{name} (копия)», полная копия всех стадий (name/code/sort_order/color/warn_days/danger_days/is_won/is_lost/hidden_by_default/won_gate/won_gate_contract_required/sla_hours/stage_features/task_types/required_fields/visibility), двухпроходная сшивка parent_stage_id old→new, копия PipelineAutomation (pipeline_id+stage_id ремаппинг, round_robin_cursor=0, last_run_at=null, created_by_user_id сохранён). Новая копия is_active=false — не перехватывает defaultSalesPipeline(). Нет новых миграций (is_template не вводится — дублирование служит сценарию «из шаблона» напрямую). Эндпоинт POST /api/pipelines/{pipeline}/duplicate (201+PipelineResource), PipelinePolicy::create (admin/director). 8 новых тестов, сьют 728 Sales+Automation passed, Pint clean. Удалено 15 не-git macOS-стрейв «* 2.php» файлов (не были закоммичены, есть реальные аналоги).
  - **Pipeline duplication (frontend):** `PipelineListItem.vue` — кнопка «Copy» (pi-copy) на каждом элементе списка с `:loading="duplicating"`. `PipelineList.vue` — пробрасывает `duplicatingId`/`highlightedId`. `CreatePipelineDialog.vue` — режим «from template» через `SelectButton` empty|from_template + `Select` воронки-источника; emit `duplicate(sourceId)`. `PipelineSettingsPage/index.vue` — `handleDuplicatePipeline(id)` (инлайн-копирование) + `handleDuplicateFromTemplate(sourceId)` (диалог). `usePipelineSettings.ts` — `duplicatePipeline(id)`, `highlightedPipelineId` ref (2.5s flash-highlight новой воронки). `salesApi.duplicatePipeline(id)`. i18n: duplicatePipeline.buttonTitle/successToast; createPipelineDialog.modeEmpty/modeTemplate/templateLabel/templatePlaceholder/templateEmpty/templateInfo/saveDuplicate. tsc+lint+build clean.
  - **Inline contact creation (backend, уже было, переиспользуется):** POST /api/contacts — существующий эндпоинт, без изменений.
  - **Inline contact creation (frontend):** `CreateContactInlineDialog.vue` (NEW) — shared-компонент с 5 полями (full_name*/phone*/email/position*/notes) + ToggleSwitch is_primary (showIsPrimary prop). Встроен в `DealAddContactDialog.vue` (sentinel «+ Создать контакт» в AutoComplete, onInlineContactCreated → attachContact к сделке) и `CompanyPage/index.vue` (sentinel в autocomplete сотрудников, openAddEmployee→closeAddEmployee→openInlineCreate, onInlineContactCreated → attachEmployee). i18n: contacts.inline_create.{title,fields.*,placeholders.*,validation.*,success,cancel,submit,create_option} (RU+EN). tsc+lint+build clean.
- [x] **DealPage 2.0 v2 — детальная карточка сделки, корректировки. PM APPROVE (commit cd80982, 2026-06-17).** `DealInfoHeader.vue` — `$brand-header-bg` токен вместо `#172747`; убран подзаголовок `#ID · Компания` (оставлен `#ID` в top-row); стадия кликабельна (кнопка-обёртка + `pi-chevron-down` + tooltip); убрана отдельная кнопка «Изменить стадию»; меню ⋮ новый состав (убраны changeOwner/moveStage/duplicate/archive; добавлены collapseAll/expandAll/customizeFields/copyLink); `DealHealthChip.vue` (NEW) — чип next_task (ok/overdue/no-task). `DealTabMain.vue` — prop `daysInStage`; строка «Дней в работе» с rotting-цветами (warn/danger через `stage.warn_days/danger_days`); чип основного продукта у бюджета; Продукты и Контакты — акцентный хедер (accent=true, count, totalLabel); Даты/Компания/Кастом — `defaultCollapsed: true`; watch `collapseAllSignal`/`expandAllSignal`. `DealFieldGroup.vue` — props `accent`, `count`, `totalLabel`; `defineExpose({ collapse, expand })`. `DealCompanyGroup.vue` — prop `defaultCollapsed`; expose `collapse/expand`. `DealProductRow.vue` — `<div>` flex-layout вместо `<tr>/<td>`; кнопки по hover. `DealFeed.vue` — убран постоянный тулбар → топбар с `pi-search` + `FeedSearchOverlay.vue` (NEW); убран отдельный ⋮; системные события — без card border/bg. `DealFeedItem.vue` — `feed-item--system` class; кнопки по hover (`.feed-item__hover-btn`); «Выполнить» всегда; `pi-user` иконка у responsible. `DealComposer.vue` — убраны Tabs, заменены `<Select>` дропдауном типа; убраны MultiSelect участники и поле location; call/meeting объединены с task формой. `DealInfoPanel.vue` — пробрасывает `nextTask`, `collapseAllSignal`/`expandAllSignal`, expose `onCollapseAll/onExpandAll`. `index.vue` — `useBreakpoints.ts` (NEW) (isMobile/isTablet); `min-width:1100px` убран; 4 брейкпоинта (≥1280/1024-1279/768-1023/<768); `<Drawer>` для планшета; SelectButton «Карточка/Лента» на мобильном; mobile-bar/tablet-bar; composer sticky на мобильном. `entities/sales.ts` — `DealDto.next_task/days_in_stage` (опц.); `DealPage/index.vue` предпочитает `deal.days_in_stage` от бэка. i18n: `clickToChangeStage`, `infoPanel`, `menu.collapseAll/expandAll/customizeFields/copyLink`, `health.noTask/overdue`, `info.fields.daysInWork`, `feed.searchTooltip`. 2026-06-16.*

**Acceptance M3:** создание сделки с продуктами; **Kanban drag-drop** меняет стадию и пишет историю; lost-reason при проигрыше; list-view с фильтрами; каталог продуктов подтягивается в line-items. CI зелёный. *(ядро выполнено S1.3 2026-06-12; остаток → S1.5+S1.6)*

**Беклог M3 (специальный таск, отложен, НЕ реализовывать сейчас):**
- [ ] **ОТЛОЖЕН: Логика красной подсветки отложенных задач (Task-overdue-red).** Если менеджер ставит задачу или переносит дедлайн на срок 1 месяц и более вперёд — подсвечивать такую задачу красным (привлечение внимания; борьба с немотивированными переносами). **Исключение:** стадии воронки, где будущие даты легитимны (напр. стадия типа COLD), исключаются из красной подсветки. **Механизм управления:** per-stage флаг в конструкторе воронки (PipelineStage) — тумблер «включить красную подсветку на этой стадии». **Зависимость:** прокачка задачника (Activity/Task — логика создания/редактирования задач, M6); реализовать в привязке к M6 или позже. Schema: добавить `bool warn_on_far_deadline (default true)` в `pipeline_stages` (отдельная миграция при реализации). **Зона:** `sales-specialist` (бэк) + `frontend-specialist` (UI в Kanban-карточке + конструктор воронки).

---

### M2+++. AMO-поля → нативные фичи MGCRM (дизайн-контракт)

**Статус:** N1–N7 DONE. PM-апрув получен по всем слайсам (N1–N5 2026-06-20, N6–N7 2026-06-20). Весь бэкенд милстоуна завершён. Хвосты: фронт всех фич (N1–N7), ETL-фаза (N7 Фаза 1–3), юр-текст DOCX ДС (от юзера), финальная доводка amo_migration-карт.
**Ведущие:** `crm-specialist` (N1, N2, N5-crm, N6-crm) + `sales-specialist` (N3, N4, N5-sales) + `contract-specialist` (N6-contract) + `migration-specialist` (N7). ТЗ: vault `MG CRM 2026/5. Планы/AMO-поля → нативные фичи MGCRM (дизайн).md` (N6) + `AMO→MGCRM — полный план миграции (build).md` (N7).

**N1 — CRM: специализация + канал привлечения (crm-specialist) ✅ DONE 2026-06-20**
- [x] Enum `CompanySpecialization` (6 значений: real_estate_agency/developer/builder/contractor/supplier/partner) + миграция `2026_06_20_200000_add_specialization_to_crm_companies` (reversible, string(32) nullable).
- [x] Справочник `acquisition_channels` (миграция `200001`, модель `AcquisitionChannel`, seeder `AcquisitionChannelSeeder` 7 baseline-каналов, idempotent `firstOrCreate`).
- [x] FK `acquisition_channel_id` на `crm_companies` + `crm_contacts` (миграция `200002`, nullable, `nullOnDelete`).
- [x] История канала: таблица `acquisition_channel_history` (миграция `200003`) + модель `AcquisitionChannelHistory` + `AcquisitionChannelHistoryService::record()` — пишет ТОЛЬКО при `old_channel_id ≠ new_channel_id` И ТОЛЬКО если ключ `acquisition_channel_id` присутствует в payload (двойной guard через `array_key_exists`).
- [x] История вызывается из `CompanyService::update()` и `ContactService::update()` (через `array_key_exists` guard; `create()` — не вызывается, история не нужна при создании).
- [x] Эндпоинты `GET /api/companies/{id}/channel-history` + `GET /api/contacts/{id}/channel-history` (`AcquisitionChannelHistoryController`, authorize view-policy).
- [x] Админ-CRUD `/api/admin/acquisition-channels` (в группе `prefix('admin')` — та же группа что company-types/contact-positions/sources/countries/cities); write: `admin-write` gate (admin/director), read: любой auth.
- [x] `StoreAcquisitionChannelRequest` + `UpdateAcquisitionChannelRequest` (name/sort_order/is_active).
- [x] `AcquisitionChannelResource` + `AcquisitionChannelHistoryResource` (old_channel/new_channel/changed_by eager-loaded).
- [x] `CompanyResource` и `ContactResource` обновлены: `specialization` (enum.value), `acquisition_channel_id`.
- [x] `StoreCompanyRequest`/`UpdateCompanyRequest` + `UpdateContactRequest`: `specialization` Rule::enum + `acquisition_channel_id` exists:acquisition_channels,id.
- [x] 267 CRM-тестов зелёных. Pint clean. 4 миграции применены на dev-Postgres.

**N3 — Sales: фактические даты + блокировка бюджета (sales-specialist) ✅ DONE 2026-06-20**
- [x] Миграция `2026_06_25_120000_add_actual_dates_and_budget_flags_to_deals_table` (reversible): `signed_at date nullable`, `paid_at date nullable`, `amount_locked boolean default false`, `perpetual_license boolean default false`.
- [x] `Deal.$fillable` + `casts()` обновлены. `DealResource` — `signed_at`/`paid_at` (toDateString), `amount_locked` (bool cast), `perpetual_license` (bool cast).
- [x] `UpdateDealRequest` — `signed_at`/`paid_at` (sometimes|nullable|date), `amount_locked`/`perpetual_license` (sometimes|boolean).
- [x] `DealService::recalcAmount()` — ранний return при `amount_locked === true` (единственная точка пересчёта, атомарное изменение).
- [x] **Контракт `amount_locked` для cross-domain (зафиксировано):** при `amount_locked = true` `Deal.amount` — авторитетный бюджет (negotiated/imported), может отличаться от `sum(deal_products)` по дизайну. Analytics/Finance/KPI/Commission **обязаны** читать `Deal.amount` как единственный авторитетный бюджет (не пересчитывать из продуктов). `DealResource` явно экспонирует `amount_locked: bool` — потребители обязаны учитывать флаг. Зафиксировано в docblock миграции и в комментарии модели.
- [x] 7 новых тестов `DealCrudTest` (signed_at/paid_at/amount_locked round-trip, defaults, validation) + 2 Unit-теста `DealAmountTest` (locked_budget_recalc_does_not_overwrite + unlocked_regression). Сьют 2031 PASS / Pint clean.

**N2 — CRM: множественные реквизиты компании (crm-specialist) ✅ DONE 2026-06-20**
- [x] Таблица `company_requisites` (модель `CompanyRequisite`): поля юр.лица, директора, налог. ID, гео, `bank_details` JSON, `is_current`, `valid_from/to`, `label/note`. FK `company_id` → `crm_companies` cascadeOnDelete.
- [x] Partial-unique «один current на компанию»: Postgres — partial index `WHERE is_current = TRUE`; SQLite — сервис-guard в `setCurrent()` транзакции.
- [x] Денорм-стратегия: источник истины = `company_requisites`; current зеркалится на `crm_companies` (12 полей + bank разложение) через `CompanyRequisiteService::mirrorToCompany()` при `setCurrent()` и `update()` (если is_current). list/search/dedup читают `Company.tax_id` — не сломаны.
- [x] Дата-миграция `2026_06_26_100001_seed_company_requisites_from_companies` — создаёт current-набор из текущих полей каждой компании (chunk 200, down() no-op).
- [x] `CompanyRequisiteService`: list/current/create/update/setCurrent (транзакция)/delete (guard: единственный current; привязанные документы)/`resolveForNewDocument` (1→авто, >1→needs_selection).
- [x] Пины: `deals.company_requisite_id` + `documents.company_requisite_id` (FK nullable nullOnDelete, миграция `100002`). Модели Deal+Document: `$fillable` + `belongsTo(CompanyRequisite)`.
- [x] Роуты `/companies/{c}/requisites` (index/store/update/destroy) + set-current + resolve. `/resolve` объявлен **до** `{requisite}` param-роута.
- [x] `CompanyResource`: `current_requisite` (whenLoaded) + `requisites` (whenLoaded). `CompanyRequisiteResource`: все поля.
- [x] 17 тестов `CompanyRequisiteTest` — CRUD, setCurrent/зеркало, инвариант «один current», гарды удаления, resolver, smoke-тест дата-миграции, пин сделки. Сьют 875 PASS.

**N4 — Catalog: вечная лицензия (crm-specialist + sales-specialist) ✅ DONE 2026-06-20**
- [x] `BillingUnit::Perpetual` (enum case `'perpetual'`, label «Вечная лицензия»; без новой миграции — varchar поле.
- [x] `ProductService::getPerpetualPlan(Product|int): ?ProductPlan` — возвращает первый perpetual-план продукта.
- [x] `ProductService::createPlan()` — guard «один perpetual-план на продукт»: abort(422) при попытке создать второй.
- [x] `DealProductService::applyLicenseMode(Deal, bool)` — пересчёт unit_price всех позиций сделки в одной транзакции (perpetual→PerpetualPlan цена; false→base price plan_id=null). Продукт без perpetual-плана или без цены — пропускается (не падает, не обнуляет). `recalcAmount` в конце самоуважает `amount_locked` (N3×N4 пересечение).
- [x] `DealProductService::addProduct()` — при `perpetual_license=true` и отсутствии явного `plan_id` авто-резолвит perpetual-план; если нет — base-план (null). Явный `plan_id` всегда приоритетнее.
- [x] Wire в `DealService::update()`: при смене `perpetual_license` флага вызывается `app(DealProductService::class)->applyLicenseMode()` в той же транзакции. Ленивый `app()` resolve для обхода DI-цикла (DealProductService→DealService).
- [x] 16 catalog-тестов (`PerpetualPlanTest`) + 9 sales-тестов (`DealPerpetualLicenseTest`).

**N5 — Жизненный цикл клиента (crm-specialist + sales-specialist) ✅ DONE 2026-06-20**
- [x] Enum `ClientStatus` (`prospect`/`active`/`disconnected`) + справочник `disconnect_reasons` (миграция `2026_06_27_100000`, admin-CRUD).
- [x] Миграция `100001` на `crm_companies`: `client_status` string(16) default `prospect`; `unique_client_since` date nullable; `disconnected_at` timestamp nullable; `disconnect_reason_id` FK nullOnDelete; `disconnect_doc_id` unsignedBigInteger nullable (без FK — добавит N6/contract).
- [x] Таблица `company_client_status_log` (миграция `100002`): append-only лог смен статуса (company_id/old_status/new_status/changed_by/changed_at/reason_id/meta). Без `updated_at`.
- [x] `CompanyService::markAsUniqueClient(Company, CarbonInterface, ?int)` — идемпотентен (guard по `unique_client_since`); пишет лог-запись.
- [x] `CompanyService::disconnect(Company, int $reasonId, ?int $docId, ?int $userId)` — статус+дата+reason; пишет лог.
- [x] `CompanyService::reconnect(Company, ?int $userId)` — если `unique_client_since` set → active, иначе → prospect; чистит disconnect-поля; пишет лог.
- [x] `DealMoveService::detectUniqueClient(Deal, int)` — на won-переходе: lockForUpdate company, `isFirstWon = unique_client_since === null` → markAsUniqueClient + `is_primary_deal = true`; иначе upsell (`is_primary_deal = false`). DDD-граница: статус компании пишется ТОЛЬКО через `CompanyService`.
- [x] `deals.is_primary_deal` (миграция `120000`, boolean default false): true = сделка-конвертер (первая win); false = стандарт или upsell. `is_upsell` derived: won && !is_primary_deal — нет отдельного столбца.
- [x] Авто-пин `deals.company_requisite_id` при создании: `DealService::create()` и `openInboundLead()` вызывают `$this->requisites->current($company)?->id`; null-кейс (0 реквизитов) — nullable без ошибки.
- [x] Артизан-команда `sales:backfill-unique-clients [--dry-run]`: ретроспективный stamp is_primary_deal + unique_client_since из имеющихся won-сделок (COALESCE(signed_at,closed_at) asc, id asc tie-break); идемпотентна.
- [x] Роуты: `GET /companies/{company}/status-log`, `POST /companies/{company}/disconnect`, `POST /companies/{company}/reconnect`; `apiResource disconnect-reasons` (admin-CRUD).
- [x] `CompanyClientStatusController` + `DisconnectReasonController` (admin-gate).
- [x] 26 crm-тестов (`ClientStatusTest`) + 3 N5-sales-тестов (`DealUniqueClientTest`) + 5 тестов (`BackfillUniqueClientsTest`).

**N6 — ДС расторжения + контур отключения (contract-specialist + crm-specialist) ✅ DONE 2026-06-20**
- [x] `DocumentKind::TerminationAgreement` + enum-метод `terminationVariableKeys()` (5 ключей scope-guard).
- [x] Шаблон `termination_agreement` (placeholder docx, юр-текст — TODO юристу) + 5 TemplateVariable группы «Расторжение» (original_contract_number/date, termination_date/reason/signatory).
- [x] `ContractGenerationService` параметризован по kind (resolveTemplate → template по kind/code, не хардкод master_skeleton).
- [x] `TerminationDocumentService::create()` — создаёт ДС (draft), пинит реквизит, автозаполняет оригинальный договор, хранит disconnect_reason_id + termination_date + termination_reason в context.custom.
- [x] `ContractContextBuilder::buildSublicensee()` переключён с legacy extra_fields на `Document.company_requisite_id → CompanyRequisite` (приоритет: пин → current → Company-колонки). Исправлен pre-existing баг (extra_fields-путь).
- [x] ДС-required scoping: `terminationVariableKeys()` применяются как required ТОЛЬКО при kind=termination_agreement; обычные договоры не 422-ят — прод-регрессия поймана и исправлена.
- [x] FK `disconnect_doc_id → documents.id` nullOnDelete (миграция `2026_06_27_200001`).
- [x] Событие `TerminationAgreementSigned` (emits только Contracts; payload: documentId/companyId/signedAt). Диспетчится в `DocumentService::dispatchTerminationSignedIfNeeded()` — строго после коммита транзакции.
- [x] `CompanyDisconnectService::initiate()` — создаёт ДС через `TerminationDocumentService`, НЕ меняет client_status, embed reason_id/date/name в context.custom. Возвращает Document.
- [x] Листенер `DisconnectCompanyOnTerminationSigned` в `Domain/Crm/Listeners/` — слушает событие Contracts, вызывает `CompanyService::disconnect()`. Идемпотентен (already-disconnected → no-op + Log::info). Зарегистрирован в `AppServiceProvider::boot()`.
- [x] `POST /companies/{id}/disconnect` переосмыслен в initiate (возвращает Document, не меняет статус компании).
- [x] `CompanyService::reconnect()` — reverses disconnect, чистит disconnect_*, status→active/prospect по unique_client_since.
- [x] 384 contracts-тестов + 386 crm-тестов зелёных.

**N7 — Инфра миграции AMO (Фаза 0) (migration-specialist) ✅ DONE 2026-06-20**
- [x] Таблица `external_refs` (миграция `2026_06_28_120000`): UNIQUE(source, entity_type, external_id) — идемпотентность ETL; FK-less полиморф; `external_payload jsonb`.
- [x] Таблица `migration_maps` (миграция `120001`): UNIQUE(map_type, amo_id, amo_parent_id) — карты custom-полей/опций.
- [x] Таблица `amo_product_mappings` (миграция `120002`): UNIQUE(amo_enum_id); FK catalog_product_id/catalog_plan_id nullOnDelete; action: map/skip/other.
- [x] Модели `Domain/Migration/{ExternalRef, MigrationMap, AmoProductMapping}`.
- [x] `created_by_id` FK nullable nullOnDelete на deals/crm_contacts/crm_companies (миграция `120003`); relation `creator()` на Deal/Contact/Company.
- [x] `is_service` boolean default false + index на users (миграция `120004`); в User `$fillable` + casts.
- [x] `AmoImportUserSeeder` — SAMPLE (не в reset-clean); email `import-amo@mgcrm.local`; is_service=true, is_active=false, роль Manager; idempotent (пароль не сбрасывается при повторном запуске).
- [x] `config/amo_migration.php` — скелет карт (pipelines/status_map/user_map TODO перед load-фазой).
- [x] 20 migration-тестов (MigrationSchemaTest + AmoImportUserSeederTest + AmoMigrationConfigTest) зелёных.

**Фронт FE-1…FE-5 — ✅ DONE (frontend-specialist, 2026-06-21, uncommitted, ветка feat/amo-native-fields)**

- [x] **FE-1 (N1):** специализация (InlineEditableField select, блок «Классификация» в CompanyRequisitesPanel); `ClientStatusBadge.vue` (Tag+Popover со status-log, 3 severity); раздел «Маркетинг» (CompanyMarketingPanel + ContactMarketingPanel, InlineEditableField select из directoriesStore); `ChannelHistoryDrawer.vue` (Drawer right, список смен).
- [x] **FE-2 (N2):** `CompanyRequisitesPanel` полностью переработан: список `RequisiteCard`, «Действующие», `RequisiteFormDialog` (create/edit/set-current); guard-удаление (422-toast без краша); `onUnmounted` + снаппи-закрытие (close до рефетча).
- [x] **FE-3 (N3):** `DealDatesGroup.vue` — 4 пары «план/факт» (`DatePicker` PrimeVue dd.mm.yy, computed overdueDays); `DealProductsGroup` расширен: toggle perpetual (`ToggleSwitch`+`ConfirmDialog`) + замок бюджета (иконка + кнопка «Снять замок», двух-строчный режим при расхождении). `DealTabMain` — perpetualMutation/lockMutation через `useMutation<DealDto>`.
- [x] **FE-4 (N1/N6):** `AcquisitionChannelsPage` + `DisconnectReasonsPage` (DataTable+Dialog CRUD, ToggleSwitch is_active, ConfirmDialog delete); роуты `/admin/acquisition-channels` + `/admin/disconnect-reasons` (gate roles: admin/director); SettingsPage hub расширен; directoriesStore — `acquisitionChannels` + `disconnectReasons` в `fetchAll()`.
- [x] **FE-5 (N6):** `DisconnectDialog.vue` (причина+дата, initiate → создаёт ДС, статус НЕ меняется сразу); `TerminationDocumentDrawer.vue` (генерация PDF + upload скана, step-lock до генерации); reconnect через confirm; компания-меню «Отключить/Возобновить» по `client_status`; `TerminationAgreementSigned` → статус «отключён» (бэкенд-событие).
- [x] Дублирующие `<Toast/>` + `<ConfirmDialog/>` убраны из DealPage + CompanyPage (остались только в DefaultLayout).
- [x] i18n RU+EN полный для всех 5 срезов.
- [x] 3 раунда браузерного QA: FE-1..4 PASS; FE-5 PASS (генерация ДС 422 на dev — placeholder-шаблон, не фронт-баг).
- [x] Orbita inline-label refactor (попутный UX-фикс): `v-tooltip` заменён на `orbita-action-btn` inline-label pattern в `NotificationsButton`, `UserProfileButton`, `QuickActionsCluster`; scoped slot `labelSide` из `OrbitaPanel`.
- [x] Новые тесты: `CompanyKpiTest.php` + `ContactKpiTest.php` (untracked — нужно добавить в commit).

**Открытые хвосты (беклог, не блокеры для текущих спринтов):**
- ETL-фазы N7 (Фаза 1–3: extract/transform/load AMO → MGCRM) — отдельная задача migration-specialist после заполнения config-карт юзером.
- Юр-текст DOCX ДС расторжения — TODO от юзера (юристу); placeholder-шаблон уже создан, placeholder docx — загрузить через `/api/templates/{id}/upload`. Генерация PDF даст 200 только после загрузки реального docx.
- Gate `disconnect`/`reconnect` — бэкенд: `$this->authorize('update', $company)`. Фронт: меню-пункт показывается по `client_status`, без дополнительной проверки роли менеджера на фронте. Вопрос: нужна ли явная роль manager+? — обсудить с юзером, сейчас приоритет: бэкенд-gate = update-policy (менеджер может).
- Finance/Analytics (M9/M10) при расчётах: читать `Deal.amount` + проверять `amount_locked`; `is_primary_deal=true` = новый клиент, `won && !is_primary_deal` = upsell. `finance-specialist` и `analytics-specialist` должны получить этот контракт при старте M9/M10.
- DealDatesGroup: использован `DatePicker` PrimeVue напрямую (не `InlineEditableField type=date`) — ТЗ указывало InlineEditableField, фактически DatePicker (расхождение несущественно по UX, зафиксировано).

---

### M4. Inbox / Каналы / Формы (1-2 недели)

**Ведущие:** `sales-specialist` + `integration-specialist`. Контекст `Inbox`.

> **DEALS 2.0: отдельной сущности Lead нет.** Входящий трафик → сразу создаёт **Компанию + Сделку в стадии «Новые лиды»** (воронка строится вокруг Компании). Сущности `Lead` и `Counterparty` из старого проекта — deprecated, в MGCRM НЕ воскрешаем.

- [ ] InboundMessage (TG/WA/Email/Form), Channel-конфиги.
- [ ] Конструктор форм + публичная сабмит-страница.
- [ ] Авто-роутинг входящих сообщений: сабмит/сообщение → **Компания + Сделка** (стадия «Новые лиды»).
- [ ] UI: inbox-список, билдер форм.
- [ ] Тесты: сабмит формы → создание Company+Deal, конфиги каналов.

**Acceptance M4:** входящее сообщение/сабмит формы создаёт Компанию+Сделку в «Новые лиды»; публичная форма принимает заявки; Channel-конфиги работают. CI зелёный.

---

### M5. Договоры (2-3 недели)

**Ведущие:** `contract-specialist` + `frontend-specialist`. Контекст `Contracts`.

- [ ] Template (CRUD, YAML-конфиги продуктов/стран из old как ТЗ), TemplateVariable (text/textarea/number/date/select/checkbox).
- [ ] **Генерация PHPWord→Gotenberg→PDF** (замена docxtpl+LibreOffice) — паттерн Vizion.
- [ ] Версии/ревизии (ContractRevision), ремарки юристов, вложения (подписанные копии).
- [ ] **Статус-машина** draft→submitted→in_review→approved→signed→uploaded→archived.
- [ ] Маршруты согласования (ApprovalRoute, Approval).
- [ ] **WYSIWYG-редактор (договоров) не делаем**; генерация PHPWord→Gotenberg→PDF; на будущее — возможна онлайн-правка через Google Docs — прорабатываем ближе к делу.
- [ ] Сумма прописью (RU) в шаблонах.
- [ ] Тесты: генерация PDF (мок Gotenberg), статус-переходы, маршрут согласования.

**Acceptance M5:** **договор генерится PHPWord→Gotenberg→PDF** по шаблону с подстановкой переменных; проходит маршрут согласования; статус-машина не пускает невалидные переходы; версии сохраняются. CI зелёный.

---

### M6. Активности / Задачи + Уведомления (1-2 недели)

**Ведущие:** `sales-specialist` (activity) + `integration-specialist` (notifications dispatch) + `bot-specialist` (TG-канал). Контексты `Activity` + `Notification`.

- [ ] Activity (call/meeting/task/note), линковка к сделкам/контактам, таймлайн на карточках.
- [ ] Задачи: исполнитель, дедлайн, категории.
- [x] **Уведомления in-app (task #9, commit 21fdd73, 2026-06-17):** `Notification` domain (`app/Domain/Notification/`): модель, `NotificationCategory` enum (task/approval/deal/mention/system), `NotificationService` (createForUser/dispatch/grouped/markRead/markAllRead/unreadCount/digest/markReadBatch), `NotificationPolicy` (строго recipient-scoped), `NotificationController` (GET /api/notifications, GET /count, POST /read-batch, POST /{id}/read, POST /read-all), `NotificationFeedResource`+`NotificationResource`, `ReadBatchNotificationsRequest` (max 200 ids, `exists` guard, user-scope в service), `NotifyActivityAssigneeListener` (ActivityAssigned → actionable task-нотификация), `NotifyApproversListener` (DocumentSubmittedForApproval → actionable approval-нотификация). Миграции: `create_notifications_table` + патч `add_action_label_to_notifications_table` (applied dev Postgres). Фронт: `notificationsStore` (fetchUnreadCount → GET /count, lightweight; syncCount/decrement/clearCount), `useNotificationsFlyout` (useAsyncResource/useMutation, load/loadMore/markRead/markAllRead/onFlyoutClose-batch), `NotificationsButton.vue` flyout в Орбите (bootstrap onMounted → count-endpoint; open → grouped; close → read-batch). 63 теста PHPUnit/SQLite (35 feature API + 28 unit dispatch/service); full suite 1601 PASS. **Тех-долг-B ЗАКРЫТ: over-fetch бейджа устранён** — badge bootstrap (`onMounted`) и read-on-close идут через лёгкие `/count` и `/read-batch`, grouped-endpoint вызывается только при открытии флайаута. Беклог (не блокер): `exists:notifications,id` в ReadBatchRequest — id-oracle (иностранный id, существующий в таблице, проходит валидацию, но service молча его скипает); приемлемо для внутренней CRM.
- [x] **Тех-долг-C — фронт-клинап (frontend-specialist, 2026-06-17, pending commit).** 4 пункта: (1) **Quick-action create-дровер** — `uiTriggersStore` (`stores/uiTriggers.ts`, NEW, Pinia, НЕ persist) как event-bus; `quickActionRegistry.ts` расширен `actionType: 'route'|'drawer'|'inline'` + `drawerKey?: DrawerTrigger`; `create_deal`/`create_contact` переведены на `actionType: 'drawer'`; `QuickActionsCluster.vue::execute()` переключён с `switch(action.key)` на `switch(action.actionType)` — navigate → triggerDrawer; `DealsPage/index.vue` + `ContactsPage/index.vue` — `watch(() => uiTriggers.pendingDrawer, { immediate: true })` → open + clearDrawer, cleanup в `onUnmounted`. (2) **`useMutation` тип** — `QuickActionsPickerDialog.vue`: `useMutation<void>()` → `useMutation<MeResponse>()`; `return response` добавлен в `save()` (TS-корректность `onSuccess(result)`). (3) **deprecated `isDarkMode`** — `toggleDarkMode()` + `setDarkMode()` удалены из `layoutStore`; `isDarkMode` ref сохранён только для boot-миграции в `main.ts`; `ProfilePage/index.vue` — вызовы `layoutStore.setDarkMode()` → `themeStore.setTheme()`; `HrStatusPieChart.vue` + `useMacroCrmEchartsTheme.ts` — `layoutStore.isDarkMode` → `themeStore.theme === 'dark'`. (4) **vite manualChunks** — уточнены path-паттерны (`/primevue/` вместо `primevue`); добавлены отдельные чанки `echarts` (echarts/vue-echarts/zrender), `vue-flow` (@vue-flow/*, d3-*, dagre), `vendor-markdown` (marked); `@vue/` + `@vueuse/` добавлены в `vue-core`.
- [ ] Preferences (юзер×kind×канал), шаблоны, broadcast.
- [ ] Тесты: создание активности/задачи, dispatch уведомлений по preferences (мок каналов).

**Acceptance M6:** активность линкуется к сделке и видна в таймлайне; задача с дедлайном назначается; уведомление приходит в in-app + email + TG по preferences пользователя. CI зелёный.

---

### M7. Автоматизации + Sequences + Bulk (2-3 недели)

**Ведущие:** `automation-specialist` + `sales-specialist` (DealStageChanged event) + `frontend-specialist`. Контекст `Automation`.

> **Планирование ЗАВЕРШЕНО (2026-06-15, PM-verify PASS).** Два детальных плана согласованы и зафиксированы в vault:
> - UI-ТЗ: `6. Справочник/ТЗ — Конструктор воронки и автоматизации (UI).md`
> - Backend plan: `5. Планы/Автоматизации (движок M7) — backend plan.md`

**MVP-скоуп (Фаза 0 → Фаза 1 → Фаза 2):**
- [x] **P0** (automation, 1–1.5д): модели+миграции (pipeline_automations, automation_runs + partial-UNIQUE идемпотентность) + enums (TriggerKind/ActionKind/RunStatus) + AutomationEngine (resolveFor/claimRunSlot/finalize). *(`d2f8c0c`, 2026-06-15)*
- [x] **P1** (automation, 2–2.5д): ActionDispatcher + 8 action-handlers (tg_notify/create_task/set_field/generate_document/change_owner/change_stage/webhook/email) — реюз TelegramNotifier/ActivityService/DealMoveService/DocumentService. *(`d2f8c0c`)*
- [x] **P2** (automation + sales-specialist, 1.5–2д): inline-триггеры (on_create → DealCreated уже есть; on_enter_stage → НОВЫЙ DealStageChanged из sales-specialist `bafbca0`) + cron (ScanIdleInStage/ScanDateField + ExecuteAutomationActionJob tries=1/ShouldBeUnique + Schedule hourly). *(`d2f8c0c`)*
- [x] **P3** (automation, 1д): AutomationTestService dry-run (без side-effects) + фильтры/scopes AutomationRun журнала. *(`d2f8c0c`)*
- [x] **P4** (automation + backend-specialist, 2д): HTTP-слой (AutomationController/AutomationRunController + FormRequests с discriminated match-валидацией + ручные Resources + routes) + PipelineAutomationPolicy (ability automation.manage `47eed95`) + тесты PHPUnit/SQLite. **Backend M7 DoD: 1531 PASS / 3584 assertions, Pint clean.** *(`d2f8c0c`, 2026-06-15)*
- [x] **Фаза 1 Frontend** (frontend-specialist, 4–6д): StageEditorItem аккордеон + AutomationInlineCard + AutomationWizardDialog (Dialog+Stepper 3 шага, 8 per-action конфигов) + AutomationListPanel (DataTable) + AutomationRunsPage + DryRunDrawer. Чистый PrimeVue, без @vue-flow. *vue-tsc/ESLint 0 ошибок, build clean.*
- [x] **Фаза 1 Backend-fix + Frontend-fix** (backend-specialist + frontend-specialist, 1д): POST /api/automations/{id}/execute (manual real-run) — ExecuteNowResult, AutomationTestService::executeNow() реюзает resolveMatches() + ActionDispatcher::dispatch(); тригger_event_ts deterministic; ExecuteAutomationRequest 422 для inline без target_id; idempotent dedup. Frontend: wizard configs usersApi/templatesApi вместо голого apiClient; date_field/idle :min=1 validate < 1; automationsApi.execute(); ru.json resultToast {executed,skipped}; loadMore пагинация по странице. **Suite: 1542 PASS / 3631 assertions, Pint clean; vue-tsc/ESLint 0 новых ошибок.** *(`c261af7` execute + `c1b4987` front, 2026-06-16)*
- [x] **Тех-долг M7** (automation-specialist + deploy-engineer, 2026-06-16): (1) User::find cross-domain — оставлен как конвенция проекта (используется во всех доменах: Sales/Activity/Inbox/Notification; отдельный resolver = расхождение с паттерном); (2) automation_runs retention — `AutomationRunRetentionService::prune(int $days)` + команда `automation:prune-runs {--days=}` (config `automation.retention_days`=90, clamp ≥1) + Schedule::dailyAt('03:00')->withoutOverlapping() в routes/console.php; (3) Infra — queue:work теперь `--queue=default,automation` (дефолт первым), новый сервис `scheduler` (schedule:work, profile=worker в dev, всегда в prod) в обоих docker-compose, rolling-restart.sh + GHA deploy.yml обновлены. **Suite: 1545 PASS / 3641 assertions, Pint 917 файлов clean. (2026-06-16)**
- [x] **Фаза 2 NODE-полотно** (@vue-flow/core — аппрувнут юзером): ПОСЛЕ приёмки Фазы 1; стадии-ноды + действия-ноды + рёбра-триггеры + graph_layout JSONB на Pipeline.
  - [x] **2A** — scaffold + viewMode ref + SelectButton + PipelineCanvas stub + @vue-flow/* npm install. vue-tsc/ESLint 0 errors.
  - [x] **2B** — migration `graph_layout json nullable` + Pipeline fillable/cast + UpdatePipelineRequest (soft validation) + PipelineResource (as-is, no `?? []`) + 4 PHPUnit tests. Suite 56 Pipeline tests PASS, Pint clean. *(`3e6db25` lineage)*
  - [x] **2C** — AnchorNode/StageNode/AutomationNode custom nodes + usePipelineCanvas.ts (nodes[]+edges[] mapping) + useGraphLayout.ts (no dagre, pure tree algorithm) + GraphLayout/GraphLayoutNodes types in entities/sales.ts + graph_layout wired from PipelineDto. vue-tsc/ESLint 0 errors.
  - [x] **2D+2E** — ToolPalette (8 draggable tools, 56px/180px collapse) + onDrop→stage detect→AutomationWizardDialog(create+prefill) + ToggleSwitch on AutomationNode→toggleActive + delete flow + onNodeDragStop debounce 400ms PATCH + autoLayout button + all states (Skeleton/Spinner/Error/empty-no-stages/empty-no-autos) + desktop-only guard 1100px + dark mode + i18n 33+ keys both locales. vue-tsc/ESLint 0 errors.
  - [x] **QA-фиксы канваса** (`811b555`): высота канваса (корень — DefaultLayout не был flex → контейнер схлопывался до 376px; флекс-цепочка + min-height 560) + авто-fitView на `@nodes-initialized` (double-rAF, id-привязка `pipeline-canvas`).
  - [x] **Браузерный QA PASS**: ядро полотна (CRUD POST201/PATCH200/DELETE204, автосейв позиций+персист, drag-drop палитры, wizard-реюз, console 0) + регрессия глобального layout по 6 страницам — чисто. Беклог-полировка: Controls/MiniMap на коротком вьюпорте за сгибом (на ≥900px ок); мёртвый ключ stageDeals; 3 хардкод-лейбла. **M7 ЗАКРЫТ ЦЕЛИКОМ.**

**Вне MVP (поздняя фаза):** Sequences/SequenceRun/BulkTask; is_sla/escalation_chain; PipelineTransition (межворонничные); field_value_changed/activity_completed триггеры; start_sequence/set_tags действия; by_product/by_country/by_department для change_owner.

**Cross-agent блокеры** (все закрыты для Фазы 1):
- ~~sales-specialist: `Sales\Events\DealStageChanged`~~ — закрыт (`bafbca0`)
- ~~contract-specialist: публичный метод генерации по template_code~~ — закрыт (`7efc402`)
- ~~backend-specialist: ability `automation.manage`~~ — закрыт (`47eed95`)
- ~~deploy-engineer: очередь `automation` в queue:work + scheduler hourly~~ — закрыт (2026-06-16)

**Acceptance M7 (Фаза 1):** правило «вход в стадию → создать задачу + TG-уведомление» срабатывает и пишется в audit-runs; dry-run показывает matched records; журнал runs отображается с фильтрами; конструктор в PipelineSettingsPage работает без поломки drag-reorder. CI зелёный.

---

### M8. Customer Success (1-2 недели)

**Ведущие:** `cs-specialist` + `analytics-specialist`. Контекст `CustomerSuccess`.

- [ ] ClientSubscription (статусы B0–B6/A1–A6/C0), health-tier (green/yellow/red).
- [ ] Модули + прогресс внедрения, чек-листы онбординга.
- [ ] ActivitySnapshot + KPI-снапшоты (cron-пересчёт).
- [ ] CS-аналитика, Excel-экспорт, bulk-импорт реестра.
- [ ] Тесты: пересчёт health-tier, прогресс модулей, импорт реестра.

**Acceptance M8:** подписка клиента отображается с health-tier; чек-лист онбординга трекает прогресс; KPI-снапшот пересчитывается по cron; реестр импортируется bulk'ом; Excel-экспорт работает. CI зелёный.

---

### 🔴 M9. Финмодуль Ф0–Ф6 (4-6 недель — самый объёмный шаг)

**Ведущий:** `finance-specialist` (+ `analytics-specialist` для отчётов). Контекст `Finance`.

> ⚠️ **Самый объёмный milestone.** Отдельный крупный подпроект — **возможно собственный суб-план** (`PLAN-finance.md`) с разбивкой на M9.1–M9.6 по фазам Ф0–Ф6 old. Делать строго вертикальными под-срезами, каждый со своим CI.

- [ ] Юрлица, GL-счета (план счетов), расчётные счета/кассы.
- [ ] Операции (приход/расход/перевод), проводки (двойная запись), главная книга.
- [ ] Реестры платежей, заявки + сценарии согласования.
- [ ] Счета-фактуры, акты, счета поставщиков.
- [ ] Признание выручки (accrual), НДС (ставки, отчёт), мультивалютность (FxRate).
- [ ] Закрытие периодов, per-entity права (FinPermission).
- [ ] Отчёты: P&L, Trial Balance, AR/AP Aging, GL, VAT, Debt, Recognition (ECharts + Excel).
- [ ] Деньги — целочисленно (копейки); НДС РФ 20% (старые 0/10/18 — историчны).
- [ ] Тесты: баланс проводок (дебет=кредит), расчёт НДС, отчёты на эталонных данных. **Возможен отдельный PG-профиль** для финтестов (вместо SQLite), если нужны точные numeric/FTS.

**Acceptance M9:** операция создаёт сбалансированные проводки; главная книга и Trial Balance сходятся; счёт/акт генерятся; НДС-отчёт корректен; закрытие периода блокирует правки; per-entity права ограничивают доступ; P&L строится в ECharts. CI зелёный.

---

### M10. Аналитика / Дашборды / Excel (1-2 недели)

**Ведущие:** `analytics-specialist` + `frontend-specialist`. Контекст `Analytics`.

- [ ] Воронки сделок (конверсии, длительность стадий).
- [ ] KPI-дашборд (личные цели, прогноз).
- [ ] Когортный анализ (retention/churn).
- [ ] Кастом-дашборды (drag-drop виджеты).
- [ ] commission-rules, salary-plans, team-targets.
- [ ] **Графики на ECharts.** Excel-экспорт.
- [ ] Тесты: расчёт конверсий/когорт, агрегаты KPI.

**Acceptance M10:** воронка показывает конверсии и длительность стадий в ECharts; KPI-дашборд считает прогресс к цели; когортный отчёт строится; кастом-дашборд собирается drag-drop'ом; Excel-экспорт работает. CI зелёный.

---

### M11. Интеграции + Telegram-бот (2-3 недели)

**Ведущие:** `integration-specialist` + `bot-specialist`. Контекст `Integration`.

- [ ] Google OAuth (логин/Calendar/Drive), 2-way GCal-sync (cron-job), Drive (загрузка договоров).
- [ ] API-токены (rate-limit через Redis token-bucket).
- [ ] Webhooks (события, HMAC-подпись, delivery-log + retry).
- [ ] OAuth2-провайдер (для сторонних), SSO (OIDC, auto-create).
- [ ] **Telegram-бот** (замена aiogram на PHP-бот): согласования, NL-команды, линковка аккаунта.
- [ ] Тесты: GCal-sync (мок Google), webhook HMAC + retry, rate-limit, бот-команды.

**Acceptance M11:** вход через Google работает; событие из CRM улетает в GCal и обратно; webhook доставляется с подписью и ретраит при сбое; API-токен лимитируется; Telegram-бот аппрувит согласование и линкует аккаунт. CI зелёный.

---

### MSP. SalesPulse — надзорный бот продаж (PHP-порт amo-assistant-bot, путь B)

**Статус:** Slice 3 DONE (2026-06-19). Build complete. Cutover-ready (ждёт живой токен + TEAMS_JSON маппинг). Slice 5 (деплой) — по явной просьбе.
**Ведущие:** `backend-specialist` (схема БД) + `sales-specialist` (движок классификации) + `bot-specialist` (Slice 3-4: nutgram-бот + планировщик). Контекст `SalesPulse`.
**Спека:** vault `MG CRM 2026/6. Справочник/AMO-бот — спека бизнес-логики (порт на PHP).md`.
**План по слайсам:** vault `MG CRM 2026/5. Планы/AMO-бот → MGCRM — план миграции.md` §8.

> **Ключевые решения (2026-06-19):**
> - Путь B: in-process порт, читает БД напрямую через доменные сервисы. API-токен сервис-юзера НЕ нужен.
> - **КАНОН (переключён):** AMO-воронки «MACRO Global» (sort_order=0) и «MACRO AI Global» (sort_order=1) — PRIMARY sales pipelines. «Продажи» REVERSIBLY ARCHIVED (`is_active=false, sort_order=2`, 11 стадий нетронуты). `DealService::defaultSalesPipelineId()` + `SalesDashboardService::resolvePipeline()` + `PipelineService::defaultSalesPipeline()` — все фильтруют `is_active=true`, дефолт = MACRO Global.
> - **Фронт-импликация (открытый пункт):** Kanban board теперь по умолчанию показывает 14-стадийную воронку MACRO Global вместо 11-стадийной «Продажи». Ширина колонок и UI board-переключателя могут нуждаться в адаптации. Реализуется отдельно по явной просьбе (`frontend-specialist`).
> - **Демо-данные:** `SalesPulseDemoSeeder` (SAMPLE — в reset-clean НЕ входит) — today-anchored датасет в обеих AMO-воронках под manager1/2/3@mgcrm.test. collectDay возвращает непустые plan+fact; MetricsService даёт ненулевые метрики. Идемпотентен.
> - «Success» = переход сделки в `is_won`-стадию (`DealStageHistory`), не активность — announcer читает ДВА источника.
> - Снапшоты: PLAN write-once, FACT upsert. БД пересоздаётся при cutover (данные регенерируемые).
> - Маппинг менеджеров (DECISION-2) и токен нового TG-бота — гейтящие входы к Slice 3, не к Slice 0-1.

#### Slice 0 — Фундамент (backend-specialist + sales-specialist) ✅ DONE 2026-06-22
- [x] 4 миграции: `pulse_snapshots`, `pulse_daily_status`, `pulse_skip_days`, `pulse_announced_events`. FK: `users.id` (cascadeOnDelete), `deals.id` (nullOnDelete), `activities.id` (cascadeOnDelete). Unique-констрейнты: `(manager_id, on_date, kind)` на snapshots; `(manager_id, on_date)` на daily_status; `activity_id` unique на announced_events (де-дуп анонсёра).
- [x] 4 модели: `PulseSnapshot`, `PulseDailyStatus`, `PulseSkipDay`, `PulseAnnouncedEvent`. Все `$fillable` + typed `casts()` + relations.
- [x] 3 enum: `SnapKind` (plan/fact), `SnapSource` (manual/auto), `AnnouncedEventType` (meeting_done/success).
- [x] `StageClassificationService` — движок классификации воронки: `funnelPosition`, `isForwardMove`, `isFunnelDowngrade`, `isStageJump`, `statusSortKey`, `isCold`. Без хардкода id — на `PipelineStage.sort_order` + флагах `is_won`/`is_lost` + code. Мемоизация realRankCache per pipeline_id. Pipeline-агностичен (работает для «Продажи» и AI Global).
- [x] `Data/PulseTaskRow` — DTO снапшота, `toArray()`/`fromArray()` round-trip, `isRealWork()`/`kindIsRealWork()` через `ActivityType::taskLikeValues()` (library-first), `isClosedToday()`.
- [x] `Data/StageMeta` — VO эмодзи+SLA per stage.code, `forCode()`/`forStage()`/`label()`.
- [x] `config/salespulse.php` — cold_stage_codes + карта stages по code (emoji/sla_days/sla_weekly) + stage_default.
- [x] Тесты: `Feature/SalesPulse/SalesPulseSchemaTest` (6 passed) + `Unit/SalesPulse/StageClassificationServiceTest` (26 passed) = **32 теста зелёных**. Pint clean.

#### Slice 1 — Снапшот + метрики (sales-specialist) ✅ DONE 2026-06-22
- [x] `DaySnapshotService` (порт `collect_day`): читает activities+deals напрямую через Eloquent, день-окно Asia/Dubai без +4ч AMO-хака, фильтр `responsible_id`+`kind`∈realWork+`target_type=deal`, pipeline-фильтр на стороне Eloquent.
- [x] `HistoryService` — carryover_days/days_in_stage по 60 дней PLAN-снапшотов (newest-first), обе формулы §1.4 verbatim.
- [x] `MetricsService` — 6 метрик §1.2 дословно; мутуально-эксклюзивный каскад lost→forward→downgrade.
- [x] `NotesService` — детект заметок через activity kind=note; `SnapshotRepository` — load/save с PLAN write-once guard.
- [x] `DayWindowResolver` — окно [00:00:00, 23:59:59.999] Asia/Dubai без хардкода +4ч.
- [x] `Data/DaySnapshot` — DTO с `toArray()`/`fromArray()`, `leads_by_id` БЕЗ `status_name` (спека §2 verbatim).
- [x] Тесты: 75 Unit-тестов паритета формул. Сьют 1728+ зелёных.

#### Slice 2 — Отчёты (sales-specialist) ✅ DONE 2026-06-22
- [x] `PlanRenderer` — plain-text рендер /startday: `📋 План на {ISO} — {name}`, ♻️ carryover, сортировка по statusSortKey (spec §7).
- [x] `FactRenderer` — HTML рендер /finishday: секции done/not_done_note/not_done_bare/extra + `PulseMetrics::render()` verbatim (spec §7).
- [x] `ProgressRenderer` — HTML рендер /progress с 5 вариантами строки (vacation/skip/no-plan/zero/live, spec §6.1).
- [x] `ConversionsRenderer` — рендер /conversions: gates/сквозная/losses/velocity + маркеры «узкое место»/«залипают» (spec §6.2).
- [x] `DayResultsService` — SYSTEM_PROMPT §5.1 verbatim; payload `closed_today_tasks`/`plan_pending_tasks` с hint-классификацией FLAG/WIN; LLM→Haiku (`quick_qa`); оффлайн-фоллбек verbatim.
- [x] `WeeklyReportService` + `WeeklyAggregationService` — SYSTEM_PROMPT §5.2 verbatim; forced tool_use `weekly_analysis`; top_movements/top_stuck (сорт по (-delta,company)/(-days,company), топ-5, jump=delta≥2); SLA-пороги §5.2; оффлайн-фоллбек.
- [x] `ConversionsService` — gates GATES(1,2)..(N-1,N), сквозная воронка, losses rollback, velocity avg/slow (avg≥3); parsePeriod: 0/N/ISO/два-ISO варианта.
- [x] `PrismPulseLlmClient` — реализует `PulseLlmClient`; реиспользует `AiRetryService` (library-first); `completeText` (dayresults/Haiku), `completeWithTool` (weekly/Sonnet, forced tool_choice); `weeklyAnalysisSchema()` Prism-объекты.
- [x] `FakePulseLlmClient` — стаб для тестов, без сети. `RuPlural` — плюрализация RU.
- [x] `ProgressService` — live-пересчёт /progress: done/postponed/in_progress/notes_count из актуального состояния Activity (не снапшота); логика skip/no-plan/zero/live (spec §6.1).
- [x] Тесты: 108 новых Unit-тестов. Полный сьют 1803 зелёных.

#### Slice 3 — Telegram-бот (bot-specialist) — гейт: токен TG-бота от юзера ✅ DONE
- [x] Второй nutgram-бот (`SalesPulseBotFactory` + `SalesPulseBot::register()`, `salespulse:run` artisan, гейт `SALESPULSE_RUN_POLLING`; FakeNutgram-fallback при пустом токене).
- [x] Хендлеры всех команд (/startday, /finishday, /progress, /dayresults, /weeklyreport, /conversions, /skipday, /unskipday, /vacation, /unvacation, /whoami, /help, /start, /announce_now-stub).
- [x] Team-config (`SALESPULSE_TEAMS_JSON` / `config/salespulse.php`; `TeamResolver` — резолв chatId→team, slug→manager, isAdmin, parseArgs §8).
- [x] Резолв caller→team→manager + admin-gating (`AdminGate` trait; `CommandContextResolver`). `SalesPulseNotifier` на 2-м токене (без поллинга).
- [x] `SkipService` (порт skips.py: skip/vacation/unvacation/isTeamSkipped/isManagerSkipped/vacationUntil/isReturningFromVacation); новая миграция `add_kind_to_pulse_skip_days` (kind/vacation_until поверх Slice-0 таблицы).
- [x] Compose-сервис `salespulse-bot` (replicas:1, отдельно от web/queue rolling-restart) в dev и prod compose.
- [x] 27 новых тестов на FakeNutgram. Полный сьют 1834 passed.
- [x] **Private-chat TEST MODE** (`config/salespulse.php` секция `test_mode`): `SALESPULSE_TEST_MODE=false` (off в проде), `SALESPULSE_TEST_ADMINS` (csv). `TestModeResolver` + ветвление в `CommandContextResolver::resolveTestTeam()` — вторая, изолированная ветка резолва только для private-chat от admin. `CommandContext.isTestMode` (default false). `/start` + `/whoami` в DM шлют тест-онбординг. Маппинг pipeline по именам + fallback `all_active_sales`. Email→user_id резолв; отсутствующий аккаунт → пропуск + Log::info. 10 тестов `SalesPulseTestModeTest` + 8 тестов `TestModeResolverTest` = +18. Сьют **209 SalesPulse-тестов зелёных**.
- [ ] QA (ждёт живого токена + TEAMS_JSON маппинга от юзера).
- [x] `AmoPipelineSeeder` в BASELINE (после `PipelineSeeder`) — архивирует «Продажи», засевает «MACRO Global» + «MACRO AI Global». Идемпотентен. `DemoDealsSeeder` и `ManagerKpiSeeder` перенаправлены на MACRO Global.
- [x] `SalesPulseDemoSeeder` в SAMPLE — today-anchored датасет в обеих AMO-воронках под demo-менеджерами. `SalesPulseDemoSeederTest` (5 тестов): collectDay непустой plan+fact; MetricsService ненулевой; идемпотентность; no-op при отсутствии воронок. Полный сьют **1886 passed**, Pint clean.
- [x] Тесты канона: `AmoPipelineSeederTest` обновлён — `DealService::defaultSalesPipelineId()` возвращает MACRO Global при наличии обеих воронок.
- **Открытый вопрос к cutover:** получить живой `SALESPULSE_BOT_TOKEN` + MGCRM user_id 5 менеджеров (Рогов/Моисеева/Шомина/Некрасов/Федорин) + chat_id обеих команд для `SALESPULSE_TEAMS_JSON`.
- **Для обкатки test mode:** `SALESPULSE_TEST_MODE=true` + `SALESPULSE_BOT_TOKEN` (тестовый бот) + поднять `salespulse-bot` polling + `SalesPulseDemoSeeder` + DM `/start` от `@Bogdan_MACRO`.

#### Slice 4 — Планировщик + announcer (bot-specialist) ✅ DONE 2026-06-19
- [x] Laravel scheduler Asia/Dubai: точные cron-окна §3 (09:30/10:00/10:15/13:00/16:00/19:00/19:30/19:45 пн-пт + 08:30 вт-пт + 20:00 пт + 09:00 пн + */5 ч.9-20). `->between('09:00','20:59')` на announcer.
- [x] Jobs: `RemindPlanJob` / `AutoCapturePlanJob` / `PostProgressJob` / `RemindFactJob` / `AutoCaptureFactJob` / `PostDayResultsJob(forToday)` / `PostWeeklyReportJob` / `RunAnnouncerJob`. Все ShouldQueue, `tries=1`, weekday+guard в каждой.
- [x] Announcer: `AnnouncerService::runAll()` — FTM-встреча (activity.is_first_time_meeting+completed_at в окне 15 мин) + переход в `is_won` (`DealStageHistory.created_at` в окне). Insert-first дедуп, ловит unique-violation.
- [x] Дедуп для Success: миграция `relax_pulse_announced_events_for_success` — `activity_id`→nullable + `deal_stage_history_id` FK nullable, два plain UNIQUE (NULL-distinct на SQLite+Postgres). Модель обновлена.
- [x] Пропуск: `is_team_skipped` / `is_manager_skipped` в каждой Job; announcer проверяет через `SkipService::isManagerSkipped()`.
- [x] Тесты: `AnnouncerServiceTest` (11) + `SchedulerJobsTest` (13) + `ScheduleRegistrationTest` (8) = +32. Итого 183 SalesPulse-теста, 1878 passed, Pint clean.
- [x] Acceptance Slice 4: scheduler зарегистрирован с точными cron-выражениями + Asia/Dubai; announcer детектирует MeetingDone и Success; дедуп переживает рестарт; формат сообщений §4 verbatim.

#### Slice 5 — Деплой/cutover (deploy-engineer — по явной просьбе)
- [ ] Контейнер `salespulse-bot` (replicas:1, mem/cpu лимиты) + env + rolling-restart отдельно.
- [ ] Гасим Python amo-bot; поднимаем salespulse-bot. БД с нуля.

**Acceptance MSP:** `/startday` фиксирует план (write-once); `/finishday` постит 6 метрик verbatim; `/dayresults` генерирует LLM-нарратив Haiku; планировщик тригерит по расписанию Asia/Dubai; announcer дедуп-постит meeting_done и success; `/skipday` блокирует команду; тесты Unit+Feature зелёные; CI зелёный.

---

### 🧹 M12. Cutover (1 неделя)

**Ведущие:** `migration-specialist` (cutover) + `product-manager` (финальный паритет).

> **Перенос данных НЕ нужен** (в старой базе только тестовые данные — будут залиты новые). `migration-specialist` сосредоточен на cutover + per-domain parity-чеклисты. Если в будущем понадобится перенос production-данных — отдельная задача вне этого milestone.

- [ ] Финальная сверка паритета фич с `./examples/contracts/` (`product-manager`): per-domain parity-чеклисты (все доменные агенты).
- [ ] Финальная полировка UI, i18n-аудит (RU полный, EN-задел).
- [ ] **Cutover:** снести **только `./examples/`** (`vizion/` + `contracts/`) из репозитория — **проект уже в корне** (`src/`+`front/`), переезд НЕ нужен (до этого момента `examples/` — рабочий контекст). Обновить пути в CLAUDE.md/PLAN.md/ARCHITECTURE.md.
- [ ] Активировать прод-деплой (`deploy-engineer`, по явной просьбе).

**Acceptance M12:** паритет фич подтверждён per-domain чек-листами; **`examples/` (`vizion/`+`contracts/`) удалён — проект уже в корне репо, переезд не нужен**; прод-деплой проходит. CI зелёный.

---

## §6. Глобальный Acceptance (паритет MVP)

Чек-лист «миграция готова» (сводит ключевые пункты milestone'ов):

- [ ] Каркас поднимается одной командой; **логин с 2FA работает**; 6 ролей; CI зелёный (M0)
- [ ] CRUD сотрудников с передачей прав + дерево отделов + **visibility-scope** ограничивает выдачу (M1)
- [ ] CRUD контактов/компаний с ИНН/КПП + кастом-поля + **дедуп/merge** (M2)
- [x] **Kanban сделок drag-drop** меняет стадию + line-items продуктов + lost-reasons (S1.3, 2026-06-12)
- [x] **Дашборд продаж** — 5 виджетов ECharts (статусы/воронка/топ-бар/прогноз/сделки без задач), фильтры период/воронка/менеджер, Excel-экспорт (S1.7, 2026-06-12)
- [ ] Входящее сообщение/форма → **лид** → конвертация в сделку (M4)
- [ ] **Договор генерится PHPWord→Gotenberg→PDF** + маршрут согласования + статус-машина (M5)
- [ ] Активность в таймлайне + задача с дедлайном + **уведомление in-app/email/TG** по preferences (M6)
- [ ] Правило-автоматизация срабатывает (триггер→действие) + sequence + **bulk с прогрессом** (M7)
- [ ] Подписка клиента с **health-tier** + чек-лист онбординга + KPI-снапшот по cron (M8)
- [ ] **Финмодуль:** сбалансированные проводки, Trial Balance сходится, счёт/акт, **НДС-отчёт**, закрытие периода, P&L в ECharts (M9)
- [ ] **Воронка/KPI/когорты в ECharts** + кастом-дашборд + Excel-экспорт (M10)
- [ ] Google OAuth + **GCal 2-way-sync** + webhook с HMAC+retry + **Telegram-бот** аппрувит (M11)
- [ ] **Паритет фич подтверждён** per-domain чек-листами + **cutover выполнен** (`examples/` снесён; проект уже в корне репо) (M12)
- [ ] CI зелёный на каждом milestone (PHPUnit + vue-tsc + build + Pint/ESLint)
- [ ] qa-tester smoke PASS на ключевых экранах
- [ ] Нет пакетов вне §3 без явной просьбы

---

## §7. Конвенции (кратко; эталон — Vizion, `./examples/vizion/`)

### Backend
- PHP 8.5: `declare(strict_types=1)`, constructor promotion, readonly, enums, match.
- `env()` только в `config/`; проектные значения — `config/crm.php`.
- Eloquent: `$fillable`/`$hidden` как свойства, касты через `casts()`. Translatable → jsonb.
- Сервисы в `app/Domain/<Context>/Services/`, constructor injection.
- API: префикс `/api`, `auth:sanctum`; **ручные API Resources** (НЕ spatie/data), как Vizion. Валидация — FormRequest.
- Миграции обратимые, FK `->constrained()->cascadeOnDelete()`, деньги — целые (копейки), индексы на горячих путях.
- Деньги/НДС: целочисленно; НДС РФ 20% (старые ставки 0/10/18 — историчны).

### Frontend
- `<script setup lang="ts">`, Composition API.
- Данные — `useAsyncResource`/`useMutation` (НЕ голый fetch). Стейт — Pinia.
- UI — PrimeVue; сетка — bootstrap-grid; **никаких utility-классов Tailwind**.
- Per-page composables (`pages/X/composables/useXPage.ts`).
- Графики — ECharts. i18n — vue-i18n, ключи RU+EN.

### Тесты
- PHPUnit, SQLite :memory: (guard в TestCase). Feature-тест на каждый endpoint, Unit — на сервисы. AI/HTTP — мокать (`Prism::fake`, `Http::fake`). Финмодуль/FTS — отдельный PG-профиль при необходимости.

---

## §8. CI/CD и Docker
- GitHub Actions: backend (sqlite-тесты `force` + pgsql migrate-smoke на `postgres:16-alpine` + **Pint блокирующий**) + frontend (vue-tsc + **eslint блокирующий** + build), раздельные jobs, PHP **8.5**. (У Vizion: Pint-шага нет, lint `continue-on-error`, PHP 8.3 — мы осознанно строже.)
- Docker Compose: postgres(PG16)+app(php-fpm)+nginx+frontend+gotenberg+queue-worker (6 как у Vizion) + **redis (NET-NEW)**. Без Horizon.
- Деплой — rolling-restart по образцу `./examples/contracts/`/Vizion, активируется `deploy-engineer` по явной просьбе (на M12 cutover).

---

## §9. Definition of Done (на любой milestone)
1. Pint + ESLint/Prettier зелёные. 2. `vue-tsc` без ошибок. 3. PHPUnit зелёный (критпуть покрыт). 4. qa-tester smoke PASS (если был UI). 5. `product-manager` сверил паритет фич с `./examples/contracts/` и обновил PLAN.md. 6. Нет пакетов вне §3 без явной просьбы.

---

## §10. Риски
- **Объём.** 140 таблиц — реалистично только поэтапно; не обещать сроки целиком.
- **Финмодуль (M9)** — отдельный крупный подпроект (4-6 недель), может потребовать вынести в свой суб-план (M9.1–M9.6).
- **Gotenberg** — внешний сервис; PHPWord-рендер сложных docx требует проверки на реальных шаблонах old.
- **Telegram-бот** — на PHP вместо aiogram; long-polling в одном процессе (нельзя масштабировать — как в old).
- **Перенос данных** — не нужен (тестовые данные; заливаем новые). Если в будущем понадобится перенос production-данных — отдельная задача `migration-specialist` вне основного плана.
- **Tailwind→Bootstrap+SCSS** — дизайн old не переносится 1-в-1, designer пересобирает на SCSS-токенах.
- **Cutover (M12)** — снос `./examples/` (`vizion/`+`contracts/`) необратим; делать только после подтверждённого паритета + бэкапа.
