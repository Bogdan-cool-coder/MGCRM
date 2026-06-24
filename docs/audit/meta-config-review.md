# MGCRM — Ревизия мета-слоя (агенты, инструкции, структура)

> Дата: 2026-06-24. Метод: 4 параллельных критика прочитали все `.claude/agents/*.md`, `CLAUDE.md`, `ARCHITECTURE.md`, `PLAN.md`, `README`, `design-handoff/`, скилл `macroglobal-design`, конфиги `.claude/`, и сверили их с **реальностью кода** (см. `docs/audit/00-MASTER.md`). Найдена 51 проблема (14 high / 17 med / 20 low).
> Главный вывод: **оркестрация-воркфлоу совпадает с тем, что мы реально делаем; дрейф — в спец-контенте (что агенты считают правдой о коде) и в `PLAN.md`.**

---

## Часть A. Отклонения и устаревшие сценарии

### A1. RBAC-дрейф — описан spatie, в коде role-enum Gates (HIGH, повсюду)
Все 17 агентов + `CLAUDE.md` + `ARCHITECTURE.md` + `PLAN.md` описывают авторизацию как **spatie/laravel-permission** (Policy + `$user->can()` + permission-middleware, «6 ролей + финправа»). Аудит и live-grep: spatie **мёртв** (0 использований `permission:`/`hasPermissionTo`), реальная авторизация — **enum-Gates на колонке `users.role`** (двойной источник роли).
- `product-manager.md:42` при security-ревью проверяет код против несуществующей модели.
- `ARCHITECTURE.md:59` (§3) и §7 «чёрный список» **запрещают** inline-проверку роли — а рабочий код именно так и устроен. Прямое противоречие закона и кода.
- **Фикс:** принять решение (IAM-1: оживить spatie-on-Sanctum ИЛИ узаконить Gates и удалить таблицы spatie), переписать `ARCHITECTURE.md §3` + футер агентов + чек-лист PM под реальную модель; зафиксировать dual-role как долг.

### A2. Висячие ссылки на несуществующие эталоны (HIGH/MED)
`Staffory`, `Touchlink`, `cloud-terminal`, голый путь `old/` упоминаются как референсы — **их нет в репо** (есть только `examples/vizion/` + `examples/contracts/`).
- `CLAUDE.md:110` «Vizion > Staffory > Touchlink» — 2 из 3 битые. `CLAUDE.md:76` «темп как Staffory/cloud-terminal».
- `PLAN.md` §0/§1/§3/§4 — 7 ссылок на эти проекты как «вторичные источники».
- `automation-specialist.md:16` — «`old/` (FastAPI)» вместо `examples/contracts/`.
- **Фикс:** оставить единственный реальный эталон стека — `./examples/vizion/`; бизнес-логика — `./examples/contracts/`; остальное удалить/в исторический футнот.

### A3. Агенты и контексты, которых нет в коде (MED)
`cs-specialist`, `finance-specialist`, `analytics-specialist`, `integration-specialist` утверждают `Domain/{CustomerSuccess,Finance,Analytics,Integration}` — **этих папок нет**. При этом `ARCHITECTURE.md §2` и `PLAN.md §4.2` их перечисляют, но **пропускают существующие** `Log`, `SalesPulse`, `Migration`.
- **Фикс:** cs/finance — пометить «greenfield, папки ещё нет»; analytics/integration — «сейчас вшиты в Sales/Inbox/Notification»; добавить Log/SalesPulse/Migration в каноничный список контекстов.

### A4. Устаревшая система координат вех + порядок работы (HIGH/MED workflow-drift)
- Агенты привязаны к `M0..M12`, `CLAUDE.md` — к именам спринтов; не согласованы (onboarding = «M12» в `cs-specialist`, «Спринт 3» в своём файле).
- `PLAN.md §5` декларирует строгий порядок `Фундамент→Продажи→Документы→Онбординг→CS→Финансы`, а по факту работа идёт **redesign-треками** (DS-4..7, Навигация, Entity Card 2.0, Bug Packs) + **SalesPulse (off-roadmap, LIVE в проде)**.
- **Фикс:** одна система координат; строку «текущий фокус»; либо вписать redesign/bug-треки в модель, либо пометить строгий порядок как приостановленный.

### A5. PLAN.md — главный очаг дрейфа (несколько HIGH)
1. **Помечено DONE то, что аудит показал мёртвым:** `M0.4` visibility-scope (ResolveVisibility — заглушка), `M5/N6` генерация договоров (template_versions=0 → всё 422), `M7` «ЗАКРЫТ ЦЕЛИКОМ» (движок ни разу не запускался), FX, discount в деньгах, spatie RBAC, company-merge.
2. **Нет статуса «built, not verified live».** `[x]` смешивает «код смержен + SQLite зелёный» с «работает в проде» — ровно тот разрыв, что вскрыл аудит. Счётчики тестов («2031 PASS») используются как доказательство готовности, хотя зелёный SQLite сосуществует с боевыми блокерами.
3. **Структурный беспорядок:** ~880 из ~1133 строк — хронологический changelog слайсов с **противоречивыми статусами** (DS-6 одновременно «DONE / PM APPROVE» и «QA FAIL — 11 дефектов»). Скелет вех (~250 строк) утоплен. Открытые QA-баги лежат под `[x]`-заголовками.
4. **DoD без live-verify гейта** (§2/§9) — корень всех «готово, но не работает».
- **Фикс:** PLAN.md ужать до <300 строк (вехи + acceptance + компактная таблица статусов); changelog → в vault; ввести статус-enum `planned/in-progress/QA-fail/done-merged/verified-live`; добавить DoD-уровень **verified-live**.

### A6. Конфиг и гигиена агентов
| Находка | Severity | Фикс |
|---|---|---|
| `.append.md` патчи дизайн-системы уже вмержены в живые агенты → осиротевшие дубли | low | пометить applied / в архив |
| `qa-tester.md` завязан на **Playwright MCP, которого на этой машине нет** (есть Claude_in_Chrome / chrome-devtools / Control_Chrome / Claude_Preview) | med | поправить fallback на реальные браузерные MCP |
| `guard-destructive.sh` не покрывает `migrate:fresh` / `db:wipe` (агент может снести dev-БД) | low | добавить паттерны (allow только на scratch-БД) |
| 5 разбросанных `.claude/` корней (root, src/, front/, **front/src/components/Orbita/**, examples/contracts/) | med | консолидировать в один root; удалить Orbita-артефакт |
| `README.md:3` «15 агентов» vs 17 в дереве и по факту | med | → 17 |
| `src/README.md` — сток-Laravel README (советует ставить `laravel/boost` — против закрытого стека) | low | заменить на 2-строчный указатель |
| `brain.conf` | — | корректен (проверено) |

### A7. Design-handoff, docs, скилл
- **design-handoff specs** поданы как «реализуем ИМЕННО это» (`CLAUDE.md:59`), но экраны (Contacts, Entity card, Deal card, Sales funnel) **уже зашипперены** → теперь исторический референс, не TODO. Пометить `STATUS: SHIPPED`.
- specs/START-HERE указывают на **несуществующий путь** `front/src/pages/crm/ContactsPage.vue` (реально `front/src/pages/ContactsPage/`). Исправить find/replace.
- `CLAUDE.md:62` + SETUP обещают **husky pre-commit** для `lint:ds`, но `.husky/` в репо **нет** — либо поставить husky, либо поправить доку на «CI-only».
- `lint:ds` в доке описан одним `.stylelintrc.json`, в репо — два конфига (`.stylelintrc.vue.json` + `.stylelintrc.scss.json`). Обновить SETUP.
- `QA-backend-backlog-report.md` устарел, перекрыт `docs/audit/` → переместить в docs/audit как исторический или удалить.
- **4 претендента** на «source of truth визуала» (vault-doc / skill / design-handoff / `front/src/theme`) — задать одну явную цепочку и продублировать в README скилла.
- `src/{QUIZ_GEN,TEMPLATE_CHECK,TUTOR}*.md` — **НЕ мусор**: runtime-ассеты (`base_path()`), не трогать (можно добавить шапку-комментарий).
- `docs/DEPLOY.md` — точен (mgcrm.macroglobal.tech, Traefik proxy, letsencrypt, /opt/mgcrm). Дрейфа нет.

---

## Часть B. Структура папок

**Вердикт: код модулен и в порядке. Хаос — в локальном/мета-слое, не в коде и не в git.**

### Что хорошо (твой страх «всё в одной куче» не подтверждается)
- **Backend:** `src/app/Domain/<Context>/` — 14 доменов, каждый с `Models/Services/Enums/Policies/Jobs/Data/...`; контроллеры разложены по под-папкам `Http/Controllers/<Context>/`. Клод чинит конкретный домен, не задевая остальные.
- **Frontend:** `pages/<Page>/` (каждая страница — папка со своими `components/composables`) + слои `api/ stores/ entities/ application/ composables/ components/ layouts/` — по `ARCHITECTURE.md`.
- **Docker:** фронт (`frontend`/Vite) и бэк (`app`/PHP-FPM) — **разные контейнеры** с примонтированными volume и hot-reload. Правка бэка не трогает фронт; полная пересборка нужна только при смене зависимостей/Dockerfile. **«Пересборки всей кучи» нет.**
- **git:** дубли `* 2` **не трекаются** (0 в индексе); junk (`data/`, `qa_screenshots_s3/`, `4_active/`, `**/.claude/agent-memory/`) — в `.gitignore`.

### Что хаос (локально; мешает агентам и тулингу, но не коммитится)
- **29 cloud-sync дублей `* 2/`** в исходниках (SalesPulse ×8, Migration ×6, front-страницы, design-skill ×5) + **`.git/index 2`** (опасно — может путать git). Источник — рабочая папка под облачным синком. **Главная грязь.**
- **5 разбросанных `.claude/`** — фрагментирует память агентов; `front/src/components/Orbita/.claude/` — артефакт запуска агента с неправильным cwd.
- `.claude/agent-memory/` — сотни файлов статусов слайсов (gitignored, но локально разрослось; многое после аудита устарело).
- Корневой cruft: `4_active/`, `qa_screenshots_s3/` (41), `data/backups`, `qa_fixture.docx`/`test_*.docx`.
- **Не закоммичено, но стоит:** `docs/audit/` (этот аудит) и часть `design-handoff/redesign/` (`DealCard-spec`, `HANDOFF`, `SalesFunnel-*`, `*.html`).

### Рекомендации по структуре
1. **Вынести проект из облачной синк-папки** (или исключить из синка) — это устранит источник `* 2`-дублей раз и навсегда. Иначе — регулярный скрипт-чистильщик `find . -name '* 2' -not -path '*/node_modules/*' -delete` перед тестами/коммитами и удалить `.git/index 2`.
2. **Схлопнуть `.claude/` в один root**; удалить `front/.claude`, `src/.claude`, `front/src/components/Orbita/.claude` (память — в корневой `.claude/agent-memory/`).
3. Добавить в `.gitignore` правило для `* 2`-дублей (страховка) и почистить корневой cruft.
4. Закоммитить `docs/audit/` и хвост `design-handoff/redesign/`.

---

## Приоритетный бэклог правок мета-слоя
| # | Приоритет | Правка |
|---|---|---|
| 1 | P0 | Решить IAM-1 (Gates vs spatie) и переписать `ARCHITECTURE.md §3` + футер агентов + чек-лист PM под реальную авторизацию |
| 2 | P0 | `PLAN.md`: ввести статус `verified-live`, переснять ложные DONE (visibility, договоры, M7, FX, discount), ужать до <300 строк, changelog → vault |
| 3 | P1 | Убрать Staffory/Touchlink/cloud-terminal/`old/` из CLAUDE/ARCHITECTURE/PLAN/automation-agent |
| 4 | P1 | Синхронизировать список доменов/контекстов (добавить Log/SalesPulse/Migration; cs/finance = greenfield; analytics/integration = folded) |
| 5 | P1 | Выбрать одну систему вех (спринты vs M-номера), добавить «текущий фокус» |
| 6 | P2 | Вынести проект из облака / чистить `* 2`; схлопнуть `.claude` корни |
| 7 | P2 | `qa-tester` MCP-fallback; `guard-destructive.sh` + `migrate:fresh`; README 15→17; `src/README.md` |
| 8 | P2 | design-handoff: STATUS: SHIPPED + поправить пути `pages/crm/`; husky-или-CI для lint:ds; убрать .append-дубли; единая цепочка source-of-truth визуала |
