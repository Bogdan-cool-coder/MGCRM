# CLAUDE.md — MACRO Global CRM (always-context)

> Мозг проекта **MACRO Global CRM**: переписываем огромную CRM (FastAPI+Next.js) на **жёсткий стек Laravel + PrimeVue по эталону Vizion**, домен за доменом.
> Этот файл лёгкий и always-injected. Жёсткие паттерны кода — в **`ARCHITECTURE.md`** (закон проекта). План — в `PLAN.md`. Эталон стека целиком лежит в `./examples/vizion/`.

## Структура (корень репозитория = проект MGCRM; работа ведётся нелокально)

```
macroglobalcrm/          ← git-репозиторий Bogdan-cool-coder/MGCRM. ⭐ САМ ПРОЕКТ ПИШЕМ ЗДЕСЬ (в корне).
├── CLAUDE.md            ← оркестрация (этот файл)
├── ARCHITECTURE.md     ← ЖЁСТКИЕ паттерны разработки (обязательны, «ни шагу в сторону»)
├── PLAN.md            ← БОЛЬШОЙ план миграции (milestone-темп M0…M12)
├── .claude/{agents,hooks,settings.json}
├── src/                ← Laravel 13 API (создаётся на M0)
├── front/              ← Vue 3.5 + PrimeVue SPA (создаётся на M0)
├── docker/  docker-compose*.yml  .github/
└── examples/            ← полные копии-эталоны, КОММИТЯТСЯ в репо (нелокальная работа)
    ├── vizion/          📐 ЭТАЛОН СТЕКА — реализацию смотрим здесь, делаем 1-в-1
    └── contracts/       ⚠️ macro-contracts (FastAPI+Next) — ТОЛЬКО ИСТОЧНИК БИЗНЕС-ЛОГИКИ
```

> 🧹 **На финальном milestone (M12, cutover):** `examples/` сносится из репозитория. До тех пор это рабочий контекст агентов и должен быть в репо целиком (работа идёт не на этой машине).

## ⚠️ Рабочий цикл агента (железно)

Каждый агент при любой задаче идёт по трём шагам:

1. **Бизнес-логику и поведение фронта смотрит в `./examples/contracts/`** — это ТЗ: модели, поля, связи, эндпоинты, статус-машины, экраны. **Написано на FastAPI/Next.js — код НЕ копируем, копируем смысл (что приложение делает).**
2. **Технический паттерн/реализацию смотрит в `./examples/vizion/`** (полная копия Vizion) — как ровно это сделано на нашем стеке: структура, сервисы, миграции, контроллеры, Vue-компоненты, конфиги.
3. **Делает 1-в-1 как Vizion** в корне проекта (`src/`, `front/`), **с единственной поправкой — деление по DDD `app/Domain/<Context>`**. Не изобретай — копируй Vizion.

Конфликт: **стек → `./examples/vizion/`**, **бизнес-логика → `./examples/contracts/`**.

## 📐 Закон проекта: ARCHITECTURE.md

**Любой код — строго по `ARCHITECTURE.md`.** Это не рекомендации, а паттерны: backend-слои (FormRequest → тонкий Controller → Domain Service → Model → API Resource), DDD-границы, деньги-копейки, Policy-авторизация, frontend-слои (api → composables/async → page-composable → component → Pinia), именование, тесты, чёрный список. Отклонение = баг; `product-manager` режет такой код на ревью. Перед кодом агент читает релевантный раздел ARCHITECTURE.md.

## Целевой стек (жёсткий — см. PLAN.md §3)

**Backend:** Laravel **13** / PHP **8.5** · PostgreSQL 16 · **Sanctum** (Bearer personal access token, как Vizion; фронт хранит токен) · **TOTP 2FA** + **spatie/laravel-permission** (6 ролей + финправа — 2 точечных исключения к минимализму Vizion) · spatie/translatable · spatie/backup · **Prism** (AI-каскады, `config/ai.php` как Vizion) · **PHPWord + Gotenberg** (договоры→PDF) · Redis (очереди, **БЕЗ Horizon**) · **PHPUnit + SQLite :memory:**.

**Frontend:** Vue **3.5** + TS strict · Vite · Pinia · Vue Router · **PrimeVue 4.5** + **Bootstrap-grid + SCSS** · PrimeIcons · **ECharts** · vue-i18n · axios.

**Организация:** **DDD `app/Domain/<Context>/{Models,Data,Enums,Services,Jobs,Policies}`**.

**Сознательно НЕТ:** Tailwind · Inertia · Livewire · Filament · Chart.js · Horizon · VeeValidate/Zod · spatie/laravel-data (ручные API Resources как Vizion) · Fortify · Pest.

## Стратегия и темп

Strangler, вертикальными срезами, домен за доменом. **Темп — milestone-стиль как Staffory/cloud-terminal:** M0 Bootstrap → … каждый milestone с day/week-оценкой, «копируем Vizion 1-в-1», vertical slice и Acceptance-чеклистом. M9 (финмодуль) — самый большой. M12 — перенос данных + cutover (снос `examples/`). Детали — PLAN.md.

## 15 агентов (`.claude/agents/`) — база описаний/правил взята с агентов Vizion

> **Все агенты — `bypassPermissions`** (рутина — docker/artisan/npm/git/Edit/Write/MCP, включая браузерные MCP-действия qa-tester'а — выполняется молча). Единственный жёсткий ограничитель — PreToolUse-хук `guard-destructive.sh` на критичный деструктив (работает и под bypass). Поведенческие правила (`frontend-specialist` и push у `deploy-engineer` — только по явной просьбе) остаются в силе как инструкции, не как пермишен-промпты.

**Кросс-функциональные (6):** `designer` (ТЗ, без кода) · `backend-specialist` (Laravel-ядро: auth/Sanctum/2FA, базовые модели, миграции, тесты — для всех) · `frontend-specialist` (Vue/PrimeVue/Pinia/Bootstrap-grid — **только по явной просьбе**, как у Vizion) · `qa-tester` (Chrome MCP, fallback Playwright) · `product-manager` (ревью + verify против ARCHITECTURE.md/PLAN.md) · `deploy-engineer` (Docker/GHA/SSH — **только по явной просьбе**).

**Доменные (8):** `contract-specialist` · `sales-specialist` · `cs-specialist` · `finance-specialist` · `automation-specialist` · `integration-specialist` · `analytics-specialist` · `bot-specialist`.

**Миграция (1):** `migration-specialist` (паритет old↔new, перенос схемы/данных, cutover).

## Workflow (как у Vizion)

```
[задача] → [main определяет агента и порядок] → [рабочий агент(ы): backend→domain→frontend→qa→PM]
        → [migration-specialist — перенос данных в КОНЦЕ домена, не в начале]
        → [если был UI у frontend-specialist → qa-tester] → [product-manager: саммари+ревью+verify+sync PLAN.md]
        → [апрув юзера] → [ТОЛЬКО по явной просьбе: deploy-engineer push/deploy]
```

**qa-tester НЕ нужен** при backend-only, рефакторинге без UI, правках только `.md`/`.yml`/`.json`, правках самих агентов/CLAUDE/PLAN/ARCHITECTURE.

## 🚨 Правило делегирования (приоритет №1)

**Делегируй ВСЁ кроме whitelist'а — по умолчанию.** Main — единственный контекст для оркестрации; когда main сам читает код/гоняет команды, он теряет высоту обзора.

**Main делает САМ (whitelist):** 1) диалог (AskUserQuestion, summary); 2) оркестрация tasks; 3) делегирование (Agent); 4) локальный git `status/diff/log/add/commit` (без push) после PM-аппрува; 5) запись секретов в `src/.env`; 6) чтение memory/CLAUDE.md/PLAN.md/ARCHITECTURE.md для ориентации; 7) минимальный Read/Grep для маршрутизации (НЕ для починки бага — это зона агента).

**Анти-паттерны main (запрещено):** ❌ Read src-файла «понять класс» · ❌ grep ради поиска бага · ❌ `docker compose exec … artisan …` напрямую · ❌ ssh · ❌ править «по мелочи» код/тесты/.md.

## Правила (железные)

- **`ARCHITECTURE.md` — закон.** Любой код строго по нему. Эталон паттернов — `./examples/vizion/`. Конфликт стека: Vizion > Staffory > Touchlink.
- **`./examples/contracts/` — только ТЗ по бизнес-логике** (FastAPI/Next — код не копируем).
- **Стек жёсткий** (PLAN §3). Пакетов вне списка не добавлять без явной просьбы. Никакого Tailwind/Inertia/Horizon/Pest/VeeValidate.
- **Library-first** (ARCHITECTURE.md §0.1): весь функционал — на готовых библиотеках. Если задачу закрывает уже подключённая/доступная либа (в проекте или `./examples/vizion/`) — новую НЕ ставим. Свой код — только когда готового нет. Новый пакет — лишь в случае особой необходимости + аппрув.
- **Самодостаточность репо:** работа нелокальная — всё нужное (включая `examples/`) лежит в репозитории. Агенты НЕ ссылаются на внешние пути вне репо.
- **Изоляция тестов:** PHPUnit строго в SQLite `:memory:` (тройная защита как Vizion). Тесты НИКОГДА не ходят в живую БД.
- **Commit — только English.** **НИКАКИХ** `Co-Authored-By: Claude`, упоминаний Claude/Anthropic/AI/🤖. Никаких `--no-verify`, `--force` push.
- **Деструктив — только по явной просьбе + бэкап** (`down -v`, `volume rm`, `DROP`, `rm -rf` данных). Guard-хук блокирует под bypass.
- **Секреты не светим** — значения в `src/.env` пишет main.
- **`docker compose`**, не `docker-compose`. PHP/composer на хосте нет — через docker (bootstrap: `docker run --rm -v "$(pwd):/app" -w /app composer:latest …`).
- **Деплой/push — только по явной прямой просьбе** через `deploy-engineer`. Локальный rebuild — допустим.
- При расхождении PLAN.md/ARCHITECTURE.md ↔ реальность — `product-manager` обновляет документ (с аппрувом), не молчим.
