# Аудит домена: Продажи — KPI/мотивация (комиссии, оклады, цели, кабинет менеджера)

## 1. Назначение

Домен реализует **личный кабинет менеджера** (`/manager-cabinet`, спринт S1.8): персональная KPI-страница с шапкой профиля, степпером месяцев, четырьмя KPI-картами (`score_pct` / личные продажи / FTM факт-план / ранг в команде), таблицей сравнения с командой и пагинированной лентой активности. Бизнес-смысл — дать продавцу видеть свой план/факт и место в команде за выбранный месяц, а руководителю (`director`/`admin`) — заглянуть в KPI подчинённого. Под капотом заложена и более широкая модель мотивации (`salary_plans`, `team_targets`, `commission_rules`), но в S1.8 она почти целиком спящая.

**Зрелость: частично (working core поверх мёртвой data-модели мотивации).** Ядро кабинета построено и работает вживую: `ManagerKpiService` + `ManagerCabinetController` + три GET-эндпоинта `/api/me/*`, контракт спеки (`income_source=won_deals`, `multi_currency_warning`, graceful zeros) соблюдён, `manager1` отдаёт `score_pct=82, badge=warning` живьём. Но: модель мотивации читается лишь частично (только `personal_income_plan_kopecks` и `personal_ftm_plan`), а `commission_rules`/`team_targets`/большинство колонок `salary_plans` спят до M10. Главный разрыв — **данные**: из 14 пользователей salary-планы есть только у менеджеров 4/5/6, и у всех троих `department_id=NULL`, у `team_targets` тоже `department_id=NULL` — поэтому вся фича сравнения с командой мертва в проде (каждый план-менеджер видит solo-таблицу из одной строки). Живые row counts: `salary_plans=3`, `team_targets=1`, `commission_rules=1`, `users=14`, `deals=13`, `activities=24`.

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI + endpoint) | Как (шаги) | Статус | Примечание |
|---|---|---|---|---|---|
| Менеджер смотрит свой месячный KPI (score_pct, личные продажи, FTM, ранг) | manager, director, admin | `/manager-cabinet` (KpiCards) → `GET /api/me/kpi` | `getKpiData`: target=self → грузит `SalaryPlan` за год/месяц → `personalIncomeFact`=SUM(won-сделок, FX→base, GROUP BY currency) по `stage_changed_at` → `scorePct`/`scoreBadge` → FTM через `ActivityService.countFtmForUser` → `buildTeamData`; возвращает meta/personal/team | ✅ работает | Контракт спеки соблюдён вживую; manager1 `score_pct=82`, `badge=warning`. HD1-аппроксимация (доход = won-сделки) задокументирована. |
| Степпер месяцев меняет период KPI | manager, director, admin | MonthStepper → watcher перезагружает `GET /api/me/kpi` + `/activity-feed` | 7 кнопок (текущий + 6 предыдущих); текущий шлёт `'current_month'`, иначе `'YYYY-MM'`; период → `KpiFilters.fromRequest` → dateFrom/dateTo; `KpiRequest` валидирует период (enum или `YYYY-MM` closure, 422 на мусор) | 🟡 частично | Статически корректно; полный browser-reload при смене месяца не подтверждён в live-QA. |
| Сравнение с командой (ранг, среднее, % коллег) | manager, director, admin | TeamComparisonTable → `GET /api/me/kpi` (блок team) | `buildTeamData`: если `target.department_id=NULL` → solo (size 1, rank 1, members=[self]); иначе активные менеджеры отдела (role=Manager) → `teamKpiBatch` их `score_pct` → ранг+среднее → анонимизация до `full_name`+`score_pct` | 🔴 сломан | **МЁРТВ в проде:** план-менеджеры 4/5/6 все с `department_id=NULL` → solo-таблица из 1 строки. В отделе 2 есть менеджеры 7-12, но у них нет salary-планов. Логика также исключает director/не-Manager-лидов. |
| Лента активности (фильтры kind/FTM, пагинация) | manager, director, admin | ActivityFeedList → `GET /api/me/activity-feed` | `resolveTargetUser` → `ActivityService.feedForUser(target, {kind,from,to,ftm_only}, perPage)` → `LengthAwarePaginator` → `ActivityFeedItemResource` с флагом `ftm_counted` | 🟡 частично | Эндпоинт работает; FE рисует target сырым/непереведённым и хардкодит `'ru'` в датах. Лента пуста у засеянных кабинетных юзеров (manager1 `ftm_count_fact=0`); 24 активности существуют (target_type contact/deal). |
| Шапка профиля менеджера (отдел, руководитель, подчинённые) | manager, director, admin | CabinetHeader → `GET /api/me/profile` | `getProfile(target)` → id, full_name, email, role, job_title, department_id/name, manager_id/name, subordinates_count, avatar_path | ✅ работает | Контракт совпадает с `MeProfileResource`. `department_name`/`manager_name` будут null у NULL-dept менеджеров (косметика). |
| Director/admin смотрит KPI другого менеджера (`?user_id=`) | director, admin | `GET /api/me/kpi?user_id=N` (только URL) → `resolveTargetUser` | Бэкенд выдаёт privileged-зрителю любого active-юзера; manager → 403. `canViewOthers`+`viewedUserId` проброшены в composable и в loaders | 🟡 частично | Бэкенд поддерживает, но `index.vue` НЕ рисует пикер юзера и не читает `canViewOthers`/`viewedUserId`. Достижимо только ручной правкой URL. |
| Lawyer (или любая роль) доходит до кабинета | lawyer, любой authenticated | Сайдбар «Кабинет менеджера» + `GET /api/me/kpi` | Нет role-гейта ни на FE, ни на BE; lawyer видит nav-item и экран KPI из своих нулей | 🔴 сломан | 🌐 Подтверждено в браузере: `lawyer@mgcrm.test` `GET /api/me/kpi` → HTTP 200 (свои нули), не 403. Концептуально не экран юриста. |
| Admin CRUD для SalaryPlan/TeamTarget/CommissionRule + полная карта мотивации | admin | (нет — нет эндпоинтов/UI) | Явно вне scope S1.8 (отложено в M10). Модели + сидер есть; контроллера/UI нет; `commission_rule_id`/`team_target_id`/`status`/`currency` не читаются | ⚪ отсутствует | Соответствует спеке «Что НЕ входит». Числится как deferred, не дефект — но спящие FK-колонки/конфиги — фактически мёртвый код до M10. |

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк в живой БД | Статус |
|---|---|---|---|---|
| SalaryPlan | `salary_plans` | Месячный per-manager KPI-план; в S1.8 читаются только `personal_income_plan_kopecks` и `personal_ftm_plan` | 3 (users 4,5,6 — июнь 2026, RUB, status=draft, team_target_id=1, commission_rule_id=1) | partial |
| TeamTarget | `team_targets` | Месячная цель выручки отдела. Создана, но не читается `ManagerKpiService` в S1.8 (M10) | 1 (department_id=NULL, 900 000 RUB, июнь 2026) | stub |
| CommissionRule | `commission_rules` | Конфиг ставок комиссий для карты мотивации M10. Не читается KPI-логикой; только relation `SalaryPlan.commissionRule()` + сидер | 1 | stub |
| ManagerKpiService | (сервис) | Ядро S1.8: KPI-payload, профиль, FTM (через ActivityService), team-batch, authz (`resolveTargetUser`) | n/a | built |
| KpiFilters | (VO) | Readonly value-object для резолва периода (preset / `YYYY-MM`) и `user_id`; `fromRequest(KpiRequest)` | n/a | built |
| DealKpiService | (сервис) | Счётчики чипов воронки DealsPage — НЕ мотивация-KPI; вне домена кабинета, для полноты | n/a | built |
| users (profile-поля) | `users` | `job_title VARCHAR(255)`, `salary_currency VARCHAR(3) DEFAULT 'RUB'`; источник профиля и role-enum | 14 | partial |

**Расхождения migration ↔ live-schema ↔ model:**

- **`users` — критичное состояние данных.** Единственные менеджеры с salary-планами (4/5/6) имеют `department_id=NULL`, что ломает сравнение с командой. Менеджеры 7-12 = отдел 2, но без планов. Колонка `role` существует И как enum в `users`, И как spatie-роль — двойной источник истины (CONVENTION-issue).
- **`team_targets` — `department_id=NULL` в проде** подрывает связь с отделом; таблица вообще не читается KPI-логикой. Спека §В2 называет FK «team_id (FK departments)», а migration/model используют `department_id` — мелкий naming-drift в vault.
- **`salary_plans` — модель и схема совпадают,** но поведенческий разрыв: `personal_income_plan_currency`, `team_target_id`, `commission_rule_id`, `status` никогда не читаются `ManagerKpiService` (спят до M10). `score_pct` игнорирует валюту плана (BUG).
- **`commission_rules` — схема/модель совпадают со спекой §Г1,** целиком не читаются логикой S1.8 (M10). Не дефект, deferred.
- **config `crm.kpi` — мёртвый ключ:** `score_warning_threshold=80`, `score_danger_threshold=80`; `scoreBadge` читает оба, но использует только warning — `score_danger_threshold` мёртвый (читается, не применяется).

**Пустые-при-наличии-кода / спящие таблицы:** `commission_rules` (1 строка-затравка) и `team_targets` (1 строка с NULL-dept) — модели и сидер есть, но ни один сервис/контроллер/ресурс не читает их FK/status/currency. Это deferred-M10, но сейчас — мёртвый вес схемы.

## 4. Эндпоинты и покрытие фронтом

| Метод+Path | Контроллер@метод | Авторизация | Вызывается фронтом? | Примечание |
|---|---|---|---|---|
| `GET /api/me/profile` | `ManagerCabinetController@profile` | `auth:sanctum + 2fa + locale + visibility`; **НЕТ role-middleware**; `resolveTargetUser` скоупит user_id (manager → только self, иначе 403) | да | CabinetHeader через `getProfile` (`managerCabinet.ts`). FE читает `r.data.data` (`MeProfileResource` оборачивает в `{data:{...}}`). |
| `GET /api/me/kpi` | `ManagerCabinetController@kpi` | `auth:sanctum + 2fa + locale + visibility`; **НЕТ role-middleware**; `KpiRequest::authorize()=true`; `resolveTargetUser` скоупит user_id | да | KpiCards + TeamComparisonTable через `getKpiData`. `KpiResource $wrap=null` → meta/personal/team на корне; FE читает `r.data` без envelope. Live-форма верифицирована. |
| `GET /api/me/activity-feed` | `ManagerCabinetController@activityFeed` | `auth:sanctum + 2fa + locale + visibility`; **НЕТ role-middleware**; `resolveTargetUser` скоупит user_id | да | ActivityFeedList через `getActivityFeed`. `LengthAwarePaginator` → `{data,meta}`. FE шлёт page/kind/ftm_only/period/user_id; BE принимает ещё `per_page` (default 25), но FE его не шлёт. |
| `PATCH /api/me/profile` | `ProfileController@update` | `auth + 2fa` | нет (в этом домене) | Отдельный эндпоинт ProfilePage, НЕ кабинет менеджера — приведён, чтобы не путать с `GET /me/profile`. |
| `GET /api/deals/kpi` | `DealKpiController@__invoke` | `auth:sanctum + 2fa + visibility`; `DealPolicy@viewAny` | да (DealsPage) | Принадлежит чипам воронки DealsPage (`useDealsKpi.ts`), НЕ кабинету. Не мёртвый — просто нет вызова из этого домена. |

Orphaned FE-вызовов нет; все три кабинетных эндпоинта вызываются. «Полу-мёртвый» путь — `?user_id=` у `GET /api/me/kpi`/`/profile`/`/activity-feed`: бэкенд поддерживает cross-user view, но FE не имеет UI-входа (только ручная правка URL).

## 5. RBAC домена

- **Доступ к маршрутам `/api/me/profile|kpi|activity-feed`:** любой authenticated, прошедший 2FA пользователь достигает маршрута (`auth:sanctum + 2fa + locale + visibility`). **Role-middleware отсутствует.** `KpiRequest::authorize()` возвращает true. Контроллер не делает `authorize()`/Policy/gate. Self-данные, если не передан `user_id`. **Дыра:** lawyer (и любая роль) получает HTTP 200 со своими нулями вместо 403; FE-nav-item тоже не загейтован (`navItems.ts:83-87`, `166-169` без `roles[]`/`adminOnly` → `filterNavByRole` возвращает его всем; `base.ts:107-110` только `requiresAuth`). Файлы: `src/routes/api.php:402-405`.
- **Cross-user view (`?user_id=`):** manager → только self (403 на чужой user_id, путь HD5); director/admin → любой active-юзер (404 если нет/неактивен). Проверка реально срабатывает в `ManagerKpiService::resolveTargetUser` (`ManagerKpiService.php:343,350`). **Дыра конвенции:** привилегия читается из enum `users.role` (`Role::Admin`/`Role::Director`), а не из spatie → двойной источник истины (ARCHITECTURE-drift). FE не имеет UI-пикера для cross-user view (STUB).
- **Анонимизация коллег (Q1):** `team.members` отдаёт только `full_name` + `score_pct`; `income_fact_kopecks` коллег не включается ни для одной роли в S1.8 (`ManagerKpiService.php:437`). **Расхождение со спекой:** `isPrivileged` вычисляется (line 439), но суммы коллег просто никогда не эмитятся — director/admin фактически НЕ получают суммы коллег, хотя спека §Ж3 говорит, что должны. Мелкий spec-drift, замаскирован solo-team-данными.

Где авторизация реально проверяется: только в `resolveTargetUser` (скоуп по user_id для не-privileged). Где дыра: на уровне роли — нет ни role-middleware, ни Policy, ни проверки в FormRequest/контроллере, что эта роль вообще имеет право видеть кабинет.

## 6. Бэклог проблем

### Сводная таблица

| Severity (FINAL) | Тип | Заголовок | Проверка |
|---|---|---|---|
| **major** | DATA-INCONSISTENCY | Сравнение с командой мертво в проде: у план-менеджеров `department_id=NULL` | ✅ подтверждено (static + live DB + live HTTP) |
| **major** | SECURITY | Нет role-гейта на `/api/me/*` — lawyer (любая роль) получает 200, не 403 | ✅ подтверждено (static + live) · 🌐 подтверждено в браузере |
| minor | DEAD-CODE | Config-модели + FK/status/currency `salary_plans` построены, но не читаются (мёртвы до M10) | не верифицировано (Phase-1) |
| minor | BUG | `score_pct` игнорирует валюту плана (USD/EUR планы считаются неверно) | не верифицировано (Phase-1) |
| minor | DEAD-CODE | `'alone'`-empty-state в TeamComparisonTable недостижим | не верифицировано (Phase-1) |
| minor | CONVENTION | Authz читает enum `users.role`, не spatie (двойной источник) | не верифицировано (Phase-1) |
| minor | BUG | Team-запрос фильтрует только role=Manager — исключает director/не-Manager-лида | не верифицировано (Phase-1) |
| minor | STUB | «Смотреть другого менеджера» проброшен, но без UI-входа | не верифицировано (Phase-1) |
| minor | BUG | Target ленты рисуется сырым lowercase-токеном, непереведён и без ссылки | не верифицировано (Phase-1) |
| minor | SPEC-DRIFT | Суммы коллег не эмитятся для director/admin (спека §Ж3 говорит — должны) | не верифицировано (Phase-1) |
| trivial | DEAD-CODE | `score_danger_threshold` читается, но не используется | не верифицировано (Phase-1) |
| trivial | BUG | Мусорный `ftm_only` нормализуется в null→false без 422 | не верифицировано (Phase-1) |
| trivial | CONVENTION | Пороги badge дублируются client-side (риск дрейфа) | не верифицировано (Phase-1) |
| trivial | CONVENTION | Дата ленты хардкодит русскую локаль, игнорируя i18n | не верифицировано (Phase-1) |
| trivial | DEAD-CODE | Тип `KpiPeriod` несёт неиспользуемый литерал `'last_month'` | не верифицировано (Phase-1) |
| trivial | SPEC-DRIFT | `income_fact` = SUM(deals.amount), не платежи (HD1-аппроксимация) | не верифицировано (Phase-1) |
| trivial | CONVENTION | Paginator ленты получает пустой `rows-per-page-options` | не верифицировано (Phase-1) |

**Плюс из live-QA (NEW-*), затрагивающие домен косвенно** (источник = live-QA): **NEW-5 (P1)** — `/api/admin/*` справочники доступны роли manager (200 OK) — это RBAC-дыра соседнего домена справочников, но укрепляет картину системного отсутствия role-гейтов; **NEW-4 (P1)** — `Route [login] not defined` 500 при запросе без Bearer — общий auth-handler дефект, проявится и на `/api/me/*`. Оба — не дефекты собственно sales-kpi, но влияют на его поверхность безопасности.

---

### BLOCKER/MAJOR — развёрнуто

#### MAJOR · DATA-INCONSISTENCY · ✅ подтверждено (static + live DB + live HTTP)
**Сравнение с командой мертво в проде: у план-менеджеров `department_id=NULL`**

- **Файлы:** `src/app/Domain/Sales/Services/ManagerKpiService.php:392` (и solo-ветка 387-405), `src/database/seeders/ManagerKpiSeeder.php:138`, `:61`.
- **Что происходит (evidence):** `buildTeamData` возвращает solo-команду (size 1, rank 1, members=[self]) когда `target.department_id` = NULL (lines 387-405). Живая БД: менеджеры 4/5/6 — единственные с `salary_plans` — все с `department_id=NULL`; строка `team_targets` тоже `department_id=NULL`. Отдел 2 содержит менеджеров 7-12, но НИ У ОДНОГО нет salary-плана. Итог: вся фича сравнения с командой показывает бессмысленную таблицу из одной строки (self) для каждого KPI-несущего менеджера. Verdict-probe подтвердил end-to-end: `SELECT id,role,department_id FROM users WHERE role='manager'` → 4,5,6 NULL, 7-12 = dept 2; `manager1` (id 4) `GET /api/me/kpi` → `team {avg_pct:82, rank:1, size:1, members:[self]}`. Refutation проверена: в `:391` жёсткий solo-возврат при NULL-dept, без fallback-сбора пиров; ветка для не-NULL-dept (line 406) для план-менеджеров недостижима; runtime-пути, перезаполняющего `department_id`, нет. Сидер ПИШЕТ `department_id` (lines 61,79,138) и `syncRoles` spatie (85,145) — значит SystemReset/re-seed, очевидно, обнулил dept на живых строках.
- **Repro:** `manager1@mgcrm.test` `GET /api/me/kpi` → `team {avg_pct:82, rank:1, size:1, members:[self]}` несмотря на 6 менеджеров в отделе 2; `SELECT department_id FROM users WHERE role='manager'` показывает 4,5,6 NULL и 7-12 = 2.
- **Предлагаемый фикс:** пере-сидировать так, чтобы salary-план-менеджеры принадлежали отделу (или бэкфилл `department_id` для 4/5/6 и дать 7-12 salary-планы); гарантировать, что SystemReset сохраняет `users.department_id`; добавить multi-manager feature-тест, проверяющий `size>1` и нетривиальный rank.

#### MAJOR · SECURITY · ✅ подтверждено (static + live) · 🌐 подтверждено в браузере
**Нет role-гейта на `/api/me/*` cabinet-маршрутах — lawyer (любая роль) получает 200, не 403**

- **Файлы:** `src/routes/api.php:402` (и `:404`), `src/app/Http/Requests/Sales/KpiRequest.php:19` (`authorize()`), `front/src/shared/nav/navItems.ts:83`, `:166`, `front/src/router/routes/base.ts:107`.
- **Что происходит (evidence):** группа маршрутов `me` вложена в group line-145 со middleware `['auth:sanctum','2fa','locale','visibility']` без промежуточной закрывающей скобки — **role-middleware нет** (`api.php:402`). `KpiRequest::authorize()` возвращает true. Контроллер `kpi()` (`ManagerCabinetController.php:46-52`) не делает `authorize()`/Policy/gate. FE-nav-item (`navItems.ts:83-87`, `166-169`) не имеет `roles[]`/`adminOnly` → `filterNavByRole` (`:305-313`) дефолтно возвращает его всем; route-meta (`base.ts:107-110`) только `requiresAuth` (тогда как соседи `pipeline-settings`, `templates` НЕСУТ `roles[]` — то есть это именно упущение). Verdict: live HTTP `lawyer@mgcrm.test` `GET /api/me/kpi` → HTTP 200 со своими нулями (не 403); lawyer также видит nav-item «Кабинет менеджера». Refutation: проверены все слои — (1) middleware группы — кроме auth/2fa/locale/visibility ничего; (2) FormRequest authorize → true; (3) controller-level authorize/Policy — отсутствуют; (4) `resolveTargetUser` скоупит только по `user_id`, не отбраковывает по роли — lawyer на своих данных проходит; (5) `visibility` — это per-record data-scope, не role-гейт. Live-QA C.9 подтвердил в браузере: `/manager-cabinet` полностью рендерится для lawyer, `/api/me/kpi` и `/api/me/activity-feed` → 200.
- **Воздействие:** экспозиция — только свои нули (суммы коллег не эмитятся → утечки чужих данных нет), поэтому это access-surface/UX-проблема, а не утечка данных; severity остаётся **major** как отсутствующая role-граница на отгруженной sales-KPI-поверхности.
- **Repro:** `curl GET /api/me/kpi` с токеном lawyer → HTTP 200. Логин как lawyer в браузере → сайдбар показывает «Кабинет менеджера» → все KPI=0.
- **Предлагаемый фикс:** добавить `roles:['admin','director','manager']` к обоим nav-entries кабинета + role-meta-гард на маршрут роутера, и зеркально — role-middleware (или Policy) на `/me`-KPI-маршруты. Подтвердить целевую аудиторию с PM (lawyer/finance, вероятно, не должны видеть кабинет).

---

### minor (не верифицировано — Phase-1)

- **DEAD-CODE — config-модели + FK/status/currency `salary_plans` построены, но не читаются (мёртвы до M10).** `CommissionRule`/`TeamTarget` не имеют потребителей вне relations `SalaryPlan` (`SalaryPlan.php:65,73`) и `ManagerKpiSeeder`; `ManagerKpiService` читает только `personal_income_plan_kopecks` и `personal_ftm_plan`. Соответствует scope «НЕ входит», но сейчас мёртвый вес. Фикс: пометить `@deprecated-until-M10` или перенести три миграции в M10-slice. (`CommissionRule.php`, `TeamTarget.php`, `SalaryPlan.php:30`)
- **BUG — `score_pct` игнорирует валюту плана.** `income_fact` FX-конвертится в base (`ManagerKpiService.php:58`), а `income_plan` берётся сырым `personal_income_plan_kopecks` (`:59`); `personal_income_plan_currency` нигде не читается. Не-RUB-план делил бы RUB-конвертированный факт на план в инвалюте → неверный `score_pct`. Замаскировано, т.к. все 3 живых плана — RUB. Фикс: конвертировать план через `ExchangeRateService` до `scorePct`, либо принудить base-валюту на записи и убрать колонку. (`SalaryPlan.php:35`)
- **DEAD-CODE — `'alone'`-empty-state в TeamComparisonTable недостижим.** Шаблон: loading→skeleton; `kpi`→DataTable; `v-else`→`'alone'` (`TeamComparisonTable.vue:69-76`). Бэкенд ВСЕГДА возвращает team-объект (`KpiResource` не null) с `size>=1`, `members=[self]` для solo — значит после загрузки `kpi` всегда truthy, и `v-else` не рендерится. Solo-менеджер видит таблицу из 1 строки вместо текста «вы одни»; ключ `managerCabinet.team.alone` не показывается. Фикс: рендерить `alone` при `kpi.team.size<=1` внутри kpi-ветки, не как `v-else`. (`TeamComparisonTable.vue:69,:7`)
- **CONVENTION — authz читает enum `users.role`, не spatie.** Privilege/team-проверки читают `viewer.role`/`target.role` enum (`ManagerKpiService.php:350,408,439`). Сидер одновременно `syncRoles` spatie (85,145) И ставит колонку role. ARCHITECTURE.md мандатит spatie. Два источника могут разойтись (spatie director, но `users.role=manager` → трактуется как не-privileged). Фикс: выбрать канонический источник (`hasAnyRole(['admin','director'])` либо синхронизировать enum из spatie через observer) + тест.
- **BUG — team-запрос фильтрует только role=Manager.** `buildTeamData` собирает `member_ids WHERE role=Manager` (`ManagerKpiService.php:408`). Director, смотрящий свой KPI (или не-Manager-лид отдела), выпадает из `member_pcts` — rank/size неверны, зритель может вовсе отсутствовать в сравнении. Фикс: всегда включать target id в набор и/или расширить фильтр на всех active-sales отдела + тест на director-self.
- **STUB — «смотреть другого менеджера» проброшен, но без UI-входа.** `canViewOthers` (admin|director) и `viewedUserId` (из `route.query.user_id`) вычислены, возвращены (`useManagerCabinetPage.ts:171-172`) и переданы во все три loader-а; бэкенд `resolveTargetUser` поддерживает. Но `index.vue` не рисует пикер и не читает `canViewOthers`/`viewedUserId`. Director может инспектировать подчинённого только ручной правкой `?user_id=N`. Фикс: добавить select-менеджера при `canViewOthers`, выставляющий `route.query.user_id`; либо задокументировать cross-user view как URL-only для S1.8. (`useManagerCabinetPage.ts:29,:34`, `index.vue`)
- **BUG — target ленты рисуется сырым lowercase-токеном, непереведён и без ссылки.** Колонка «Объект» рендерит `'{{ row.target_type }} #{{ row.target_id }}'` буквально. Живые `activities.target_type` — lowercase-токены `'contact'`/`'deal'`; ячейка показывает напр. `deal #5` — непереведено, lowercase, не кликабельная ссылка. Фикс: маппинг через i18n (`t('targetType.deal')`) + `router-link`; или вернуть `target_label`+`target_route` из API. (`ActivityFeedList.vue:81,:82`)
- **SPEC-DRIFT — суммы коллег не эмитятся для director/admin (спека §Ж3 говорит — должны).** Спека §Ж3/§В3: для managers — анонимизация до `full_name`+`score_pct`, но director/admin должны получать `income_fact_kopecks` коллег отдельным ключом. Сервис вычисляет `isPrivileged` (`:439`), но payload `members` несёт только `full_name`/`score_pct`/`is_viewer` для всех ролей. Замаскировано solo-team-данными. Фикс: либо включать `income_fact_kopecks` per-member для privileged, либо обновить vault, что суммы коллег не показываются никогда. (`ManagerKpiService.php:437,:439`)

### trivial (не верифицировано — Phase-1)

- **DEAD-CODE — `score_danger_threshold` читается, но не используется.** `scoreBadge` читает `$dangerThreshold` (`ManagerKpiService.php:161`), но не применяет; danger — fallthrough. Оба порога = 80 в `crm.php:200`. Изменение `score_danger_threshold` ни на что не влияет.
- **BUG — мусорный `ftm_only` → null→false без 422.** `prepareForValidation` использует `filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)` → мусор становится null, проходит nullable-boolean, читается как false. Плохой ввод проглатывается вместо 422. (`KpiRequest.php:33`)
- **CONVENTION — пороги badge дублируются client-side.** `TeamComparisonTable.badgeFor` пере-выводит badge (`>=100 success / >=80 warning / else danger`) на клиенте, т.к. `team.members[]` несёт только `score_pct`. Второй источник истины, тихо разойдётся при смене `score_warning_threshold`. Фикс: добавить `score_badge` в каждого team-member в `KpiResource`. (`TeamComparisonTable.vue:99`, `ManagerKpiService.php:158`)
- **CONVENTION — дата ленты хардкодит русскую локаль.** `formatDate` вызывает `d.toLocaleString('ru', {...})` с литералом `'ru'`; MonthStepper корректно использует `useI18n().locale.value`. В EN-сессии даты ленты на русском. (`ActivityFeedList.vue:177`)
- **DEAD-CODE — тип `KpiPeriod` несёт неиспользуемый `'last_month'`.** `KpiPeriod = 'current_month' | 'last_month' | string`. MonthStepper эмитит только `'current_month'`/`'YYYY-MM'`. (Бэкенд `KpiRequest` всё ещё принимает `last_month` как валидный enum — достижим только через raw URL.) (`managerCabinet.ts:1`, `MonthStepper.vue:48`)
- **SPEC-DRIFT — `income_fact` = SUM(deals.amount), не платежи (HD1).** `income_fact` = SUM won-сделок по `stage_changed_at`, FX-конвертировано; задокументировано HD1. Игнорирует contract status; M10 должен перейти на first-payment и учитывать `requires_signed_contract`/`payment_trigger`. Нет S1.8-action. (`ManagerKpiService.php:227`, `ManagerKpiSeeder.php:186`)
- **CONVENTION — paginator ленты получает пустой `rows-per-page-options`.** `:rows-per-page-options="[]"` — безобидный no-op (dropdown не рисуется). Размер фиксирован 25. Фикс: убрать проп. (`ActivityFeedList.vue:107`)

## 7. Расхождения со спекой (vault) и предложения по актуализации

**`Sales — Кабинет менеджера.md`:**
- *frontmatter status / Blocking bug:* спека говорит `status: blocking-bug`, «BUG-LAYOUT-1 — ActivityFeedList row 5 недостижима», «Коммиты: не закоммичено». Реальность: BUG-LAYOUT-1 закрыт (acceptance-чеклист line 754 подтверждает `min-height:0`, 2026-06-12), кабинет live (lawyer/manager1 эндпоинты → 200 с полным payload), модуль закоммичен и работает. **Изменить:** `status: done` (или `partial`); убрать blocking-bug-баннер; заменить на live-реальность: working core, мёртвое сравнение с командой из-за NULL `department_id`, незагейтованные маршруты.
- *Что делает / scope (director/admin через `?user_id=`):* спека говорит «director/admin могут смотреть любого через `?user_id=`». Реальность: бэкенд поддерживает, но FE-UI пикера нет — только ручная правка URL. **Добавить:** «Cross-user view в S1.8 — URL-only (без пикера); director/admin передают `?user_id=N` вручную. UI-пикер отложен.»

**`Спринт 1 — S1.8 Кабинет менеджера (детальный план).md`:**
- *§Ж3 Анонимизация коллег / §В3 contract:* спека говорит «director/admin видит полные суммы; backend включает `income_fact_kopecks` коллег отдельным ключом для privileged». Реальность: `buildTeamData` не эмитит `income_fact_kopecks` ни для одной роли; шлёт только `full_name`/`score_pct`/`is_viewer`; `isPrivileged` вычислен, но не используется для сумм. **Изменить:** либо пометить фичу «суммы-коллег-для-director» как deferred, либо обновить §Ж3, что суммы коллег не показываются ни в одной роли в S1.8.
- *§З Hardening HD4 (empty department) + §Б3 team:* спека говорит «`department_id` null → team solo (size 1); FE TeamComparisonTable показывает `'alone'` empty-state». Реальность: `'alone'`-state недостижим (`kpi` всегда truthy после загрузки); solo-менеджер видит таблицу из 1 строки. И в LIVE ВСЕ salary-план-менеджеры — NULL-dept, так что сравнение никогда не отрабатывает с `size>1`. **Добавить known-issue:** «`alone`-state недостижим — починить условие на `kpi.team.size<=1`»; и data-note: засеянные менеджеры должны делить отдел, чтобы сравнение было осмысленным.
- *§Е API endpoints auth / acceptance visibility:* спека говорит «все три под `['auth:sanctum','2fa','locale','visibility']`; manager видит self, director/admin любого». Реальность: верно как написано, НО role-middleware нет — lawyer/finance/любая роль достигает маршрутов (200, свои нули); спека не называет роли, которые НЕ должны видеть кабинет. **Добавить explicit roles-clause:** маршруты кабинета + nav ограничить `admin`/`director`/`manager`; lawyer → 403 / без nav-item. Задокументировать целевую аудиторию.

**`team_targets` naming-drift:** спека §В2 называет FK «team_id (FK departments)», а migration/model используют `department_id`. **Изменить:** привести vault к фактическому имени `department_id`.

**config `crm.kpi`:** спека §Г отмечает оба порога = 80 «danger < 80, warning 80-99», то есть `score_danger_threshold` намеренно избыточен. **Изменить:** убрать ключ либо реализовать честную 3-полосную логику (success/warning/danger с двумя разными порогами).
