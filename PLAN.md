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
| **spatie/laravel-permission** | 7.x | роли admin/director/lawyer/manager/accountant/cfo + фин-права. **Точечное исключение** |
| spatie/laravel-translatable | 6.x | jsonb-поля (на будущее EN; old RU-only) |
| spatie/laravel-backup | 9.x | ежедневный дамп БД |
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
| `Onboarding` | Course, Lesson, Quiz, прогресс | M12 |
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
| 3 — Онбординг | M12(Onboarding-часть) | Onboarding |
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

#### M0.1 — Скелет монорепо и Docker (backend-specialist + deploy-engineer)
> **Стартовая точка:** у Vizion `docker-compose.yml` уже содержит **6 сервисов** (app, nginx, frontend, queue-worker, gotenberg, postgres) и **БЕЗ redis**. → `gotenberg` + `queue-worker` копируемы 1-в-1; **redis — NET-NEW** (эталона у Vizion нет). `docker-compose.dev.yml` у Vizion **НЕ существует** — авторим его сами.
- [ ] Скопировать структуру каталогов и docker-конфиги из `./examples/vizion/` (`src/`, `front/`, `docker/php`, `docker/nginx`, `docker/frontend.Dockerfile`), переименовать (`macro-crm-*`).
- [ ] **redis (NET-NEW, нет эталона у Vizion):** `redis:7-alpine`, bind `127.0.0.1`, в ОБА compose-файла; `depends_on: redis` на `app` и `queue-worker`.
- [ ] `docker-compose.dev.yml` (авторим сами — у Vizion нет): сервис `db` (postgres:16-alpine, volume `pgdata`), `redis` (redis:7-alpine, 127.0.0.1). API/web — на хосте или в контейнере (как Vizion `composer run dev`).
- [ ] `docker-compose.yml` (prod): app(php-fpm), nginx, frontend(node build), queue-worker, gotenberg:8, postgres:16 — 6 копируемых из Vizion + redis (NET-NEW). Без Horizon. **Решить build-context vs готовый image:** prod-compose Vizion тянет prebuilt-образы из GHCR — у нас на старте `build:`-контекст; registry-путь переименовать под MGCRM (не оставлять vizion-GHCR-пути).
- [ ] `.env.example` с категориями: DB, SANCTUM, ADMIN seed, REDIS, ANTHROPIC (Prism), GOOGLE, SMTP, TELEGRAM, GOTENBERG_URL.
- **DoD:** `docker compose -f docker-compose.dev.yml up -d db redis` поднимает БД + redis; `pg_isready` ок.

#### M0.2 — Laravel 13 bootstrap (backend-specialist)
> **НЕ копировать `composer.json` require-блок Vizion 1-в-1** — у Vizion LV12/PHP8.2/PHPUnit11, у нас LV13/PHP8.5 → конфликт версий. `composer create-project` даёт LV13-корректный скелет (phpunit/collision уже под LV13), пакеты §3.1 ставим ПОВЕРХ. Vizion-конфиги — LV12-формы (`bootstrap/app.php`, `config/*`) → **адаптируем, не 1-в-1**.
- [ ] `composer create-project laravel/laravel src` (LV13) через `docker run --rm -v "$(pwd):/app" -w /app composer:latest`. **PHP запинить на 8.5** в `docker/php/Dockerfile`.
- [ ] `config/database.php` — pgsql основной. **НО** `phpunit.xml` — SQLite :memory: с `force="true"` + guard в `tests/TestCase.php` (паттерн Vizion: тест падает, если `database.default !== sqlite`).
- [ ] Установить пакеты §3.1 ПОВЕРХ скелета: sanctum, spatie/permission, spatie/translatable, spatie/backup, prism, phpoffice/phpword, phpoffice/phpspreadsheet, pragmarx/google2fa, wapmorgan/morphos, **predis/predis** (драйвер redis).
- [ ] **Redis-драйвер:** в `.env.example` — `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_HOST=redis`.
- [ ] **Очистить/адаптировать** sqlite-ориентированные composer-скрипты Vizion `post-create-project-cmd` (под наш pgsql+dev-флоу).
- [ ] **Явный порядок dev-старта:** скопировать `.env` из `.env.example` → `php artisan key:generate` → убедиться, что pgsql-env указывает на compose-сервис `db` → ТОЛЬКО ПОТОМ `php artisan migrate`.
- [ ] `config/ai.php` — скопировать из `./examples/vizion/`. ⚠️ у Vizion он **GLM-primary (Z.AI) + Anthropic-fallback**; наш §3 — Anthropic → **вырезать Z.AI/GLM, оставить каскад Anthropic**, убрать `Z_API_KEY`.
- [ ] `config/crm.php` — проектные значения (роли, валюты, storage paths).
- **DoD:** `php artisan migrate` (на pgsql) проходит; `php artisan test` (sqlite) зелёный на дефолтном тесте.

#### M0.3 — Аутентификация: Sanctum + 2FA (backend-specialist)
> **Auth = Bearer personal access token (как Vizion реально):** `createToken('api')->plainTextToken`, фронт хранит токен. ⚠️ Vizion-`AuthController` написан ДО правил ARCHITECTURE.md (inline `$request->validate` + сырой `response()->json`) — **НЕ копировать verbatim**, переписать через `LoginRequest` (FormRequest) + `UserResource`. 2FA-флоу **эталона у Vizion нет** — реплицируем документированный флоу `./examples/contracts/apps/api/app/routers/auth_2fa.py` (setup → verify-setup → validate → backup codes → temp-токен), на `pragmarx/google2fa`.
- [ ] `User` в `app/Domain/Iam/Models/User.php`: поля из old (email, password_hash, full_name, **role enum**, telegram_user_id, avatar_path, department_id, manager_id, is_active, **totp_enabled, totp_secret, backup_codes**).
- [ ] Sanctum **Bearer**: `POST /api/login` (→ `plainTextToken` или temp-токен при 2FA), `/logout`, `GET /api/me`, защита `auth:sanctum`. Контроллер — через `LoginRequest` + `UserResource` (НЕ verbatim Vizion).
- [ ] 2FA-флоу (по `auth_2fa.py`): `login` (success) → temp-токен → `POST /api/2fa/validate` → полный токен. Setup: `POST /api/2fa/setup` (QR) → `/2fa/verify-setup`. Backup-коды (8, hashed). Реализация — `pragmarx/google2fa`.
- [ ] Фронт-`vite.config.ts` Vizion проксирует на Vizion `nginx:80` + Vizion-домены в `allowedHosts` → **перенацелить на наш стек** (наш backend/nginx + наши домены).
- **DoD:** Feature-тесты login/logout/me/2fa-флоу зелёные.

#### M0.4 — Роли и доступы (backend-specialist)
> ⚠️ **`spatie/laravel-permission` — эталона у Vizion НЕТ** (Vizion использует простую строковую колонку `role` — это явно НЕ наш паттерн по ARCHITECTURE.md). Помечаем как **NEW-пакет**; 6 ролей выводим из `./examples/contracts/`, не из Vizion. **`SetLocale` middleware — копируем у Vizion** (`routes/api.php`, locale middleware).
- [ ] spatie/permission (NEW): сидер 6 ролей (admin/director/lawyer/manager/accountant/cfo) + базовые permissions. Роли — из `./examples/contracts/`.
- [ ] Middleware `SetLocale` (RU/EN из заголовка/query, **копируется у Vizion**), задел `ResolveVisibility` (scope all/department/personal — реализация в M1).
- **DoD:** тест проверки роли на защищённом эндпоинте.

#### M0.5 — Frontend bootstrap (frontend-specialist + designer)
- [ ] `npm create vite front` (Vue+TS), скопировать структуру `front/src/*` из `./examples/vizion/`. ⚠️ `vite.config.ts` Vizion проксирует на Vizion `nginx:80` + Vizion-домены в `allowedHosts` → **перенацелить proxy/allowedHosts на наш стек**.
- [ ] `main.ts`: Pinia + persist + Router + i18n + **PrimeVue preset** + axios-middleware + bootstrap-приложения (паттерн Vizion: `bootstrapPromise.then() → router.isReady() → mount`). Хранение токена — Bearer (консистентно с auth-решением M0.3).
- [ ] PrimeVue: тема-preset + CSS-переменные в `theme/`. Подключить **bootstrap-grid** (только сетка) + PrimeIcons. **БЕЗ Tailwind.**
- [ ] `designer`: дизайн-токены (цвета, типографика, радиусы, тени) в **SCSS/PrimeVue-preset**, НЕ Tailwind. Палитра из old как референс цветов: primary `#172747`, semantic success/warning/danger/info — реализация на SCSS-переменных.
- [ ] `api/client.ts`: axios + Sanctum **Bearer** (токен в `Authorization`-заголовке, как Vizion), обработка 401 → logout.
- [ ] `composables/async/{useAsyncResource,useMutation}` — копия из `./examples/vizion/`.
- **DoD:** `npm run build` + `vue-tsc` без ошибок.

#### M0.6 — Layout, навигация, логин-страница (frontend-specialist + designer)
- [ ] `DefaultLayout` (header + sidebar + main), `Toolbox` (профиль, локаль, выход).
- [ ] `stores/user.ts`, `stores/layout.ts`. Router-guard `policy.ts` (auth, redirect).
- [ ] Страницы: `LoginPage` (email+password+2FA), пустой `DashboardPage`.
- [ ] i18n: `ru.json` (+ пустой `en.json`).
- **DoD:** логин в браузере проходит (qa-tester), редирект на dashboard, sidebar рендерится.

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

- [ ] Миграции/модели: Contact v2, Company v2 (ИНН/КПП, юрформа), ContactPosition, M2M связи Contact↔Company + роли.
- [ ] Справочники: типы компаний, гео (страны/города), источники.
- [ ] **CustomFieldDef** — кастом-поля для любой сущности (полиморфно), как в old.
- [ ] Дедуп + merge контактов/компаний.
- [ ] UI: списки (DataTable + фильтры), карточки контакта/компании, формы, merge-диалог.
- [ ] Тесты: CRUD, дедуп-логика, кастом-поля.

**Acceptance M2:** CRUD контактов и компаний с ИНН/КПП; кастом-поля видны в карточке; дедуп находит дубли и сливает; гео-справочники подключены. CI зелёный.

---

### M3. Sales / Kanban (2-3 недели)

**Ведущие:** `sales-specialist` + `catalog` (в его зоне) + `frontend-specialist` + `designer`. Контекст `Sales` + `Catalog`.

- [ ] Pipeline (sales/lifecycle/renewal), стадии, lost-reasons.
- [ ] Deal: CRUD, продукты line-items (DealProduct), контакты, история стадий.
- [ ] **Kanban-доска** (PrimeVue + drag-n-drop) + list-view (DataTable, фильтры).
- [ ] Карточка сделки, форма отчёта о встрече.
- [ ] Каталог продуктов/планов/цены (Catalog).
- [ ] Тесты: переходы стадий, расчёт суммы сделки, drag-drop API.

**Acceptance M3:** создание сделки с продуктами; **Kanban drag-drop** меняет стадию и пишет историю; lost-reason при проигрыше; list-view с фильтрами; каталог продуктов подтягивается в line-items. CI зелёный.

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
- [ ] Уведомления: in-app центр, email (SMTP), TG-канал.
- [ ] Preferences (юзер×kind×канал), шаблоны, broadcast.
- [ ] Тесты: создание активности/задачи, dispatch уведомлений по preferences (мок каналов).

**Acceptance M6:** активность линкуется к сделке и видна в таймлайне; задача с дедлайном назначается; уведомление приходит в in-app + email + TG по preferences пользователя. CI зелёный.

---

### M7. Автоматизации + Sequences + Bulk (2-3 недели)

**Ведущие:** `automation-specialist` + `frontend-specialist`. Контекст `Automation`.

- [ ] PipelineAutomation: триггеры (on_enter_stage/on_create/idle_in_stage/date_approaching/field_changed/activity_completed) + действия (tg_notify/create_task/set_field/generate_document/change_owner/webhook/email/start_sequence).
- [ ] Визуальный билдер правил + audit-runs.
- [ ] Sequences (email/SMS-цепочки).
- [ ] Bulk-операции (генерация доков, задачи) с прогресс-баром.
- [ ] Тесты: срабатывание триггера → действие; прогон sequence; bulk с прогрессом (мок очереди).

**Acceptance M7:** правило «вход в стадию → создать задачу + TG-уведомление» срабатывает и пишется в audit-runs; sequence шлёт цепочку писем; bulk-генерация доков показывает прогресс. CI зелёный.

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
- [ ] **Kanban сделок drag-drop** меняет стадию + line-items продуктов + lost-reasons (M3)
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
