# MACRO CRM — гайд для Claude Code

Этот файл автоматически инжектируется в каждую сессию. Здесь — обзор проекта, workflow и список агентов. **Не дублирует** PLAN.md и BACKLOG.md, только ссылается.

> Историческое примечание: репозиторий называется `MACRO Contract generator` — **не переименовываем**, это исторический артефакт фазы до 30 мая 2026. Продукт же — **MACRO CRM**.

---

## Что за проект

**MACRO CRM** — собственная CRM-система команды продаж MACRO Global Technologies, полностью заменяющая AmoCRM. Покрывает воронку продаж (14 AmoCRM-style этапов), Customer Success реестр подписок (B0-B6/A1-A6/C0), генерацию сублицензионных договоров с PDF, аналитику и (в roadmap) inbox/каналы/автоматизации/TG-бота команды.

**Ключевые документы:**
- [PLAN.md](./PLAN.md) — план: стек, БД, модули, milestones, acceptance
- [BACKLOG.md](./BACKLOG.md) — всё, что **НЕ** в текущем scope (фичи на потом, замены стека по триггерам)
- [.claude/agents/](.claude/agents/) — специализированные агенты с детальными промптами

---

## Стек (кратко — детали в PLAN.md)

- **Backend:** Python 3.11+, FastAPI, SQLAlchemy 2.0 async (asyncpg), Pydantic v2 (`ConfigDict from_attributes`), Alembic с `pg_advisory_xact_lock` seed-keys, python-jose JWT в **cookie `access_token`** (НЕ Authorization header), python-docx + docxtpl + LibreOffice headless (PDF), openpyxl, httpx, num2words (план), Claude API Haiku→Sonnet (эпик 7).
- **Frontend:** Next.js 14+ (app router, `output: "standalone"`), TypeScript **strict** (`tsc --noEmit` must be 0), SWR, Tailwind (классы `input/label/btn-primary/btn-secondary/btn-ghost/card/badge`; токены `primary/primary-light/danger/success/info`), Bootstrap Icons (`bi-*`). Все fetch — через `api/fetcher` из `@/lib/api` с `credentials: "same-origin"`.
- **AI:** Claude API (эпик 7 — Haiku/Sonnet для TG-бота команды), генерация документов через docxtpl без LLM.
- **БД:** PostgreSQL 16 (одна БД, миграции 0001..0017 в `apps/api/alembic/versions/`).
- **Infra:** Docker compose (`db`, `api` scale=2, `web`, `bot`, `onlyoffice` под профилем `onlyoffice`). Traefik 2.x + Let's Encrypt (external network `proxy`).
- **Deploy:** push в `main` → GitHub Actions workflow `Deploy` → `appleboy/ssh-action` → `deploy/rolling-restart.sh` (scale 2→4 → wait healthy → kill old) на ServerCore `root@153.80.193.132:/opt/macro-contracts`. Поддомены: `contracts.macroglobal.tech` (основной), `office-153-80-193-132.nip.io` (OnlyOffice workaround). Верификация — `gh run watch <id> --exit-status`.

---

## Агенты

### 6 базовых (cross-cutting)

| Агент | Модель | Permission | Зона |
|---|---|---|---|
| **`designer`** | sonnet | default | UX/UI architect. Пишет ТЗ для frontend-specialist в едином стиле (Tailwind токены, Bootstrap Icons, RU тексты). Не пишет код. |
| **`frontend-specialist`** | sonnet | acceptEdits | Next.js 14 / TS strict / Tailwind / SWR по ТЗ designer'а. Только RU тексты (до отдельного i18n-эпика). |
| **`backend-specialist`** | opus | acceptEdits | FastAPI, SQLAlchemy async, миграции с advisory-lock, pure-function pytest, Docker, инфраструктура. |
| **`qa-tester`** | sonnet | acceptEdits | QA через **Claude_in_Chrome MCP** (Playwright НЕ установлен). После UI-итераций — прогоняет фичу, собирает console+network ошибки, скриншоты, отчёт PASS/FAIL. Dev: `localhost:3000` (admin@example.com/admin). Prod: read-only только. |
| **`product-manager`** | sonnet | default (Edit `.md` only) | После каждого этапа: саммари + code review (cookie-auth, TS strict, pure-function tests, migrations idempotency) + verify против PLAN.md + sync доков (с аппрувом). |
| **`deploy-engineer`** | sonnet | default | Docker compose + GHA + ssh root@153.80.193.132. **Активируется ТОЛЬКО по явной просьбе пользователя** (push в `main`, hotfix, ручной rolling-restart). |

### N domain (по разделу функции системы)

| Агент | Модель | Permission | Зона |
|---|---|---|---|
| **`contract-specialist`** | sonnet | acceptEdits | Эпик 3: документооборот 2.0. Шаблоны (Template, TemplateVariable), master_skeleton.docx, OnlyOffice WYSIWYG, docxtpl render, LicensorEntity, ApprovalRoute, сумма прописью (num2words), Variables UI/Preview. |
| **`cs-specialist`** | opus | acceptEdits | Фаза 4 + эпик расширений: Customer Success реестр. Subscription / SubscriptionModule / ImplementationItemStatus / ActivitySnapshot / RegistryKpiSnapshot. Lifecycle pipeline B0-B6/A1-A6/C0, health-tier recompute, чек-листы, импорт реестра, attention/dashboard. |
| **`sales-specialist`** | opus | acceptEdits | Эпики 1-2-6: Sales pipeline + Lead + Contact/Company split + Activities/Timeline + Renewal pipeline. Разделение Counterparty → Contact + Company, Lead-сущность, ActivityModel (call/meeting/task/note), bulk-генерация, конверсии, forecast. |
| **`automation-specialist`** | opus | acceptEdits | Эпик 4: PipelineAutomation. Модель + executor (inline + cron). Триггеры (`on_enter_stage`, `idle_in_stage_days`, `date_field_approaching`, `field_value_changed`, `activity_completed`). Действия (`tg_notify`, `create_task`, `set_field`, `generate_document`, `change_owner`, `webhook`, `email`, `start_sequence`). UI «Автоматизации» на каждой воронке. |
| **`integration-specialist`** | opus | acceptEdits | Эпики 5+9+11: каналы и интеграции. Inbox (TG/WA/Email/Forms → авто-Lead), конструктор форм, импорт из AmoCRM (pipelines/stages/users/contacts/companies/leads/deals/notes/tasks mapping, параллельная работа N недель, switch), Public API + Webhooks (in/out). |
| **`analytics-specialist`** | sonnet | acceptEdits | Эпик 10 + расширения: аналитика contracts/registry, Excel экспорт (openpyxl), KPI plan vs fact, конверсии по воронкам, forecast выручки, дашборды, Sparkline, срезы. |
| **`bot-specialist`** | opus | acceptEdits | Эпик 7: TG-бот команды продаж. Порт MACRO Auto на нашу БД, `/startday /finishday /progress /dayresults /skipday /whoami /help /unskipday`, multi-team, LLM Haiku→Sonnet, snapshots, auto-announcer переходных задач. |
| **`finance-specialist`** | opus | acceptEdits | Модуль «Финансы» (управленческий учёт ERP-уровня). Double-entry GL под operation-centric UX, расчётные счета/кассы, финоперации (приход/расход/перевод), статьи ДДС + разнесение, реестр платежей, согласование под типы операций, заявки менеджеров, инвойсы/акты, полный НДС, мультиюрлицо, accrual + cash-basis (ДДС), импорт банк-выписки. Модели `fin_*`, сервисы `app/services/finance/*`, роутеры `/api/finance/*`, страницы `/finance/*`. Фазы Ф0-Ф6. Спека: `finance-module-research/J_phase0_LOCKED.md`. |

---

## Workflow

### Главное правило: каждое изменение проходит через product-manager

```
[пользователь даёт задачу]
        ↓
[main-сессия определяет — UI это или нет]
        ↓
   ┌────┴────┐
   ↓         ↓
[UI задача]  [не-UI задача]
   ↓         ↓
[designer пишет ТЗ]
   ↓         ↓
[пользователь смотрит ТЗ, корректирует]
   ↓         ↓
[main-сессия делегирует рабочим агентам]
- frontend-specialist (UI по ТЗ)
- backend-specialist (общий backend, миграции, тесты)
- domain-агент (доменная фича: contract / cs / sales / automation / integration / analytics / bot)
        ↓
[агенты работают с acceptEdits — быстро]
        ↓
[агент возвращает короткое summary main-сессии]
        ↓
[ЕСЛИ был UI-итерация у frontend-specialist → main вызывает qa-tester]
qa-tester:
  1. Логинится на dev-домене (localhost:3000, admin@example.com/admin)
  2. Прокликивает фичу по ТЗ designer'а (happy path) через Claude_in_Chrome MCP
  3. Собирает console errors + network 4xx/5xx + скриншоты
  4. Smoke по 2-3 соседним страницам (регрессия)
  5. Отчёт PASS/FAIL
        ↓
[если FAIL] → main возвращает frontend-specialist'у с fix-actions из отчёта
[если PASS] → дальше в product-manager
        ↓
[main-сессия ОБЯЗАТЕЛЬНО вызывает product-manager]
        ↓
product-manager делает 4 шага:
  1. Саммари — что изменено (файлы / что / как / почему)
  2. Code review — security + конвенции стека (cookie-auth, TS strict, pure-function tests,
     advisory-lock в миграциях; флагует critical/warning/suggestion)
  3. Verify — сверяет с PLAN.md и md выполнявшего агента, ищет расхождения
  4. Sync — если есть расхождения, предлагает правки в .md и ждёт аппрува
        ↓
[пользователь даёт правки] → [агент переделывает автоматом] → [PM снова саммари]
        ↓
[после аппрува по фиче]
        ↓
[ТОЛЬКО ПО ЯВНОЙ ПРОСЬБЕ ПОЛЬЗОВАТЕЛЯ — deploy-engineer сливает/деплоит]
```

### Permission modes

| Категория | Permission | Поведение |
|---|---|---|
| Рабочие (frontend, backend, domain-агенты) | `acceptEdits` | Auto-accept Edit/Write — быстро |
| `qa-tester` | `acceptEdits` | Нет Edit/Write — только Read + Bash (whitelist) + Claude_in_Chrome MCP |
| `product-manager` | `default` (Edit только `.md`) | Каждая правка документации требует аппрува; код не правит |
| `designer` | `default` (read-only) | Только Read/Grep/Glob/Bash; пишет ТЗ в чат |
| `deploy-engineer` | `default` | Каждая команда деплоя — ручной аппрув. **Запускается только по явной просьбе** |

### Как main-сессия выбирает агента

По полю `description` в `.claude/agents/<name>.md`. Большинство проактивные («use proactively for X»). Если задача неочевидна — **спроси пользователя кого вызвать** или используй несколько по очереди (subagent → subagent запрещено).

### Conventions main-сессии (для тебя, Claude)

**Перед делегированием:**
- Если задача про UI → **сначала designer** пишет ТЗ, потом frontend-specialist
- Если миграция нужна → backend-specialist (он же миграции с `pg_advisory_xact_lock`)
- Если новый бэкенд-эндпоинт + UI → backend-specialist первый, потом designer + frontend-specialist
- Несколько модулей — определи порядок (миграция → backend → domain-агент → frontend → qa-tester (если UI) → product-manager)
- Доменная фича — определи правильного domain-агента: договоры → `contract-specialist`, реестр/подписки → `cs-specialist`, lead/deal/activity/renewal → `sales-specialist`, триггеры/действия → `automation-specialist`, AmoCRM-импорт/каналы/webhooks → `integration-specialist`, KPI/dashboards/Excel → `analytics-specialist`, TG-бот → `bot-specialist`, финансы/учёт/ДДС/НДС/проводки/расчётные счета/реестр платежей/инвойсы → `finance-specialist`.

**После рабочего агента:**
**Сначала qa-tester (если был UI-итерация у `frontend-specialist`), потом `product-manager`.** qa-tester сидит в цепочке между frontend и PM — если FAIL, возвращай frontend'у с fix-actions из отчёта; если PASS, передавай PM. Если PM нашёл расхождения — пользователю показывай его отчёт, ничего не приукрашивай.

**Когда qa-tester НЕ нужен:**
- Backend-only изменения (нечего смотреть глазами)
- Чистый рефакторинг без UI-эффекта
- Изменения только в `.md` / `.yml` / `.json`-конфигах
- Изменения в самих агентах (`.claude/agents/*.md`) или в CLAUDE.md/PLAN.md

**Делегируй почти каждое действие.** Main-сессия — оркестратор. Все правки кода/конфигов/seeders/миграций/тестов/доков делает соответствующий агент. Main-сессия делает только: git commit/status, **запись секретов в `.env`** (чтобы не уходили в transcripts субагентов — критично!), TaskCreate/Update, диалог с пользователем. Если правка кажется мелкой (1-2 строки) — всё равно через агента, без коротких путей.

**Когда вызывать deploy-engineer:**
- Пользователь явно сказал: «push в main», «слей в main», «релиз», «деплой prod», «hotfix», «rolling-restart на VPS»
- НЕ вызывать на «закоммить и иди дальше» — это просто `git commit` без push (в нашем флоу commit делает main-сессия)
- НЕ вызывать на каждое изменение кода — push в `main` запускает GHA → авто-деплой, поэтому каждый push = прод-релиз

**Зона ответственности по git-операциям:**
- `git add` + `git commit` (локально, без push) — **main-сессия** делает сама после PM-аппрува по этапам. Никаких subagent'ов для этого.
- `git push origin main` (= прод-релиз через GHA) — **deploy-engineer**, только по явной просьбе пользователя.
- Hotfix, ручной rolling-restart, ssh на VPS — **deploy-engineer**, только по явной просьбе.

**Правила commit-сообщений (КРИТИЧНО):**
- **Только на английском.** Subject и body коммита — английский, независимо от того что вся остальная коммуникация на русском.
- **НИКОГДА** не добавлять `Co-Authored-By: Claude <...>` или любой AI-trailer в footer коммита. Запрещено пользователем явно.
- Никаких подписей про Claude / Anthropic / AI-generated / 🤖 в commit messages.
- Стиль — обычный человеческий, как в `git log --oneline | head -20` (`feat(scope): ...`, `fix(scope): ...`, `refactor(scope): ...`).
- Никогда `--no-verify`. Никогда `--force` push.

**При расхождении PLAN.md ↔ код:**
Если ты или агент видит, что PLAN.md устарел или фактическая реализация расходится с планом:
- НЕ молчи, НЕ правь PLAN.md тихо
- Передай эту находку `product-manager` или подними флаг пользователю с предложением: (а) обновить план (б) откатить код

PLAN.md — single source of truth.

---

## Полезные команды

```bash
# Backend tests (pure-function, без DB fixture)
cd apps/api && .venv/bin/python -m pytest -q
cd apps/api && .venv/bin/python -m pytest -q tests/test_pricing.py

# Frontend type check (must be 0)
cd apps/web && npx tsc --noEmit

# Frontend dev
cd apps/web && npm run dev   # localhost:3000

# Backend dev
cd apps/api && .venv/bin/uvicorn app.main:app --reload --port 8000

# Alembic migrations
cd apps/api && .venv/bin/alembic upgrade head
cd apps/api && .venv/bin/alembic revision -m "add foo" --autogenerate

# Docker compose (local)
docker compose up -d db
docker compose up --build api web
docker compose --profile onlyoffice up -d onlyoffice
docker compose logs -f api
docker compose ps

# Git
git status
git diff --stat
git log --oneline | head -20
git diff main..HEAD

# CI/CD verification
gh run list --limit 5
gh run watch <id> --exit-status   # exit 0 = success

# SSH to prod VPS (только deploy-engineer по явной просьбе)
ssh -i ~/.ssh/id_ed25519 root@153.80.193.132
ssh -i ~/.ssh/id_ed25519 root@153.80.193.132 'cd /opt/macro-contracts && docker compose ps'
ssh -i ~/.ssh/id_ed25519 root@153.80.193.132 'cd /opt/macro-contracts && ./deploy/rolling-restart.sh'
```

---

## Что НЕ делаем

- **Никакого `Authorization: Bearer` header** — auth строго через cookie `access_token`. Все frontend fetch с `credentials: "same-origin"`. Backend deps через `Cookie(...)`.
- **Никаких хардкоженых секретов** в коде, md или transcripts. `.env` пишет **только main-сессия**, subagent'ы туда не лазят (секреты утекут в логи агентов).
- **Никакого `--no-verify`** в `git commit`.
- **Никакого `--force` push** в `main` — это прод. Если очень надо — только сам пользователь, никогда не агент.
- **Никакого `Co-Authored-By: Claude`** в commit messages — AI-trailer запрещён пользователем.
- **Никаких commit messages на русском** — только English (`feat(scope): ...` стиль).
- **Никакого `any` в TypeScript** — strict mode, `tsc --noEmit` must be 0. Use `unknown` + narrowing.
- **Pytest без DB fixture** — все тесты pure-function, должны прогоняться из любой среды. `asyncio_mode="auto"`.
- **Миграции с `pg_advisory_xact_lock` seed-key** (идемпотентность критична — у нас scale=2 на api, параллельные стартапы могут гоняться). Seeders — advisory-lock + insert-missing, НЕ truncate-insert.
- **Frontend НЕ придумывает UX сам** — должно быть ТЗ от designer'а.
- **qa-tester НЕ ходит в прод с записью** — только read-only smoke без логина на prod (`https://contracts.macroglobal.tech`).
- **i18n пока НЕ делаем** — все тексты в JSX/throw строго RU до отдельного i18n-эпика. EN добавим, когда дойдём.
- **Никакого Playwright MCP** — он не установлен. Для QA только `mcp__Claude_in_Chrome__*` тулы.
- **Глобального toast/notification пока нет** — используем inline-сообщения в формах + `badge` варианты.
- **Не переименовываем репо** `MACRO Contract generator` → `MACRO CRM` — это исторический артефакт, ничего нигде не меняем.

---

## Что мы УЖЕ обсудили и зафиксировано

Чтобы новая сессия не задавала повторно вопросы (фиксация на 30 мая 2026):

- **Полная замена AmoCRM, не интеграция.** MACRO CRM строится как самостоятельная замена. AmoCRM-импорт (эпик 9) нужен только для миграции исторических данных и параллельной работы N недель до switch-а.
- **12-эпиковый roadmap утверждён** (эпики 0-12, см. PLAN.md). Текущая фаза: эпик 0 (закрытие хвостов) → эпик 1 (Contact + Company + Lead).
- **Counterparty будет разделён** на `Contact` + `Company` в эпике 1. Сейчас одна сущность — это исторический долг.
- **Lead-сущность отдельно от Deal** — введём в эпике 1 с собственным Lead pipeline.
- **Sales pipeline 14 этапов AmoCRM-style уже в проде:** INBOUND Leads → Outbound leads → Неразобранное → qualification → schedule a meeting → walking → Meeting → cold deals → warm deals → Trial → HOT deals → success → lost. (`Pipeline.kind="sales"`)
- **CS lifecycle pipeline уже в проде:** B0-B6 (внедрение) / A1-A6 (активные стадии) / C0 (отвалившийся, C1→C0 mapping). (`Pipeline.kind="lifecycle"`)
- **Renewal pipeline** — пока нет, появится в эпике 6 (`Pipeline.kind="renewal"`).
- **ApprovalRoute / ApprovalStep / Approval уже существуют** — используются в договорах, развивать в эпике 3.
- **OnlyOffice DocServer активирован** через nip.io workaround (`office-153-80-193-132.nip.io`). DocEditor работает, но эмитит нефатальный `onError` event (требует диагностики через `JSON.stringify` в будущем). Не блокирует прод.
- **React.memo фикс для OnlyOffice editor container** отгружен (`HEAD = 07d1959`).
- **Фаза 4 CS реестра (волны a/b/c/e) + расширенная аналитика + Excel экспорт + Фаза 5 OnlyOffice WYSIWYG для master_skeleton.docx** — всё в проде. 121+ контрагентов / 128 подписок импортировано.
- **Прод-конфиг:** 2 healthy api replicas + web + db + onlyoffice (по профилю), `contracts.macroglobal.tech` через Traefik+LE.
- **Тестовые креды:** dev `admin@example.com / admin`. Prod read-only — `b.yadykin@macroglobaltech.com`.
- **Кодовая база называется «MACRO Contract generator»** исторически (до 30 мая 2026 продукт был Contract Generator). **Не переименовываем** — слишком много рисков (CI/CD, docker volumes, VPS paths, GitHub remote).
- **Деплой = `git push origin main`** → GHA `Deploy` workflow → rolling-restart на VPS. Никакого dev-branch автодеплоя.
- **MCP для QA — Claude_in_Chrome** (`mcp__Claude_in_Chrome__*`), не Playwright. Playwright MCP в окружении НЕ установлен.

---

## 🧠 Continuity (Brain Protocol v3.0)

Память сессии живёт в Obsidian vault «Contracts MACRO» (`.claude/brain.conf` → `vault_name=Contracts MACRO`). Хуки (`~/.claude/hooks/`) сохраняют и восстанавливают контекст.

- **Рабочая память — ОДИН файл:** `4. Активная работа/SESSION_STATE.md`. Дубль не создавать (при реорганизации vault — `mv`, не copy).
- **Параллельный чат:** STATE общий на проект. В параллельной сессии — ПЕРЕД работой перепиши `→ Следующий шаг:` под свою задачу, иначе после компакта подхватишь чужой шаг. parallel-guard на старте предупредит, если STATE недавно трогала другая сессия.
- **В начале сессии / на «продолжай»** — сначала читаю SESSION_STATE.
- **После сжатия контекста:** читаю SESSION_STATE → нахожу строку `→ Следующий шаг:` → продолжаю С НЕГО. Первое сообщение = «Далее — [действие]». Не спрашиваю «на чём остановились», не пересказываю, не меняю план. `compression_count` поднимает pre-compact хук.
- **Durable-память по матрице-роутеру:** журналы → `3. Журнал/ГГГГ-ММ/`, планы/эпики → `5. Планы/`, доки модулей (для разработчика) → `2. Модули/`, обзор/стек → `1. Проект/`, **продуктовая база знаний для людей (маркетинг/менеджеры/обучение юзеров) → `7. Вики/`** (правила — skill `brain-protocol` / `references/wiki-guide.md`). Перед правкой модуля — читать `2. Модули/<m>` + свежий `3. Журнал`; после завершённого юнита — писать запись в `3. Журнал`; при релизе фичи / смене UX — обновлять `7. Вики`.
- **Узкий скоуп:** готовые/«хорошие» файлы править точечно (`Edit`), без широких регенеративных проходов.
- Полная методика — skill `brain-protocol` (репо `~/Desktop/Claude/Claude Brain/`).

---

Конец гайда. Если непонятно — читай PLAN.md. Если вопрос про конкретный модуль — открой md соответствующего агента.
