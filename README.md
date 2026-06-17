# MGCRM — миграция MACRO Global CRM на Laravel 13 + PrimeVue

Это рабочий репозиторий + «мозг» проекта: переписываем большую CRM (сейчас FastAPI+Next.js) на жёсткий стек **Laravel 13 / PHP 8.5 + Vue 3.5 / PrimeVue** по эталону **Vizion**, домен за доменом. Работа ведётся через **Claude Code** с командой из 15 специализированных агентов.

---

## 0. TL;DR — как запуститься

```bash
git clone https://github.com/Bogdan-cool-coder/MGCRM.git
cd MGCRM
claude            # открыть Claude Code в корне репо
```
Затем в сессии Claude Code напиши:
> **поехали M0** (или: «запусти M0 Bootstrap по PLAN.md»)

Claude (main-сессия) — оркестратор: он сам делегирует шаги агентам (`backend-specialist`, `deploy-engineer`, `designer`, `frontend-specialist`) и проведёт каркас по чек-листу M0. Дальше — milestone за milestone (M1…M12).

---

## 1. Что лежит в репозитории

```
MGCRM/                       ← корень = сам проект (Laravel src/ + Vue front/ создаются на M0)
├── README.md               ← этот файл
├── CLAUDE.md               ← мозг: правила, рабочий цикл, делегирование (грузится в Claude автоматически)
├── ARCHITECTURE.md         ← ЖЁСТКИЕ паттерны кода (закон проекта, «ни шагу в сторону»)
├── PLAN.md                ← план миграции, milestone-темп M0…M12 (M0 расписан по шагам)
├── brand/                  ← бренд-ассеты MACRO Global (логотип SVG, брендбук PDF)
├── .claude/
│   ├── agents/             ← 17 агентов (роли, зоны, правила)
│   ├── hooks/guard-destructive.sh   ← блок критичного деструктива (down -v, DROP, rm -rf данных)
│   └── settings.json
└── examples/               ← эталоны (read-only, сносятся на финальном M12)
    ├── vizion/             ← полная копия Vizion = ЭТАЛОН СТЕКА (как делать)
    └── contracts/          ← полная копия macro-contracts (FastAPI/Next) = источник БИЗНЕС-ЛОГИКИ (что делать)
```

> **Планы, прогресс, решения и детализация спринтов** ведутся в Obsidian-vault **`MG CRM 2026`** (`~/Documents/Obsidian Vault/MG CRM 2026`), не в репо-доках. В репо хранится только «жёсткая» архитектура и конвенции.

## 2. Требования на машине

- **Docker + docker compose** (PHP/Node/Postgres крутятся в контейнерах — **на хост ставить PHP/composer/node НЕ нужно**; для bootstrap используется `composer:latest` через `docker run`).
- **Claude Code CLI** (`claude`).
- **git** + **gh** (GitHub CLI), авторизованный на аккаунте с доступом к `Bogdan-cool-coder/MGCRM` (для push).
- Опционально для QA глазами: **Chrome + расширение Claude_in_Chrome** (дефолтный браузерный MCP у `qa-tester`; альтернатива — Playwright MCP, если попросишь).

## 3. Как устроена работа (важно понять до старта)

- **Ты общаешься с main-сессией Claude как с тимлидом.** Он не пишет код сам — **делегирует** профильным агентам. Просто формулируй задачи («сделай авторизацию», «добавь воронку сделок»), он выберет агента и порядок.
- **Рабочий цикл каждого агента:** смотрит ЧТО делать в `examples/contracts/` → смотрит КАК делать в `examples/vizion/` → пишет в корне (`src/`/`front/`) **как Vizion**, с делением по DDD `app/Domain/<Context>`. Не изобретаем — копируем Vizion.
- **Цепочка фичи:** агент → (если был UI) `qa-tester` → `product-manager` (ревью + сверка с PLAN/ARCHITECTURE + апдейт доков) → твой апрув → (только по явной просьбе) `deploy-engineer` пушит/деплоит.
- **Агенты на `bypassPermissions`** — рутина (docker/artisan/npm/git/правки) идёт молча, без подтверждений. Единственный жёсткий стоп — `guard-destructive.sh` на опасные команды.
- **Library-first:** весь функционал на готовых библиотеках; если задачу закрывает то, что уже есть (в проекте или у Vizion) — новое не ставим.

## 4. Запуск M0 Bootstrap (первый milestone)

M0 = пустой рабочий каркас: docker-монорепо → Laravel 13 → Sanctum (Bearer) + 2FA → роли (spatie/permission) → фронт PrimeVue+Bootstrap-grid → layout+логин → CI. Полный пошаговый чек-лист (M0.1–M0.7) с Definition of Done — в **`PLAN.md` §5, milestone M0**.

Запускать НЕ руками по шагам, а через Claude:
> «Веди M0 по PLAN.md. Начни с M0.1 (docker-монорепо) и M0.2 (Laravel 13 bootstrap), делегируй `deploy-engineer` и `backend-specialist`.»

⚠️ M0 честно помечен по «граблям» (читай примечания в PLAN §5/M0):
- LV13/PHP8.5 — **новее** Vizion (12/8.3): `composer.json` Vizion **не копируется 1-в-1**, скелет ставится `composer create-project`, пакеты добавляются сверху.
- **Redis** — net-new (у Vizion его нет), настраивается с нуля (драйверы очереди/кэша).
- **Auth = Sanctum Bearer-token** (как у Vizion реально), 2FA — по образцу `examples/contracts/.../auth_2fa.py` (у Vizion 2FA нет).
- **spatie/permission** — новый пакет (у Vizion простые строковые роли — не наш паттерн).

## 5. Правила (железные — см. CLAUDE.md / ARCHITECTURE.md)

- **`ARCHITECTURE.md` — закон.** Слои backend (FormRequest → тонкий Controller → Service → Model → API Resource), DDD-границы, деньги в копейках, Policy-авторизация, фронт (api → composables → page-composable → Pinia). Отклонение = баг.
- **Стек закрытый** (PLAN §3). Новый пакет — только по явной просьбе. Без Tailwind/Inertia/Horizon/Pest/VeeValidate.
- **Тесты — PHPUnit + SQLite :memory:** (тройная изоляция, тесты не ходят в живую БД).
- **Commit — только English, без AI-трейлеров.** Push/деплой — **только по твоей явной просьбе** (через `deploy-engineer`).
- **`examples/`** — read-only справка; сносится на M12 (cutover), проект остаётся в корне.

## 6. Куда смотреть

| Нужно | Файл |
|---|---|
| Как работаем, делегирование, правила | `CLAUDE.md` |
| Паттерны кода (обязательны) | `ARCHITECTURE.md` |
| План, milestones, чек-листы, Acceptance | `PLAN.md` |
| Роли и зоны агентов | `.claude/agents/<name>.md` |
| Эталон реализации | `examples/vizion/` |
| Что должно делать приложение (ТЗ) | `examples/contracts/` |
