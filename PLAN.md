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

#### Навигация — Срез 4 (frontend-specialist, 2026-06-16) — PM CODE REVIEW (uncommitted, pending QA)
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
- **PM-статус:** CODE REVIEW PASS. Pending браузерный QA (qa-tester).

#### Навигация — Срез 5: Quick-actions синк в профиль (#10) — QA PASS → pending commit (2026-06-16)
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
- [x] **Kanban-редизайн Сделок — Срез 1 (карточка-гибрид) + Срез 2 (тулбар/хром страницы).** `DealsKanbanCard.vue` — полная переработка: health-сигнал (кант + нижняя плашка ok/no-task/overdue), rotting-счётчик (warn/rotting по warn_days/danger_days из stage), аватар менеджера, чип primary_product, bulk-чекбокс. `DealsKanbanColumn.vue` — заливка шапки из `stage.color` (10 вариантов: 5 bright/5 soft), мульти-валютный Popover, кнопка «+» в шапке. `DealsToolbar.vue` (NEW) — amo-style одна строка (поиск+фильтр / сводка / 3 вида / ⋯ меню / + Новая). `DealsFilterOverlay.vue` (NEW) — фиксированный оверлей с backdrop, 3 блока (пресеты ToggleButton / свойства / теги). `DealsBulkToolbar.vue` (NEW) — 6 действий + счётчик + отмена. `DealsTaskBoard.vue` (NEW) + `TaskCard.vue` (NEW) + `useTaskBoard.ts` (NEW) — персональный канбан задач, вид 3. `StageColorPicker.vue` (NEW) — палитра 10 кружков. `salesStore` — DealsView 'kanban'|'list'|'tasks' + BoardSort + bulkMode + localStorage persist. `entities/sales.ts` — NextTaskDto / PrimaryProductDto / BoardColumnDto multi-currency. `useDealsFilters.ts` — расширен на overlay-фильтры. `_colors.scss` — health + stage + task-tag цветовые токены. i18n: sales.deals.page.{toolbar,menu,bulk,card,taskTypes,currency,columns} + tasks.board.* (RU+EN). PM APPROVE, pending QA. 2026-06-16.*
- [x] **Тех-долг Сделки-борд (bulk-операции + select-all + preselect + FxRate-fallback) — backend-specialist + frontend-specialist, 2026-06-17.** Backend: `BulkDealService` (all-or-nothing 403, каждая сделка авторизуется по `update`/`delete` ability, DB::transaction, делегация в DealService::update/DealMoveService::move/DealService::delete), `BulkDealActionRequest` (4 операции change_owner/change_stage/set_field/edit_tags, whitelist `DIRECT_SET_FIELDS`, currency-whitelist в withValidator), `BulkDealDeleteRequest`, `DealExportService` (filteredQuery + chunkById 500, visibility-scope, `Amount (kopecks)` raw-колонка, scope-тест). Маршруты: `PATCH /api/deals/bulk` + `DELETE /api/deals/bulk` (перед `deals/{deal}` чтобы 'bulk' не биндился как id). 8 тестов `DealBulkTest` + 3 теста `DealExportTest`. Frontend: `DealsBulkToolbar.vue` (select-all Checkbox + indeterminate + selectAll/clearSelection emit), `allVisibleDealIds` computed (kanban = localColumns.flatMap, list = deals, tasks = []); bulk-диалоги 5 шт. (BulkAssignDialog/BulkMoveStageDialog/BulkEditFieldDialog/BulkTagDialog/BulkAddTaskDialog) через `salesApi.bulkPatchDeals`/`bulkDeleteDeals`; preselect via `salesStore.bulkSelection` из Pinia. Беклог (не блокер): (1) ❗ поле `rate_available` (бэк) ↔ `fx_rate_available` (фронт-адаптер) — несовпадение ключа; `?? true` в адаптере маскирует; ≈-префикс всегда показывается, даже когда курс недоступен — требует фикса в `BoardRawColumnDto`; (2) `DealsListView.vue::rottingClass` использует захардкоженные 7/14 дней вместо `stage.warn_days/danger_days` (list-view не получает PipelineStageDto с порогами); (3) preselect через URL-query (cross-page) — не реализован (текущий preselect — только через Pinia в рамках страницы).
- [x] **DealPage 2.0 v2 — детальная карточка сделки, корректировки (frontend uncommitted, pending QA).** `DealInfoHeader.vue` — `$brand-header-bg` токен вместо `#172747`; убран подзаголовок `#ID · Компания` (оставлен `#ID` в top-row); стадия кликабельна (кнопка-обёртка + `pi-chevron-down` + tooltip); убрана отдельная кнопка «Изменить стадию»; меню ⋮ новый состав (убраны changeOwner/moveStage/duplicate/archive; добавлены collapseAll/expandAll/customizeFields/copyLink); `DealHealthChip.vue` (NEW) — чип next_task (ok/overdue/no-task). `DealTabMain.vue` — prop `daysInStage`; строка «Дней в работе» с rotting-цветами (warn/danger через `stage.warn_days/danger_days`); чип основного продукта у бюджета; Продукты и Контакты — акцентный хедер (accent=true, count, totalLabel); Даты/Компания/Кастом — `defaultCollapsed: true`; watch `collapseAllSignal`/`expandAllSignal`. `DealFieldGroup.vue` — props `accent`, `count`, `totalLabel`; `defineExpose({ collapse, expand })`. `DealCompanyGroup.vue` — prop `defaultCollapsed`; expose `collapse/expand`. `DealProductRow.vue` — `<div>` flex-layout вместо `<tr>/<td>`; кнопки по hover. `DealFeed.vue` — убран постоянный тулбар → топбар с `pi-search` + `FeedSearchOverlay.vue` (NEW); убран отдельный ⋮; системные события — без card border/bg. `DealFeedItem.vue` — `feed-item--system` class; кнопки по hover (`.feed-item__hover-btn`); «Выполнить» всегда; `pi-user` иконка у responsible. `DealComposer.vue` — убраны Tabs, заменены `<Select>` дропдауном типа; убраны MultiSelect участники и поле location; call/meeting объединены с task формой. `DealInfoPanel.vue` — пробрасывает `nextTask`, `collapseAllSignal`/`expandAllSignal`, expose `onCollapseAll/onExpandAll`. `index.vue` — `useBreakpoints.ts` (NEW) (isMobile/isTablet); `min-width:1100px` убран; 4 брейкпоинта (≥1280/1024-1279/768-1023/<768); `<Drawer>` для планшета; SelectButton «Карточка/Лента» на мобильном; mobile-bar/tablet-bar; composer sticky на мобильном. `entities/sales.ts` — `DealDto.next_task/days_in_stage` (опц.); `DealPage/index.vue` предпочитает `deal.days_in_stage` от бэка. i18n: `clickToChangeStage`, `infoPanel`, `menu.collapseAll/expandAll/customizeFields/copyLink`, `health.noTask/overdue`, `info.fields.daysInWork`, `feed.searchTooltip`. 2026-06-16.*

**Acceptance M3:** создание сделки с продуктами; **Kanban drag-drop** меняет стадию и пишет историю; lost-reason при проигрыше; list-view с фильтрами; каталог продуктов подтягивается в line-items. CI зелёный. *(ядро выполнено S1.3 2026-06-12; остаток → S1.5+S1.6)*

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
