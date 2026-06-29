# CLAUDE.md — MACRO Global CRM (always-context)

> Мозг проекта **MACRO Global CRM**: переписываем огромную CRM (FastAPI+Next.js) на **жёсткий стек Laravel + PrimeVue по эталону Vizion**, домен за доменом.
> Этот файл лёгкий и always-injected. Жёсткие паттерны кода — в **`ARCHITECTURE.md`** (закон проекта). План — в `PLAN.md`. Эталон стека целиком лежит в `./examples/vizion/`.

## Brain-vault проекта

**Планы, прогресс, решения, спринт-детализации** — Obsidian-vault **`MG CRM 2026`** (`~/Documents/Obsidian Vault/MG CRM 2026`, путь прописан в `.claude/brain.conf`).
- В начале рабочей сессии читать `4. Активная работа/SESSION_STATE.md` — там текущий контекст.
- Спринт-роадмап: `5. Планы/MGCRM (Laravel) — Master Roadmap.md`.
- Дизайн-система и токены PrimeVue (vault-справка, вторична): `6. Справочник/Дизайн-система MG CRM (бренд MACRO Global).md` + `6. Справочник/PrimeVue 4 — тема, токены и тулинг (MGCRM).md`. **Основной эталон визуала теперь — skill `.claude/skills/macroglobal-design/` (см. §«Эталон визуала» ниже).**

Старый vault **`Contracts MACRO`** — **АРХИВ/референс** (старые спеки, аудиты, журналы). НЕ редактировать.

## Ключевые решения (зафиксированы 2026-06-11)

- **Перенос данных не нужен** (в старой базе тестовые данные). `migration-specialist` — только cutover + parity-чеклисты.
- **Порядок спринтов: Продажи → Документы → Онбординг → CS → Финансы.** Онбординг — отдельный спринт между Документами и CS.
- **Договоры — генерация PHPWord→Gotenberg→PDF без WYSIWYG-редактора**; на будущее возможна онлайн-правка через Google Docs — прорабатываем ближе к делу.
- **DEALS 2.0 — нет отдельной сущности Lead.** Лид = сделка в стадии «Новые лиды», воронка строится вокруг **Компании** (Deal-on-Company). `Counterparty`/`Lead` из старого проекта — deprecated, в MGCRM не воскрешаем.
- **Бренд-ассеты** (`brand/`) — логотип и брендбук MACRO Global. Тема PrimeVue: styled Aura, `definePreset`, primary `#172747`, prefix `p`, darkModeSelector `.app-dark`, cssLayer true, мост через `var(--p-*)` в SCSS.
- **Google SSO** — не в Фундаменте (там email+пароль+2FA), а на сквозном спринте Интеграций.

## Структура (корень репозитория = проект MGCRM; работа ведётся нелокально)

```
macroglobalcrm/          ← git-репозиторий Bogdan-cool-coder/MGCRM. ⭐ САМ ПРОЕКТ ПИШЕМ ЗДЕСЬ (в корне).
├── CLAUDE.md            ← оркестрация (этот файл)
├── ARCHITECTURE.md     ← ЖЁСТКИЕ паттерны разработки (обязательны, «ни шагу в сторону»)
├── PLAN.md            ← БОЛЬШОЙ план миграции (вехи M0…M12 — исторические ID, замаплены на спринты)
├── .claude/{agents,hooks,settings.json}
├── src/                ← Laravel 13 API
├── front/              ← Vue 3.5 + PrimeVue SPA
├── docker/  docker-compose*.yml  .github/
└── examples/            ← полные копии-эталоны, КОММИТЯТСЯ в репо (нелокальная работа)
    ├── vizion/          📐 ЭТАЛОН СТЕКА — реализацию смотрим здесь, делаем 1-в-1
    └── contracts/       ⚠️ macro-contracts (FastAPI+Next) — ТОЛЬКО ИСТОЧНИК БИЗНЕС-ЛОГИКИ
```

> 🧹 **На закрывающем спринте (cutover):** `examples/` сносится из репозитория. До тех пор это рабочий контекст агентов и должен быть в репо целиком (работа идёт не на этой машине).

## ⚠️ Рабочий цикл агента (железно)

Каждый агент при любой задаче идёт по трём шагам:

1. **Бизнес-логику и поведение фронта смотрит в `./examples/contracts/`** — это ТЗ: модели, поля, связи, эндпоинты, статус-машины, экраны. **Написано на FastAPI/Next.js — код НЕ копируем, копируем смысл (что приложение делает).**
2. **Технический паттерн/реализацию смотрит в `./examples/vizion/`** (полная копия Vizion) — как ровно это сделано на нашем стеке: структура, сервисы, миграции, контроллеры, Vue-компоненты, конфиги.
3. **Делает 1-в-1 как Vizion** в корне проекта (`src/`, `front/`), **с единственной поправкой — деление по DDD `app/Domain/<Context>`**. Не изобретай — копируй Vizion.

Конфликт: **стек → `./examples/vizion/`**, **бизнес-логика → `./examples/contracts/`**.

## 📐 Закон проекта: ARCHITECTURE.md

**Любой код — строго по `ARCHITECTURE.md`.** Это не рекомендации, а паттерны: backend-слои (FormRequest → тонкий Controller → Domain Service → Model → API Resource), DDD-границы, деньги-копейки, **авторизация только через Policy/Gate** (никогда inline `if($user->role===…)`; цель — permission-модель spatie, сейчас — enum-Gates, долг IAM-1), frontend-слои (api → composables/async → page-composable → component → Pinia), именование, тесты, чёрный список. Отклонение = баг; `product-manager` режет такой код на ревью. Перед кодом агент читает релевантный раздел ARCHITECTURE.md.

## 🎨 Эталон визуала: дизайн-система MACRO Global (design-handoff)

**Одна явная цепочка source-of-truth визуала (по типу вопроса):** значения токенов → `front/src/theme` (код, единственный источник) · визуальный замысел/лейаут конкретного экрана → спеки+мокапы `design-handoff/redesign/` · бренд-инварианты/общая дизайн-система → skill `macroglobal-design` (`.claude/skills/macroglobal-design/`). Vault-спека `MG CRM 2026` и «визуальный» Vizion — вторичны/исторические. **Иерархия эталонов в целом:** визуал/бренд → эта цепочка; структура кода/паттерны → Vizion + ARCHITECTURE.md; состав фич/поведение → `./examples/contracts/`.
- **Живой индекс апрувнутых мокапов + ТЗ — `design-handoff/redesign/HANDOFF.md`** (актуальный перечень экранов и их статус). Есть макет — реализуем ИМЕННО его; статус (зашипперено/в работе) сверяем по HANDOFF.
- **Токены:** значения системы (`--mg-*`) ⇄ переменные репо (`$primary-900`/`$surface-*`/`--p-*`); в `.vue`/`.scss` пиши переменную репо, НЕ литерал. Бренд-инварианты (сайдбар `#172747`, шапка карточки сделки `#172747`) — единственные допустимые хардкоды.
- **Жёстко:** никаких hex/px мимо токенов · только PrimeIcons (`pi pi-*`) · без эмодзи/градиентов/bluish-purple/цветных теней · обе темы (light+dark) обязательны · деньги `1 200 000 ₽`.
- **Контроль:** `npm run lint:ds` (stylelint, pre-commit+CI) + **обязательный визуальный гейт `qa-tester`** (computed-styles в обеих темах; визуальное отклонение = FAIL). Агенты `designer`/`frontend-specialist`/`qa-tester` пропатчены под систему.

## Целевой стек (жёсткий — см. PLAN.md §3)

**Backend:** Laravel **13** / PHP **8.5** · PostgreSQL 16 · **Sanctum** (Bearer personal access token, как Vizion; фронт хранит токен) · **TOTP 2FA** + **RBAC** (цель = **spatie/laravel-permission**: 6 ролей `admin/director/lawyer/manager/accountant/cfo` + гранулярные права, через Policy/`$user->can()`/permission-middleware на guard `sanctum`) · spatie/translatable · spatie/backup · **Prism** (AI-каскады, `config/ai.php` как Vizion) · **PHPWord + Gotenberg** (договоры→PDF) · Redis (очереди, **БЕЗ Horizon**) · **PHPUnit + SQLite :memory:**.

> **RBAC — состояние (честно, долг IAM-1):** spatie — целевая/каноничная модель. **Сейчас** авторизация реально идёт через **enum-Gates на колонке `users.role`**; таблицы spatie засеяны, но НЕ подключены (права висят на guard `web`, Sanctum их не видит). План IAM-1: подключить spatie на guard `sanctum` и перенести Gate-проверки в права; до этого новый authz-код идёт **через Policy/Gate** (никогда inline `if($user->role===…)`), целясь в permission-модель; `users.role` — переходный двойной источник, удаляется после миграции.

**Frontend:** Vue **3.5** + TS strict · Vite · Pinia · Vue Router · **PrimeVue 4.5** + **Bootstrap-grid + SCSS** · PrimeIcons · **ECharts** · vue-i18n · axios.

**Организация:** **DDD `app/Domain/<Context>/{Models,Data,Enums,Services,Jobs,Policies}`**.

**Сознательно НЕТ:** Tailwind · Inertia · Livewire · Filament · Chart.js · Horizon · VeeValidate/Zod · spatie/laravel-data (ручные API Resources как Vizion) · Fortify · Pest.

## Стратегия и темп

Strangler, вертикальными срезами, домен за доменом. **Темп — milestone-стиль:** vertical slice + day/week-оценка + Acceptance-чеклист, «копируем Vizion 1-в-1». **Первичная координата — порядок спринтов по именам:** Фундамент → Продажи → Документы → Онбординг → CS → Финансы. M-номера (`M0…M12`) — только внутри `PLAN.md` как исторические ID вех, замапленные на эти спринты; в координации статус домена выражаем не номером, а реальным состоянием (`построено` / `частично` / `каркас — не работает в проде` / `greenfield — кода нет`). Cutover (снос `examples/`) + финальный паритет — на закрывающем спринте (перенос данных не нужен — тестовые). Детали — PLAN.md §5 и vault `MG CRM 2026`.

> **Текущий фокус:** redesign-hardening (DS-треки, Entity Card 2.0, Навигация) + audit-driven bugfix по `docs/audit/` + SalesPulse (off-roadmap, LIVE в проде). Строгий порядок спринтов на этот период приостановлен в пользу redesign/bug-треков.

## 17 агентов (`.claude/agents/`)

> **Все агенты — `bypassPermissions`** (рутина — docker/artisan/npm/git/Edit/Write/MCP, включая браузерные MCP-действия qa-tester'а — выполняется молча). Единственный жёсткий ограничитель — PreToolUse-хук `guard-destructive.sh` на критичный деструктив (работает и под bypass). Поведенческие правила (`frontend-specialist` и push у `deploy-engineer` — только по явной просьбе) остаются в силе как инструкции, не как пермишен-промпты.

**Кросс-функциональные (6):** `designer` (ТЗ, без кода) · `backend-specialist` (Laravel-ядро: auth/Sanctum/2FA, базовые модели, миграции, тесты — для всех) · `frontend-specialist` (Vue/PrimeVue/Pinia/Bootstrap-grid — **только по явной просьбе**, как у Vizion) · `qa-tester` (браузерные MCP этой машины — Claude_in_Chrome / chrome-devtools / Control_Chrome / Claude_Preview; Playwright MCP на этой машине нет) · `product-manager` (ревью + verify против ARCHITECTURE.md/PLAN.md) · `deploy-engineer` (Docker/GHA/SSH — владелец деплой-конфига и git-push в `main`; push/деплой — **только по явной просьбе юзера**; после push выкатка автоматическая через GHA `deploy.yml`; изменения деплой-инфры — **только по явной просьбе**).

**Доменные (10) — спринт + реальный статус, НЕ M-номер:**
- `crm-specialist` (спринт Фундамент/Продажи: Contact/Company/Catalog/CustomFields/дедуп — построено).
- `sales-specialist` (спринт Продажи: Pipeline/Deal/Kanban/KPI/Lead/Activity — построено; SalesPulse — LIVE).
- `contract-specialist` (спринт Документы: генерация PHPWord→Gotenberg — каркас, не работает в проде, см. аудит).
- `cs-specialist` (спринт CS — greenfield: `Domain/CustomerSuccess` ещё нет, создать при старте спринта).
- `finance-specialist` (спринт Финансы — greenfield: `Domain/Finance` ещё нет, создать при старте спринта).
- `automation-specialist` (спринт сквозной: каркас — движок ни разу не запускался в проде, см. аудит).
- `integration-specialist` (сквозной: standalone `Domain/Integration` ещё нет — работа сейчас вшита в Inbox/Notification).
- `analytics-specialist` (сквозной: standalone `Domain/Analytics` ещё нет — работа сейчас вшита в Sales).
- `bot-specialist` (сквозной).
- `onboarding-specialist` (спринт Онбординг: курсы/квизы/прогресс/AI-тьютор — backend построен, student-loop сломан, см. аудит).

**Миграция (1):** `migration-specialist` (паритет old↔new, перенос схемы/данных, cutover на закрывающем спринте).

> **Контексты `src/app/Domain/` (14, реально):** Activity · Automation · Catalog · Contracts · Crm · Iam · Inbox · Log · Migration · Notification · Org · Onboarding · Sales · SalesPulse. (`CustomerSuccess`/`Finance` — greenfield; `Analytics`/`Integration` — пока вшиты в Sales/Inbox/Notification.)

## Workflow (как у Vizion)

```
[задача] → [main определяет агента и порядок] → [рабочий агент(ы): backend→domain→frontend→qa→PM]
        → [если был UI у frontend-specialist → qa-tester] → [product-manager: саммари+ревью+verify+sync PLAN.md]
        → [апрув юзера] → [main коммитит локально] → [deploy-engineer: git push в main по явной просьбе → авто-деплой на прод через GHA deploy.yml]
```

> **Деплой-политика (с 2026-06-29):** push в `main` делает **`deploy-engineer` ТОЛЬКО по явной прямой просьбе юзера** (main не пушит); сам push автоматически триггерит прод-деплой через `deploy.yml` (SSH → `git reset --hard origin/main` → `rolling-restart.sh`: force-recreate app (короткий блип ~секунды) + health-wait + `migrate --force` в новом контейнере + health-check `nginx/up`). **Исключение:** пуши, затрагивающие только `**.md` / `docs/**` / `.claude/**`, прод НЕ деплоят (`paths-ignore`). Ручной запуск через `workflow_dispatch` сохранён как фолбэк. CI-ключ `id_ed25519_mgcrm_deploy` на VPS; приватный — в GH-секрете `SSH_PRIVATE_KEY`. **Дисциплина:** WIP и непроверенный код в `main` не пушим — каждый code-push в `main` идёт в прод автоматически. Изменения деплой-инфры — только `deploy-engineer` по явной просьбе.

> `migration-specialist` участвует только на закрывающем спринте (cutover: снос `examples/` + per-domain parity-чеклисты). Перенос данных не нужен (тестовые).

**qa-tester НЕ нужен** при backend-only, рефакторинге без UI, правках только `.md`/`.yml`/`.json`, правках самих агентов/CLAUDE/PLAN/ARCHITECTURE.

## 🚨 Правило делегирования (приоритет №1)

**Делегируй ВСЁ кроме whitelist'а — по умолчанию.** Main — единственный контекст для оркестрации; когда main сам читает код/гоняет команды, он теряет высоту обзора.

**Main делает САМ (whitelist):** 1) диалог (AskUserQuestion, summary); 2) оркестрация tasks; 3) делегирование (Agent); 4) локальный git `status/diff/log/add/commit` (без push) после PM-аппрува; 5) запись секретов в `src/.env`; 6) чтение memory/CLAUDE.md/PLAN.md/ARCHITECTURE.md для ориентации; 7) минимальный Read/Grep для маршрутизации (НЕ для починки бага — это зона агента).

**Анти-паттерны main (запрещено):** ❌ Read src-файла «понять класс» · ❌ grep ради поиска бага · ❌ `docker compose exec … artisan …` напрямую · ❌ ssh · ❌ править «по мелочи» код/тесты/.md.

## Правила (железные)

- **`ARCHITECTURE.md` — закон.** Любой код строго по нему. Эталон паттернов — `./examples/vizion/`. Единственный источник при конфликте стека — `./examples/vizion/` (других эталонов стека в репо нет).
- **`./examples/contracts/` — только ТЗ по бизнес-логике** (FastAPI/Next — код не копируем).
- **Стек жёсткий** (PLAN §3). Пакетов вне списка не добавлять без явной просьбы. Никакого Tailwind/Inertia/Horizon/Pest/VeeValidate.
- **Library-first** (ARCHITECTURE.md §0.1): весь функционал — на готовых библиотеках. Если задачу закрывает уже подключённая/доступная либа (в проекте или `./examples/vizion/`) — новую НЕ ставим. Свой код — только когда готового нет. Новый пакет — лишь в случае особой необходимости + аппрув.
- **Самодостаточность репо:** работа нелокальная — всё нужное (включая `examples/`) лежит в репозитории. Агенты НЕ ссылаются на внешние пути вне репо.
- **Изоляция тестов:** PHPUnit строго в SQLite `:memory:` (тройная защита как Vizion). Тесты НИКОГДА не ходят в живую БД.
- **Commit — только English.** **НИКАКИХ** `Co-Authored-By: Claude`, упоминаний Claude/Anthropic/AI/🤖. Никаких `--no-verify`, `--force` push.
- **Деструктив — только по явной просьбе + бэкап** (`down -v`, `volume rm`, `DROP`, `rm -rf` данных). Guard-хук блокирует под bypass.
- **Секреты не светим** — значения в `src/.env` пишет main.
- **`docker compose`**, не `docker-compose`. PHP/composer на хосте нет — через docker (bootstrap: `docker run --rm -v "$(pwd):/app" -w /app composer:latest …`).
- **Деплой/push:** push в `main` делает `deploy-engineer` ТОЛЬКО по явной прямой просьбе; push в `main` автоматически катит прод (GHA `deploy.yml`), кроме пушей только по `**.md`/`docs/**`/`.claude/**`. main не пушит. WIP в `main` не пушим. Изменения деплой-инфры — через `deploy-engineer` по явной просьбе. Локальный rebuild допустим.
- При расхождении PLAN.md/ARCHITECTURE.md ↔ реальность — `product-manager` обновляет документ (с аппрувом), не молчим.
