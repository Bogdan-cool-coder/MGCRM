# Аудит домена: SalesPulse — AMO-бот контроля (снапшоты, дневной статус, пропуски)

## 1. Назначение

SalesPulse — это нативный PHP-порт (vault DECISION-3 = путь B, «копируем AMO 1-в-1») надзорного Telegram-бота над отделом продаж. Бизнес-задача: ежедневно фиксировать у каждого менеджера утренний **PLAN** и вечерний **FACT** дня (снапшоты по активностям/сделкам), отслеживать дневной статус (зафиксировал/не зафиксировал, кем — вручную или авто), вести отпуска/выходные (skip/vacation), постить в командный чат прогресс, дневные итоги, недельный отчёт и автоматические «поздравления» по закрытым встречам и выигранным сделкам (announcer). Управление — целиком через Telegram-команды и Laravel-планировщик; **REST/HTTP-поверхности и фронтенда у домена нет вообще** (это сознательное решение по vault).

**Зрелость: частично (код зрелый, в проде по памяти LIVE — но в dev полностью инертен).** Обоснование: код-база полная и протестированная (по vault — 183 теста SalesPulse, Slice 0–4 BUILD COMPLETE + APPROVED), все 4 таблицы (`pulse_snapshots`, `pulse_daily_status`, `pulse_skip_days`, `pulse_announced_events`) присутствуют в живой схеме и точно совпадают с моделями/миграциями. **Однако все 4 таблицы пусты (0 строк в живой dev-БД)** — это НЕ баг, а конфигурационное состояние: `SALESPULSE_TEAMS_JSON` не задан (`teams=[]`), `SALESPULSE_TEST_MODE`/`SALESPULSE_RUN_POLLING`/`SALESPULSE_BOT_TOKEN` не заданы, контейнер `salespulse-bot` в dev не запущен. Поэтому все 12 cron-джоб итерируют ноль команд и no-op'ят, а поллинг не работает — фича сконфигурирована «выключенной» в dev. По проектной памяти бот реально LIVE в проде на отдельном сервере со своим env (это нужно подтвердить отдельно — паритет prod-env не верифицирован в рамках этого аудита).

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI-экран + endpoint) | Как (кратко шаги) | Статус | Примечание |
|---|---|---|---|---|---|
| Утренний захват PLAN (вручную) | roster-менеджер (себе), team-admin (любому) | нет UI · TG `/startday [manager] [date]` → `StartdayHandler` | resolve team → target (admin для чужого) → weekend short-circuit → `DaySnapshotService.collectDay` → `SnapshotRepository.savePlan` (WRITE-ONCE) → stamp `daily_status.plan_at/plan_source` → `PlanRenderer` | ✅ работает | Код полный, протестирован. В dev не триггерится (teams=[]); по памяти LIVE в проде |
| Вечерний захват FACT (вручную) | roster-менеджер (себе), team-admin | нет UI · TG `/finishday [manager] [date]` → `FinishdayHandler` | `collectDay` (вечер) → `SnapshotRepository.saveFact` (UPSERT) → load PLAN → `NotesService.dealIdsWithNoteToday` → `MetricsService.compute` → stamp `fact_at` → `FactRenderer` | ✅ работает | `saveFact` намеренно upsert (перезаписывает прошлый FACT). В dev не триггерится |
| Авто-фиксация plan/fact | система (scheduler) | нет UI · `AutoCapturePlanJob` 10:15 / `AutoCaptureFactJob` 19:45 (`routes/console.php`) | `RosterResolver.today` + weekday guard → по командам (skip если team-skipped) → по менеджерам без `plan_at` → `collectDay` → `savePlan(AUTO)` → пост `[auto]` | ✅ работает | No-op в dev: ноль команд. Зарегистрировано в `schedule:list` |
| Announcer (поздравления + дедуп) | система (scheduler) / team-admin (`/announce_now`) | нет UI · `RunAnnouncerJob` каждые 5 мин 09–20 Mon-Fri, либо TG `/announce_now` | по командам: сканировать завершённые FTM-встречи (15-мин свежесть) + переходы `DealStageHistory` в is_won → отфильтровать чужие/skip → INSERT `pulse_announced_events` ПЕРВЫМ (unique) → при свежем insert пост HTML; `QueryException` (гонка) → skip | 🟡 частично | Дедуп race-safe, НО `record()` постит ПОСЛЕ закоммиченного insert без отката при сбое отправки → потерянное поздравление + «призрачная» строка (см. бэклог). No-op в dev |
| Управление skip/vacation | team-admin | нет UI · TG `/skipday /unskipday /vacation /unvacation` → `SkipHandler` → `SkipService` | AdminGate → `parseArgs [date,slug]` → запись/удаление `pulse_skip_days`; vacation = по строке на рабочий день в span с общим `vacation_until`; <2 раб. дней — откат | 🟡 частично | Баг коллизии kind в `SkipService::vacation`: pre-existing 1-day `/skipday` внутри span остаётся `kind=skip` и мислейблится в `/progress` (см. бэклог, MAJOR) |
| Дневные итоги и недельный отчёт (SLA) | система (scheduler) + team-admin on demand | нет UI · `PostDayResultsJob` (08:30 Tue-Fri / 20:00 Fri) + `PostWeeklyReportJob` (Mon 09:00); TG `/dayresults /weeklyreport /conversions` | агрегировать снапшоты + SLA-пороги из `config('salespulse.stages')` → рендер (Prism Haiku для dayresults, Sonnet tool_use для weekly) → пост в чат | ✅ работает | No-op в dev (teams=[]). LLM через Prism/`config/ai.php` по vault §8 |
| Telegram long-polling бот | система (контейнер `salespulse-bot`) | нет UI · `php artisan salespulse:run` → `RunBotCommand`, gated `config('salespulse.bot.run_polling')` | nutgram `getUpdates`-цикл диспатчит в command-handlers. Пустой токен → idle. Должен крутиться ровно в одной реплике или Telegram 409 | ⚪ не верифицирован | В dev контейнера `salespulse-bot` нет; `SALESPULSE_RUN_POLLING`/`SALESPULSE_BOT_TOKEN` не заданы. Статически нельзя подтвердить single-replica в проде |
| Web/admin-видимость pulse-данных (skip/vacation/снапшоты) | нет | нет SPA-роута, нет `api/*`-модуля, нет Pinia-store | n/a — у `pulse_*` ноль web read/write путей; админ не видит отпуска/skip/снапшоты из CRM, только через Telegram | ⚪ отсутствует | Намеренно по vault (SalesPulse = Telegram-only надзор-бот). Помечено, чтобы это было осознанным продуктовым решением |

> Live-QA (Phase 3) не затронул SalesPulse: домен не имеет ни SPA-страниц, ни REST-эндпоинтов, поэтому в браузерном прогоне он не появляется. Все 9 NEW-* из live-QA относятся к CRM/Sales/Onboarding (контакты, сделки, KPI, каталоги, курсы) и к этому домену не относятся. Это согласуется с архитектурой домена (Telegram-only).

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк в живой БД | Статус |
|---|---|---|---|---|
| `PulseSnapshot` | `pulse_snapshots` | Сериализованный утренний PLAN или вечерний FACT дня одного менеджера. `data` jsonb хранит `PulseTaskRow[]` + `leads_by_id`. | 0 | built (пусто — config-off) |
| `PulseDailyStatus` | `pulse_daily_status` | Флаги manager-day: зафиксирован ли plan/fact, кем (manual/auto), счётчики напоминаний. Одна строка на (manager, on_date). | 0 | built (пусто — config-off) |
| `PulseSkipDay` | `pulse_skip_days` | Маркер отпуска/выходного, который чтит scheduler. `manager_id` NULL = team-wide skip; задан = личный. `kind` разделяет skip и vacation; vacation пишет строку на каждый покрытый рабочий день с общим `vacation_until`. | 0 | built (пусто — config-off) |
| `PulseAnnouncedEvent` | `pulse_announced_events` | Ledger дедупликации announcer'а. Одна строка на запощенный `meeting_done`/`success`, чтобы 5-минутный announcer не дублировал между cron-тиками. | 0 | built (пусто — config-off) |

**Расхождения migration ↔ live-schema ↔ model:**

- `pulse_snapshots`: **match** во всех трёх. Колонки `id, manager_id, on_date, kind, source, captured_at, data(jsonb)` + UNIQUE(`manager_id,on_date,kind`)=`uq_pulse_snapshots_manager_date_kind`. Энумы `SnapKind plan|fact`, `SnapSource manual|auto` совпадают.
- `pulse_daily_status`: **match**. UNIQUE(`manager_id,on_date`)=`uq_pulse_daily_status_manager_date`; `plan_at/fact_at/plan_source/fact_source` + счётчики напоминаний присутствуют.
- `pulse_skip_days`: migration↔live↔model **match** (есть `kind` SkipKind skip|vacation и `vacation_until`, INDEX(`on_date`), FK `manager_id` CASCADE). **DRIFT vault↔code**: vault §8 НЕ перечисляет колонки `kind` и `vacation_until`, хотя код и живая схема их имеют. Документ vault устарел (см. §7).
- `pulse_announced_events`: migration↔live↔model **match** (`id, activity_id` uniq, `deal_stage_history_id` uniq, `event_type` meeting_done|success, `manager_id`, `deal_id`, `chat_id`, `posted_at`; FK: activity CASCADE, dsh CASCADE, manager CASCADE, deal SET NULL). **DRIFT vault↔code**: vault §8 перечисляет только `activity_id` uniq; код+живая схема добавляют второй уникальный индекс `deal_stage_history_id` + `event_type` для семантики «Success как переход стадии».

**Пустые-при-наличии-кода таблицы:** все 4 таблицы пусты при полном рабочем коде. Это корректное конфигурационное состояние dev (см. §1 и бэклог-issue про config-off), а НЕ дефект схемы/кода. Все 4 миграции применены, все индексы и FK на месте.

## 4. Эндпоинты и покрытие фронтом

> У домена **нет REST/HTTP-эндпоинтов**. Поверхность — Console-команда, Telegram-команды (TG-CMD) и Scheduler-джобы (SCHED). Ни один не вызывается фронтом по определению — фронта у домена нет.

| Метод+Path | Контроллер/Handler@метод | Авторизация | Вызывается фронтом? | Примечание |
|---|---|---|---|---|
| CONSOLE `php artisan salespulse:run` | `RunBotCommand@handle` | process-level: gated `config('salespulse.bot.run_polling')`; пустой токен → idle; ровно ОДИН контейнер (replicas:1) иначе TG 409 | нет | Single-replica — операционный инвариант, НЕ enforced кодом (см. бэклог) |
| TG-CMD `/start, /help, /whoami` | `InfoHandler` | любой caller в привязанном чате (team по `chat_id` в TEAMS_JSON); чужой чат тихо игнор | нет | — |
| TG-CMD `/startday [manager] [date]` | `StartdayHandler@__invoke` | roster-менеджер себе; чужой target требует admin (`TeamResolver::resolveTargetUser:109-124`) | нет | Пишет `pulse_snapshots` PLAN (write-once) + `daily_status` |
| TG-CMD `/finishday [manager] [date]` | `FinishdayHandler@__invoke` | roster-менеджер себе; admin для чужих | нет | Пишет `pulse_snapshots` FACT (upsert) + `daily_status` |
| TG-CMD `/progress` | `ProgressHandler` | любой caller в team-чате (`$ctx->hasTeam()`). Read-only | нет | — |
| TG-CMD `/dayresults [date]` | `DayResultsHandler@__invoke` | ADMIN only — `AdminGate::passesAdminGate` | нет | — |
| TG-CMD `/weeklyreport` | `WeeklyReportHandler@__invoke` | ADMIN only — AdminGate | нет | — |
| TG-CMD `/conversions` | `ConversionsHandler@__invoke` | ADMIN only — AdminGate | нет | — |
| TG-CMD `/announce_now` | `AnnounceHandler@__invoke` | ADMIN only — AdminGate | нет | Триггерит `AnnouncerService` → пишет `pulse_announced_events` |
| TG-CMD `/skipday [manager] [date]` | `SkipHandler@skipday` | ADMIN only — AdminGate | нет | Пишет `pulse_skip_days` (kind=skip) |
| TG-CMD `/unskipday [manager] [date]` | `SkipHandler@unskipday` | ADMIN only — AdminGate | нет | Удаляет skip-строки |
| TG-CMD `/vacation manager [from] [until]` | `SkipHandler@vacation` | ADMIN only — AdminGate | нет | Пишет `pulse_skip_days` (kind=vacation) по строке/раб. день. Содержит баг коллизии kind (см. бэклог) |
| TG-CMD `/unvacation manager [from]` | `SkipHandler@unvacation` | ADMIN only — AdminGate | нет | Удаляет vacation-строки ≥ from |
| SCHED (12 cron) `RemindPlan 09:30/10:00; AutoCapturePlan 10:15; PostProgress 13:00/16:00; RemindFact 19:00/19:30; AutoCaptureFact 19:45; PostDayResults 08:30 Tue-Fri + 20:00 Fri; PostWeeklyReport Mon 09:00; RunAnnouncer every5min 09-20` | `routes/console.php:78-113` → `App\Domain\SalesPulse\Jobs\*` | нет (system jobs). Каждая итерирует `config('salespulse.teams')`; no-op при `teams=[]`. Queue=redis | нет | Asia/Dubai, Mon-Fri. Крутится в scheduler/queue-контейнерах (без поллинга) |

**Orphaned FE-вызовы / мёртвые эндпоинты:** нет. Единственные TG-related FE-вызовы — `POST /api/me/telegram-link` и `DELETE /api/me/telegram` на `ProfilePage` — относятся к домену **Notification** (`TelegramLinkController`, `routes/api.php:408`, ПЕРВИЧНЫЙ нотификационный бот), а НЕ к SalesPulse (отдельный токен, нет REST). Эти эндпоинты существуют и корректно подключены. Мёртвых вызовов и shape-дрейфа для SalesPulse нет (негативное подтверждение).

## 5. RBAC домена

Авторизация домена **звуковая** и единообразная. Гейтинг идёт по TG-username из конфигов команды (`team.admins` / `team.managers[].tg`), а НЕ через `users.role`/spatie-permission — то есть нет проблемы двойного источника ролей (это особенность надзорного бота: команда определяется привязкой Telegram-чата).

| Действие | Разрешено | Где реально проверяется |
|---|---|---|
| `/startday` `/finishday` для СЕБЯ | любой roster-менеджер (TG-username в `team.managers[].tg`) | `TeamResolver::callerManager` через `resolveTargetUser` (`TeamResolver.php:109-124`) |
| `/startday` `/finishday` для ДРУГОГО менеджера | только team-admin (TG-username в `team.admins`) | `TeamResolver::resolveTargetUser:114` (`isAdmin && slug present`) |
| `/progress` | любой caller в team-чате | `ProgressHandler` (`$ctx->hasTeam()`) |
| `/dayresults` `/weeklyreport` `/conversions` `/announce_now` `/skipday` `/unskipday` `/vacation` `/unvacation` | только team-admin | `AdminGate` trait `::passesAdminGate` (`AdminGate.php:22-35`) — используется всеми 8 хендлерами, каждый проверен |
| команда из чужого чата (не в TEAMS_JSON) | никто — тихо игнорируется | `AdminGate.php:24-26` `hasTeam()==false` → return false, без ответа |
| long-polling `getUpdates` на токене SalesPulse | ровно ОДИН процесс (контейнер `salespulse-bot`, replicas:1) | docker-compose `salespulse-bot` + run_polling gate (`RunBotCommand`). **НЕ enforced кодом** — операционный инвариант |

**Дыры:** в коде RBAC-дыр нет. Единственный не-кодовый риск — single-replica поллинга держится на операционном инварианте (нет advisory-lock/leader-election); при двух поллерах Telegram отдаёт 409 и апдейты теряются (см. бэклог, minor).

> Контраст с live-QA: NEW-5 (`/api/admin/*` доступны роли manager) — это дефект CRM-каталогов, а НЕ SalesPulse. RBAC SalesPulse от него независим (не использует spatie/role).

## 6. Бэклог проблем

### Сводная таблица

| Severity (FINAL) | Тип | Заголовок | Проверка |
|---|---|---|---|
| **major** | BUG | `vacation()` idempotency pre-check игнорирует `kind` → дни застревают как `skip` и мислейблятся в `/progress` | ✅ подтверждено (static probe, conf 0.95) |
| minor | DATA-INCONSISTENCY | Все `pulse_*` пусты, т.к. фича config-disabled в dev (`SALESPULSE_TEAMS_JSON` unset → `teams=[]`) | не верифицировано (Phase-1) |
| minor | BUG | Announcer постит ПОСЛЕ коммита dedup-строки → сбой `sendMessage` оставляет «призрачное» событие, которое никогда не доставляется | не верифицировано (Phase-1) |
| minor | BUG | `SnapshotRepository` savePlan/saveFact используют SELECT-then-INSERT вместо upsert по unique-ключу → необработанный `QueryException` при конкуренции | не верифицировано (Phase-1) |
| minor | BUG | `SkipHandler::secondDate()` ре-токенизирует аргументы независимо от `parseArgs` → порядок токенов может дать until<from с путаным «too short» | не верифицировано (Phase-1) |
| minor | CONVENTION | `salespulse:run` single-replica — операционный, не code-enforced инвариант (риск 409 при дубле поллера) | не верифицировано (Phase-1) |
| minor | MISSING | У SalesPulse нет frontend/admin-поверхности — skip/vacation/snapshot state web-невидим (by design) | не верифицировано (Phase-1) |
| trivial | DEAD-CODE | Нет FE→missing-endpoint мёртвых вызовов и shape-mismatch для SalesPulse (негативное подтверждение) | не верифицировано (Phase-1) |

---

### MAJOR · BUG · ✅ подтверждено (static probe, confidence 0.95)

**`vacation()` idempotency pre-check игнорирует `kind`, дни застревают как `skip` и мислейблятся в `/progress`**

- **Файлы:** `src/app/Domain/SalesPulse/Services/SkipService.php:91-94` (existence-check), `:96-100` (covered++/continue), `:168-184` / `:176` (`vacationUntil()`), `:202-209` (`onVacation()`/`isReturningFromVacation()`); единственный вызывающий — `src/app/Domain/SalesPulse/Telegram/Handlers/SkipHandler.php:102` (`vacation()`), `:104-106` (days<2 rollback).
- **Что происходит (evidence):** `vacation()` проверяет существование строки только по `(on_date, manager_id)` — БЕЗ фильтра по `kind` (`SkipService.php:91-94`: `whereDate('on_date')->where('manager_id')->exists()`). Если у менеджера уже есть однодневный `/skipday` (`kind=skip`) на день внутри span отпуска, `$exists=true` → цикл делает `$covered++` + `continue` (`:96-100`), но НЕ пишет строку `kind=vacation` и не выставляет `vacation_until` для этого дня. `vacationUntil()` (`:176`) фильтрует `->where('kind', SkipKind::Vacation)`, поэтому этот день возвращает `null` → `/progress` рендерит его как обычный skip, а не «🌴 отпуск до DD.MM». `onVacation()`/`isReturningFromVacation()` (`:202-209`) тоже ключуются по `kind=Vacation` → пограничный сигнал «возврат из отпуска» может быть неверным. Дополнительно `$covered` раздувается pre-existing skip'ами (`:97`), что может пропустить отпуск короче 2 реальных дней мимо guard'а `days<2` в `SkipHandler.php:104`. Верификатор подтвердил: единственный rollback (`unvacation`, `:106`) срабатывает только при `days<2` и удаляет ТОЛЬКО `kind=Vacation`-строки, то есть не чинит pre-existing `kind=skip`. Уникального индекса `(manager_id,on_date)` на `pulse_skip_days` НЕТ (только PK + plain index на `on_date`) — upsert/overwrite не форсируется. Теста на overlap skip-then-vacation нет. Док-комментарий `SkipKind` enum (`:18-19`) явно требует, чтобы тег `kind` сохранял различимость `/unskipday` vs `/unvacation` и лейбла `/progress` — pre-check это нарушает.
- **Repro:** как admin: `/skipday <mgr> 2026-07-01`; затем `/vacation <mgr> 2026-07-01 2026-07-05` → 2026-07-01 остаётся `kind=skip` с `vacation_until=NULL`, тогда как 07-02…07-05 — `kind=vacation`; `/progress` на 07-01 показывает skip, не отпуск.
- **Граница severity (по верификатору):** предусловие узкое (admin должен дать `/skipday`, потом перекрывающий `/vacation`; фича admin-only Telegram и config-off в dev) — lower bound minor; оставлено **major** из-за «тихо неверного» вывода бота + обхода days-guard. БД-мутация в probe не проводилась (вердикт по чтению кода).
- **Предлагаемый фикс:** добавить `->where('kind', SkipKind::Vacation)` в existence-check (`:91-94`) и апгрейдить существующую `kind=skip`-строку в span до vacation (выставить `kind` + `vacation_until`), либо использовать `updateOrCreate` по ключу `(manager_id,on_date)` с принудительным `kind=vacation` + `vacation_until`. Считать `$covered` только для строк, которые реально vacation.

---

### Minor / trivial (не верифицировано — Phase-1)

- **minor · DATA-INCONSISTENCY** — Все `pulse_*` пусты, т.к. фича config-disabled в dev. `src/config/salespulse.php:64` (`teams = json_decode(env('SALESPULSE_TEAMS_JSON','[]')) ?: []`), `:41-42`, `RosterResolver.php:32-41`, `AnnouncerService.php:426-435`, `TeamResolver.php:37-46`, `routes/console.php:78-113`. В живом app-контейнере `SALESPULSE_TEAMS_JSON`/`SALESPULSE_TEST_MODE`/`SALESPULSE_RUN_POLLING`/`SALESPULSE_BOT_TOKEN` пусты → все write-пути no-op'ят. Inline-массив `managers` в `config/salespulse.php:110-115` — внутри блока `test_mode.team` (активен только при `SALESPULSE_TEST_MODE=true`), это НЕ боевой roster. **Фикс:** ожидаемо в dev; локально проверить через `SALESPULSE_TEST_MODE=true` + `SALESPULSE_TEST_ADMINS=<tg>` и `/startday` в DM, либо заполнить `SALESPULSE_TEAMS_JSON`. **Для прода — отдельно подтвердить, что prod-env реально имеет непустой TEAMS_JSON и работающий `salespulse-bot`, иначе прод тоже молча инертен.**
- **minor · BUG** — Announcer постит ПОСЛЕ коммита dedup-строки. `src/app/Domain/SalesPulse/Services/AnnouncerService.php:360-386`. `record()` делает `PulseAnnouncedEvent::create()` (коммит сразу, без транзакции, `:363-372`), затем `$this->notifier->sendToChat(...)` (`:384`). Если `sendToChat` бросает (Telegram 5xx/429/network), строка ledger уже сохранена → следующий 5-мин тик считает событие анонсированным, поздравление тихо теряется. Insert-first корректен для race-safety (`QueryException` ловится `:373-382` с `Log::info`), но send-исключение НЕ ловится/логируется вообще. **Фикс:** обернуть insert+send так, чтобы сбой отправки удалял/помечал строку для retry; либо принять at-most-once и добавить `$tries>1` + backoff на `RunAnnouncerJob`; как минимум — ловить и логировать send-исключение, чтобы потеря была наблюдаемой.
- **minor · BUG** — `SnapshotRepository` savePlan/saveFact: SELECT-then-INSERT вместо upsert. `src/app/Domain/SalesPulse/Services/SnapshotRepository.php:40-68` (savePlan), `:78-107` (saveFact). `savePlan` читает `$existing` ВНЕ транзакции (`:40-44`), затем INSERT внутри `DB::transaction` (`:55-61`) БЕЗ try/catch (в отличие от `AnnouncerService::record`, который ловит `QueryException`). Write-once держится на неатомарном read-then-write, а не на constraint `uq_pulse_snapshots_manager_date_kind`. При гонке (менеджер делает `/startday` ровно в 10:15, когда срабатывает `AutoCapturePlanJob`) оба проходят existence-check, один INSERT ловит unique-violation и бросает необработанный `QueryException`. `saveFact` имеет тот же паттерн внутри транзакции. Риск низкий (поллинг single-process, авто-джоб проверяет `plan_at` первым), но не constraint-safe. **Фикс:** `updateOrCreate`/`firstOrCreate` по `(manager_id,on_date,kind)`, либо ловить unique-violation `QueryException` и перечитывать существующую строку (зеркало `AnnouncerService::record`).
- **minor · BUG** — `SkipHandler::secondDate()` ре-токенизирует аргументы независимо от `parseArgs`. `src/app/Domain/SalesPulse/Telegram/Handlers/SkipHandler.php:155-169`, `src/app/Domain/SalesPulse/Services/TeamResolver.php:126-200`. `secondDate()` независимо обходит ВСЕ токены и возвращает 2-й, который принимает `parseDateToken` (loose `d.m`); `parseArgs` (TeamResolver) отдельно берёт ПЕРВУЮ дату как `from` и первый не-date как `slug`. Два парсера токенизируют независимо → переставленные токены дают несогласованные from/until. Пример: `/vacation 31.05 ilya 12.05` → from=31.05, slug=ilya, until=12.05 → until<from → `workingDaysInSpan` пуст → `VACATION_TOO_SHORT` без внятного фидбэка. **Фикс:** парсить аргументы один раз в структуру `{dates[], slug}`, derive from=dates[0], until=dates[1], валидировать until>=from с явной ошибкой; не давать `secondDate` пере-сканировать.
- **minor · CONVENTION** — `salespulse:run` single-replica не enforced кодом. `src/app/Console/Commands/SalesPulse/RunBotCommand.php`, `src/config/salespulse.php:41-42`. Поллинг гейтится только `config('salespulse.bot.run_polling')`; ничто не мешает двум контейнерам/процессам вызывать `getUpdates` на одном токене → Telegram 409 Conflict, апдейты теряются. **Фикс:** задокументировать `replicas:1` в docker-compose для `salespulse-bot`, либо брать Redis advisory-lock при старте и выходить, если не получен.
- **minor · MISSING** — У SalesPulse нет frontend/admin-поверхности (by design). `front/src/router/routes/base.ts`, `front/src/components/AppShell/AppSidebar.vue`, `front/src/api/`. Exhaustive-grep по `pulse|salespulse|skipday|finishday|startday|dayresults` даёт только CSS `@keyframes 'pulse'` — нет `api/*`-модуля, роута, пункта меню, store. Админ не видит из CRM, кто в отпуске/skip, не видит сегодняшние PLAN/FACT, не может поправить ошибочный skip — всё через Telegram. Vault помечает SalesPulse как «Telegram-бот надзора продаж» без module-файла — это by design. **Фикс:** действий не требуется, если продукт не хочет web-видимости (read-only admin-панель skip/vacation + дневных снапшотов). Помечено как осознанное решение, не недосмотр.
- **trivial · DEAD-CODE** — Нет FE→missing-endpoint мёртвых вызовов и shape-mismatch (негативное подтверждение). `front/src/api/auth.ts:69-78`, `front/src/pages/ProfilePage/composables/useProfilePage.ts:177-203`. Единственные TG-related FE-вызовы (`/api/me/telegram-link`, `/api/me/telegram`) — домен Notification, существуют и корректно подключены; orphaned-вызовов к несуществующим pulse-эндпоинтам и shape-дрейфа нет.

### Релевантные NEW-* из live-QA

Нет. Все NEW-1…NEW-9 из live-QA относятся к CRM/Sales/Onboarding (CompanyChannelsBlock, 403 в DealPage, i18n-ключ, `Route [login] not defined`, `/api/admin/*` для manager, Физлица-таб, KPI-несоответствие, «Продолжить» в курсах, SPA на :8080) и не затрагивают SalesPulse, у которого нет ни SPA-страниц, ни REST-поверхности. Это согласуется с архитектурой домена.

## 7. Расхождения со спекой (vault) и предложения по актуализации

**Документ:** `5. Планы/AMO-бот → MGCRM — план миграции.md`.

1. **§8 B-build-план → Таблицы (миграции).** Vault сейчас говорит: `pulse_skip_days (on_date, team_chat_id?, manager_id?, created_by)` — БЕЗ колонок `kind` и `vacation_until`; `pulse_announced_events` перечисляет только `activity_id uniq` (без `deal_stage_history_id`). **Реальность:** живая схема + модель имеют `pulse_skip_days.kind (skip|vacation)` и `pulse_skip_days.vacation_until`; `pulse_announced_events` имеет ОБА уникальных индекса `uq_pulse_announced_events_activity` И `uq_pulse_announced_events_dsh` (`deal_stage_history_id`), с `event_type meeting_done|success` — потому что Success стал переходом стадии (`DealStageHistory`), как решено в самом vault §2.2. **Предложение:** обновить список таблиц §8 на: `pulse_skip_days (on_date, kind skip|vacation, vacation_until, team_chat_id?, manager_id?, created_by)`; `pulse_announced_events (activity_id uniq, deal_stage_history_id uniq, event_type meeting_done|success, manager_id, deal_id?, chat_id, posted_at)`. Текст устарел относительно реализованной (и APPROVED) схемы.

2. **Frontmatter status / §8 Статус сборки.** Vault: `status: BUILD COMPLETE (Slice 0–4, все APPROVED) — остался Slice 5 (cutover, ждёт токен+маппинг)`. **Реальность:** для кода — точно; но running dev-env имеет `SALESPULSE_TEAMS_JSON` unset и не имеет контейнера `salespulse-bot`, то есть фича полностью инертна в dev. В vault нет пометки, что dev config-off и что паритет prod-env нужно подтвердить. **Предложение:** добавить одну операционную строку в §8 Статус сборки: «Dev: `SALESPULSE_TEAMS_JSON` unset + нет poller-контейнера ⇒ фича инертна; гонять через `SALESPULSE_TEST_MODE=true`. Prod go-live (Slice 5) обязан подтвердить непустой TEAMS_JSON + single-replica `salespulse-bot`.»

3. **(подтверждающее, не дрейф)** `pulse_snapshots` и `pulse_daily_status` в vault §8 совпадают с кодом/живой схемой полностью (включая `uniq manager+date+kind` и `uniq manager+date`). Отсутствие web/admin-UI у домена — by design, vault это фиксирует; добавлять FE-спеку не нужно, если продукт явно не запросит read-only админ-видимость skip/vacation/снапшотов.
