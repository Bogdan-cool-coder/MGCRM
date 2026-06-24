# Аудит домена: Продажи — Дашборд (агрегаты, виджеты, взвешенный прогноз)

## 1. Назначение

Домен — это аналитический дашборд отдела продаж (спринт S1.7, в vault помечен `done`, QA PASS). Один сервис-агрегатор `SalesDashboardService` строит весь payload §В3 одним проходом SQL `GROUP BY`: `status_groups` (активные/выигранные/проигранные + тренд к прошлому периоду), `funnel` (конверсия и среднее время в стадии), взвешенный `forecast` (вероятность по ключевым словам стадии), `top_products`/`top_managers` (топ-N с хвостом «Другие») и `deals_without_tasks` (счётчик сделок без задач). `DashboardController` — тонкий контроллер на 2 эндпоинта (JSON + xlsx). Авторизация выстроена корректно: смотреть может любой аутентифицированный пользователь, но данные **до агрегации** урезаются по видимости (All/Department/Own), а фильтр `manager_id` доступен только admin/director (чужой id → 422, проверено вживую). Деньги — целые копейки с `ExchangeRateService::safeConvert` и флагом `multi_currency_warning`; каждая колонка-фильтр проиндексирована.

**Зрелость: частично (механически рабочий каркас с критичным дефектом сквозного UX).** Бэкенд-движок зрелый и точно следует спеке, но: (а) выгрузка Excel сломана сквозно (BLOCKER, подтверждено вживую); (б) дефолтный выбор воронки на фронте промахивается мимо реальных данных; (в) расчёт тренда смешивает две несогласованные популяции. Живые данные крошечные и тестовые: всего 13 `deals` (4 живых, 9 soft-deleted, 0 archived), причём **все 4 живые сделки лежат на неактивных воронках 1 и 2**, тогда как активные воронки 6 и 7 пусты — поэтому «из коробки» дашборд показывает почти пусто. Live QA подтвердил: дашборд **не пустой**, он рендерит структуру воронки и просто показывает 0 у менеджера, у которого нет своих сделок (известный «blank»-issue **опровергнут**).

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI + endpoint) | Как (шаги) | Статус | Примечание |
|---|---|---|---|---|---|
| Сборка payload дашборда | admin/director (All), деп-менеджеры (Department), менеджеры (Own) | `/dashboard` (DashboardPage) · `GET /api/sales/dashboard?period&pipeline_id?&manager_id?` | resolve VisibilityScope → resolvePipeline → scoped baseQuery (по `stage_changed_at`) → statusGroups/funnel/forecast/topProducts/topManagers + countDealsWithoutTasks → meta + 5 виджетов | 🟡 частично | Движок работает, но дефолтная воронка (BE) и pre-select (FE) промахиваются мимо живых сделок |
| Взвешенный прогноз (forecast) | все роли (scoped) | payload `GET /api/sales/dashboard` · WidgetForecast.vue | `GROUP BY stage_id,currency` по не-won/не-lost → probabilityForStage(name) по ключевым словам → safeConvert → weighted = converted×prob → бакеты HOT(≥0.7)/Warm(≥0.4)/Trial(≥0.3) | 🟡 частично | Бакет Trial практически недостижим (keyword `trial`=0.5 попадает в Warm); vault это уже признаёт |
| Тренд к прошлому периоду | все роли (scoped только для текущего периода) | часть `status_groups` · WidgetStatusGroups.vue (Tag) | prevPeriod() сдвигает окно; computeTrendsFromPrev делает RAW `DB::table('deals')` count (без soft-delete/visibility/manager scope) → computeTrendPct | 🔴 сломан | Числитель scoped, знаменатель — нет; 9/13 сделок soft-deleted раздувают прошлый период (подтверждено) |
| Метрики воронки (конверсия) | все роли (scoped) | часть payload · WidgetFunnelTable.vue | per-stage count + avg_days = `AVG(NOW()-stage_changed_at)` + transition_to_next_pct (накопитель «хвостом»); won=100%/lost=0% | 🟡 частично | avg_days = «прошло с последнего перехода» для текущих в стадии, а не реальное dwell-time; `deal_stage_history` (vault §Б2) не используется |
| Топ-N продукты / менеджеры | все роли (scoped) | часть payload · WidgetTopBar.vue (SelectButton) | продукты: JOIN deal_products→catalog_products GROUP BY product, SUM. менеджеры: GROUP BY `users.full_name`, COUNT+SUM. LIMIT 11 → «Другие» | 🟡 частично | topManagers группирует по `full_name` (не id) → одноимённые пользователи схлопываются; нет фильтра is_active |
| Сделки без задач (счётчик + deep-link) | все роли (visibility-scoped) | часть payload · WidgetDealsWithoutTasks.vue (`<a href>` → `/deals?pipeline_id=X&no_tasks=1`) | countDealsWithoutTasks(pipelineId, user) → EXISTS-подзапрос по activities; FE рендерит счётчик + сырой anchor | 🔴 сломан | (a) счётчик игнорирует period и manager_id; (b) deep-link мёртв: DealsPage читает только `route.query.view`, параметр называется `only_no_task`, а не `no_tasks` |
| Выгрузка в Excel | любой аутентиф. (scoped) — но сейчас падает у всех | кнопка Export на `/dashboard` · `GET /api/sales/dashboard.xlsx` | FE exportDashboardXlsx() → `window.open(...)` → backend StreamedResponse | 🔴 сломан | `window.open` не несёт Bearer; Sanctum чисто-Bearer → 500 `Route [login] not defined` (подтверждено вживую и в браузере) |
| Plan/fact против team_targets | — | — (нет endpoint) | НЕ реализовано в дашборде; plan/fact живёт в S1.8/M10 (кабинет KPI) | ⚪ отсутствует | Корректно вне scope S1.7 по vault §А; не дефект, а несовпадение ожиданий брифа |

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк в живой БД | Статус |
|---|---|---|---|---|
| `Deal` | `deals` | Мастер-сущность Deal-on-Company; источник всех агрегатов (`amount` в копейках, `currency`, `stage_id`, `owner_user_id`, `department_id`, `stage_changed_at`) | 13 (4 живых, 9 soft-deleted, 0 archived); живые: 3 на воронке 1, 1 на воронке 2 — обе неактивны | built |
| `Pipeline` | `pipelines` | Воронка; дефолт = первая активная sales-воронка по `sort_order,id` → id=6 «MACRO Global» (пустая) | 7 (активные 6,7 — 0 живых сделок; неактивные 1–5 — живые только на 1 и 2) | built |
| `PipelineStage` | `pipeline_stages` | Стадия; `is_won`/`is_lost` → группировка статусов; `name` → вероятность прогноза по ключевым словам | 82 | built |
| `DealStageHistory` | `deal_stage_history` | Аудит переходов (from→to). Vault §Б2 называет её источником avg_days, но дашборд её НЕ использует | 1 | partial |
| `TeamTarget` | `team_targets` | План выручки отдела (plan-сторона). Модель есть; корректно вне scope S1.7 (vault §А «KPI plan vs fact — M10»); не упоминается ни в одном контроллере/роуте/сервисе дашборда | 1 | stub |
| `DealProduct` | `deal_products` | Позиции для top_products (`SUM(amount)` по продукту, по валютам) | 4 | built |

**Расхождения migration ↔ live-schema ↔ model:**
- **`deals.deleted_at` (soft delete):** миграция и live-схема согласованы (SoftDeletes). Живых 9/13 удалены. НЕ схема-мисматч — а код-путь `computeTrendsFromPrev` через сырой `DB::table('deals')`, который обходит глобальный scope soft-delete.
- **`deals.archived_at`:** есть в миграции и live, `count(archived_at)=0`. Модель кастует в datetime, но scope не определён; сервис не фильтрует — архивные сделки попали бы в агрегаты (сейчас live-эффект нулевой). Латентно.
- **`deals.closed_at / signed_at / paid_at` (fact-даты):** есть в миграции и live, но дашборд их **не использует** — период keyится на `stage_changed_at`. Колонки фактически мёртвы для period-scoping. Живьём у 3 выигранных сделок (id 10,11,12) все три fact-даты **NULL** — fact-даты не пишутся при выигрыше.
- **`deal_stage_history`:** таблица есть (1 строка), но кода дашборда, который бы её читал, нет — для дашборда фактически не используется (drift спеки §Б2).
- **`team_targets`:** таблица есть (1 строка); модель связана только с `SalaryPlan` (кабинет KPI), не с дашбордом. Built-but-unexercised — корректно по спеке.
- **`users.full_name`:** колонка не UNIQUE в схеме, а topManagers группирует по ней → одноимённые пользователи схлопываются (код ошибочно полагается на уникальность имени).
- **`pipelines.is_active` vs распределение сделок:** data-state-несогласованность — активные 6,7 пусты, все живые сделки на неактивных 1,2; и `resolvePipeline()`, и FE `pipelines[0]` целятся в активную/первую воронку, промахиваясь мимо данных.

## 4. Эндпоинты и покрытие фронтом

| Метод + Path | Контроллер@метод | Авторизация | Вызывается фронтом? | Примечание |
|---|---|---|---|---|
| `GET /api/sales/dashboard` | `Sales\DashboardController@dashboard` | `auth:sanctum` + DashboardRequest::authorize → `can('viewAny', Deal)` (DealPolicy::viewAny=true для любого authed); visibility-scope (All/Dep/Own) в baseQuery; `manager_id` только admin/director (чужой id → 422, проверено) | Да (`getDashboardData`, salesDashboard.ts; Bearer через axios) | Основной endpoint; форма ответа `DashboardResponse` совпадает с payload, включая `meta.no_pipeline?` |
| `GET /api/sales/dashboard.xlsx` | `Sales\DashboardController@export` | Тот же DashboardRequest (viewAny Deal + visibility-scope); StreamedResponse того же scoped payload | Да — но через `window.open` (salesDashboard.ts:23) **БЕЗ Authorization** | 🔴 СЛОМАН: top-level navigation без Bearer → Sanctum чисто-Bearer → 500 `Route [login] not defined` (подтверждено вживую) |
| `GET /api/pipelines` | Pipeline list (`salesApi.getPipelines`) → PipelineService::list | `auth:sanctum` | Да (DashboardToolbar pipeline Select) | Порядок `sort_order,id` БЕЗ фильтра is_active → data[0] = неактивная воронка 2 «Продажи (копия)»; FE pre-select data[0] → промах мимо больших сделок воронки 1 |
| `GET /api/users` | Users list (`usersApi.getUsers`) | `auth:sanctum` | Да (DashboardToolbar manager Select, только admin/director) | Клиентская фильтрация по admin/director/manager (useDashboardPage.ts) |

Мёртвых endpoint'ов и orphaned FE-вызовов в домене нет: все 4 endpoint'а вызываются фронтом. Проблема не в наличии вызова, а в **способе** (`window.open` для xlsx) и в **дефолтном выборе аргумента** (`pipelines[0]`).

## 5. RBAC домена

| Действие | Кому разрешено | Где реально проверяется | Дыра? |
|---|---|---|---|
| Смотреть дашборд в своём scope | любой аутентифицированный | DashboardRequest::authorize → DealPolicy::viewAny (true); SalesDashboardService::baseQuery применяет VisibilityScope::Own | Нет — данные урезаются server-side до агрегации |
| Смотреть Department/All | деп-менеджеры (Department), admin/director (All) | VisibilityResolver::resolve(user) в baseQuery; scope из spatie-роли, fallback на `users.role` | Нет |
| Фильтр по чужому `manager_id` | только admin, director | DashboardRequest::passedValidation (чужой id у не-admin/director → 422, проверено вживую: manager1 → HTTP 422) | Нет — проверено вживую |
| Видеть Select менеджера | только admin, director | FE `v-if canSeeAllManagers` (useDashboardPage.ts / DashboardToolbar.vue) | Нет (но это FE-gate; сервер всё равно бьёт по 422) |
| Экспорт xlsx | как у view (любой authed, scoped) | DashboardController@export переиспользует DashboardRequest + scoping | Авторизация корректна, но FE-путь неаутентифицирован (см. BLOCKER) |

**Вывод по RBAC домена:** в самом дашборде авторизация выстроена правильно — двухуровневая (policy-гейт + visibility-scope до агрегации), `manager_id` защищён на сервере (422), не только спрятан на FE. **Дыр в RBAC дашборда нет.** Маршрут `/dashboard` в роутере имеет только `requiresAuth` без roles-gate (base.ts) — это допустимо, т.к. данные урезаются на бэкенде.

> Замечание по соседнему домену: live QA выявил **NEW-5** — менеджер может читать `/api/admin/*` (справочники: company-types, sources, countries, cities, contact-positions, acquisition-channels, disconnect-reasons → все 200). Это дыра RBAC **не дашборда**, а слоя admin-справочников; на дашборд не влияет, но фиксируется как контекст (передать crm/admin-домену).

## 6. Бэклог проблем

### Сводная таблица

| Severity (FINAL) | Тип | Заголовок | Проверка |
|---|---|---|---|
| blocker | BUG | Кнопка Export xlsx сломана — `window.open` без Bearer → HTTP 500 | ✅ подтверждено (live curl) · 🌐 подтверждено в браузере |
| major | DATA-INCONSISTENCY | Дефолтная воронка промахивается мимо живых сделок (FE pre-select неактивной воронки) | ⚠️ частично (не «пусто», а «не та воронка, теряет 75% сделок») |
| major | BUG | «Open list» из виджета «сделки без задач» ведёт на нефильтрованный список (params игнорируются + не то имя) | ✅ подтверждено (статический трейс) |
| major | BUG | Тренд (прошлый период) игнорирует soft-delete и visibility, текущий — учитывает | ✅ подтверждено (SQL-доказательство 12 vs 3) |
| minor | SPEC-DRIFT | Период keyится на `stage_changed_at` вместо close/fact-даты для won/lost | ✅ подтверждено (понижено major→minor) |
| minor | BUG | Счётчик «сделки без задач» не scoped по period/manager_id согласованно | не верифицировано (Phase-1) |
| minor | BUG | Бакет Trial недостижим; пороги HOT/Warm/Trial перекрываются | не верифицировано (Phase-1) |
| minor | BUG | top_managers группирует по `users.full_name` (коллизии) + считает неактивных | не верифицировано (Phase-1) |
| minor | SPEC-DRIFT | avg_days = elapsed-since-move, а не dwell-time; `deal_stage_history` не используется | не верифицировано (Phase-1) |
| minor | BUG | При неудаче конвертации валюты count растёт, а amount теряется (расхождение count/amount) | не верифицировано (Phase-1) |
| minor | BUG | Архивные сделки (`archived_at`) попадают во все агрегаты | не верифицировано (Phase-1) |
| minor | CONVENTION | TopBar хардкодит hex-цвета в обход темы ECharts и DS-токенов | не верифицировано (Phase-1) |
| minor | CONVENTION | Формат денег захардкожен на ru/RUB в виджетах и форматтерах графиков | не верифицировано (Phase-1) |
| minor | BUG | Ошибки загрузки pipelines/managers глотаются молча — тулбар пуст без фидбэка | не верифицировано (Phase-1) |
| trivial | MISSING | team_targets есть, но не подключён к дашборду — нет plan/fact (корректно вне scope) | не верифицировано (Phase-1) |

---

### BLOCKER · BUG · ✅ подтверждено (live curl) + 🌐 подтверждено в браузере — Кнопка Export xlsx сломана

**Файлы:** `front/src/api/salesDashboard.ts:19`, `:23` · `front/src/pages/DashboardPage/index.vue:5` · `front/src/api/client.ts:36-42` (Bearer только на apiClient) · `src/config/sanctum.php:48` (guard=[], Bearer-only) · `src/routes/api.php:145,431-434` · `src/bootstrap/app.php:27-31`

**Что происходит:** `exportDashboardXlsx()` открывает xlsx через `window.open('/api/sales/dashboard.xlsx?...', '_blank')` (salesDashboard.ts:23). Приложение аутентифицируется чисто Bearer-токеном из JS (sanctum.php:48 `guard=[]`, web/session-fallback намеренно выключен). Top-level навигация `window.open` не несёт ни заголовка `Authorization`, ни cookie → запрос неаутентифицирован. Реальный браузер шлёт `Accept: text/html`, и `AuthenticationException` в Laravel маппится на `redirect()->route('login')` — а такого именованного роута в API-only приложении нет → **HTTP 500 `Route [login] not defined`**. Кнопка открывает новую вкладку с 500, файл не скачивается. Это тот же корень, что и live-QA **NEW-4**: неаутентифицированный API-хит отдаёт полную Laravel-трассу вместо чистого 401.

**Проверка:** live-probe (curl). `GET /api/sales/dashboard.xlsx?period=current_month` без Bearer: с `Accept: application/json` → 401 `{"message":"Unauthenticated."}`; с `Accept: text/html` (как window.open) → **500** `{"message":"Route [login] not defined.","exception":"RouteNotFoundException"}`. В браузере (live QA A.4): popup заблокирован браузером, скачивания нет, фидбэка пользователю нет, Bearer в URL отсутствует. confidence 0.99.

**Repro:** залогиниться любым пользователем → `/dashboard` → клик «Export» → новая вкладка показывает 500 (`Route [login] not defined`), xlsx не скачивается.

**Предлагаемый фикс:** скачивать xlsx через аутентифицированный axios-клиент как blob — `apiClient.get(url, { responseType: 'blob' })` + object URL + временный `<a download>` (этот паттерн уже используется в `sales.ts:371`, `companies.ts:211`, `contacts.ts:277` — дашборд от него отклонился). Вторично: зарегистрировать корректный 401-JSON для неаутентифицированных API-хитов (`redirectGuestsTo`/exception handler в `bootstrap/app.php`), чтобы вместо 500 отдавался 401 (закрывает заодно NEW-4).

---

### MAJOR · DATA-INCONSISTENCY · ⚠️ частично — Дефолтная воронка промахивается мимо живых сделок

**Файлы:** `src/app/Domain/Sales/Services/SalesDashboardService.php:574` (resolvePipeline) · `front/src/pages/DashboardPage/composables/useDashboardPage.ts:75-79` · `src/app/Domain/Sales/Services/PipelineService.php:74-81` (list без is_active-фильтра) · `front/src/api/sales.ts:119`

**Что происходит:** `resolvePipeline()` без `pipeline_id` берёт первую активную sales-воронку по `sort_order,id` → id=6 «MACRO Global» (0 живых сделок). Но FE на этот дефолт **не полагается**: `useDashboardPage.ts:76-79` pre-select `data[0]`, а `PipelineService::list` сортирует по `sort_order,id` **без фильтра is_active**, поэтому `data[0]` = воронка 2 «Продажи (копия)» (is_active=false), у которой 1 живая сделка (id=13). То есть «из коробки» FE показывает 1 активную сделку, а не «полностью пусто». 3 самые крупные сделки на воронке 1 («Продажи», is_active=false) **никогда не выбираются автоматически**.

**Проверка:** ⚠️ частично. Исходная формулировка «renders entirely blank» **фактически неверна для живого FE-пути** — воронка 2 непуста. Но дефект реальный и major: FE pre-select произвольной **неактивной** воронки (минимальный id) вместо активной или воронки с большинством сделок; `/api/pipelines` не active-first → дефолт показывает почти пустую воронку (1 из 4 живых сделок), а 3 крупнейшие сделки воронки 1 не всплывают. Путь к пустой воронке 6 достижим, только если `/api/pipelines` падает (ошибка глотается молча — useDashboardPage.ts:80) → backend-дефолт = воронка 6 = пусто. Live QA: «blank»-issue **DENIED** — дашборд рендерит структуру, 0 у менеджера = у него нет своих сделок. confidence 0.9.

**Repro:** залогиниться admin, открыть `/dashboard` без ручной смены воронки → выбрана неактивная «Продажи (копия)» с 1 сделкой; 3 крупные сделки воронки 1 не видны.

**Предлагаемый фикс:** FE — pre-select первую **активную** воронку (или с максимумом сделок), а не `pipelines[0]`; либо явная подсказка «нет данных по этой воронке». BE — `/api/pipelines` отдавать active-first; рассмотреть fallback на недавно активную воронку со сделками. По данным — переназначить живые сделки с неактивных воронок 1,2 на активную.

---

### MAJOR · BUG · ✅ подтверждено (статический трейс) — «Open list» ведёт на нефильтрованный список сделок

**Файлы:** `src/app/Domain/Sales/Services/SalesDashboardService.php:94` (filter_url) · `front/src/pages/DashboardPage/components/WidgetDealsWithoutTasks.vue:42-52` · `front/src/pages/DealsPage/index.vue:193-196,267-272` · `front/src/pages/DealsPage/composables/useDealsList.ts:61`

**Что происходит:** бэкенд строит `filter_url = "/deals?pipeline_id={id}&no_tasks=1"` (SalesDashboardService.php:94). Виджет рендерит его сырым anchor: `<Button tag='a' :href='data.filter_url'>` (full-page nav). DealsPage в `onMounted` читает **только** `route.query.view` (index.vue:267-272); grep по `front/src/pages/DealsPage` подтверждает, что `pipeline_id`/`no_tasks` из `route.query` **не читаются нигде**. `currentPipelineId` (index.vue:193-196) = `salesStore.activePipelineId ?? pipelines[0]`, не из URL. Плюс параметр фильтра на API называется `only_no_task` по всему FE/API (useDealsList.ts:61, useDealsBoard.ts:79, useDealsFilters.ts, useDealsKpi.ts) — параметра `no_tasks` не существует. Два независимых корня, оба ломают deep-link.

**Проверка:** ✅ подтверждено статическим трейсом (мутация/рендер не нужны — параметры не читаются ни одним код-путём). Искали router-guard query→store: отсутствует. Алиаса `no_tasks→only_no_task` на API нет. confidence 0.93.

**Repro:** на `/dashboard` выбрать воронку со сделками без задач, клик «open list» → навигация на `/deals?pipeline_id=X&no_tasks=1`, но список показывает ВСЕ сделки текущей воронки стора без фильтра «без задач» (возможно вообще другая воронка).

**Предлагаемый фикс:** DealsPage в `onMounted` читать `pipeline_id` и флаг «без задач» из `route.query` и мапить в `salesStore.activePipelineId` + `filters.only_no_task`; согласовать имя параметра (использовать `only_no_task`). Заменить сырой `<a href>` на `router-link`/`router.push` ради SPA-навигации.

---

### MAJOR · BUG · ✅ подтверждено (SQL-доказательство) — Тренд (прошлый период) игнорирует soft-delete и visibility

**Файлы:** `src/app/Domain/Sales/Services/SalesDashboardService.php:693` (computeTrendsFromPrev) · `:588` (baseQuery) · `:113,153-154,162-165` · `src/app/Domain/Sales/Models/Deal.php:23,37` (SoftDeletes)

**Что происходит:** текущий период `statusGroups()` строится через `baseQuery()` (line 588) = `Deal::query()` (глобальный scope SoftDeletes → `deleted_at IS NULL`) + visibility-scope + `manager_id`. А `computeTrendsFromPrev()` (line 693) делает сырой `DB::table('deals')` **только** с `pipeline_id` + окном `stage_changed_at` — без `whereNull('deleted_at')`, без visibility-scope, без `manager_id`. Знаменатель прошлого периода включает soft-deleted и вне-scope сделки, числитель — нет → `trend_pct` считается по двум несогласованным популяциям. `trend_pct` user-facing — рендерится Tag-ом в WidgetStatusGroups.vue:39-47 (formatTrendPct). Триггер всегда срабатывает (statusGroups:153-154 безусловно вызывает computeTrendsFromPrev).

**Проверка:** ✅ подтверждено SQL-доказательством. Для воронки 1, окно июнь-2026: RAW-стиль (computeTrendsFromPrev, без deleted_at/visibility/manager) → active=7, won=5 (12 всего); SCOPED-стиль (baseQuery, `deleted_at IS NULL`) → won=3 (3 всего). 9 soft-deleted сделок (все на воронке 1, stage_changed_at 2026-06-19) раздувают raw/prev ровно на 9. `DB::table` обходит Eloquent-scopes полностью. confidence 0.95.

**Repro:** soft-delete нескольких сделок с датой в прошлом периоде → `GET /api/sales/dashboard?period=current_month`. `trend_pct` для active/won/lost/total искажён, т.к. prev считает удалённые, current — нет. Аналогично: admin фильтрует одного менеджера — current scoped по нему, prev-знаменатель считает всех.

**Предлагаемый фикс:** провести `computeTrendsFromPrev` через тот же scoped-builder, что и `baseQuery` (через `Deal::query()` или `->whereNull('deals.deleted_at')` + те же visibility-scope и `manager_id`), идеально — переиспользовать общий scoped query builder для обоих периодов.

---

### Прочие проблемы (minor/trivial — не верифицировано (Phase-1))

- **minor · SPEC-DRIFT · ✅ подтверждено (понижено major→minor)** — Период keyится на `stage_changed_at` вместо close/fact-даты для won/lost (`SalesDashboardService.php:588,592-593`). «Выиграно в этом периоде» по факту означает «последний переход стадии в этом периоде». Колонки `closed_at/signed_at/paid_at` есть, но не используются; у 3 won-сделок (id 10,11,12) все fact-даты **NULL** — fact-даты не пишутся при выигрыше. Понижено до minor: vault не фиксирует дату признания выручки (это неоднозначность, а не нарушенное требование), а очевидный фикс (фильтр по `closed_at`) сейчас выкинул бы все 3 won-сделки из-за NULL. Проблема не «сломанные числа», а семантика отчёта + upstream-захват дат.
- **minor · BUG** — Счётчик «сделки без задач» не scoped по period и admin/director `manager_id` (`SalesDashboardService.php:70`, `ActivityService.php:480`). `countDealsWithoutTasks(pipelineId, user)` visibility-scoped, но игнорирует period и `manager_id`, тогда как остальные виджеты их учитывают → при фильтрации admin'ом по менеджеру/периоду карточка расходится с остальным дашбордом. Фикс: передать в метод тот же period + manager_id (или scoped base query).
- **minor · BUG** — Бакет Trial практически недостижим; пороги HOT/Warm/Trial перекрываются при exclusive if/elseif (`SalesDashboardService.php:327`, `config/crm.php:169`). Keyword `trial`=0.5 попадает в Warm (≥0.4); в Trial-бакет доходит только proposal/КП (0.3). vault уже признаёт это known/accepted (revisit на M10). Фикс: переименовать бакеты под пороги или сдвинуть вероятность `trial` в [0.3,0.4).
- **minor · BUG** — top_managers группирует по `users.full_name` (не UNIQUE → одноимённые схлопываются и суммируют выручку) и не фильтрует `is_active` (`SalesDashboardService.php:393,395`). buildTopNChartPayload re-aggregate по имени усугубляет. Фикс: GROUP BY `u.id`, опц. фильтр is_active.
- **minor · SPEC-DRIFT** — avg_days_in_stage = `AVG(NOW()-stage_changed_at)` для текущих в стадии, а не реальное dwell-time; `deal_stage_history` (vault §Б2) не читается (`SalesDashboardService.php:187,194`). Пустая стадия показывает avg=0; долго застрявшая раздувает только текущую. Фикс: либо реализовать avg_days по `deal_stage_history`, либо обновить §Б2.
- **minor · BUG** — При неудаче конвертации валюты count инкрементится (line 140) ДО `safeConvert` (line 141), при `null` ставится `multi_currency_warning=true` и `continue` (line 146) → сделка посчитана, но amount опущен → count/amount расходятся (`SalesDashboardService.php:140,143`). Фикс: инкрементить count только после успешной конвертации либо отдельный `unconverted_count`.
- **minor · BUG** — Архивные сделки (`archived_at`) попадают во все агрегаты — нет scope/фильтра, baseQuery исключает только soft-delete (`SalesDashboardService.php:588`, `Deal.php:97`). Live-эффект нулевой (`count(archived_at)=0`). Латентно: решить семантику и добавить `->whereNull('deals.archived_at')` при необходимости.
- **minor · CONVENTION** — TopBar хардкодит `itemStyle.color:'#2B4987'` (line 121) и label-color `'#7E7F82'` (line 129) в обход зарегистрированной темы ECharts `macro-crm` и DS-токенов (`WidgetTopBar.vue:121,129`, `echarts.ts:46`). `#7E7F82` не адаптируется к light-теме; нарушает правило «нет hex вне токенов». Фикс: убрать inline-цвета, опираться на палитру темы/CSS-переменные.
- **minor · CONVENTION** — Формат денег захардкожен на ru/RUB в виджетах и форматтерах (`WidgetStatusGroups.vue:37`, `WidgetForecast.vue:39`, `WidgetTopBar.vue:99`, `chartFormatters.ts:138`). `formatMoney` без аргументов → всегда ru/RUB, хотя `meta.base_currency` возвращается API и есть en-локаль. EN-пользователь видит «млн/млрд» и ₽. Фикс: пробрасывать активную i18n-локаль + `meta.base_currency`.
- **minor · BUG** — Ошибки загрузки pipelines/managers глотаются молча (`useDashboardPage.ts:80,93` — `catch {}`). При падении `/api/pipelines` Select пуст, pre-select нет, дашборд грузится по backend-дефолту (воронка 6 = пусто) без объяснения. Фикс: лог/toast-предупреждение или inline-ошибка на Select.
- **trivial · MISSING** — team_targets (plan-сторона) есть, но не подключён ни к одному endpoint дашборда — нет plan/fact (`TeamTarget.php:18`, `SalesDashboardService.php:72`). Корректно вне scope S1.7 по vault §А (M10); не дефект, а несовпадение ожиданий брифа. Действий для S1.7 нет.

### Relevant live-QA NEW issues
- **NEW-4 (P1) — `Route [login] not defined` 500 экспонируется** — это тот же корень, что и BLOCKER xlsx-экспорта: неаутентифицированный API-хит отдаёт полную Laravel-трассу (раскрытие путей/классов/строк) вместо чистого 401. Фикс из BLOCKER (401-JSON для guests в `bootstrap/app.php`) закрывает оба. Источник: live-QA.
- **NEW-5 (P1) — `/api/admin/*` доступны менеджеру** — дыра RBAC слоя admin-справочников (не дашборда), но релевантна как контекст: менеджер читает acquisition-channels/disconnect-reasons. Передать crm/admin-домену. Источник: live-QA.

## 7. Расхождения со спекой (vault) и предложения по актуализации

**`2. Модули/Sales — Дашборд (агрегаты, виджеты).md`:**
- **Follow-up «Dead method DashboardController::scope()»** — спека говорит «закрыть при следующем касании». Реальность: метода `scope()` в DashboardController уже нет (grep подтверждает). → Убрать пункт или пометить DONE.
- **Frontend — WidgetDealsWithoutTasks.vue (`href → /deals?no_tasks=1`)** — спека: «счётчик, null-branch, href → /deals?no_tasks=1». Реальность: deep-link мёртв (DealsPage читает только `route.query.view`; параметр `only_no_task`, не `no_tasks`). → Добавить пометку known-defect, завести баг на фикс.
- **API — `GET /api/sales/dashboard.xlsx`** — спека: «Excel-выгрузка — паритет ✅». Реальность: FE-кнопка зовёт xlsx через `window.open` без Bearer → 500 (проверено вживую). → Понизить строку паритета экспорта с ✅ до known-defect, пока FE-путь не починят (blob-скачивание через authed axios).
- **Агрегаты — Status Groups / trend_pct** — спека: «trend_pct (vs prev period), GROUP BY в SQL, visibility-scope до GROUP BY». Реальность: текущий период scoped, прошлый (`computeTrendsFromPrev`) — сырой `DB::table('deals')` без scope. → Зафиксировать требование: тренд должен переиспользовать тот же scoped query (soft-delete + visibility + manager_id). В follow-up как correctness-баг.

**`5. Планы/Спринт 1 — S1.7 Дашборд (детальный план).md`:**
- **§Б1/§Б3 — period scoping won/revenue** — спека не фиксирует дату признания выручки. Реальность: все группы (active/won/lost) фильтруются по `stage_changed_at` → «won в периоде» = «последний переход в периоде». → Зафиксировать дату признания: won по `closed_at/signed_at/paid_at`, active по `stage_changed_at/created_at`; добавить явное правило (и учесть, что fact-даты сейчас не пишутся при выигрыше — нужен upstream-захват).
- **§Б2 — Funnel avg_days source** — спека: «Источник: DealStageHistory … для avg_days, fallback на deals.stage_changed_at». Реальность: всегда `deals.stage_changed_at`, `deal_stage_history` не читается; метрика = elapsed-since-move для текущих в стадии. → Либо реализовать avg_days по `deal_stage_history`, либо обновить §Б2 (avg_days = время для сделок текущих в стадии, убрать упоминание DealStageHistory).

**Несовпадение ожиданий брифа (не дефект):** бриф указывал team_targets как релевантный домену, но vault §А явно исключает plan/fact и TeamTarget из S1.7 (M10). Это корректный scoping; правок vault не требует.
