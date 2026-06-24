# Аудит домена: Сквозное — журнал действий (entity_logs) + оболочка фронта (навигация/Орбита, тема, роутер, i18n)

## 1. Назначение

Сквозной слой «журнал действий + оболочка приложения» отвечает за две независимые вещи. Во-первых, **журнал действий**: полиморфный append-only лог (`entity_logs`), куда доменные сервисы пишут события жизни сущности (`created`, `stage_changed`, `contact_added`, `task_completed`, `data_changed`, `kp_sent`, `contract_sent`…) с детализацией в `meta` JSON; этот лог показывается во вкладке «Лог» карточки сделки и мини-таймлайне карточек компании/контакта. Параллельно работает «единая лента» (`GET /{entity}/{id}/feed`), которая **не читает `entity_logs` вообще**, а склеивает в PHP `deal_stage_history` + `activities` + `deal_audits` (для сделки) либо только `activities` (для компании/контакта), плюс отдельная история смены канала привлечения (`acquisition_channel_history`). Во-вторых, **оболочка фронта**: единый источник пунктов навигации, питающий оба режима (боковое меню и «Орбита»), тема (light/dark), роутер-гард, layouts и i18n.

**Общая зрелость: частично.** Оболочка фронта — зрелая (vault-статус навигации = done, QA PASS 2026-06-17; роутер fail-closed, обе темы есть). Read-пути журнала и ленты архитектурно корректны и авторизованы (делегируют `view`-политике сущности, дыр в авторизации не найдено), но **сломаны на стыке FE↔BE**: два подтверждённых major-бага рассинхрона полей делают вкладку «Лог» и историю канала визуально пустыми/обезличенными для реальных строк. Данных в живой БД мало: `entity_logs` = 6 строк (все `subject_type=deal`), `acquisition_channel_history` = 1, `deal_stage_history` = 1, `deal_audits` = 0 — писатели лога приземлились ~2026-06-20, большинство сделок старше их, бэкфилла нет (данные тестовые, перенос не планируется). Контактный лог не пишет ни один сервис в обычном потоке → вкладка постоянно пустая. То есть код есть, путей много, но в проде «живёт» по сути только deal-лог, да и тот рендерится неправильно.

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI + endpoint) | Как (шаги) | Статус | Примечание |
|---|---|---|---|---|---|
| Запись строки в журнал (write) | Любой доменный сервис; actor = User или null (inbound/system) | Без UI; `EntityLogService::record()` из `DealService`/`DealMoveService`/`DealContactService`/`CompanyService`/`ActivityService` | Вызывающий резолвит `subject_type`+`subject_id` → `EntityLog::create` с `meta` JSON. Без транзакции внутри `record()`; чаще вызывается ПОСЛЕ коммита родительской транзакции | 🟡 частично | Deal-ветка отрабатывает (6 живых строк). Company-ветка подключена, но 0 строк. Contact-ветка достижима только через завершённую контакт-активность и в проде не сработала (0 строк). Company/contact end-to-end не проверены |
| Чтение журнала (read) | Любой, кто может `view` сущность | «Лог» сделки (`EntityLogTab`), мини-таймлайн компании/контакта (`EntityMiniTimeline`); `GET /{entity}/{id}/log` | `authorize('view')` → `EntityLogService::forSubject()` фильтрует по subject, eager-load `actor:id,full_name`, сортировка `created_at desc`, пагинация (`per_page` 1..100) → `EntityLogResource` | 🔴 сломан | Бэкенд отдаёт корректные данные, но FE читает несуществующие поля (`user`/`old_value`/`new_value`/`description`) → каждая строка рендерится «Система — <action>» без имени и без деталей. Видимая поломка (major BUG #1) |
| Единая лента (feed merge) | Любой, кто может `view` сущность | «Лента» сделки (`DealFeed`), «Активность» компании/контакта (`EntityActivitiesTab`); `GET /{entity}/{id}/feed` | Загрузить все источники целиком → нормализовать в `{id,type,occurred_at,actor,payload}` → merge → `sortByDesc` → `forPage()` в PHP | 🟡 частично | Работает, но in-memory load без `per_page`-cap. Ветка `field_change` сделки пуста (`deal_audits` = 0). У CRM-ленты нет источника `field_change`, хотя FE-чип «Изменения» его ждёт |
| История смены канала (read) | Любой, кто может `view` компанию/контакт | `ChannelHistoryDrawer`; `GET /{entity}/{id}/channel-history` | `authorize('view')` + eager-load `oldChannel/newChannel/changedByUser` → `AcquisitionChannelHistoryResource` (`old_channel/new_channel/changed_by/changed_at`) | 🔴 сломан | Бэкенд корректен, FE читает `from_channel/to_channel/changed_by_name` → каналы показывают «нет канала», редактор «—» для реальных строк (major BUG #2) |
| Контактный журнал (read) | Любой, кто может `view` контакт | Мини-таймлайн контакта; `GET /contacts/{id}/log` | То же, что deal/company read | 🟡 частично | В обычном потоке ни один сервис не пишет contact-subject лог (`ContactService` пишет только channelHistory). Технически достижим через завершение контакт-активности, но в проде 0 строк → вкладка постоянно пустая |
| Оболочка фронта — навигация/тема/роутер/i18n | Все аутентифицированные (пункты role-gated) | `DefaultLayout` + `AppSidebar` \| `Orbita`; `router/policy.ts` + `access.ts`; `stores/layout.ts` + `theme.ts`; `shared/nav/navItems.ts` | Единый `navItems` питает оба режима; `router.beforeEach` fail-closed по `user.role`; тема и navMode персистятся; обе темы | ✅ работает | vault-статус done (QA PASS). FE-гейт — UX-only, реальная авторизация серверная. Двойной источник роли (`users.role` + spatie) — сквозной «запах», здесь не эксплуатируется |

Поправки live-QA, релевантные оболочке: подтверждён `bell-only-in-orbita` (в боковом режиме нет иконки колокольчика — NEW из перечня known-issues, источник live-QA); в консоли менеджера всплывает `[Vue warn]: Failed to resolve component: CompanyChannelsBlock` (NEW-1) и отсутствует i18n-ключ `sales.deal.feed.events.dealCreatedVerb` (NEW-3) — оба касаются ленты/оболочки. NEW-4 (`Route [login] not defined`, 500 вместо 401) — дефект error-handling оболочки бэкенда.

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк в живой БД | Статус |
|---|---|---|---|---|
| `EntityLog` | `entity_logs` | Полиморфный append-only лог действий на сделке/компании/контакте; детализация в `meta` JSON | **6** (все `subject_type=deal`; 0 company, 0 contact) | 🟡 частично |
| `AcquisitionChannelHistory` | `acquisition_channel_history` | История смены канала привлечения (old→new + кто/когда) | **1** | ✅ построено |
| `DealStageHistory` | `deal_stage_history` | Переходы стадий; источник `stage_change` ленты сделки | **1** | 🟡 частично |
| `DealAudit` | `deal_audits` | Пофайловые изменения; источник `field_change` ленты сделки | **0** | 🔴 пусто (ветка `field_change` сегодня ничего не даёт) |
| `Activity` | `activities` | Полиморфная задача/заметка/звонок/встреча; источник `activity` обеих лент | **24** (deal-targeted) + 2 contact-targeted | ✅ построено |

**Расхождения migration ↔ live-schema ↔ model:**

- `entity_logs` — **vault-спека устарела целиком**, но migration↔live↔model согласованы между собой (это SPEC-DRIFT, не баг схемы). Живая `\d entity_logs`: `subject_type varchar(30)`, `subject_id bigint`, `actor_id bigint` (FK nullOnDelete), `action varchar(40)`, `meta json`, `created_at` (без `updated_at`); индекс `ix_entity_logs_subject(subject_type,subject_id,created_at)`; миграция `2026_06_20_120000`. Vault при этом документирует `entity_type/entity_id/event/payload(jsonb)` и миграцию `2026_06_21_XXXXXX` — всё мимо.
- **Wire-shape `EntityLogResource` ↔ FE `EntityLogEntry`** — рассинхрон: бэкенд отдаёт `{subject_type,subject_id,action,meta,actor:{id,full_name}|null,actor_id,created_at}`, FE-тип объявляет `user/old_value/new_value/description` (ничего из этого бэкенд не эмитит) → major BUG.
- **Wire-shape `AcquisitionChannelHistoryResource` ↔ FE `ChannelHistoryEntry`** — рассинхрон: бэкенд отдаёт `old_channel/new_channel` (объекты) + `changed_by`, FE-тип объявляет `from_channel/to_channel/changed_by_name` (строки) → major BUG.

**Пустые при наличии кода:**

- `deal_audits` = 0 → ветка `field_change` ленты сделки и FE-скролл к key-action для КП/договора сегодня не дают вывода (DEAD-CODE/DATA-INCONSISTENCY).
- `entity_logs` company/contact = 0 → company-писатели подключены, но не отработали; contact-писателя нет вовсе.

> Замечание по числам: Phase-1 указывал 7 строк `entity_logs`; живой rowcount на момент финального аудита = **6**. Расхождение на одну строку не меняет вывода (все строки — `subject_type=deal`, company/contact = 0); используем live-значение 6.

## 4. Эндпоинты и покрытие фронтом

| Метод + Path | Контроллер@метод | Авторизация | Вызывается фронтом? | Примечание |
|---|---|---|---|---|
| `GET /api/deals/{deal}/log` | `Log\EntityLogController@dealLog` | `auth:sanctum` + 2fa + visibility; `authorize('view',$deal)`; `per_page` 1..100 | Да (`useEntityLog('deal')` → `logApi.getLog`) | Подключено, но wire-shape mismatch (major BUG #1) |
| `GET /api/companies/{company}/log` | `Log\EntityLogController@companyLog` | `auth:sanctum` + 2fa + visibility; `authorize('view',$company)`; `per_page` 1..100 | Да (`useEntityLog('company')`, мини-таймлайн) | Тот же mismatch. Live: 0 company-строк (не отработано) |
| `GET /api/contacts/{contact}/log` | `Log\EntityLogController@contactLog` | `auth:sanctum` + 2fa + visibility; `authorize('view',$contact)`; `per_page` 1..100 | Да (`useEntityLog('contact')`) | **Endpoint жив, но писателя нет** → всегда пусто. Полу-мёртвая фича (см. backlog) |
| `GET /api/deals/{deal}/feed` | `Sales\DealFeedController@index` | `auth:sanctum` + 2fa + visibility; `authorize('view',$deal)`; **нет верхнего cap на `per_page`** | Да (`useDealFeed`) | Ветка `field_change` пуста (`deal_audits` = 0) |
| `GET /api/companies/{company}/feed` | `Crm\CrmFeedController@companyFeed` | `auth:sanctum` + 2fa + visibility; `authorize('view',$company)`; **нет cap**; только activity | Да (`useEntityFeed`) | Чип «Изменения» мёртв (нет `field_change`-айтемов) |
| `GET /api/contacts/{contact}/feed` | `Crm\CrmFeedController@contactFeed` | `auth:sanctum` + 2fa + visibility; `authorize('view',$contact)`; **нет cap**; только activity | Да (`useEntityFeed`) | Чип «Изменения» мёртв |
| `GET /api/companies/{company}/channel-history` | `Crm\AcquisitionChannelHistoryController@forCompany` | `auth:sanctum` + 2fa + visibility; `authorize('view',$company)`; eager-load каналов/юзера | Да (`ChannelHistoryDrawer`) | Подключено, но wire-shape mismatch (major BUG #2) |
| `GET /api/contacts/{contact}/channel-history` | `Crm\AcquisitionChannelHistoryController@forContact` | `auth:sanctum` + 2fa + visibility; `authorize('view',$contact)` | Да (`ChannelHistoryDrawer`, контакт) | Тот же mismatch |

Orphaned FE-вызовов нет (все 8 endpoint'ов вызываются фронтом). «Мёртвых» в смысле «никем не дёргается» endpoint'ов тоже нет — но `GET /contacts/{id}/log` функционально мёртв (нет писателя), а ветки `field_change` в feed-эндпоинтах не имеют источника данных.

## 5. RBAC домена

Авторизация во всех read-путях домена **делегирована политике самой сущности** и проверяется реально на сервере:

- **Просмотр журнала** (`GET /log`) — `EntityLogController::{deal,company,contact}Log` через `authorize('view',$entity)` + глобальный visibility-middleware. Доступ наследует видимость subject'а: владелец + scope отдела/видимости; admin/director — широко.
- **Просмотр ленты** (`GET /feed`) — `DealFeedController@index` / `CrmFeedController@{company,contact}Feed` через `authorize('view',$entity)`.
- **Просмотр истории канала** (`GET /channel-history`) — `AcquisitionChannelHistoryController@for{Company,Contact}` через `authorize('view',$entity)`.
- **Запись строки лога** — не user-facing; эмитится внутренне как side-effect уже авторизованной мутации, отдельного гейта нет (имплицитно прикрыт политикой родительской мутации, напр. `DealPolicy::update` перед тем, как `DealService::update` логирует `data_changed`).
- **Рендер пункта навигации / доступ к роуту (FE)** — role-gated в `shared/nav/navItems.ts` (`adminOnly` → admin/director); роутер fail-closed через `router/policy.ts` + `access.ts` по `user.role`. Это **только UX/display-гейт**, реальная авторизация серверная.

**Дыр в авторизации внутри домена не найдено** — это сильная сторона домена. Единственный сквозной «запах» — двойной источник роли (`users.role` column И spatie-таблицы): FE-гейт читает `user.role`, но здесь это не эксплуатируется (серверная авторизация не зависит от FE-роли). Важно: RBAC-дыры, найденные live-QA (manager видит чужие контакты/компании; lawyer входит в manager-cabinet; manager читает `/api/admin/*` — NEW-5), относятся к доменам crm/sales/shell-admin, **не** к read-путям журнала/ленты этого домена — здесь авторизация корректна.

## 6. Бэклог проблем

### Сводная таблица (FINAL severity после верификации)

| FINAL | Тип | Заголовок | Проверка |
|---|---|---|---|
| major | BUG | `EntityLogTab`/`MiniTimeline` читают неверные имена полей — каждая строка лога = «Система», детали теряются | ✅ подтверждено (live probe) |
| major | BUG | `ChannelHistoryDrawer` читает неверные имена полей — каналы/редактор всегда пусто/«—» | ✅ подтверждено (live probe) |
| minor | SPEC-DRIFT | vault Log-спека устарела целиком (колонки/ресурс/путь компонента/история «заменяет Статистики») | ⚠️ частично (downgrade major→minor: факт верен, но это doc-only, без runtime-эффекта) |
| minor | DEAD-CODE | `GET /contacts/{id}/log` — пустая фича, contact-таймлайн постоянно пуст | ⚠️ частично (downgrade major→minor: писатель ЕСТЬ — `ActivityService` при завершении контакт-активности; «мёртв» переоценено) |
| minor | PERF | Feed-эндпоинты грузят все строки в память и пагинируют в PHP, без верхнего cap на `per_page` | не верифицировано (Phase-1) |
| minor | DEAD-CODE | Чип «Изменения» в CRM-ленте мёртв — CRM-feed не возвращает `field_change` | не верифицировано (Phase-1) |
| minor | DATA-INCONSISTENCY | Запись лога/аудита идёт ПОСЛЕ коммита родительской транзакции (тихий desync + post-commit 500) | не верифицировано (Phase-1) |
| minor | CONVENTION | `DealService::create()` без `DB::transaction` (неатомарные вставки deal+stage_history+log) | не верифицировано (Phase-1) |
| minor | BUG | `useEntityLog` глотает ошибку загрузки; у `EntityLogTab`/`MiniTimeline` нет error-состояния (ошибка = «пусто») | не верифицировано (Phase-1) |
| minor | BUG | Entity feed (company/contact) без error-состояния — сбой fetch молча показывает «пусто» | не верифицировано (Phase-1) |
| minor | DEAD-CODE | Скролл к key-action «КП отправлен»/«договор отправлен» целится в `field_change`-айтемы, которых нет | не верифицировано (Phase-1) |
| minor | DATA-INCONSISTENCY | `entity_logs` почти пуст: писатели добавлены ~2026-06-20, бэкфилла нет; company/contact-ветки не отработали | не верифицировано (Phase-1) |
| minor | SPEC-DRIFT | Docblock'и ссылаются на несуществующий `EntityLogPolicy` | не верифицировано (Phase-1) |
| trivial | PERF | Лишние запросы на каждый move стадии: `User::find()` + `PipelineStage::value()` | не верифицировано (Phase-1) |

NEW из live-QA, релевантные домену (источник = live-QA): **NEW-1** `CompanyChannelsBlock` не резолвится (касается панели каналов компании), **NEW-3** отсутствует i18n-ключ `sales.deal.feed.events.dealCreatedVerb` (касается ленты), **bell-only-in-orbita** (оболочка) — см. ниже компактным списком.

---

### MAJOR #1 — `EntityLogTab`/`EntityMiniTimeline` читают неверные имена полей

**Severity: major · Тип: BUG · Проверка: ✅ подтверждено (live probe)**

**Файлы:**
- `front/src/components/crm/entity/EntityLogTab.vue:53` (bind `entry.user?.full_name`), `:58`, `:64`, `:70` (bind `entry.old_value`/`new_value`/`description`)
- `front/src/components/crm/entity/EntityMiniTimeline.vue:40`, `:43`
- `front/src/entities/crm.ts:469`, `:471`, `:473`, `:478` (тип `EntityLogEntry`)
- `front/src/composables/crm/useEntityLog.ts:43` (прокидывает `res.data` без трансформации)
- `front/src/api/crm/log.ts:29`
- `src/app/Http/Resources/Log/EntityLogResource.php:14`–`29`

**Что происходит (evidence):** Live `GET /api/deals/13/log` (admin-token) возвращает строки вида `{action, meta:{contact_name,…}, actor:{id,full_name}, actor_id}` — **никаких** ключей `user`/`old_value`/`new_value`/`description`. FE-тип `EntityLogEntry` (`crm.ts:463-480`) объявляет именно `user/old_value/new_value/description`, а рендереры биндят `entry.user?.full_name` и `entry.old_value/new_value/description`. Композабл и api-слой передают `res.data` без ремапа. Метка `action` рендерится (ключ совпадает), поэтому строка не пустая — но `entry.user` всегда `undefined` → выводится `t('crm.log.system')` («Система») для КАЖДОЙ строки, а вся детализация из `meta` (имя контакта, from/to стадии, заголовок созданной сущности) молча теряется.

**Repro:** Войти как admin, открыть сделку со строками лога → вкладка «Лог». Каждая строка показывает «Система — <action>» без имени и без деталей, хотя API отдаёт `actor.full_name` + `meta`. То же на мини-таймлайне компании.

**Предлагаемый фикс:** Привести `EntityLogEntry` к реальному wire-shape (`actor:{id,full_name}|null`, `meta`) и обновить оба рендерера: читать `entry.actor?.full_name` и выводить деталь из `meta` по `action` (`stage_changed`→`meta.from_stage`/`to_stage`, `created`→`meta.title`, `contact_added`→`meta.contact_name`, `data_changed`→diff по `meta`). Альтернатива — добавить в `EntityLogResource` алиас `user` + плоские `old_value`/`new_value`/`description` (хуже: тащит лишние поля).

---

### MAJOR #2 — `ChannelHistoryDrawer` читает неверные имена полей

**Severity: major · Тип: BUG · Проверка: ✅ подтверждено (live probe)**

**Файлы:**
- `front/src/components/crm/ChannelHistoryDrawer.vue:64` (`entry.from_channel`), `:69` (`entry.to_channel`), `:75` (`entry.changed_by_name`), `:118`–`119` (fetch без адаптера)
- `front/src/entities/crm.ts:361`–`367` (тип `ChannelHistoryEntry`)
- `src/app/Http/Resources/Crm/AcquisitionChannelHistoryResource.php:16`–`37`
- роут `src/routes/api.php:254` (eager-load связей)

**Что происходит (evidence):** Live `GET /api/companies/3/channel-history` (admin-token) возвращает `{old_channel:null, new_channel:{id:3,name:'Входящий запрос'}, changed_by:{id:1,full_name:'MG CRM Admin'}, changed_at}`. FE-тип `ChannelHistoryEntry` объявляет `from_channel`/`to_channel`/`changed_by_name` как строки; drawer биндит `entry.from_channel`/`to_channel`/`changed_by_name` — ничего из этого бэкенд не эмитит. Адаптера/ремапа нет (`apiClient.get`, тип `ChannelHistoryEntry` напрямую). Итог: `entry.to_channel` = `undefined` → fallback «нет канала», хотя `new_channel.name='Входящий запрос'`; `entry.changed_by_name` = `undefined` → «—» вместо «MG CRM Admin». Проверено против единственной реальной строки истории.

**Repro:** Войти как admin, открыть компанию со сменой канала → Marketing → drawer «История канала». Канал-назначение показывает «нет канала», редактор «—» вместо реального имени канала/пользователя.

**Предлагаемый фикс:** Обновить `ChannelHistoryEntry` до `{old_channel:{id,name}|null, new_channel:{id,name}|null, changed_by:{id,full_name}|null, changed_at}` и биндить `entry.old_channel?.name` / `entry.new_channel?.name` / `entry.changed_by?.full_name`. Контроллер уже eager-загружает связи — серверных изменений не требуется.

---

### Minor / trivial (не верифицировано независимо — Phase-1; кроме помеченных ⚠️)

- **⚠️ частично (major→minor) · SPEC-DRIFT — vault Log-спека устарела целиком.** `2. Модули/Log — Лента действий (entity_logs).md` документирует колонки `entity_type/entity_id/event/payload(jsonb)`, миграцию `2026_06_21_XXXXXX`, ресурс `{id,event,actor,payload,created_at}`, методы `append()/getForEntity()`, компонент `front/src/components/log/EntityLogFeed.vue` и историю «Лог заменяет Статистики». Реальность: `subject_type/subject_id/action/meta(json)`, миграция `2026_06_20_120000`, ресурс эмитит `{subject_type,subject_id,action,meta,actor,actor_id,created_at}`, методы `record()/forSubject()`, директории `components/log/` нет, FE использует `EntityLogTab`+`EntityMiniTimeline`+`EntityActivitiesTab` без замены «Статистики». Все пункты независимо проверены — факт 100% верен, но это doc-only drift с нулевым runtime/security-эффектом (vault — dev-brain, не shipped-код), потому downgrade до minor. Фикс — переписать спеку (черновик уже в `vaultUpdates`).
- **⚠️ частично (major→minor) · DEAD-CODE — `GET /contacts/{id}/log` пустой / contact-таймлайн постоянно пуст.** Live `GET /api/contacts/1/log` → `{data:[],total:0}`; `entity_logs` GROUP BY показывает 0 contact-строк. НО `ActivityService::recordCompletionOnTarget` (`ActivityService.php:904-930`, вызов из `complete()` на `:272`) пишет contact-subject лог через `LogSubjectType::tryFrom($activity->target_type)` (`:943`) — то есть писатель ЕСТЬ. Причина пустоты: единственные 2 контакт-активности (id 22,23) — `kind='note'`, `is_closed=false`, а заметки нельзя завершить (`assertCompletable` их отклоняет). Путь подключён, но не упражнён, а не мёртв. Истинные части: `ContactService::create/update` не логирует field-changes (`:215` пишет только channelHistory) и contact-лог сегодня пуст — реальный coverage-gap. Downgrade до minor. Открытый продуктовый вопрос: добавлять ли логирование в `ContactService` или убрать contact-лог-поверхность.
- **minor · PERF — feed-эндпоинты грузят всё в память.** `DealFeedService::feed` (`:41`,`:67`) и `CrmFeedService` (`:34`) делают `->get()` по всем источникам, merge, sort, потом `->forPage()` в PHP. Контроллеры (`DealFeedController.php:34`, `CrmFeedController.php:41`) прокидывают `per_page` без cap (в отличие от `EntityLogController`, который клампит 1..100). Память/латентность растут линейно с числом событий сущности. Документированный MVP-tradeoff (docblock: «SQL UNION refactor deferred until a deal exceeds ~1000 events»). Фикс: cap `per_page` + `LIMIT` на каждый источник; долгосрочно — SQL UNION с DB-пагинацией.
- **minor · DEAD-CODE — чип «Изменения» в CRM-ленте мёртв.** `EntityActivitiesTab.vue:32`/`:257`, `useEntityFeed.ts:114`. `CrmFeedService:20` эмитит только `TYPE_ACTIVITY` («no audit trail for CRM entities yet») → company/contact feed никогда не содержит `field_change` → чип всегда даёт пустую ленту (хотя правки компании ПИШУТ `entity_logs` DataChanged — но feed читает activities, не лог). Фикс: либо добавить источник `field_change` в `CrmFeedService` (читать `entity_logs` DataChanged), либо скрыть чип для CRM-сущностей.
- **minor · DATA-INCONSISTENCY — лог/аудит пишутся ПОСЛЕ коммита транзакции.** `DealService.php:994/1006/1012`, `DealMoveService.php:152/163`, `CompanyService.php:207/229`. В `DealService::update` запись поля внутри `DB::transaction`, но `auditService->record` и `entityLog->record` — после коммита; `CompanyService::update` мутирует вообще без транзакции и логирует после. Если post-commit insert лога бросит — данные уже сохранены, таймлайн/аудит без записи, а запрос 500-ит после успешной мутации. Фикс: внести запись лога/аудита в ту же транзакцию ИЛИ обернуть post-commit `record()` в try/catch + `report()`.
- **minor · CONVENTION — `DealService::create()` без транзакции.** `DealService.php:761/802/805/814`. `Deal::create` + `DealStageHistory::create` + `entityLog->record` — три независимых стейтмента без `DB::transaction` (в отличие от `createInbound()` на `:864`, который оборачивает идентичную последовательность). Сбой между вставками → сделка без log/history. Фикс: обернуть тело `create()` в `DB::transaction(...)`.
- **minor · BUG — `useEntityLog` глотает ошибку загрузки.** `useEntityLog.ts:46`/`:64`, `EntityLogTab.vue:38`, `EntityMiniTimeline.vue:26`. `load()` ловит ошибку в `error`-ref, но ни один компонент не читает `log.error` — ветвятся только на `loading`+`entries.length`. На 403/500 показывается empty-состояние («История пуста»), неотличимое от реально пустого лога; `loadMore()` глотает ошибку голым `catch {}` без тоста. Фикс: показать `log.error` отдельным error/retry-состоянием + тост.
- **minor · BUG — entity feed (company/contact) без error-состояния.** `useEntityFeed.ts:246`, `EntityActivitiesTab.vue:57`. `fetchPage` обёрнут только в try/finally (нет catch, нет error-ref) — отклонённый GET feed не имеет error-состояния, таб показывает «пусто». Фикс: добавить error-ref в `useEntityFeed` + ветку error/retry в `EntityActivitiesTab`.
- **minor · DEAD-CODE — скролл к key-action КП/договора целится в `field_change`.** `DealFeed.vue:259`/`:260`, `DealFeedService.php:41`. `scrollToFeedItem` мапит `kp_sent`/`contract_sent` на предикат `item.type==='field_change'`, но эти айтемы идут из `deal_audits` (= 0 строк), а отправка КП/договора пишется как `entity_logs` actions `kp_sent`/`contract_sent`, не как `field_change`. Кнопки key-action не находят цель → молчаливый скролл-вниз. Фикс: драйвить присутствие в ленте из источника `entity_logs` (или отдельный feed-тип), либо убрать эти key-action-цели.
- **minor · DATA-INCONSISTENCY — `entity_logs` почти пуст, нет бэкфилла.** `2026_06_20_120000_create_entity_logs_table.php:23`, `DealService.php:814`. Live: 6 строк, все `subject_type=deal`; 0 company, 0 contact. Только сделка, созданная после фичи, имеет строку `created`; остальные сделки старше писателей, бэкфилла нет. Company/contact write-ветки никогда не дали строку. Фикс: бэкфилл не нужен (тестовые данные), но упражнить company/contact-пути (отредактировать компанию, завершить контакт-активность) до cutover.
- **minor · SPEC-DRIFT — docblock'и ссылаются на несуществующий `EntityLogPolicy`.** `LogSubjectType.php:18`, `EntityLogService.php:24`. `grep -rn EntityLogPolicy` находит только в этих docblock'ах — класса нет, `src/app/Domain/Log/Policies/` пуст. Реальная авторизация корректно делегирована политике subject'а в `EntityLogController` (`authorize('view',$entity)`). Вводит в заблуждение следующего мейнтейнера. Фикс: обновить оба docblock'а.
- **trivial · PERF — лишние запросы на move стадии.** `DealMoveService.php:166`/`:171`. В `move()` вызов `record()` делает `User::find($userId)` (чтобы передать actor, который `record()` сводит к `actor?->id`) и `PipelineStage::query()->whereKey($fromStageId)->value('name')` — два лишних SELECT на каждый move. Фикс: передавать известный `$userId` напрямую, from-stage брать из уже загруженной связи.

**NEW из live-QA (источник = live-QA):**
- **NEW-1 (P1, источник live-QA) · BUG — `CompanyChannelsBlock` не резолвится.** `[Vue warn]: Failed to resolve component: CompanyChannelsBlock` ×3 при загрузке карточки компании — компонент используется в шаблоне, но не импортирован/не зарегистрирован. Искать в `front/src/pages/CompanyPage/`. Касается панели каналов компании (смежно с историей канала этого домена).
- **NEW-3 (P3, источник live-QA) · BUG — отсутствует i18n-ключ `sales.deal.feed.events.dealCreatedVerb`.** Лента сделки показывает fallback вместо локализованного глагола; ключ отсутствует в обоих `front/src/locales/ru.json` и `en.json`. Фикс: добавить ключ в оба файла.
- **bell-only-in-orbita (источник live-QA) · UX — нет колокольчика уведомлений в боковом режиме навигации.** Подтверждено: в дефолтном sidebar-режиме иконки нет. Оболочка: вынести триггер уведомлений в общий слой шапки, а не только в «Орбиту».

## 7. Расхождения со спекой (vault) и предложения по актуализации

Главное расхождение — **vault-документ `2. Модули/Log — Лента действий (entity_logs).md` устарел целиком** (факт верифицирован, severity = minor как doc-only). Предложения по актуализации (черновики уже в `vaultUpdates` JSON):

1. **Модель/миграция.** Переименовать в спеке колонки `entity_type→subject_type`, `entity_id→subject_id`, `event→action`, `payload→meta` (`json`, не `jsonb`); исправить id миграции на `2026_06_20_120000`; поправить имя/колонки индекса на `ix_entity_logs_subject(subject_type,subject_id,created_at)`.
2. **Сервис/ресурс/ответ API.** Методы `append()/getForEntity()` → `record()/forSubject()`; форму ресурса и пример JSON привести к `{id,subject_type,subject_id,action,meta,actor:{id,full_name}|null,actor_id,created_at}` (без `UserBriefResource`).
3. **Frontend-секция.** Убрать несуществующий путь `front/src/components/log/EntityLogFeed.vue` и историю «Лог заменяет Статистики»; задокументировать реальный набор: `EntityLogTab.vue` (вкладка «Лог» сделки) + `EntityMiniTimeline.vue` (правый рельс компании/контакта) + `EntityActivitiesTab.vue` (лента «Активность»), через `useEntityLog`/`useEntityFeed`; над логом сделки — сетка из 6 метрик.
4. **Покрытие писателями (coverage reality).** Добавить блок «Coverage / exercised state»: Deal-писатели живут (6 строк); Company-писатели подключены, но не упражнены (0 строк); у Contact писателя в обычном потоке нет (`ContactService` пишет только channelHistory; теоретический путь — завершение контакт-активности через `ActivityService`, но в проде не сработал). Пометить `finance_event`/`contract_event` как reserved/extension-only. Зафиксировать продуктовое решение: добавлять ли логирование в `ContactService` или убрать contact-лог-поверхность.
5. **Снять упоминание `EntityLogPolicy`** из концептуальной части — авторизация делегирована политике subject'а в `EntityLogController`, отдельной Log-политики нет.

По навигации (`5. Планы/Навигация — ТЗ (Орбита + боковое меню).md`) расхождений с кодом нет — это самый точный из трёх связанных документов: единый `navItems` питает оба режима, роутер fail-closed по `user.role`. Контент менять не нужно; опционально — зафиксировать финальный состав `navItems` после того, как пользователь финализирует чистку набора модулей, которую спека откладывает. Отдельно стоит завести в vault мелкий пункт про **отсутствие колокольчика уведомлений в боковом режиме** (live-QA `bell-only-in-orbita`) как known UX-issue оболочки.
