# MACRO Global CRM

CRM компании MACRO Global на стеке **Laravel 13 / PHP 8.5 + Vue 3.5 / PrimeVue 4.5**. Проект — strangler-переписывание большой CRM (исходно FastAPI + Next.js) на жёсткий, консистентный стек; backend организован по DDD (`app/Domain/<Context>`), фронт — Vue SPA. Разработка ведётся через **Claude Code** с флотом из 19 специализированных агентов.

## Стек

- **Backend:** Laravel 13, PHP 8.5, PostgreSQL 16, Sanctum (Bearer-токен) + TOTP 2FA, RBAC на `spatie/laravel-permission` (guard `sanctum`), Redis (очереди/кэш, без Horizon), Prism (AI-каскады), PHPWord + Gotenberg (договоры→PDF), Reverb (realtime), Sentry.
- **Frontend:** Vue 3.5 (TS strict) + Vite, Pinia, Vue Router, PrimeVue 4.5 + Bootstrap-grid + SCSS, PrimeIcons, ECharts, vue-i18n, axios, Sentry.
- **Тесты:** PHPUnit на SQLite `:memory:` (тройная изоляция, в живую БД не ходят).
- **Сознательно НЕ используем:** Tailwind, Inertia, Livewire, Filament, Chart.js, Horizon, VeeValidate/Zod, spatie/laravel-data, Fortify, Pest.

## Структура репозитория

```
MGCRM/                       ← корень = сам проект
├── src/                     ← Laravel API — app/Domain/<Context>/{Models,Data,Enums,Services,Jobs,Policies}
├── front/                   ← Vue SPA (TS) — pages/components/stores/api/composables/entities/router/theme/locales
├── docker/                  ← Dockerfile'ы php/nginx/frontend + конфиги (имена macro-crm-*)
├── docker-compose.yml       ← прод-профиль
├── docker-compose.dev.yml   ← dev-профиль (postgres, redis, app, nginx, frontend, queue, scheduler, bot, gotenberg, reverb)
├── .github/workflows/       ← CI + deploy.yml (push-to-main → авто-деплой)
├── docs/                    ← backend-standard.md, DEPLOY.md, designer-charter.md, realtime-contract.md, audit/
├── design-handoff/redesign/ ← апрувнутые мокапы + ТЗ (HANDOFF.md — живой индекс)
├── brand/                   ← бренд-ассеты MACRO Global
├── .claude/                 ← agents/ (19), hooks/, AGENTS.md, skills/
└── examples/                ← contracts/ (ТЗ бизнес-логики, FastAPI/Next) + vizion/ (архив; сносится на cutover)
```

Домены `src/app/Domain/`: Activity · Automation · Catalog · Contracts · Crm · Iam · Inbox · Log · Migration · Notification · Org · Onboarding · Sales · SalesPulse. (`CustomerSuccess`/`Finance` — greenfield; `Analytics`/`Integration` пока вшиты в Sales/Inbox/Notification.)

## Локальный запуск (dev)

Требования: **Docker + docker compose**, **git**. PHP/Node/Composer на хост ставить не нужно — всё в контейнерах.

```bash
git clone https://github.com/Bogdan-cool-coder/MGCRM.git
cd MGCRM
docker compose -f docker-compose.dev.yml up -d       # поднять dev-стек
docker compose -f docker-compose.dev.yml exec app php artisan migrate --seed
```

Фронт (Vite dev-server) и API поднимаются в контейнерах `frontend`/`app`+`nginx`. Dev-креды и URL-адреса — во внутренней memory/docs проекта (не хранятся в README). Опционально для QA-прогонов глазами: Chrome + расширение Claude_in_Chrome, либо Playwright MCP (`.mcp.json`, harness в `e2e/`).

## Как ведётся работа

Проект пишется через **Claude Code**: main-сессия — оркестратор, она делегирует профильным агентам (`backend-architect`, `<module>-backender`/`<module>-frontender`, `frontend-specialist`, `qa-tester`, `reviewer`, `deploy-engineer` и др.). Формулируешь задачу — main выбирает агента и порядок; цепочка фичи: рабочий агент → (если UI) `qa-tester` → `reviewer` (ревью + verify + sync доков) → твой апрув → (по явной просьбе) `deploy-engineer` пушит. Полный список ролей и governance-модель флота — в **`.claude/AGENTS.md`** и **`.claude/agents/<name>.md`**.

## Деплой

Push в `main` (делает `deploy-engineer` **только по явной просьбе**) автоматически катит прод через GHA `deploy.yml` (SSH → `git reset --hard origin/main` → rolling-restart: force-recreate app + health-wait + `migrate --force` + health-check). Пуши только по `**.md` / `docs/**` / `.claude/**` прод не деплоят. Детали — **`docs/DEPLOY.md`**.

## Куда смотреть

| Нужно | Файл |
|---|---|
| Как работаем, делегирование, правила, рабочий цикл | `CLAUDE.md` |
| Жёсткие паттерны кода (закон проекта) | `ARCHITECTURE.md` |
| Backend house-style (доменные границы, reuse, library-registry) | `docs/backend-standard.md` |
| План миграции, milestones, Acceptance | `PLAN.md` |
| Роли, зоны и governance агентов | `.claude/AGENTS.md`, `.claude/agents/<name>.md` |
| Деплой-пайплайн | `docs/DEPLOY.md` |
| Дизайн-система, мокапы, статус экранов | `design-handoff/redesign/HANDOFF.md`, skill `.claude/skills/macroglobal-design/` |
| Что приложение должно делать (ТЗ бизнес-логики) | `examples/contracts/` |
