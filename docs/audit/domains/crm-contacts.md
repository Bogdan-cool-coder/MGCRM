# Аудит домена: CRM — Контакты

> Дата: 2026-06-24 · Источники: Phase-1 `crm-contacts.json`, Phase-2 verdicts (`b0`, `b1`, `majors`), Phase-3 `live-qa.md`, live `schema.sql` + `rowcounts.txt`.
> Severity blocker/major — ПОСЛЕ верификации (из verdicts). minor/trivial — из Phase-1, **не верифицировано**.

## 1. Назначение

Домен **Контакты** — это «физлицо»-половина единой CRM Контакты/Компании (срез S1.1 + Wave 2). Контакт хранит физическое лицо: ФИО, должность, каналы связи (телефон/email/Telegram), источник, статус, теги, произвольные поля (`extra_fields`), владельца (`owner_id`), канал привлечения и связи. На контакте построены: M2M-привязка к компаниям (с должностью, статусом занятости и флагом «основная»), множественные каналы связи (`contact_channels`), связи контакт-контакт (`crm_contact_relations`), дедуп/мёрж, массовые операции (assign owner / add tag / delete) и экспорт в XLSX. Фронт даёт полноценный грид с KPI-полосой, фильтры, дедуп-диалог и насыщенную карточку контакта.

**Зрелость: частично (с blocker-дырами в безопасности).** Бэкенд CRUD, M2M, каналы, связи, дедуп, bulk и экспорт — построены; фронт рендерит весь сценарий. НО: (1) листинг и экспорт **не скоупятся по владельцу** — менеджер, не владеющий ни одним контактом, видит и экспортирует PII всех (live-подтверждено: manager1 видит 3 контакта + 13 компаний admin'а, экспорт пустым телом отдаёт 6566-байтный xlsx со всеми); (2) и `/contacts`, и `/companies` грузят грид в режиме «Компании» из-за тождественного тернарника; (3) `created_by_id` никогда не пишется при создании (NULL во всех 3 живых строках) — сорт/фильтр по автору мёртвы. Живые данные тонкие: `crm_contacts` — 3 строки, `crm_contact_company_links` — 3, а множественные `contact_channels` / `crm_contact_relations` / `crm_contact_positions` — **0 строк** (фичи построены, но ни разу не прогнаны с данными). Итог: каркас «зрелый по коду», но не безопасен и местами не доведён для прод-cutover.

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI + endpoint) | Как (кратко) | Статус | Примечание |
|---|---|---|---|---|---|
| Просмотр списка контактов | admin, director, manager, lawyer | `/contacts` → `GET /api/contacts` (`ContactService::list`) | Открыть «Контакты» → (баг: грид грузится в режиме «Компании», нужно переключить на «Физлица») → DataTable с пагинацией → поиск/фильтр/сорт | 🔴 сломан | Два дефекта: (1) `/contacts` дефолтит в company-режим (index.vue:792); (2) list отдаёт ВСЕ контакты любой роли без owner-scope (PII-утечка, live-подтверждено) |
| KPI-полоса контактов | admin, director, manager | `ContactsKpiBar` → `GET /api/contacts/kpi` | Над таблицей: total/active/no_touch_30/new_week | 🔴 сломан | KPI скоупится по `owner_id` (у manager total=0), а list — нет (=3). Чип противоречит строкам под ним. Live-подтверждено |
| Создание контакта (quick-create) | admin, director, manager, lawyer | quick-create drawer → `POST /api/contacts` | Заполнить имя/контакты → save; `owner_id` = создатель | 🟡 частично | Работает, но `created_by_id` не пишется → авторство навсегда NULL; `extra_fields` не валидируется против CustomFieldDef |
| Открытие карточки контакта | admin, director, owner-manager | `/contacts/{id}` → `GET /api/contacts/{id}` | Клик по строке → карточка с табами (обзор, компании, каналы, связи, сделки, лента, файлы) | ✅ работает | view-policy работает: non-owner manager → 403 → дженерик-панель ошибки (нет forbidden-специфичного сообщения) |
| Inline-редактирование поля | admin, director, owner-manager | `ContactPage` inline → `PATCH /api/contacts/{id}` | Двойной клик по полю → правка → Enter/blur | ✅ работает | Путь `extra_fields` не валидируется против активных CustomFieldDef |
| Множественные каналы связи | admin, director, owner-manager | `ContactChannelsBlock` → `POST/DELETE /api/contacts/{id}/channels` | Добавить канал (тип+значение); copy/open; удалить через меню | 🟡 частично | Нет UI-редактирования (PATCH + `api.updateChannel` мёртвы); биндинг роута unscoped (IDOR-форма); `contact_channels` 0 строк |
| Связи контакт-контакт | admin, director, owner-manager | `ContactRelations` → `/api/contacts/{id}/relations` | Добавить/править/удалить связь | 🟡 частично | Связь на soft-deleted контакт → null counterpart (list без `withTrashed`); `crm_contact_relations` 0 строк; unscoped binding (IDOR-форма) |
| Привязка/отвязка компании (M2M) + основная | admin, director, owner-manager | `ContactCompaniesTab` → `/api/contacts/{id}/companies[...]` | Поиск компании → привязка с должностью/статусом → set primary | ✅ работает | 3 link-строки live; `is_primary` без partial-unique в БД (race при конкурентном переназначении) |
| Фильтр по автору | admin, director, manager | `ContactsFilterOverlay` → `GET /api/contacts?author_ids[]` | Выбрать «Автор» в оверлее → применить | 🔴 сломан | `created_by_id` NULL во всех строках + не отдаётся ресурсом; фильтр и сорт «author» — тихие no-op |
| Фильтр по должности | все | filter overlay → `GET /api/contacts?position=` | Ввести строку → partial LIKE по free-text `position` | 🟡 частично | `crm_contact_positions` пуст (0); нет списка опций; матч только по свободному тексту |
| Массовые операции (assign owner / tag / delete) | admin, director, manager, lawyer | `ContactsBulkToolbar` → `PATCH/DELETE /api/contacts/bulk` | Bulk-режим → выбрать строки → assign owner / add tag / delete | 🟡 частично | BE bulk update/delete авторизует per-contact (хорошо); НО UI показывает деструктив всем ролям без гейтинга, а утечка списка даёт менеджеру выбрать чужие |
| Экспорт контактов (XLSX) | admin, director, manager, lawyer | Toolbar More→Export / Bulk Export → `POST /api/contacts/export` | Клик Export → BE строит xlsx → скачивание | 🔴 сломан | **BLOCKER**: нет authz, пустой ids → все контакты. FE non-bulk Export шлёт пустые ids → полный PII-дамп для любой роли. Live-подтверждено: manager1 → 200 / 6566 байт |
| Дедуп: скан / мёрж / dismiss | admin, director, manager | More→Dedup → `MergeDialog` → `GET /api/crm/dedup/scan`, POST merge/dismiss | Скоуп скана → группы кандидатов → выбор мастера → merge/dismiss | ⚪ не проверено | Построено (vault: 150 тестов зелёные); `scanAll` без Policy-check (vault follow-up #1). Статически здесь не прогнано |
| Импорт контактов | — | Toolbar More→Import (disabled) | Кнопка отрендерена disabled | ⚪ отсутствует | Ручного импорта нет; только AMO ETL ставит `created_by_id` |
| Drawer истории каналов привлечения | admin, director, owner-manager | `ContactMarketingPanel` → `GET /api/contacts/{id}/channel-history` | Открыть drawer → история смены канала привлечения | ✅ работает | Через raw `apiClient.get` (drawer:118); `contactsApi.getChannelHistory` мёртв. 1 строка `acquisition_channel_history` live |

Сводка статусов: ✅ работает 4 · 🟡 частично 5 · 🔴 сломан 4 · ⚪ отсутствует/не проверено 2.

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк в живой БД | Статус |
|---|---|---|---|---|
| `Contact` | `crm_contacts` | Физлицо (ядро домена) | 3 | built (`created_by_id` NULL во всех строках) |
| `ContactChannel` | `contact_channels` | Множественные каналы (phone/email/tg/wa/…) | 0 | построено, пусто — сосуществует с legacy-скалярами `phone/email/tg_username`; FK `contact_id` ON DELETE CASCADE (только hard-delete) |
| `ContactRelation` | `crm_contact_relations` | Связь контакт-контакт (Wave 2) | 0 | построено, пусто; `RelationService::list` грузит counterpart без `withTrashed` |
| `ContactCompanyLink` | `crm_contact_company_links` | M2M-pivot (position, position_id, employment_status, is_primary) | 3 | built; нет partial-unique в БД по `is_primary` (race) |
| `ContactPosition` | `crm_contact_positions` | Справочник должностей | 0 | построено, пусто; `contact.position` хранится free-text → фильтр матчит только строки |
| `Source` | `crm_sources` | Справочник источников (`/api/admin/sources`) | 5 | built; FE только GET'ит; create/update/delete — в Settings |
| `CustomFieldDef` | `custom_field_defs` | Опр-я произвольных полей (`extra_fields[code]`) | 0 | built; значения не валидируются против активных defs |

**Расхождения migration ↔ live-schema ↔ model ↔ vault:**
- `crm_contacts.created_by_id` — есть в миграции (Wave 2) и в схеме (все строки NULL), но **не пишется** `create()` и **не отдаётся** `ContactResource`; в vault-спеке отсутствует. Вердикт: DRIFT + BUG.
- `crm_contacts.acquisition_channel_id` — есть в миграции/схеме/модели (`update()` пишет историю), в vault отсутствует. Вердикт: DRIFT (vault устарел).
- `crm_contacts.last_activity_at` — есть в миграции + индекс, используется `only_active`/`engagement_tier`, в vault отсутствует. Вердикт: DRIFT (vault устарел).
- **Индексы:** живые индексы `crm_contacts` — pkey, `full_name`, `email`, `phone`, `status`, `last_activity_at`. **Нет индексов на `owner_id` и `created_by_id`** — а именно на них опираются предполагаемый owner-scope и сорт/фильтр по автору. Вердикт: PERF-gap.
- **Пустые-при-наличии-кода:** `contact_channels` (0), `crm_contact_relations` (0), `crm_contact_positions` (0) — фичи построены и замигрированы, но ни разу не прогнаны с данными; параллельно живут legacy-скаляры `phone/email/tg_username` на самом `Contact`. Риск cutover: list/search читают скаляр, карточка читает каналы.
- **Двойной источник ролей:** `users.role` enum + spatie (14 строк `model_has_roles`); CRM-authz читает только enum. Вердикт: DUAL SOURCE — будущая spatie-проверка может разойтись с enum.

## 4. Эндпоинты и покрытие фронтом

| Метод + Path | Контроллер@метод | Авторизация | Вызов фронтом? | Примечание |
|---|---|---|---|---|
| `GET /api/contacts` | `ContactController@index` → `ContactService::list` | viewAny=true, **НЕТ row-scope** | да | **BLOCKER PII-leak**: list без owner-scope (только opt-in `only_mine`). Live: manager1 (owns 0) видит все 3. Достижим только после переключения switch'а с company-режима |
| `POST /api/contacts` | `ContactController@store` → `ContactService::create` | create=true | да | `create()` ставит `owner_id`, но НЕ `created_by_id` (service:191-194). Сорт/фильтр по автору мёртвы |
| `GET /api/contacts/{contact}` | `ContactController@show` | view policy (owner или admin/director) | да | view-policy работает; non-owner manager → 403 → дженерик-ошибка. Resource не отдаёт `created_by`/`created_by_id` |
| `PATCH /api/contacts/{contact}` | `ContactController@update` | update policy | да | patchField + saveExtraField; `extra_fields` валидируется только как nullable array (UpdateContactRequest:36) |
| `DELETE /api/contacts/{contact}` | `ContactController@destroy` → `ContactService::delete` | delete policy (owner или admin/director) | да | Soft-delete; FK-cascade каналов/связей срабатывает только при hard-delete → осиротевшие зависимости |
| `POST /api/contacts/export` | `ContactBulkController@export` → `ContactExportService::buildXlsx` | **НЕТ** | да | **BLOCKER**: нет `authorize()`; `contact_ids` 'sometimes'; пусто → ВСЕ строки (`when($ids!==[])`). Live: manager1 пустое тело → 200, 6566 байт. FE non-bulk Export шлёт пустые ids |
| `PATCH /api/contacts/bulk` | `ContactBulkController@bulkUpdate` | `BulkContactService` authorize | да | assign_owner / add_tag из bulk-тулбара |
| `DELETE /api/contacts/bulk` | `ContactBulkController@bulkDestroy` | `BulkContactService` authorize (403) | да | bulkDelete; корректно авторизует per-contact (в отличие от export) |
| `GET /api/contacts/kpi` | `ContactsKpiController` → `ContactsKpiService` | owner-scope (`applyContactScope`) | да | **MAJOR**: KPI скоупится (=0), list — нет (=3). Live-подтверждено |
| `GET /api/contacts/{contact}/companies` | `ContactCompanyController@index` | view policy | да | getCompanies |
| `POST /api/contacts/{contact}/companies` | `ContactCompanyController@store` | manageLinks | да | attachCompany |
| `DELETE /api/contacts/{contact}/companies/{company}` | `ContactCompanyController@destroy` | manageLinks | да | detachCompany |
| `POST /api/contacts/{contact}/companies/{company}/primary` | `ContactCompanyController@setPrimary` | manageLinks | да | setPrimaryCompany |
| `GET /api/contacts/{contact}/channels` | `ContactChannelController@index` | view policy | **нет** | Мёртвый из FE: каналы приходят в payload `show`; `contactsApi.getChannels` не вызывается |
| `POST /api/contacts/{contact}/channels` | `ContactChannelController@store` | FormRequest authorize (update parent) | да | addChannel |
| `PATCH /api/contacts/{contact}/channels/{channel}` | `ContactChannelController@update` | FormRequest авторизует ТОЛЬКО parent; биндинг НЕ `scoped()` | **нет** | **IDOR + мёртвый**: роут `channels/{channel}` (api.php:187) не `scoped()`; сервис мутирует канал без проверки `channel.contact_id==contact.id`. FE никогда не зовёт `contactsApi.updateChannel` (contacts.ts:194 мёртв); меню канала — только Delete |
| `DELETE /api/contacts/{contact}/channels/{channel}` | `ContactChannelController@destroy` | authorize('update', parent); биндинг unscoped | да | deleteChannel — та же unscoped-binding IDOR-форма (api.php:188) |
| `GET /api/contacts/{contact}/relations` | `ContactRelationController@index` | view policy | да | getRelations; биндинг `relations/{relation}` (api.php:191-194) unscoped |
| `POST /api/contacts/{contact}/relations` | `ContactRelationController@store` | FormRequest authorize | да | addRelation |
| `PATCH /api/contacts/{contact}/relations/{relation}` | `ContactRelationController@update` | FormRequest авторизует parent; unscoped binding | да | updateRelation — IDOR-форма как каналы |
| `DELETE /api/contacts/{contact}/relations/{relation}` | `ContactRelationController@destroy` | authorize parent; unscoped binding | да | deleteRelation |
| `GET /api/contacts/{contact}/deals` | контакт-сделки (Wave 2) | view policy | да | getDeals paginated; `amounts_by_currency` |
| `GET /api/contacts/{contact}/channel-history` | история каналов привлечения | view policy | да | Через raw `apiClient.get` в `ChannelHistoryDrawer.vue:118`; `contactsApi.getChannelHistory` (contacts.ts:284) мёртв |
| `GET /api/contacts/{contact}/feed` | activity feed | view policy | да | Лента в ContactPage; статически wiring не подтверждён |
| `GET /api/contacts/{contact}/log` | entity log | view policy | да | Через `api/crm/log.ts` |
| `GET/POST/DELETE /api/contacts/{contact}/folders[...]/files` | CrmFile endpoints | view/manage | да | Через `api/crm/files.ts` |
| `POST/PATCH/DELETE /api/admin/sources` | `SourceController` (apiResource) | Gate admin-write | **нет** (из Contacts) | Contacts FE только GET'ит sources; CRUD — в Settings. **NEW-5 (live-QA)**: эти `/api/admin/*` справочники доступны manager'у на чтение (200) |

**Orphaned FE-вызовы (мёртвый код):** `contactsApi.updateChannel` (contacts.ts:194), `contactsApi.getChannelHistory` (contacts.ts:284) — без вызывающих компонентов.
**Мёртвые endpoint'ы из FE:** `GET /api/contacts/{contact}/channels` (каналы приходят в `show`), `PATCH /api/contacts/{contact}/channels/{channel}` (нет UI-редактирования).

## 5. RBAC домена

- **admin / director** — полный доступ: view/update/delete всех контактов, KPI глобальный, list глобальный, export всех. UI-гейтинг отсутствует (тот же тулбар, что у всех).
- **lawyer** — `ContactPolicy` не выделяет lawyer → трактуется как Manager (owner-scope на item-доступ), но list/export unscoped → видит/экспортирует всех. UI-гейтинга нет.
- **manager** — item view/update/delete только для своих (`owner_id`); **НО list и export unscoped → видит и экспортирует всех**. KPI скоупится корректно (=0). UI-гейтинга нет: export/import/bulk-delete/assign-owner/merge — всё видно.

**Где авторизация реально проверяется:** item-доступ (`show`/`update`/`destroy`) — через `ContactPolicy` (owner_id или admin/director); bulk update/delete — через `BulkContactService` per-contact (бросает 403). Это работает.

**Где дыры:**
1. `GET /api/contacts` — `viewAny=true`, нет row-scope в `ContactService::list`. `ResolveVisibility` middleware только штампует неиспользуемый request-атрибут `visibility_scope` (M0-каркас; «фильтрация в M1»); Contacts его не читает (его читают только Deals/Activities). **Blocker.**
2. `POST /api/contacts/export` — нет `authorize()`, пустой ids → все. **Blocker.**
3. Вложенные роуты `channels/{channel}` и `relations/{relation}` не `scoped()` — авторизуется только parent → IDOR-форма (нужен runtime-confirm для эксплуатации).
4. KPI скоупится по owner, list — нет → видимое противоречие (major).
5. Нет FE-гейтинга ролей: деструктив/export видны всем (major; для export это реальный вектор PII-дампа).
6. **NEW-5 (live-QA):** `/api/admin/*` справочники (sources, contact-positions, acquisition-channels, disconnect-reasons и др.) отдают 200 для manager — справочники, которые должны быть admin-only.

**Двойной источник ролей:** CRM-authz читает `users.role` enum (Role::Admin/Director/…); параллельно 14 строк spatie `model_has_roles`. Будущая spatie-проверка может разойтись с enum.

## 6. Бэклог проблем

### Сводная таблица

| Severity (FINAL) | Тип | Заголовок | Проверка |
|---|---|---|---|
| blocker | SECURITY | Список контактов отдаёт PII всех контактов любой роли (нет owner-scope) | ✅ подтверждено (live probe) / 🌐 подтверждено в браузере |
| blocker | SECURITY | Эндпоинт export без authz; пустой ids → дамп всех контактов (PII) | ✅ подтверждено (статически + ранее live 200/6566B) |
| major | BUG | `/contacts` всегда открывается в режиме «Компании» (список контактов недостижим по умолчанию) | ✅ подтверждено (статически, детерминированный тернарник) |
| major | DATA-INCONSISTENCY | KPI-полоса (owner-scope) противоречит списку (unscoped) — total 0 над видимыми строками | ✅ подтверждено (live GET) / 🌐 NEW-7 |
| major | BUG | `created_by_id` не пишется при создании → сорт/фильтр по автору мёртвы | ✅ подтверждено (live SQL + GET) |
| major | SECURITY | Нет ролевого UI-гейтинга в Контактах (export/import/bulk-delete/assign-owner видны всем) | ⚠️ частично (BE-guarded actions — UX-gap; export — реальный вектор) |
| major | SECURITY | Вложенные роуты channels/relations не scoped() — IDOR через несоответствие parent/child | не верифицировано (Phase-1, needsLiveCheck) |
| minor | BUG | Soft-delete осиротевает каналы/связи; связь на удалённый контакт → null counterpart | не верифицировано (Phase-1) |
| minor | PERF | Нет индексов на `owner_id` и `created_by_id` | не верифицировано (Phase-1) |
| minor | BUG | `extra_fields` не валидируется против активных CustomFieldDef | не верифицировано (Phase-1) |
| minor | DEAD-CODE | `updateChannel` + `getChannelHistory` — мёртвый FE-код; в меню канала нет edit | не верифицировано (Phase-1) |
| minor | BUG | Фильтр по должности неэффективен (free-text vs пустой справочник позиций) | не верифицировано (Phase-1) |
| minor | CONVENTION | Двойной источник ролей: `users.role` enum vs spatie `model_has_roles` | не верифицировано (Phase-1) |
| trivial | STUB | Таблицы каналов/связей/позиций построены, но пусты (ни разу не прогнаны) | не верифицировано (Phase-1) |
| trivial | BUG | 403 на чужой контакт показывается как дженерик-ошибка (нет forbidden-сообщения) | не верифицировано (Phase-1) |
| trivial | SPEC-DRIFT | Vault-спека Contact опускает `created_by_id`, `acquisition_channel_id`, `last_activity_at` | не верифицировано (Phase-1) |
| — (live-QA) | SECURITY | NEW-5: `/api/admin/*` справочники доступны manager (200) | 🌐 подтверждено в браузере |
| — (live-QA) | BUG | NEW-6: вкладка «Физлица» не переключает вид (URL `?type=person`, таблица показывает компании) | 🌐 подтверждено в браузере |

---

### BLOCKER #1 — Список контактов отдаёт PII всех контактов любой роли (нет owner-scope)

**Severity: blocker · Тип: SECURITY · Проверка: ✅ подтверждено (live probe) + 🌐 подтверждено в браузере**

**Файлы:**
- `src/app/Domain/Crm/Services/ContactService.php:49` (нет owner-scope в list-запросе), `:149` (`only_mine` opt-in, по умолчанию off)
- `src/app/Domain/Crm/Policies/ContactPolicy.php:19` (`viewAny` → true безусловно)
- `src/app/Http/Controllers/Crm/ContactController.php:34-43` (index передаёт фильтры в list без scope)
- `src/app/Http/Middleware/ResolveVisibility.php:18` (каркас; атрибут Contacts'ом не читается)
- `src/app/Domain/Crm/Services/ContactsKpiService.php:124-130` (KPI скоупится — асимметрия)
- `src/app/Http/Resources/Crm/ContactResource.php:21-29` (отдаёт phone/email/tg_username/notes/owner_id)

**Что происходит (evidence):** `ContactPolicy::viewAny` возвращает true, а `ContactService::list` не накладывает owner-scope (только opt-in `only_mine`, по умолчанию off). `ResolveVisibility` лишь штампует неиспользуемый request-атрибут `visibility_scope` (M0-каркас, комментарий «query filtering arrives in M1»); `ContactController` его никогда не читает (его читают только `DealController:371` и `ActivityController:253`). KPI-сервис (`applyContactScope`) скоупится, а его docblock утверждает «применяет тот же scope, что `ContactController::index()`» — но index не скоупится, т.е. комментарий ложный. Live: manager1 (id=4, role=manager, владеет 0 контактами) `GET /api/contacts?per_page=50` вернул 3 контакта, все `owner_id=1`, с телефонами (+7 800 555 3535, +7 999 123 4567, +7 777 000 00 00) и email (test@example.com). Browser-QA (A.3): тот же manager видит ВСЕ 13 компаний + 3 person-контакта (все admin-owned) с полными телефонами.

**Repro:** Войти `manager1@mgcrm.test` → `GET /api/contacts` → 3 контакта с PII, хотя своих 0.

**Предлагаемый фикс:** Наложить owner-scope на `ContactService::list` для не-admin/director (зеркало `ContactsKpiService::applyContactScope`); идеально — вынести общий query-scope `Contact`, чтобы list и KPI использовали один.

---

### BLOCKER #2 — Эндпоинт export без authz; пустой ids → дамп всех контактов (PII)

**Severity: blocker · Тип: SECURITY · Проверка: ✅ подтверждено (статически, все guard'ы исключены; ранее live 200/6566B)**

**Файлы:**
- `src/app/Http/Controllers/Crm/ContactBulkController.php:67-82` (`export()`: нет authorize(); `contact_ids` 'sometimes'; дефолт `[]`)
- `src/app/Domain/Crm/Services/ContactExportService.php:57-58` (`when($contactIds !== [])` → пусто = все строки)
- `src/app/Domain/Crm/Models/Contact.php:21-61` (нет global scope / visibility-трейта)
- `src/routes/api.php:145,170` (group middleware auth:sanctum/2fa/visibility; роут export без role-gate)
- `front/src/pages/ContactsPage/composables/useContactsBulk.ts:27,66-71` (`selectedIdsList=[]` без выделения → постит `contact_ids:[]`)
- `front/src/pages/ContactsPage/components/ContactsToolbar.vue:143-145` (More→Export эмитит безусловно, без guard'а выделения)

**Что происходит (evidence):** `export()` не вызывает `authorize()` (в отличие от `apply()`/`delete()`, которые идут через `BulkContactService` с `AccessDeniedHttpException→403`); `contact_ids` опционален; `buildXlsx` накладывает `whereIn` только `when($ids !== [])`, поэтому пустой массив → все (не-удалённые) строки. Docblock `buildXlsx` сам пишет «visibility NOT enforced here — caller pre-filters», но контроллер не pre-фильтрует. FE non-bulk More→Export связан с тем же bulk-путём с пустым выделением → `{contact_ids:[]}` → полный дамп. Live (ранее): manager1 `POST /api/contacts/export {}` → HTTP 200, 6566-байтный xlsx со всеми контактами. (В Phase-2 POST не перезапускался из-за read-only правила, но код-путь однозначен.)

**Repro:** Войти manager1 → `POST /api/contacts/export` с пустым телом (или More→Export в UI без выделения) → скачивается xlsx с PII всех.

**Предлагаемый фикс:** BE: `authorize('viewAny', Contact)` И пропустить export через scope `BulkContactService`, чтобы отдавался только видимый вызывающему набор; отвергать/обрабатывать пустой ids. FE: требовать выделение или передавать текущие фильтры списка; скрыть Export для непривилегированных ролей. (Замечание Phase-2: пока row-level visibility не реализована во всём проекте, *инкрементальная* утечка относительно списка мала на 3-строчном seed — но дефект безграничен по мере роста данных.)

---

### MAJOR #3 — `/contacts` всегда открывается в режиме «Компании»

**Severity: major · Тип: BUG · Проверка: ✅ подтверждено (статически, детерминированный тернарник)**

**Файлы:**
- `front/src/pages/ContactsPage/index.vue:792`
- `front/src/router/routes/base.ts:37-55` (имена 'Contacts'→/contacts и 'Companies'→/companies, оба → ContactsPage)

**Что происходит (evidence):** И `/contacts` (name 'Contacts'), и `/companies` (name 'Companies') резолвятся в один `ContactsPage`. Строка index.vue:792: `const initialType = route.name === 'Companies' ? 'company' : 'company'` — **обе ветки возвращают `'company'`**. Поэтому `/contacts` грузится в company-режиме, шлёт `GET /api/companies`, а в гриде под пунктом «Контакты» показываются компании, label создания = «Создать компанию». Другого места, переопределяющего `initialType`, нет (искал — только line 792).

**Repro:** Клик «Контакты» в сайдбаре (или открыть `/contacts`): грид показывает компании, label = «Создать компанию». Нужно вручную переключить switch на «Физлица».

**Предлагаемый фикс:** `index.vue:792` → `route.name === 'Companies' ? 'company' : 'contact'`.

> Связано с **NEW-6 (live-QA, 🌐 подтверждено в браузере):** клик по вкладке «Физлица» обновляет URL на `?type=person`, но таблица всё равно показывает компании — компонент не реагирует на смену query-параметра. Это второй симптом того же узла переключения режима; фикс initialType не закроет NEW-6 автоматически — нужно ещё чтобы компонент отслеживал смену типа и перезагружал данные.

---

### MAJOR #4 — KPI-полоса (owner-scope) противоречит списку (unscoped)

**Severity: major · Тип: DATA-INCONSISTENCY · Проверка: ✅ подтверждено (live GET) + 🌐 NEW-7**

**Файлы:**
- `src/app/Domain/Crm/Services/ContactsKpiService.php:124` (88-89, 124-130 — `applyContactScope` по owner_id)
- `src/app/Domain/Crm/Services/ContactService.php:49` (нет scope; only_mine opt-in 149-151)
- `front/src/pages/ContactsPage/components/ContactsKpiBar.vue` (рендерится прямо над DataTable, index.vue:39-43)

**Что происходит (evidence):** `ContactsKpiService::forContacts` применяет `applyContactScope` (owner_id=user) для не-admin/director, а `ContactService::list` scope не имеет. Live: manager1 `GET /api/contacts/kpi?entity=contact` → `{total:0,active:0,no_touch_30:0,new_week:0}`; `GET /api/contacts` → `meta.total=3` (все owner_id=1). KPI-полоса сидит прямо над таблицей → чип «total 0» противоречит 3 видимым строкам. Browser-QA NEW-7: на вкладке «Компании» KPI «Всего: 2» при 13 показанных строках — тот же узор.

**Repro:** Войти manager1 → Контакты (переключить на «Физлица») → KPI «total 0» над 3 видимыми строками.

**Предлагаемый фикс:** Использовать один общий scope для list и KPI. Фикс утечки списка (BLOCKER #1) автоматически выравнивает их.

---

### MAJOR #5 — `created_by_id` не пишется при создании → сорт/фильтр по автору мёртвы

**Severity: major · Тип: BUG · Проверка: ✅ подтверждено (live SQL + live GET + код)**

**Файлы:**
- `src/app/Domain/Crm/Services/ContactService.php:191` (create() ставит только owner_id), `:74-76` (author-фильтр), `:354-356` (author-сорт)
- `src/app/Http/Requests/Crm/StoreContactRequest.php:33` (нет `created_by_id`)
- `front/src/pages/ContactsPage/composables/useContactsPageData.ts:27` (CONTACT_SORT_MAP: owner header → 'author')
- `front/src/pages/ContactsPage/components/ContactsFilterOverlay.vue:64-72` (`author_ids` MultiSelect)

**Что происходит (evidence):** `create()` ставит только `owner_id`, `StoreContactRequest` не содержит `created_by_id` → все 3 живые строки имеют `created_by_id` NULL. Live GET как manager1: в payload нет ключа `created_by_id` (ресурс его не отдаёт). Author-фильтр → `whereIn('created_by_id', ...)`, author-сорт → `leftJoin users on created_by_id orderBy full_name` — но колонка NULL и не отдаётся. FE мапит заголовок owner-колонки на сорт 'author' и биндит `author_ids` MultiSelect. Итог: оба контрола — тихие no-op. Только AMO-импорт ставит `created_by_id`.

**Repro:** Создать контакт → `SELECT created_by_id` → NULL. В оверлее выбрать «Автор» или кликнуть сорт owner/author → эффекта нет.

**Предлагаемый фикс:** `create()` ставить `created_by_id = creator->id` по умолчанию; отдавать в `ContactResource`; забэкфиллить существующие строки. (Либо убрать author-контрол, пока не поддержан.)

---

### MAJOR #6 — Нет ролевого UI-гейтинга в Контактах

**Severity: major · Тип: SECURITY · Проверка: ⚠️ частично (фактическое ядро true; bulk update/delete BE-guarded — UX-gap; export — реальный вектор)**

**Файлы:**
- `front/src/pages/ContactsPage/index.vue`, `front/src/pages/ContactsPage/components/ContactsToolbar.vue:131`, `front/src/pages/ContactsPage/components/ContactsBulkToolbar.vue`
- `front/src/pages/ContactPage/composables/useContactPageActions.ts:171`

**Что происходит (evidence):** grep по `useAuthStore`/`isAdmin`/`hasRole`/`.can(`/`role===` в `pages/ContactsPage` + `ContactPage` → ноль попаданий. Toolbar More (Export/Columns/Bulk), bulk-тулбар (Assign owner/Add tag/Merge/Delete) и delete в ContactPage рендерятся безусловно. **Сужение (Phase-2):** bulk update/delete авторизуются на BE per-contact (`BulkContactService`, 403), поэтому для них это в основном UX/defense-in-depth-gap, не самостоятельная эскалация. Однако кнопка **Export не имеет BE-authz** (BLOCKER #2) + список unscoped (BLOCKER #1), поэтому негейтнутый Export — реальный exploit-вектор полного PII-дампа, и негейтнутый bulk-UI даёт менеджеру выбрать строки, которые он не должен видеть (т.к. список их утекает). Поэтому остаётся major.

**Repro:** Войти любой не-admin → все bulk/export/delete контролы видимы и кликабельны.

**Предлагаемый фикс:** Гейтить деструктив/export по роли (admin/director) через auth-store; зеркалить BE-policy в UI. Критически — параллельно чинить export-authz (BLOCKER #2) + scope списка (BLOCKER #1).

---

### MAJOR #7 — Вложенные роуты channels/relations не scoped() — IDOR

**Severity: major · Тип: SECURITY · Проверка: не верифицировано (Phase-1, needsLiveCheck) — открытый вопрос на runtime-confirm**

**Файлы:**
- `src/routes/api.php:187` (`channels/{channel}`), `:193` (`relations/{relation}`)
- `src/app/Http/Controllers/Crm/ContactChannelController.php:46`
- `src/app/Domain/Crm/Services/ContactChannelService.php:45`

**Что происходит (evidence):** Роуты `channels/{channel}` и `relations/{relation}` НЕ `->scoped()`. `update()`/`destroy()` авторизуют только parent-контакт (FormRequest `can('update',$contact)` / `authorize('update',$contact)`); сервис мутирует переданный `{channel}`/`{relation}` без проверки принадлежности этому контакту. Пользователь, имеющий право update на контакт A, может передать id контакта A (проходит authz) с `{channel}`, принадлежащим контакту B, и изменить/удалить его.

**Repro:** Как владелец контакта A: `PATCH /api/contacts/A/channels/{channel_of_B}` → меняет канал B (нужен runtime-confirm).

**Предлагаемый фикс:** Добавить `->scoped()` в определения вложенных роутов, либо в контроллере/сервисе проверять `$channel->contact_id === $contact->id` (404 иначе). Тот же фикс для `relations/{relation}`.

---

### minor / trivial (не верифицировано, Phase-1)

- **minor · BUG** — Soft-delete осиротевает каналы/связи; связь на soft-deleted контакт → null counterpart (`ContactService.php:227`, `ContactRelationService.php:34`). FK `contact_channels` ON DELETE CASCADE срабатывает только при hard-delete; `RelationService::list` грузит counterpart без `withTrashed`. Фикс: каскадный soft-delete или detach зависимостей; грузить counterpart `withTrashed` с маркером «удалён».
- **minor · PERF** — Нет индексов на `owner_id` и `created_by_id` (`ContactService.php:70`). Живые индексы: pkey, full_name, email, phone, status, last_activity_at. Фикс: btree-индексы на оба.
- **minor · BUG** — `extra_fields` не валидируется против активных CustomFieldDef (`StoreContactRequest.php:33`, `UpdateContactRequest.php:36` — только `['nullable','array']`). Required-поля можно пропустить, произвольные ключи/типы — сохранить. Фикс: валидировать против активных defs (scope=contact): required, type, options.
- **minor · DEAD-CODE** — `contactsApi.updateChannel` (contacts.ts:194) и `contactsApi.getChannelHistory` (contacts.ts:284) — без вызывающих; меню канала — только Delete; drawer зовёт raw `apiClient.get` (`ChannelHistoryDrawer.vue:118`). Фикс: либо подключить Edit→updateChannel и провести drawer через getChannelHistory, либо удалить мёртвые методы.
- **minor · BUG** — Фильтр по должности неэффективен (`ContactsFilterOverlay.vue:150`, `useContactsPageData.ts:167`, `ContactService.php:120`): оверлей шлёт строку, BE делает `whereLike('position', ...)` по free-text; `crm_contact_positions` пуст → нет списка опций. Фикс: засеять `crm_contact_positions`, линковать через `position_id`, дать dropdown.
- **minor · CONVENTION** — Двойной источник ролей: `users.role` enum vs spatie `model_has_roles` (`ContactPolicy.php:41`, `AppServiceProvider.php:243`). 14 строк spatie + `users.role` заполнен. Фикс: выбрать один источник истины (синхронизировать enum↔spatie или убрать неиспользуемый).
- **trivial · STUB** — `contact_channels` (0) / `crm_contact_relations` (0) / `crm_contact_positions` (0) построены, но пусты; multi-channel сосуществует с legacy-скалярами phone/email/tg. Фикс: QA с реальными данными до cutover; решить, что источник истины — legacy-скаляр или channel-таблица.
- **trivial · BUG** — Non-owner 403 → дженерик load-error (`front/src/pages/ContactPage/index.vue:22`), нет «нет доступа»-панели. Фикс: различать 403 → forbidden-панель.
- **trivial · SPEC-DRIFT** — Vault-спека Contact опускает `created_by_id`, `acquisition_channel_id`, `last_activity_at` (`Contact.php:33` vs vault `CONTACTS 2.0 …:61`). Фикс: обновить список полей в модуле CONTACTS 2.0.

### Релевантные NEW-* из live-QA (🌐 подтверждено в браузере)

- **NEW-5 (P1) · SECURITY** — `/api/admin/*` справочники доступны manager (200): `company-types`, `sources`, `countries`, `cities`, `contact-positions`, `acquisition-channels`, `disconnect-reasons`. Должны быть admin-only; manager не должен читать каналы привлечения / причины отключения (чувствительная BI). Фикс: гейт роли на этих admin-роутах (или вынести «справочники для выбора» в отдельный read-only endpoint без чувствительных полей).
- **NEW-6 (P2) · BUG** — Вкладка «Физлица» не переключает вид: URL → `?type=person`, таблица показывает компании. Компонент не реагирует на смену query-параметра. Связано с MAJOR #3 (узел переключения режима), но требует отдельного фикса реактивности.
- **NEW-7 (P2) · уже покрыт MAJOR #4** — KPI «Всего: 2» при 13 строках на вкладке «Компании» — тот же узор KPI-vs-list.

## 7. Расхождения со спекой (vault) и предложения по актуализации

Документ: `2. Модули/Crm — CONTACTS 2.0 (Контакты, Компании, дедуп).md`.

1. **Поля Contact (раздел «Сущности и ключевые поля → Contact (crm_contacts)», ~line 61).** Спека: `full_name, position, phone, email, tg_username, notes, source, status, tags, extra_fields, owner_id`. Реальность: модель + живая схема также имеют `created_by_id`, `acquisition_channel_id`, `last_activity_at` (Wave 2). **Предложение:** добавить эти три поля в список + follow-up-note, что `create()` обязан ставить `created_by_id` (сейчас баг — всегда NULL).

2. **Безопасность (IDOR + Policy) / API contacts.** Спека: «все item-эндпоинты через Policy; `viewAny=true` с фильтрацией видимости через visibility-scope middleware». Реальность: `ResolveVisibility` лишь штампует НЕИСПОЛЬЗУЕМЫЙ request-атрибут (M0-каркас); `ContactService::list` и `ContactBulkController::export` scope НЕ применяют → менеджеры видят/экспортируют всех (live-подтверждённая PII-утечка). **Предложение:** пометить заявление про visibility-scope-on-list как НЕ РЕАЛИЗОВАНО; добавить blocker-note: list + export должны быть owner-scoped до cutover.

3. **Follow-ups / backlog.** Спека не упоминает export-authz, scoping вложенных роутов каналов/связей, KPI-vs-list mismatch. **Предложение:** добавить три follow-up: (a) blocker export authz+scope; (b) `->scoped()` на вложенные роуты channels/relations; (c) унифицировать scope list и KPI. Дополнительно: NEW-5 (admin-справочники доступны manager).

4. **Frontend UI → ContactsPage.** Спека: «ContactsPage с SelectButton-переключателем (Физлица/Компании)». Реальность: `index.vue:792` хардкодит `initialType` в `'company'` для ОБОИХ роутов → пункт «Контакты» открывает список компаний до ручного переключения; вдобавок сам переключатель не реагирует на смену типа (NEW-6). **Предложение:** зафиксировать default-mode баг и неработающее переключение вкладки как известные дефекты к фиксу (`initialType` должен быть `'contact'` для роута Contacts + реактивность на смену типа).

5. **dataModelDiff (общая актуализация миграционной таблицы vault).** Отметить отсутствие индексов на `owner_id`/`created_by_id` (PERF-gap); зафиксировать, что `contact_channels` / `crm_contact_relations` / `crm_contact_positions` построены, но пусты (0 строк) и сосуществуют с legacy-скалярами — решение об источнике истины принять до cutover. Зафиксировать двойной источник ролей (`users.role` enum vs spatie) и выбрать авторитетный.
