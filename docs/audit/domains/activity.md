# Аудит домена: Активности — задачи, встречи, отчёты о встречах

> Ключ домена: `activity` · Код: `app/Domain/Activity` (BE), `front/src/pages/MyTasksPage` + `front/src/components/{ActivityFormDialog,MeetingReportDialog}.vue` (FE)
> Дата аудита: 2026-06-24 · Источники: Phase-1 (`activity.json`), верификация (`activity__majors.json`), live-QA (`live-qa.md`), live-схема + rowcounts.

## 1. Назначение

Домен «Активности» — единый журнал работы по сущностям CRM: звонки, встречи, задачи, заметки, follow-up и презентации (`kind` ∈ call/meeting/task/note/follow_up/presentation), привязанные к сделке/компании/контакту (полиморфно, без FK) либо без цели (`target_type=NULL` = личная задача). На нём держатся: статус-машина задач, личный таскборд (Мои задачи), таймлайны карточек, проставление «вовлечённости» (`last_activity_at`) на компании/контакты, лента действий (entity-log), а также конструктор **отчёта о встрече** (реестр вопросов `meeting_report_questions`/`_options`) и учёт **FTM** (first-time meeting — первая встреча с ЛПР), который кормит KPI-кабинет менеджера.

**Зрелость: частично (split-зрелость).**
- **Задачная половина — зрелая и реально эксплуатируется**: миграция ⇄ live-DDL ⇄ `fillable`/`casts` модели полностью согласованы (нет DATA-INCONSISTENCY), контроллеры тонкие, вся логика (статус-машина, scopedQuery как зеркало `DealService`, gate `task_types` сделки, IDOR-gate по цели, денормализация `department_id`, авторизация на уровне FormRequest) лежит в `ActivityService`. В живой БД **24** активности (все deal/contact-targeted), CRUD/пресеты/my-board/inline-edit прогоняются.
- **Половина «отчёт о встрече + FTM» — мёртва-холодна в проде и структурно сломана сквозняком**: в живой БД **0** строк с `meeting_report_json`, **0** FTM-строк, **0** company-targeted активностей, **0** standalone-задач (`target_type=NULL`). Реестр вопросов (**6** вопросов / **8** опций) — только seed. Конструктор отчёта не способен записать FTM, per-pipeline вопросы недостижимы из UI, админ-UI реестра отсутствует, сохранение отчёта не проставляет вовлечённость и не пишет в ленту. Эта поверхность ни разу не отработала end-to-end.

---

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI + endpoint) | Как (кратко) | Статус | Примечание |
|---|---|---|---|---|---|
| Activity CRUD + visibility scoping | Все роли (own/department/all по роли) | MyTasksPage list/kanban, OpenTasksList, ActivityFormDialog · GET/POST/PATCH/DELETE `/api/activities` | `scopedQuery` зеркалит `DealService`; create гоняет gate `task_types` + IDOR-gate по цели + денормализует `department_id`; responsible\|creator всегда достижимы | ✅ работает | 24 живых строк; ядро прогоняется и покрыто тестами |
| Activity status machine | responsible \| creator \| All-scope | MyTasksTable inline-dropdown, complete/reopen · PATCH `/status`, POST `/complete`, POST `/reopen` | new→{in_progress,rejected}; in_progress→{done,rejected,new}; done→{in_progress}; rejected→{new,in_progress}. `complete()` ставит done+`is_closed`+completed_*+progress=100; `reopen()` чистит; `changeStatus(done)` ставит completed_* но **НЕ** `is_closed` | 🟡 частично | `changeStatus(done)` ≠ `complete()` по `is_closed` и по engagement/entity-log; FE оптимистично ставит `is_closed=true`, что противоречит BE |
| Engagement propagation (last_activity_at + entity-log) | система на create/complete | `ActivityService::create/complete` → touchTargetEngagement + recordCompletionOnTarget | company-цель штампует company; contact — contact; deal фанит на deal.company + связанные контакты; complete() пишет meeting_held/task_completed в entity-log | 🟡 частично | `MeetingReportService::saveReport` и `changeStatus(done)` **пропускают** оба шага — встречи через конструктор/inline-done невидимы для вовлечённости и ленты |
| Meeting report constructor (E8) | любой, кто видит сделку | MeetingReportDialog (из ActivityFormDialog / DealFeedItem) · POST `/api/deals/{deal}/meeting-report` | валидирует `answers.question_id` в активном реестре; reject пустого; create/update meeting-Activity status=done с `meeting_report_json` | 🔴 сломан | 0 отчётов в проде. FTM-флаги дропаются, `pipeline_id` не шлётся, `is_required` — фантом, без engagement/log. End-to-end ни разу не работал |
| FTM (first-time-meeting) counting | система / KPI-кабинет менеджера | `ManagerKpiService::countFtmForUser/feedForUser`, `ActivityFeedItemResource::ftmCounted`, `ActivityService::applyFtmConditions` | 5-условный предикат: kind=meeting AND is_first_time_meeting AND ftm_decision_maker_attended AND ftm_presentation_shown AND ftm_report_url IS NOT NULL | 🔴 сломан | 0 FTM-строк. Конструктор (предполагаемая точка захвата FTM) не может выставить флаги. Предикат **триплицирован** (ActivityService:1059, ManagerKpiService:205, ActivityFeedItemResource:41) |
| Personal task board bucketing | текущий пользователь | MyTasksPage kanban · GET `/api/activities/my-board` | открытые task-like активности где responsible\|creator, в бакеты overdue/today/tomorrow/this_week/next_week по `due_at` vs `now()->startOfDay()` | 🟡 частично | Работает, но границы суток считаются от UTC `now()`, команда в Asia/Dubai (+4ч) → today/tomorrow «съезжают» на 4ч раньше |
| Quick reschedule preset | responsible\|creator\|All-scope | задуманные quick-кнопки списка · POST `/api/activities/{id}/reschedule` | preset tomorrow/next_week/next_month → сервер считает start-of-day `due_at` | 🔴 сломан | МЁРТВ — нет FE-вызова. FE переносит через PATCH `due_at`. Vault документирует другой контракт (`{due_at}`); таймзоно-корректная серверная математика недостижима |
| Meeting-report question registry admin CRUD | Admin, Director (задумано) | задуманные Settings/«Справочники» · GET/POST/PATCH/DELETE `/api/meeting-report-questions` | add/edit/reorder/deactivate/delete вопросов + опций, per-pipeline | ⚪ отсутствует | Нет FE-UI/страницы/компонента/api-функции. 4 мёртвых admin-endpoint. 6 вопросов/8 опций — только seed |
| Assignment notification | система → responsible | событие `ActivityAssigned` на create-with-responsible/reassign | `NotifyActivityAssigneeListener` пишет in-app уведомление кроме: нет responsible, kind=note, self-assign | ✅ работает | `ActivityCreated`/`ActivityStatusChanged` слушателей НЕ имеют → отчёт о встрече уведомление не шлёт (но он же self-assign) |
| Company timeline (E7) | visibility-scoped | CompanyActivitiesTab · GET `/api/activities` (timeline mode) | company-target активности + активности всех видимых сделок компании | ⚪ не верифицировано | 0 company-targeted активностей живьём → company-target половина не прогонялась в проде; deal-fan-out скорее всего работает |

Примечание live-QA: журней-таблица live-QA не покрыла MyTasksPage/активности отдельным сценарием (тестировались dashboard/deals/contacts/onboarding/manager-cabinet). Это согласуется с «холодным» статусом meeting-report/FTM — поверхность не трогали.

---

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк в живой БД | Статус |
|---|---|---|---|---|
| `Activity` | `activities` | Единая полиморфная сущность call/meeting/task/note/follow_up/presentation; несёт статус-машину, FTM-флаги, snapshot `meeting_report_json`, денормализованный `department_id`. Цель = deal/company/contact (без FK) или NULL = личная задача | **24** (deal+contact-targeted; **0** company-targeted, **0** standalone/NULL-target, **0** FTM, **0** с `meeting_report_json`) | built |
| `MeetingReportQuestion` | `meeting_report_questions` | Запись реестра конструктора отчёта; global (`pipeline_id` NULL) или per-pipeline; `kind` text\|select; admin CRUD | **6** (все global, только seed — не редактируются из приложения) | built |
| `MeetingReportOption` | `meeting_report_options` | Вариант ответа для вопроса kind=select | **8** | built |

**Расхождения migration ↔ live-schema ↔ model:**
- ✅ `activities`: миграция ⇄ модель (`fillable`/`casts`) ⇄ live-DDL **полностью согласованы**. Все FTM-колонки (`is_first_time_meeting`, `ftm_decision_maker_attended`, `ftm_presentation_shown`, `ftm_report_url`), `meeting_report_json` (json) и `department_id` присутствуют как в спеке; все 6 индексов на месте.
- ✅ `meeting_report_questions`/`_options`: миграция ⇄ модель ⇄ live-DDL согласованы (колонка `kind` не `type`; `pipeline_id` cascade; индексы на месте).
- ⚠️ **FE↔BE контракт-дрейф (major):** FE-DTO `MeetingReportQuestionDto` объявляет `is_required` (`entities/activity.ts:112`), но колонки/атрибута/поля ресурса `is_required` **нет нигде** — `MeetingReportQuestionResource` (стр. 18) отдаёт только `id/pipeline_id/text/kind/sort_order/is_active/options`; колонка отсутствует и в `schema.sql`, и в live `\d`. Фантомное поле (детально — issue #4).
- 🧊 **Построено-но-холодно (info):** реестр засеян (6/8), но **0** активностей с `meeting_report_json`, **0** FTM, **0** company-targeted, **0** standalone — режимы meeting-report/FTM/company-activity/standalone-task ни разу не отработали в живой БД.
- 📄 **Doc↔code дрейф (minor):** Vault Wave-2 документирует body POST `/reschedule` как `{"due_at":"..."}`, а request принимает только `{"preset":...}` (см. §7).

---

## 4. Эндпоинты и покрытие фронтом

| Метод + Path | Контроллер@метод | Авторизация | Вызывается FE? | Примечание |
|---|---|---|---|---|
| GET `/api/activities` | `ActivityController@index` | auth:sanctum + `ActivityPolicy@viewAny` (true; строки фильтруются в scopedQuery); timeline-mode гейтит цель через `assertTargetVisible` | да | MyTasksPage list (preset 'all') + таймлайны (useDealFeed/useDealActivities/useCompanyActivities/useEntityFeed); paginated и timeline |
| POST `/api/activities` | `ActivityController@store` | `StoreActivityRequest::authorize` → `create` (true для любого auth); target visibility + gate `task_types` в сервисе | да | ActivityFormDialog, TaskQuickForm, MyTasksPage create, Kanban quick-add |
| POST `/api/activities/bulk` | `ActivityController@bulkStore` | `StoreBulkActivityRequest::authorize` → create(true); контроллер перепроверяет view на КАЖДОЙ сделке (all-or-nothing 403) | да | DealsPage BulkAddTaskDialog |
| GET `/api/activities/{activity}` | `ActivityController@show` | `ActivityPolicy@view` (own/department/all; responsible\|creator всегда достижимы) | да | ActivityFormDialog edit-load, MeetingReportDialog load-existing |
| PATCH `/api/activities/{activity}` | `ActivityController@update` | `UpdateActivityRequest::authorize` → `ActivityPolicy@update` (тот же canAccess); **`responsible_id` reassignment UNSCOPED** (`exists:users,id` only) | да | ActivityFormDialog, OpenTasksList inline, MyTasksTable inline, TaskQuickForm reschedule (PATCH `due_at`) |
| DELETE `/api/activities/{activity}` | `ActivityController@destroy` | `ActivityPolicy@delete` (All-scope ИЛИ created_by == user) | да | MyTasksPage delete, OpenTasksList 3-click delete (через родителя), TaskQuickForm |
| POST `/api/activities/{activity}/complete` | `ActivityController@complete` | `CompleteActivityRequest::authorize` → `ActivityPolicy@complete` (All ИЛИ responsible ИЛИ creator) | да | MyTasksPage, OpenTasksList, TaskQuickForm, useTaskBoard kanban, DealFeedItem |
| POST `/api/activities/{activity}/reopen` | `ActivityController@reopen` | `authorize('reopen')` → `ActivityPolicy@reopen` (= complete) | да | MyTasksPage reopen, DealFeedItem |
| PATCH `/api/activities/{activity}/status` | `ActivityController@status` | `ChangeStatusRequest::authorize` → `ActivityPolicy@changeStatus` (= complete) | да | MyTasksTable inline status-dropdown (patchStatus); ⚠️ триггерит баг расхождения `is_closed` для status='done' |
| POST `/api/activities/{activity}/reschedule` | `ActivityController@reschedule` | `RescheduleActivityRequest::authorize` → `ActivityPolicy@update`; rules: preset ∈ {tomorrow,next_week,next_month} | **НЕТ** | 💀 МЁРТВЫЙ endpoint — нет FE-вызова. activity.ts не экспортит reschedule-fn; FE переносит через PATCH `due_at`. SPEC-DRIFT: vault документирует `{due_at}`, код — только preset |
| GET `/api/activities/presets/{preset}` | `ActivityController@presets` | `ActivityPolicy@viewAny`; строки scoped + per-preset предикат | да | MyTasksPage list view |
| GET `/api/activities/counts-by-preset` | `ActivityController@countsByPreset` | `ActivityPolicy@viewAny`; counts тем же scopedQuery+predicate | да | MyTasksPage tabs (activityStore.fetchCounts) |
| GET `/api/activities/my-board` | `ActivityController@myBoard` | `ActivityPolicy@viewAny`; строки hard-scoped к responsible\|creator (visibility-scope игнорируется) | да | MyTasksPage kanban (useTaskBoard) |
| GET `/api/activities/my-open-count` | `ActivityController@myOpenCount` | `ActivityPolicy@viewAny`; считает responsible_id == user AND is_closed=false | да | Бейдж в навигации (activityStore.fetchMyOpenCount) |
| GET `/api/meeting-report/questions` | `MeetingReportController@questions` | **auth only (без policy)** — реестр читает любой авторизованный | да | MeetingReportDialog; **ВСЕГДА** с `pipeline_id=null` (ни один вызов не передаёт `pipelineId`) |
| POST `/api/deals/{deal}/meeting-report` | `MeetingReportController@save` | `SaveMeetingReportRequest::authorize` (user!=null) + контроллер `authorize('view', deal)` — пишет отчёт любой, кто **видит** сделку | да | MeetingReportDialog; FE не шлёт `ftm_*`, и BE их всё равно дропает |
| GET `/api/meeting-report-questions` | `MeetingReportQuestionController@index` | `authorize('viewAny')` → true (любой auth) | **НЕТ** | 💀 Admin-список реестра — нет FE-вызова. Admin-UI не существует |
| POST `/api/meeting-report-questions` | `MeetingReportQuestionController@store` | `StoreMeetingReportQuestionRequest::authorize` → `MeetingReportQuestionPolicy@create` (Admin\|Director) | **НЕТ** | 💀 Admin create — нет FE-вызова / нет UI |
| PATCH `/api/meeting-report-questions/{question}` | `MeetingReportQuestionController@update` | `UpdateMeetingReportQuestionRequest::authorize` → policy@update (Admin\|Director) | **НЕТ** | 💀 Admin update — нет FE-вызова / нет UI |
| DELETE `/api/meeting-report-questions/{question}` | `MeetingReportQuestionController@destroy` | `authorize('delete')` → policy@delete (Admin\|Director) | **НЕТ** | 💀 Admin delete — нет FE-вызова / нет UI |

**Orphaned FE-вызовы:** не обнаружено (все FE-вызовы попадают в существующие endpoint'ы).
**Мёртвые endpoint'ы:** 5 — POST `/reschedule` (FE переехал на PATCH `due_at`) и все 4 admin CRUD `/api/meeting-report-questions` (нет admin-UI).

---

## 5. RBAC домена

| Действие | Кому разрешено | Где реально проверяется | Оценка |
|---|---|---|---|
| Просмотр/список активностей | Все роли (own/department/all scope от роли через VisibilityResolver) | `ActivityPolicy@view/viewAny` + `ActivityService::scopedQuery` | ✅ корректно (зеркало DealService) |
| Создание активности | Любой авторизованный | `ActivityPolicy@create` (true); target-visibility + gate `task_types` в сервисе | ✅ create открыт by design, но IDOR-цель и task_types закрыты в сервисе |
| Обновление активности | All-scope (admin/director) ИЛИ responsible ИЛИ creator ИЛИ в поддереве отдела | `ActivityPolicy@update` → canAccess | ✅ |
| Complete/reopen/changeStatus | All-scope ИЛИ responsible ИЛИ creator | `ActivityPolicy@complete/reopen/changeStatus` | ✅ |
| Удаление активности | All-scope (admin/director) ИЛИ только creator | `ActivityPolicy@delete` | ✅ |
| Сохранение отчёта о встрече на сделке | Любой, кто **видит** сделку | `MeetingReportController@save` → `authorize('view', deal)` | 🟡 авторизация по view, а не по «работе со сделкой» — широковато, но в рамках текущей модели прав |
| Чтение реестра вопросов отчёта | Любой авторизованный | `MeetingReportController@questions` (без policy) | 🟡 без policy, но это справочные данные |
| CRUD вопросов отчёта | Admin, Director (только BE; UI нет) | `MeetingReportQuestionPolicy@create/update/delete` по `users.role` enum | ⚪ BE-гейт есть и корректен, но недостижим (нет UI) |
| **Переназначение `responsible_id`** | Любой, кто может update активность — на **ЛЮБОГО** пользователя (unscoped) | Store/UpdateActivityRequest правило `exists:users,id` only (без department/scope-проверки) | 🟥 **дыра**: задачу можно протолкнуть в видимость чужого отдела; `department_id` при reassign не пересинхронизируется (см. issue #10) |

Главная RBAC-дыра домена — **unscoped reassignment `responsible_id`** (issue #10, SECURITY/minor): авторизация на «обновить активность» есть, но получатель назначения никак не ограничен областью видимости актора.

---

## 6. Бэклог проблем

После верификации: **0 blocker, 4 major, 7 minor, 0 trivial.** Phase-1-блокеров для домена не было (файлов `activity__b*.json` нет); все 6 верифицированных пунктов — из `__majors.json` (i0–i5): два остались major, четыре понижены до minor. Остальные minor — из Phase-1 json, независимо не верифицированы.

### Сводная таблица

| FINAL severity | Тип | Заголовок | Проверка |
|---|---|---|---|
| **major** | BUG | FTM нельзя записать через конструктор отчёта (FE не шлёт `ftm_*`, BE `saveReport()` их дропает) | ✅ подтверждено (code-read 4 файлов + live psql) |
| **major** | MISSING | У admin CRUD вопросов отчёта нет FE-UI (4 мёртвых admin-endpoint; реестр seed-only) | ✅ подтверждено (grep FE + route-scan + live counts) |
| **major** | BUG | Inline status→done оставляет задачу открытой и пропускает engagement/log (`changeStatus(Done)` ≠ `complete()`) | ✅ подтверждено (code-read, без мутации) |
| **major** | BUG | Сохранение отчёта о встрече пропускает engagement-штамп и entity-log (встреча невидима для tiers + ленты) | ✅ подтверждено (code-path) |
| minor | BUG | MeetingReportDialog не передаёт `pipeline_id` — per-pipeline вопросы недостижимы | ⚠️ частично (понижено major→minor: live-impact нулевой, 0/6 вопросов pipeline-scoped) |
| minor | DEAD-CODE | FE ссылается на несуществующее поле вопроса `is_required` — required-UX мёртв и не enforced | ⚠️ частично (понижено major→minor: чистый dead FE-контракт, без security/data-impact) |
| minor | DEAD-CODE | POST `/api/activities/{id}/reschedule` — мёртвый route (нет FE) и противоречит vault-контракту | не верифицировано (Phase-1) |
| minor | BUG | Границы суток считаются от UTC `now()`, команда в Asia/Dubai (+4ч) — today/tomorrow «съезжают» на 4ч раньше | не верифицировано (Phase-1) |
| minor | CONVENTION | FTM-предикат триплицирован в 3 файлах (риск дрейфа) | не верифицировано (Phase-1) |
| minor | SECURITY | Переназначение `responsible_id` unscoped — задачу можно протолкнуть в видимость чужого отдела | не верифицировано (Phase-1) |
| minor | STUB | Машина meeting-report + FTM построена, но в проде полностью холодна (не прогнана end-to-end) | не верифицировано (Phase-1) |

---

### MAJOR-1 · BUG · ✅ подтверждено (code-read 4 файлов + live psql) — FTM нельзя записать через конструктор отчёта

**Severity:** major (Phase-1 major → verdict confirmed/major, conf 0.97)
**Файлы:**
- `front/src/entities/activity.ts:188` (`SaveMeetingReportPayload` — только `answers/comment/activity_id`)
- `front/src/components/MeetingReportDialog.vue:216` (onSubmit шлёт ровно это)
- `src/app/Http/Requests/Activity/SaveMeetingReportRequest.php:27-30` (валидирует `is_first_time_meeting/ftm_decision_maker_attended/ftm_presentation_shown/ftm_report_url`)
- `src/app/Domain/Activity/Services/MeetingReportService.php:56` (create-ветка 113-127, update-ветка 104-107)

**Что происходит (evidence):** `SaveMeetingReportRequest` валидирует четыре `ftm_*` поля (docblock: «FTM flags optionally accompany a meeting report»), но `saveReport()` в обеих ветках читает только `answers/comment/activity_id` — четыре `ftm_*` ключа **никогда не пишутся** в Activity. FE при этом их даже не шлёт (`SaveMeetingReportPayload` их не содержит, диалог не рендерит FTM-контролов). Слушателя `ActivityCreated` нет (в `AppServiceProvider` подключён только `ActivityAssigned`), пост-фактум штамповки тоже нет. Итог: все 5 условий FTM **никогда не выполнимы** через конструктор. Live: 0 FTM-строк, 0 `meeting_report_json`. (Уточнение: FTM всё ещё выставим через тумблеры `ActivityFormDialog` — дефект узкий: «FTM не захватывается именно конструктором отчёта».)

**Repro:** `POST /api/deals/{id}/meeting-report {answers:[...], is_first_time_meeting:true, ftm_decision_maker_attended:true, ftm_presentation_shown:true, ftm_report_url:'http://x'}` → созданная активность имеет все `ftm_* = false/null`.

**Предлагаемый фикс:** определить каноническую точку захвата FTM. Если это конструктор отчёта — добавить `ftm_*` в `SaveMeetingReportPayload`, отрендерить контролы в MeetingReportDialog и смаппить четыре ключа в create- И update-ветки `saveReport()` (булевы → default false). Иначе — задокументировать, что FTM ставится только через ActivityFormDialog, и **убрать неиспользуемые правила из `SaveMeetingReportRequest`** (request не должен валидировать поля, которые отбрасывает, — это реальный контракт-дефект).

---

### MAJOR-2 · MISSING · ✅ подтверждено (grep FE + route-scan + live counts) — у admin CRUD вопросов отчёта нет FE-UI

**Severity:** major (Phase-1 major → verdict confirmed/major, conf 0.96)
**Файлы:**
- `front/src/api/activity.ts:106` (только read-only `getMeetingReportQuestions()` → `/api/meeting-report/questions`)
- `src/routes/api.php:569-572` (4 admin-route)

**Что происходит (evidence):** BE отдаёт GET/POST/PATCH/DELETE `/api/meeting-report-questions` (Admin/Director). grep по `front/src` за `meeting-report-questions` (plural) и `MeetingReportQuestion` находит только read-сторону (api/activity.ts, entities/activity.ts, MeetingReportDialog.vue). Нет ни router-записи, ни страницы Settings/«Справочники», ни компонента управления. 4 admin-route существуют BE-side, но имеют нулевую FE-поверхность. Реестр (6 вопросов + 8 опций) — целиком из seed; админ не может добавить/изменить/переупорядочить/деактивировать/удалить вопрос из приложения.

**Repro:** залогиниться как admin → нет экрана Settings/«Справочники» для вопросов отчёта; `grep front/src 'meeting-report-questions'` → ничего.

**Предлагаемый фикс:** построить admin-CRUD экран под Settings «Справочники», дёргающий четыре `/api/meeting-report-questions` endpoint'а, с per-pipeline scope и управлением опциями. До этого реестр меняется только через seed/миграцию.

---

### MAJOR-3 · BUG · ✅ подтверждено (code-read, без мутации) — inline status→done оставляет задачу открытой и пропускает engagement/log

**Severity:** major (Phase-1 major → verdict confirmed/major, conf 0.9)
**Файлы:**
- `front/src/pages/MyTasksPage/components/MyTasksTable.vue:480` (patchStatus оптимистично `is_closed=true`, строка 485 перезаписывает серверной строкой)
- `src/app/Domain/Activity/Services/ActivityService.php:358` (`changeStatus`, ветка done 358-396)
- `src/app/Domain/Activity/Services/ActivityService.php:266` (`complete()`: touchTargetEngagement:266, recordCompletionOnTarget:272)

**Что происходит (evidence):** `MyTasksTable.patchStatus()` оптимистично ставит `is_closed=(newStatus==='done'||'rejected')` (480), затем зовёт `changeStatus`. BE `changeStatus(Done)` ставит `completed_at/completed_by_id/progress_pct=100`, но **НИКОГДА** не ставит `is_closed`, и в отличие от `complete()` пропускает и `touchTargetEngagement` (266), и `recordCompletionOnTarget` (272, пишет meeting_held/task_completed в entity-log). У модели нет boot-hook/мутатора, выводящего `is_closed` из status (плоский fillable+cast, строки 52/72). Контроллер/request inline-пути `is_closed` через `$extra` не передаёт. Итог: после inline-done BE-refresh возвращает `is_closed=false`, противореча FE-оптимизму; задача остаётся «открытой» по `is_closed`-предикатам; engagement и лента не двигаются. Сравните: POST `/complete` закрывает + штампует + логирует.

**Repro:** MyTasksPage list → inline выставить status='done'. Оптимистичная строка показывает `is_closed=true`, ответ сервера переставляет `is_closed=false`; `last_activity_at` и `entity_logs` нетронуты.

**Предлагаемый фикс:** заставить `changeStatus(Done)` идти тем же путём close+engagement+log, что и `complete()` (или маршрутизировать FE 'done' через POST `/complete`). Согласовать семантику `is_closed`, чтобы статус-машина и `/complete` договорились.

---

### MAJOR-4 · BUG · ✅ подтверждено (code-path) — сохранение отчёта о встрече пропускает engagement-штамп и entity-log

**Severity:** major (Phase-1 major → verdict confirmed/major, conf 0.9)
**Файлы:**
- `src/app/Domain/Activity/Services/MeetingReportService.php:113` (create-ветка; конструктор без зависимостей)
- `src/app/Domain/Activity/Services/ActivityService.php:266` (touchTargetEngagement)
- `src/app/Domain/Activity/Services/ActivityService.php:272` (recordCompletionOnTarget → entityLog.record:919)

**Что происходит (evidence):** `MeetingReportService::saveReport()` создаёт meeting-Activity status=done напрямую через `Activity::create()` (113-127) и диспатчит только `ActivityCreated` (129). Он не зовёт ни `touchTargetEngagement()`, ни `recordCompletionOnTarget()`; конструктор сервиса вообще не принимает ни `EngagementService`, ни `EntityLogService`. Обычный путь `complete()` делает оба (engagement 266 + meeting_held entity-log 272). `ActivityCreated` слушателей не имеет (подключён только `ActivityAssigned`, AppServiceProvider:280). Даже штатный `ActivityService::create` штампует engagement (180) — `saveReport` обходит `ActivityService` целиком. Итог: встреча, залогированная через конструктор, никогда не обновляет `last_activity_at` на company/контактах сделки и не появляется как `meeting_held` в ленте. Латентно (0 отчётов живьём), но реальный дефект при использовании потока.

**Repro:** `POST /api/deals/{id}/meeting-report` с answers; проверить `crm_companies.last_activity_at` (без изменений) и `entity_logs` на строку `meeting_held` (отсутствует). Сравнить с POST `/api/activities/{id}/complete` на meeting.

**Предлагаемый фикс:** в `saveReport()` после создания встречи зеркалить `complete()`-ветку: `touchTargetEngagement` + `recordCompletionOnTarget` для new-activity (инжектить `ActivityService`/`EngagementService` + entity-log сервис).

---

### MINOR (компактный список)

- **MINOR-5 · BUG · ⚠️ частично (понижено major→minor, conf 0.93) — MeetingReportDialog не передаёт `pipeline_id`.** Диалог объявляет optional prop `pipelineId` (`MeetingReportDialog.vue:120`) и форвардит в `getMeetingReportQuestions(props.pipelineId ?? null)` (148), но оба mount-сайта — `ActivityFormDialog.vue:179-185` и `DealFeedItem.vue:177-183` — `:pipeline-id` не передают, поэтому всегда запрашивается `pipeline_id=null`. BE per-pipeline фича (`meeting_report_questions.pipeline_id`, индекс `ix_mrq_pipeline_active_sort`) недостижима. **Понижено:** live-impact нулевой (0/6 вопросов pipeline-scoped, read-endpoint корректно деградирует к global-only) — блокируется лишь ещё-не-используемая фича. Фикс: протянуть `pipeline_id` из контекста сделки через ActivityFormDialog/DealFeedItem в `:pipeline-id`.

- **MINOR-6 · DEAD-CODE · ⚠️ частично (понижено major→minor, conf 0.97) — фантомное поле `is_required`.** `MeetingReportQuestionDto` объявляет `is_required: boolean` (`entities/activity.ts:112`), диалог рендерит красную `*` при `q.is_required` (`MeetingReportDialog.vue:38`), но `MeetingReportQuestionResource` (стр. 18) поля не отдаёт и колонки нет в DB (подтверждено в schema.sql и live `\d`). `q.is_required` всегда `undefined`: звёздочка не рендерится, per-question enforcement отсутствует (onSubmit проверяет лишь «хотя бы один ответ ИЛИ непустой комментарий»). **Понижено:** чистый dead FE-контракт, без security/data-impact. Фикс: либо добавить `is_required` в миграцию/модель/ресурс и enforce, либо убрать из FE-DTO и звёздочку.

- **MINOR-7 · DEAD-CODE · не верифицировано (Phase-1) — мёртвый POST `/reschedule`.** Route `activities/{activity}/reschedule` (`api.php:561`) → `ActivityService::reschedule` (318) принимает preset ∈ {tomorrow,next_week,next_month} (`RescheduleActivityRequest:25`). grep `front/src` за `/reschedule` — ноль вызовов; activity.ts не экспортит reschedule-fn; TaskQuickForm переносит через PATCH `due_at`. Таймзоно-корректная серверная математика суток недостижима. Vault Wave-2 документирует body `{due_at}`, код принимает только preset. Фикс: либо подключить FE quick-кнопки к endpoint'у (предпочтительно — централизует таймзоно-корректную математику), либо удалить endpoint/метод; и согласовать vault-контракт.

- **MINOR-8 · BUG · не верифицировано (Phase-1) — границы суток от UTC при Asia/Dubai (+4ч).** `config/app.php:68` `'timezone' => 'UTC'`. `myBoard()` (825 `$now->copy()->startOfDay()`), `applyPreset()` (1077) и `reschedule()` (322 `now()->startOfDay()`) считают границы суток от UTC, хотя docblock reschedule утверждает «Дубай-окно, без client +4h hack». Для Дубая бакеты today/tomorrow/this_week и пресеты «съезжают» на 4ч раньше (задача в 02:00 Dubai = 22:00 пред. дня UTC попадает не в тот бакет). Фикс: считать границы суток в операционной таймзоне (`Asia/Dubai` либо `now()->setTimezone('Asia/Dubai')->startOfDay()->utc()`) консистентно в myBoard/applyPreset/reschedule.

- **MINOR-9 · CONVENTION · не верифицировано (Phase-1) — FTM-предикат триплицирован.** 5-условный предикат FTM реализован трижды: `ActivityService::applyFtmConditions` (query builder, 1059), `ManagerKpiService::ftmCounted` (object predicate, 205), `ActivityFeedItemResource::ftmCounted` (41, doc-нота «Mirrors ManagerKpiService»). Три копии = риск дрейфа: при изменении правила `ftm_counted` в ленте и KPI-счётчик молча разойдутся. Фикс: вынести предикат в единый источник, потребляемый query, KPI-count и ресурсом.

- **MINOR-10 · SECURITY · не верифицировано (Phase-1) — unscoped reassignment `responsible_id`.** `Update/StoreActivityRequest` валидируют `responsible_id` лишь `exists:users,id` (`UpdateActivityRequest:28`, `StoreActivityRequest:30`) — без department/scope-проверки. Любой, кто может update активность, переназначит её на ЛЮБОГО пользователя, в т.ч. из невидимого отдела, протолкнув задачу в чужую видимость; денормализованный `department_id` при reassign не пересинхронизируется (вопреки E10). Фикс: ограничить `responsible_id` пользователями в области видимости актора (и пересинхронизировать `department_id` при reassign).

- **MINOR-11 · STUB · не верифицировано (Phase-1) — meeting-report + FTM построены, но холодны.** Live: `meeting_report_questions=6`, `_options=8` засеяны, но 0 активностей с `meeting_report_json`, 0 FTM, 0 company-targeted, 0 standalone. В сумме с тремя структурными дефектами выше поверхность meeting-report/FTM практически нефункциональна. Фикс: после починки FTM/pipeline_id/is_required/engagement-дефектов прогнать meeting-report и FTM end-to-end (и проверить company-target + standalone-режимы) до того, как на них опираться.

### NEW-* из live-QA

Прямых live-QA NEW-issue по домену активностей нет — журней-таблица не покрывала MyTasksPage/задачи отдельным сценарием. Смежные находки (`DealPage`, не ядро домена): **NEW-2 (P2)** — необработанный 403 в mount-hook `DealPage` (`index.vue:151`, `useDealPage.ts`) при заходе на чужую сделку; **NEW-3 (P2/Low)** — отсутствует i18n-ключ `sales.deal.feed.events.dealCreatedVerb` в `ru.json`/`en.json`. Обе относятся к домену продаж/карточки сделки, а не к activity-ядру, и здесь упомянуты только для трассировки. (Уточнение из live-QA, не по этому домену: `sales-dashboard#1` «dashboard renders blank» — ОПРОВЕРГНУТО, дашборд не пустой, просто 0 сделок у менеджера.)

---

## 7. Расхождения со спекой (vault) и предложения по актуализации

Целевой документ: **`2. Модули/Activity — Активности и задачи.md`**.

1. **Reschedule row (Волна 2 → Бэкенд-расширение ActivityService).** Спека: `POST /api/activities/{id}/reschedule | body {"due_at": "..."}`. Реальность: endpoint принимает только `{"preset": "tomorrow|next_week|next_month"}` (`RescheduleActivityRequest:25`, `ActivityService::reschedule:318`), считает start-of-day на сервере, **не имеет FE-вызова**. Предложение: исправить body на `{"preset": ...}`, добавить пометку, что endpoint сейчас мёртвый (нет FE), и решить — подключать или удалить.

2. **Модель данных → `meeting_report_questions`.** Спека: колонки `pipeline_id, text, kind, sort_order, is_active` (без `is_required`). Реальность: FE (`entities/activity.ts:112`, `MeetingReportDialog.vue:38`) ссылается на `is_required`, которого нет в миграции/модели/ресурсе/DB. Предложение: либо добавить колонку `is_required` в таблицу/модель/ресурс и описать как правило обязательного ответа, либо пометить `is_required` как FE-only dead code к удалению.

3. **API-эндпоинты → `/api/meeting-report-questions` admin CRUD.** Спека: GET/POST/PATCH/DELETE — «admin/director CRUD реестра». Реальность: 4 admin-endpoint существуют BE-side, но не имеют FE-UI/api-функции — реестр seed-only. Предложение: добавить статус-ноту «admin-UI реестра ещё НЕ построен (BE-only; 4 dead endpoints)» и перенести «meeting-report-questions admin UI» в раздел Backlog/границы до реализации.

4. **Бизнес-правила → E3 / Волна 2 (status machine).** Спека: статус-машина и complete/reopen описаны; `is_closed` = «финальное закрытие (≠ done)». Реальность: `changeStatus(done)` ставит completed_*/progress, но НЕ `is_closed` и пропускает engagement/entity-log, тогда как `complete()` ставит `is_closed` + engagement + log; FE inline `patchStatus` оптимистично ставит `is_closed=true` на done, противореча BE. Предложение: задокументировать явное расхождение PATCH `/status` (done) ↔ POST `/complete` по `is_closed` и engagement/log, либо специфицировать, что они должны сойтись.

5. **Бизнес-правила → E8 (MeetingReport) + FTM.** Спека: `question_id ∈ активный реестр (global + pipeline)`; `meeting_report_json {answers,comment}`. Реальность: per-pipeline вопросы недостижимы (FE не шлёт `pipeline_id`), FTM-флаги принимаются request'ом, но дропаются `saveReport()`, FE их вообще не шлёт, и `saveReport` пропускает engagement/log; весь путь E8+FTM холоден (0 отчётов, 0 FTM живьём). Предложение: добавить блок «known gaps» к E8 — `pipeline_id` не протянут FE; FTM не захватывается конструктором (BE дропает + FE опускает); нет engagement/entity-log на save. Трекать как Wave-3 фиксы.

Дополнительно по **`5. Планы`**: поскольку задачная половина зрелая, а meeting-report/FTM половина холодна и сломана сквозняком, в роадмапе стоит выделить отдельный пункт «Wave-3: оживление meeting-report/FTM» (4 major + 3 lowered-minor выше) до того, как KPI-кабинет менеджера начнёт опираться на FTM-метрику.
