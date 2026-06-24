# Аудит домена: CRM — Компании (реквизиты, холдинг, жизненный цикл клиента, дедуп, кастом-поля, файлы)

## 1. Назначение

Домен **Компании** — ядро CRM в модели **deal-on-company**: компания (`crm_companies`) одновременно и юрлицо-клиент (денормализованные реквизиты — `legal_name`, `tax_id`, банковские данные), и центр воронки продаж (вокруг компании строятся сделки, лиды = сделки в стадии «Новые лиды»). Домен покрывает: ведение карточки клиента и инлайн-редактирование, множественные наборы реквизитов с текущим (`is_current`) и зеркалом на `crm_companies`, холдинговую иерархию (parent/subsidiary через `holding_id`), жизненный цикл клиента (`prospect → active → disconnected → reconnect`), дедупликацию (scan/merge/dismiss), кастом-поля (полиморфные defs + значения в `extra_fields`), каналы связи компании, файлы/папки, справочники (страны, типы, источники, причины расторжения, каналы привлечения) и журнал смены статуса.

**Зрелость: частично (зрелый каркас с двумя боевыми дырами).** Backend выстроен по слоям ARCHITECTURE.md (FormRequest → тонкий Controller → Domain Service → Model → Resource), модели сходятся с живой схемой БД, single-record IDOR закрыт корректно. Но в проде **две подтверждённые блокирующие дыры**: (1) список и экспорт компаний не скоупятся по видимости — любой пользователь видит и выгружает всю базу клиентов; (2) merge сирот­ит дочерние FK (сделки, документы, реквизиты, холдинг). Живые данные тестовые и тонкие: `crm_companies = 13` (все `owner_user_id = 1`), `company_requisites = 16`, `company_client_status_log = 3`, `acquisition_channel_history = 1`; ряд таблиц построен, но пуст (`custom_field_defs = 0`, `company_channels = 0`, `crm_files = 0`, `dismissed_duplicates = 0`) — половина фич не проэксплуатирована.

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI + endpoint) | Как (шаги) | Статус | Примечание |
|---|---|---|---|---|---|
| Список + экспорт компаний (скоуп видимости) | НАМЕРЕНИЕ: admin/director — все, manager — свои/отдел; ФАКТ: любой авторизованный | ContactsPage (toolbar) → `GET /api/companies`, `POST /api/companies/export` | `CompanyService.list` применяет search/type/source/category/date/preset и opt-in `only_mine`, но НЕ применяет обязательный owner/department-скоуп; `CompanyExportService.buildXlsx` при пустых ids выгружает всю таблицу | 🔴 сломан | BLOCKER, 🌐 подтверждено в браузере: manager1 (owns 0) видит все 13, owners=[1]; export {} → HTTP 200, XLSX 6980 байт |
| CRUD одной компании + IDOR на запись | admin/director — любой; manager — owner/responsible | CompanyPage → `GET/PATCH/DELETE /api/companies/{id}` | show/destroy вызывают `authorize`; update опирается на `UpdateCompanyRequest.authorize`; `CompanyPolicy.canAccess` проверяет owner/responsible; чужая компания → 403 | ✅ работает | `GET /api/companies/13` как manager1 → 403 подтверждён. Single-record IDOR закрыт; течёт только список/экспорт |
| Жизненный цикл клиента (prospect→active→disconnected→reconnect) | manager/director/admin; система; листенер `DisconnectCompanyOnTerminationSigned` | первая выигранная сделка; DisconnectDialog → `POST .../disconnect`; событие подписи; reconnect (нет UI) | prospect→active на первой won-сделке (идемпотентно); disconnect создаёт `TerminationAgreement` (статус не меняется); подпись → disconnected; reconnect → active/prospect; каждый переход пишет `company_client_status_log` (3 строки) | 🟡 частично | reconnect мёртв в UI (нет кнопки), BE reconnect неидемпотентен (пишет active→active) |
| Реквизиты: set-current + зеркало денорм | пользователи с update-доступом | CompanyRequisitesPanel → `POST/PATCH/DELETE/set-current .../requisites` | `create()` всегда ставит `is_current=false`; зеркало в `crm_companies` — только на `setCurrent()` или `update()` уже-текущего; delete блокирует единственный набор/закреплённый документами | 🟡 частично | Первый/единственный реквизит через API — не-текущий → `currentRequisite` null, зеркало не отрабатывает. 16 строк на 13 компаний |
| Дедуп: scan / merge / dismiss | admin/director — глобально; owner/responsible — per-entity | MergeDialog → `GET /api/crm/dedup/scan`, `POST /api/crm/dedup/merge`, `POST /api/crm/dedup/dismiss` | scan группирует по нормализованным email/phone/tax_id/name; merge переносит ТОЛЬКО `crm_contact_company_links`, затем soft-delete дубля; dismiss пишет нормализованную пару | 🔴 сломан | BLOCKER: merge сирот­ит deals/documents/requisites/channels/status-log/subsidiaries. `dismissed_duplicates` = 0 |
| Кастом-поля (полиморфные, `extra_fields`) | admin/director — defs; любой — значения | CustomFieldRenderer → `GET /api/crm/custom-fields/schema`; `PATCH /api/companies/:id extra_fields` | НАМЕРЕНИЕ: значения валидируются/коэрсятся через `CustomFieldService.writeFields`; ФАКТ: `extra_fields` — nullable-array mass-assignment, writeFields не вызывается на company-пути, defs пуст, нет admin-UI для defs | 🔴 сломан (company-путь) | MAJOR: для компаний валидация отсутствует; defs пуст лишь маскирует эффект сегодня |
| Холдинг (parent/subsidiary) | owner/responsible/admin/director | HoldingTree → `GET/POST/DELETE .../holding` | `HoldingService` строит дерево по `holding_id` (CTE на PG, PHP-fallback на SQLite); attach с защитой от цикла (422 `holding_cycle`); detach обнуляет `holding_id` | ✅ работает | merge НЕ переносит `holding_id` дочек (см. блокер дедупа) |
| Файлы компании (папки/загрузки) | owner/responsible/admin/director | CompanyFilesTab → `.../folders` & `.../files` | per-entity папки автосеются (15 строк); загрузка — FE-заглушка «скоро» (B-C1); `crm_files = 0` | 🟡 частично | Схема и папки есть; FE-загрузка не реализована (B-C1) |
| Каналы привлечения + история | owner/responsible/admin/director | company create/update с `acquisition_channel_id` | `AcquisitionChannelHistoryService` пишет полиморфную строку при смене канала (1 строка) | ✅ работает | `channel-history` GET существует, но `companiesApi.getChannelHistory` без вызывающего (мёртв) |
| Каналы связи компании (телефоны/email/сайт) | owner/responsible/admin/director | CompanyChannelsBlock → `GET/POST/DELETE .../channels` | add/delete с фронта; PATCH update существует на BE, но нет edit-UI | 🟡 частично | `company_channels = 0`; 🌐 NEW-1: компонент `CompanyChannelsBlock` не резолвится на странице (Vue warn x3) |
| Справочники admin (CRUD) | запись — admin/director; чтение — любой авторизованный | `/admin/*` → `/api/admin/{...}` | index/show без `authorize` (открытое чтение); store/update/destroy → `authorize('admin-write')` по `users.role` | 🟡 частично | 🌐 NEW-5: manager успешно GET-ит все `/api/admin/*` (200) — API-чтение открыто, FE-роутер закрывает лишь страницы |

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк в живой БД | Статус |
|---|---|---|---|---|
| Company | `crm_companies` | Ядро клиента (deal-on-company): trade name + денорм реквизиты, классификация, холдинг, lifecycle, владение | 13 (все `owner_user_id=1`) | built |
| CompanyRequisite | `company_requisites` | Множественные наборы реквизитов; `is_current=true` зеркалится в `crm_companies` | 16 | built |
| CompanyChannel | `company_channels` | Телефоны/email/сайт/tg/wa компании (зеркало контактных каналов) | 0 (никогда не использовалось) | built |
| CompanyClientStatusLog | `company_client_status_log` | Append-only журнал переходов `client_status` | 3 | built |
| CustomFieldDef | `custom_field_defs` | Полиморфные defs (scope contact/company/deal); значения в `extra_fields[code]`, но write-путь не подключён | 0 | partial |
| DismissedDuplicate | `dismissed_duplicates` | Пары «не дубликат» (нормализ. a<b), чтобы scan их пропускал | 0 | built |
| AcquisitionChannel + History | `acquisition_channels`, `acquisition_channel_history` | Справочник + полиморфная история смены канала | каналы 10, история 1 | built |
| CrmFolder + CrmFile | `crm_folders`, `crm_files` | Папки и загрузки per-entity; системные автосеются; виртуальная scan-папка проецирует документы сделки | папки 15, файлы 0 | built (FE-загрузка B-C1) |
| Directories (CompanyType, DisconnectReason, Source, Country, City, ContactPosition) | `crm_company_types`, `disconnect_reasons`, `crm_sources`, `crm_countries`, `crm_cities`, `crm_contact_positions` | Admin-справочники; `company.source`/`country_code` — денорм varchar без FK | types 4, reasons 8, sources 5, countries 3, cities 5, positions 0 | built |
| SavedView | `crm_saved_views` | Per-user пресеты фильтров/колонок списка | 2 | built |
| (cross-domain) Deal | `deals` | Сделки, ссылаются на `company_id` (важно для merge-блокера) | 13 (9 компаний имеют сделки) | — |
| (cross-domain) Document | `documents` | Документы, `source_company_id` (важно для merge-блокера) | 8 | — |
| (pivot) ContactCompanyLink | `crm_contact_company_links` | M2M контакт↔компания (единственное, что переносит merge) | 3 | built |

**Расхождения migration ↔ live-schema ↔ model:**
- **Несоответствий КОЛОНОК модель↔живая-схема НЕ найдено** по Company, CompanyRequisite, CompanyChannel, CustomFieldDef — модели сходятся со схемой. (trivial)
- **Дублирование классификации:** `crm_companies.specialization` (enum) сосуществует с легаси `industry` (varchar) и зеркальными legal-колонками реквизита. (trivial)
- **Денорм без FK:** `company.source` и `company.country_code` — varchar, ссылающиеся на `crm_sources.code` / `crm_countries.code` БЕЗ внешнего ключа; могут «дрейфнуть» при переименовании/удалении справочной записи. (trivial)
- **Отсутствие индексов:** FK-констрейнты на `owner_user_id`, `responsible_user_id`, `holding_id` есть, но PostgreSQL не индексирует ссылающиеся колонки автоматически, и явный btree-индекс не создан. После добавления скоупа списка по owner/responsible это станет горячей точкой без индекса. (minor)

**Пустые-при-наличии-кода таблицы** (фичи построены, но не проэксплуатированы): `custom_field_defs` (0 — write-путь значений мёртв), `dismissed_duplicates` (0), `company_channels` (0 — каналы не использовались), `contact_channels` (0), `crm_files` (0 — FE-загрузка B-C1 заглушка).

## 4. Эндпоинты и покрытие фронтом

| Метод + Path | Контроллер@метод | Авторизация | Вызывается фронтом? | Примечание |
|---|---|---|---|---|
| GET `/api/companies` | `CompanyController@index` | auth:sanctum + 2fa; `viewAny=true`; **НЕТ row-level скоупа** (visibility-атрибут игнорируется) | да | 🔴 утечка подтверждена live (manager1 видит все 13) |
| POST `/api/companies` | `CompanyController@store` | `create=true` для всех; авто-owner+department от создателя | да | — |
| GET `/api/companies/{company}` | `CompanyController@show` | `authorize view`; owner OR responsible OR admin OR director | да | manager1 → 403 на чужую компанию (подтверждено) |
| PATCH `/api/companies/{company}` | `CompanyController@update` | **НЕТ in-controller authorize**; бэкстоп `UpdateCompanyRequest.authorize` | да | minor: неявная защита |
| DELETE `/api/companies/{company}` | `CompanyController@destroy` | `authorize delete`; admin/director — любой; manager — только owner (responsible НЕ может) | да | — |
| POST `/api/companies/export` | `CompanyBulkController@export` | только auth; пустое тело → вся таблица | да | 🔴 утечка подтверждена (HTTP 200, XLSX 6980 байт, по коду) |
| PATCH `/api/companies/bulk` | `CompanyBulkController@apply` | per-entity Gate `update`; all-or-nothing | да | корректно скоупится (`BulkCompanyService.authorizeCompanies`) |
| DELETE `/api/companies/bulk` | `CompanyBulkController` | per-entity Gate; 422 на пустом | да | — |
| GET/POST/PATCH/DELETE `/api/companies/{company}/requisites[/{rid}]` | `CompanyRequisiteController` | `FormRequest authorize can update`; set-current | да | первый/единственный реквизит — не-текущий (зеркало не отрабатывает) |
| POST `/api/companies/{company}/requisites/{id}/set-current` | `CompanyRequisiteController@setCurrent` | транзакция: сброс соседей, текущий, зеркало в `crm_companies` | да | — |
| GET/POST/DELETE `/api/companies/{company}/channels[/{channel}]` | `CompanyChannelController` | `authorize` | да | таблица 0 строк; компонент не резолвится (NEW-1) |
| PATCH `/api/companies/{company}/channels/{channel}` | `CompanyChannelController@update` | `authorize` | **нет (orphaned BE)** | 🔴 МЁРТВ: `companiesApi.updateChannel` определён, меню канала предлагает только Delete |
| POST `/api/companies/{company}/disconnect` | `CompanyClientStatusController@disconnect` | `authorize update`; создаёт `TerminationAgreement`; статус меняется лишь по signed-событию | да | — |
| POST `/api/companies/{company}/reconnect` | `CompanyClientStatusController@reconnect` | `authorize` | **нет (orphaned BE)** | 🔴 МЁРТВ + НЕТ UI: `companiesApi.reconnect` определён, не вызывается; неидемпотентен (пишет active→active) |
| POST `/api/companies/{company}/termination-documents/generate` | `CompanyClientStatusController` | `authorize` | **нет (orphaned BE)** | МЁРТВ: drawer использует общий `/api/documents/{id}/generate` |
| GET `/api/companies/{company}/channel-history` | `AcquisitionChannelHistoryController` | `authorize` | **нет (orphaned BE)** | МЁРТВ: `companiesApi.getChannelHistory` без вызывающего |
| GET/POST/DELETE `/api/companies/{company}/holding` | `HoldingController` | show `authorize view`; detach `authorize update`; attach `FormRequest`; cycle guard 422 | да | — |
| GET/POST/PATCH/DELETE `/api/companies/{company}/employees[/{contact}]` | `CompanyEmployeeController` | `authorize manageEmployees (=canAccess)` | да | — |
| POST `/api/contacts/{contact}/companies/{company}/primary` | `ContactCompanyController@setPrimary` | route есть; 404 при отсутствии связи | да | — |
| GET `/api/companies/{company}/deals` | `CompanyController` | связанные сделки + `amounts_by_currency` (wave 2) | да | — |
| GET `/api/companies/{company}/status-log` | `CompanyClientStatusController` | popover ClientStatusBadge | да | — |
| POST `/api/crm/dedup/merge` | `DedupController@merge` | `authorize update` на master + каждый дубль; **mergeCompany сирот­ит non-link FK (blocker)** | да | 🔴 data-loss |
| GET `/api/crm/dedup/scan` | `DedupController@scan` | global gated `dedup-scan-all` (`users.role`); per-entity `authorize view`; грузит почти-полные таблицы (perf) | да | — |
| POST `/api/crm/dedup/dismiss` | `DedupController@dismiss` | хранит нормализованную пару | да | — |
| GET `/api/crm/custom-fields/schema` | `CustomFieldDefController` | возвращает 0 defs (таблица пуста) → рендерер всегда empty-state | да | — |
| GET `/api/crm/custom-fields` | `CustomFieldDefController@index` | по scope; defs пуст | да | — |
| POST `/api/crm/custom-fields` | `CustomFieldDefController@store` | `admin-write` gate (`users.role`); **НЕТ FE admin-UI для создания defs** | **нет** | write-слой значений мёртв на company-пути |
| GET `/api/crm/saved-views` | `SavedViewController` | `useSavedViews` грузит, но контрол тулбара скрыт (D3 убран) | да | — |
| GET (read any) / POST/PATCH/DELETE (admin-write) `/api/admin/{countries,company-types,sources,disconnect-reasons,acquisition-channels,cities,contact-positions}` | Crm Admin controllers | index/show **БЕЗ authorize** → читает любой авторизованный; writes `authorize admin-write` | да | 🌐 NEW-5: manager GET-ит все (200) |

## 5. RBAC домена

| Действие | Кому разрешено | Где реально проверяется | Дыра? |
|---|---|---|---|
| Список / экспорт компаний | НАМЕРЕНИЕ: admin/director — все, manager — свои/отдел; ФАКТ: **любой видит И экспортирует ВСЕ** | `CompanyService.list` (`CompanyService.php:58-181`) и `CompanyExportService.buildXlsx` (`:45-66`) скоуп НЕ применяют; `ResolveVisibility` лишь штампует атрибут, который никто не читает | 🔴 ДА — блокер (cross-tenant утечка) |
| Просмотр/правка одной компании + sub-resources | admin, director, либо `owner_user_id`/`responsible_user_id` | `CompanyPolicy.canAccess` через `authorize` + `FormRequest authorize` | ✅ закрыто корректно |
| Удаление одной компании | admin, director, либо `owner_user_id` (responsible НЕ может) | `CompanyPolicy.delete` (`CompanyPolicy.php:44-52`) | ✅ |
| Bulk update/delete | admin/director — любой; manager — только свои/responsible (all-or-nothing) | `BulkCompanyService.authorizeCompanies` | ✅ |
| Global dedup scan, custom-field-def CRUD, directory writes, system reset | admin + director (system-reset — admin); по колонке `users.role` | Gates в `AppServiceProvider.php:243-260` | 🟡 двойной источник роли (см. бэклог major→minor) |
| Чтение admin-справочников (index/show) | любой авторизованный | Crm/Admin/* контроллеры (нет `authorize` на index/show); FE-роутер закрывает страницы `/admin/*` | 🟡 API-чтение открыто (NEW-5, minor) |

**Главный вывод RBAC:** авторизация одной записи (view/update/delete/bulk) выстроена корректно и проверяется в Policy/FormRequest/Gate. Дыра — на коллекциях: список и экспорт не имеют обязательного row-level скоупа, а `ResolveVisibility` — M0-заглушка (`«query filtering arrives in M1»`), штампующая `visibility_scope`, который ни один сервис CRM не читает.

## 6. Бэклог проблем

### Сводка

| Severity (FINAL) | Тип | Заголовок | Проверка |
|---|---|---|---|
| blocker | SECURITY | Список и экспорт компаний утекают ВСЕ компании любому пользователю | ✅ подтверждено + 🌐 в браузере |
| blocker | SECURITY | Утёкший список кликабелен → manager попадает на 403-тупик CompanyPage | ✅ подтверждено live + 🌐 |
| blocker | DATA-INCONSISTENCY | Merge компаний сирот­ит deals/documents/requisites/channels/status-log/subsidiaries | ✅ подтверждено (live data + статика) |
| major | DEAD-CODE | Слой валидации кастом-полей мёртв на company-пути; `extra_fields` — free-form mass-assignment | ⚠️ частично (уже, чем заявлено) |
| major | SPEC-DRIFT | Спека + docblock `CompanyPolicy` утверждают фильтрацию списка по видимости, которой нет | ✅ подтверждено live |
| major | PERF | Dedup scan грузит почти-полные таблицы в память | ✅ подтверждено (статика) |
| minor | SECURITY | Двойной источник роли: Policy/Gates читают `users.role`, VisibilityResolver — spatie | ⚠️ частично (понижено с major) |
| minor | DEAD-CODE | `reconnect()` — мёртвый FE-код, нет UI; endpoint неидемпотентен | ✅ подтверждено (понижено с major) |
| minor | BUG | Первый/единственный реквизит через API — не-текущий; `currentRequisite` null, зеркало не отрабатывает | не верифицировано (Phase-1) |
| minor | CONVENTION | `CompanyController.update` без in-controller `authorize` | не верифицировано (Phase-1) |
| minor | DEAD-CODE | PATCH-эндпоинт канала мёртв (нет FE edit-UI) | не верифицировано (Phase-1) |
| minor | PERF | Нет btree-индексов на `owner_user_id`, `responsible_user_id`, `holding_id` | не верифицировано (Phase-1) |
| minor | SECURITY | index/show admin-справочников читает любой авторизованный | не верифицировано (Phase-1) / 🌐 NEW-5 |
| trivial | DEAD-CODE | `generateTerminationDocument` + `getChannelHistory` — мёртвые FE-методы | не верифицировано (Phase-1) |
| — (live-QA) | BUG | NEW-1: компонент `CompanyChannelsBlock` не резолвится на странице компании | 🌐 подтверждено в браузере |
| — (live-QA) | SECURITY | NEW-5: `/api/admin/*` доступны manager-роли | 🌐 подтверждено в браузере |
| — (live-QA) | BUG | NEW-7: KPI «Всего: 2» против 13 строк в списке | 🌐 подтверждено в браузере |

---

### BLOCKER #1 — Список и экспорт компаний утекают ВСЕ компании любому пользователю

**Severity:** blocker · **Тип:** SECURITY · **Проверка:** ✅ подтверждено (live probe) + 🌐 подтверждено в браузере

**Файлы:**
- `src/app/Http/Middleware/ResolveVisibility.php:30-41` (штампует атрибут, никто не читает)
- `src/app/Domain/Crm/Services/CompanyService.php:58-181` (list: нет параметра scope/user, нет visibility-фильтра; единственный owner-фильтр — opt-in `only_mine` на `:146-148`)
- `src/app/Domain/Crm/Services/CompanyExportService.php:45-66` (`buildXlsx`: пустые ids → `when($companyIds !== [], ...)` → вся таблица)
- `src/app/Http/Controllers/Crm/CompanyBulkController.php:66-81` (export: `company_ids` по умолчанию `[]`)
- `src/app/Http/Controllers/Crm/CompanyController.php:37-46` (index: только инъекция `_auth_user_id`, не читает `visibility_scope`)
- `src/app/Domain/Crm/Policies/CompanyPolicy.php:24-27` (`viewAny → true`)
- (эталон-паттерн, которого нет у компаний) `src/app/Domain/Sales/Services/DealService.php:167,1423-1434` (`scopedQuery()`)

**Что происходит (evidence):** `ResolveVisibility` — M0-каркас: его собственный docblock гласит «Query filtering by this scope arrives in M1», он лишь штампует request-атрибут `visibility_scope`. `CompanyPolicy.viewAny` возвращает `true`, а `CompanyController.index` всё равно не вызывает `authorize('viewAny')`. На модели `Company` нет global scope. `CompanyService.list` не принимает ни `VisibilityScope`, ни `User`, применяя только opt-in `only_mine`. Экспорт: `CompanyExportService.buildXlsx` использует `when($companyIds !== [], whereIn)`, поэтому `[] == вся таблица`. **LIVE:** manager1 (`user id=4`, role=manager, owns 0, responsible-for 2) `GET /api/companies?per_page=100` → `meta.total=13`, returned=13, distinct owners=[1] — видит все 13 (даже больше owner-OR-responsible). По коду `POST /api/companies/export {}` → HTTP 200, XLSX 6980 байт всей таблицы. Браузерный прогон (live-QA, A.3/A.3b) подтвердил: manager видит 13 компаний с именами, ИНН, owner-связями.

**Repro:** залогиниться `manager1@mgcrm.test` (owns 0). `GET /api/companies` возвращает все 13 чужих компаний; `POST /api/companies/export {}` скачивает полный XLSX.

**Предлагаемый фикс:** добавить переиспользуемый visible-companies-скоуп (admin/director — все; manager — `owner_user_id` OR `responsible_user_id` OR поддерево отдела через `VisibilityResolver.departmentSubtreeIds`), применить в `CompanyService.list` и `CompanyExportService.buildXlsx` по образцу `DealService.scopedQuery()`. Запретить «пустые ids = всё» в экспорте. Добавить Feature-тесты на оба пути.

---

### BLOCKER #2 — Утёкший список кликабелен → manager попадает на 403-тупик CompanyPage

**Severity:** blocker · **Тип:** SECURITY · **Проверка:** ✅ подтверждено live + 🌐 в браузере

**Файлы:**
- `front/src/pages/ContactsPage/index.vue:130` (`@row-click=onRowClick`), `:166-172` (RouterLink `:to=/companies/:id` для каждой строки)
- `front/src/pages/ContactsPage/composables/useContactsPageData.ts:234`
- `front/src/api/crm/companies.ts:90`
- `front/src/pages/CompanyPage/composables/useCompanyPageData.ts:24-30` (`loadCompany → companiesApi.get → 403 → companyError → error state`)
- (корень) `src/app/Domain/Crm/Services/CompanyService.php:58-181`, `src/app/Domain/Crm/Policies/CompanyPolicy.php:61-70` (item view закрыт → отсюда 403)

**Что происходит (evidence):** `GET /api/companies` возвращает manager1 все 13 (включая чужие, с утечкой `tax_id`, напр. id=3 `tax_id=123456789012`). Таблица рендерит каждую строку как RouterLink на `/companies/:id`. При этом `GET /api/companies/{id}` для того же manager — 403 (live: `/api/companies/9` → 403, `/api/companies/13` → 403). Клик по любой утёкшей строке открывает CompanyPage, который показывает `company.page.errors.load`. Итог: info-disclosure (имена/owner/tax_id/category) + сломанная навигация — каждая строка ведёт в 403-тупик. (Примечание: в браузерном прогоне A.3b card открылась полностью — это сцена, когда роль-фактически видит карточку; для НЕ-авторизованной по записи роли 403-тупик подтверждён статически и live-probe.)

**Repro:** залогиниться manager1, открыть `/contacts` (Компании). Таблица показывает 13 чужих. Клик по любой → error-state CompanyPage (`GET /api/companies/:id` 403).

**Предлагаемый фикс:** первичное лечение — BE-скоуп списка (Blocker #1). FE: не рендерить RouterLink для неоткрываемых строк / обрабатывать 403 списка как authz-ошибку. Это симптом того же корня, что и Blocker #1.

---

### BLOCKER #3 — Merge компаний сирот­ит deals, documents, requisites, channels, status-log и subsidiaries

**Severity:** blocker · **Тип:** DATA-INCONSISTENCY · **Проверка:** ✅ подтверждено (live data + статика)

**Файлы:**
- `src/app/Domain/Crm/Services/DedupService.php:517-530` (`mergeCompany` переносит только `crm_contact_company_links`, затем `Company::delete()`)
- `src/app/Domain/Crm/Services/DedupService.php:81-86` (docblock: прочие связи — заглушки, отложены до S1.3+)
- `src/app/Domain/Crm/Services/DedupService.php:89-113` (`merge()` обрабатывает лишь ContactCompanyLink + DismissedDuplicate)
- `src/app/Domain/Crm/Models/Company.php:29` (`SoftDeletes`)
- `src/app/Http/Controllers/Crm/DedupController.php:88-108` (линейный вызов, без событий/FK-миграции)
- `schema.sql:7010,7026,7042,7138,7162,7346,7546` (FK-действия срабатывают только на hard delete)

**Что происходит (evidence):** `mergeCompany` переносит на master только `crm_contact_company_links`, затем soft-delete дубля. Так как `Company` использует `SoftDeletes` (model:29), DB-уровневые FK-действия (`CASCADE`/`RESTRICT`/`SET NULL`) **не срабатывают** — строка-дубль физически остаётся с `deleted_at`, и `deals.company_id`, `company_requisites/channels/client_status_log.company_id`, `documents.source_company_id`, `crm_companies.holding_id` и `acquisition_channel_history(entity_type='company', entity_id)` продолжают указывать на скрытую компанию. Живые данные подтверждают масштаб: **9 компаний имеют сделки** (напр. company 1 — 4 сделки), 16 реквизитов, 2 subsidiary ссылаются на холдинг, 8 документов. Ни observer'а, ни model deleting-hook, ни cascade-трейта, ни merge-события для митигации нет; docblock сервиса сам признаёт отсрочку.

**Repro:** смержить две дублирующиеся компании, где у дубля есть сделка/документ/реквизит/subsidiary; дубль soft-delete'ится, а его строки остаются указывать на удалённый id — сирот­еют.

**Предлагаемый фикс:** в `mergeCompany`, внутри транзакции, перенести на master ВСЕ company-FK таблицы и `holding_id` дочек до soft-delete; cross-domain FK (deals, documents) делегировать их доменным сервисам по согласованному merge-контракту. Добавить Feature-тест на сценарий с дочерними строками.

---

### MAJOR #1 — Слой валидации кастом-полей мёртв на company-пути; `extra_fields` — free-form mass-assignment

**Severity:** major · **Тип:** DEAD-CODE · **Проверка:** ⚠️ частично (уже, чем заявлено в Phase-1)

**Файлы:**
- `src/app/Domain/Crm/Services/CustomFieldService.php:60`
- `src/app/Http/Requests/Crm/UpdateCompanyRequest.php:61`, `src/app/Http/Requests/Crm/StoreCompanyRequest.php:58`
- `src/app/Domain/Crm/Models/Company.php:76` (`extra_fields` в `$fillable`, cast `array` на `:93`)

**Что происходит (evidence):** `extra_fields` валидируется только как `['nullable','array']` и находится в `$fillable`, т.е. mass-assign'ится сырым. `CompanyService` не импортирует/не инъектит `CustomFieldService` и не ссылается на `writeFields`/`extra_fields`. `custom_field_defs = 0`. Так что **company-путь** хранит `extra_fields` дословно, без валидации/коэрса по defs. **Уточнение верификации:** заявление Phase-1 «writeFields никем не вызывается» ОПРОВЕРГНУТО — `CustomFieldService.writeFields` вызывается из `DealService.php:969` и `SetFieldAction.php:67` (`writeField`). Сервис живой для Deal + automation; мёртв ТОЛЬКО для Company (и, по той же проводке, Contact create/update). Severity оставлен major: company-side пробел реален, пустой defs лишь маскирует эффект сегодня.

**Repro:** `PATCH` компании с `extra_fields` с произвольными ключами и не-числовым значением для number-поля — сохраняется как есть.

**Предлагаемый фикс:** подключить `CustomFieldService.writeFields` в `CompanyService.create/update` с валидацией/коэрсом по активным defs И добавить FE admin-UI для создания defs (`POST /api/crm/custom-fields` без вызывающего); либо задокументировать `extra_fields` как free-form и удалить мёртвый company-путь. Добавить тест.

---

### MAJOR #2 — Спека + docblock `CompanyPolicy` утверждают фильтрацию списка по видимости, которой нет

**Severity:** major · **Тип:** SPEC-DRIFT · **Проверка:** ✅ подтверждено live

**Файлы:**
- `src/app/Domain/Crm/Policies/CompanyPolicy.php:14-27` (docblock: «we gate visibility through ensure_object_visible scope (ResolveVisibility middleware)»; inline `:26` «All authenticated users can list (filtered by visibility scope)»)
- `src/app/Domain/Crm/Services/CompanyService.php:58-181` (скоупа нет)
- `src/app/Domain/Iam/Services/VisibilityResolver.php:11-19` (docblock сам признаёт «M0 scaffold ... actual query filtering arrives in M1»)

**Что происходит (evidence):** docblock Policy и vault-спека оба утверждают наличие row-level фильтрации списка. Её нет: `ResolveVisibility` штампует атрибут, который никто не читает, global scope отсутствует, `CompanyService.list` атрибут игнорирует. LIVE (read-only GET как manager1, owns 0): `GET /api/companies` → returned=13, total=13, owners=[1]. Ложная документация активно **маскирует** блокер утечки списка/экспорта.

**Repro:** прочитать заголовок `CompanyPolicy` + vault-спеку, затем live наблюдать, что manager видит все компании.

**Предлагаемый фикс:** реализовать скоуп (предпочтительно) либо исправить docblock и vault-спеку, явно зафиксировав отсутствие row-level фильтрации списка.

---

### MAJOR #3 — Dedup scan грузит почти-полные таблицы в память

**Severity:** major · **Тип:** PERF · **Проверка:** ✅ подтверждено (статика)

**Файлы:**
- `src/app/Domain/Crm/Services/DedupService.php:241-263` (per-entity: `orWhereNotNull('phone')` + `->get()` + PHP post-filter `normalizePhone` на `:265-289`)
- `src/app/Domain/Crm/Services/DedupService.php:356-409` (`scanAllCompanies`: `$base->get()` грузит всю scoped-таблицу, группирует в PHP)

**Что происходит (evidence):** per-entity scan расширяет запрос через `orWhereNotNull('phone')` (матчит ВСЕ строки с телефоном, не только тот же номер) и `->get()` материализует их, далее PHP-фильтр по нормализованному телефону — загрузка каждой phone-bearing (+email/tax_id/name) строки на каждый вызов. Global scan грузит всю scoped-таблицу и группирует в PHP. O(n) память, без SQL-side нормализованного равенства/индекса. Живых строк сегодня мало (13), но форма кода неограничена; документированный AMO-импорт существенно нарастит объём.

**Repro:** запустить per-entity scan для компании с телефоном на большом датасете — грузит все phone-bearing строки.

**Предлагаемый фикс:** добавить нормализованную индексированную колонку телефона, заполняемую на записи, для индексного равенства; глобальный scan группировать SQL-ом по индексированным нормализованным колонкам.

---

### Minor / Trivial (не верифицировано — Phase-1, кроме помеченных)

- **minor · SECURITY · ⚠️ частично (понижено с major):** Двойной источник роли — `CompanyPolicy.canAccess/delete` и CRM-gates читают `$user->role` (колонка), `VisibilityResolver.resolve` предпочитает spatie (`getRoleNames()->first()`). Нарушает single-spatie-source из ARCHITECTURE.md. **НО:** единственный write-путь роли — `UserService.create` (`:41-56`) — пишет колонку и `syncRoles()` атомарно из одного `$role`; user-update/role-change метода нет, observer'а нет → in-app дивергенция недостижима, эксплойт требует прямой правки БД. Понижено blocker-adjacent major → minor (convention/maintainability). Файлы: `CompanyPolicy.php:47-69`, `AppServiceProvider.php:243-260`, `VisibilityResolver.php:29`.
- **minor · DEAD-CODE · ✅ подтверждено (понижено с major):** `reconnect()` — мёртвый FE (определён `companies.ts:293-298`, ноль вызовов; в `CompanyPage/index.vue:802` лишь комментарий-заголовок), нет кнопки на `ClientStatusBadge.vue` → отключённую компанию нельзя реактивировать из SPA. BE `reconnect` (`CompanyService.php:437-457`) неидемпотентен — пишет active→active log без guard. Не data-loss/auth-дефект → minor.
- **minor · BUG · не верифицировано (Phase-1):** Первый/единственный реквизит через API создаётся `is_current=false` (`CompanyRequisiteService.php:77-82`); зеркало в `crm_companies` отрабатывает только на `setCurrent()`/update-while-current. → `currentRequisite` null, денорм `legal_name`/`tax_id` не заполняются до ручного set-current. Фикс: авто-set-current для первого реквизита компании.
- **minor · CONVENTION · не верифицировано (Phase-1):** `CompanyController.update` (`:97-102`) без in-controller `authorize` (show/destroy имеют), опирается на `UpdateCompanyRequest.authorize`. Фикс: добавить `$this->authorize('update', $company)`.
- **minor · DEAD-CODE · не верифицировано (Phase-1):** PATCH-эндпоинт канала мёртв — `companiesApi.updateChannel` определён, меню канала предлагает лишь Delete; `company_channels = 0`. Фикс: edit-action либо удалить эндпоинт+метод.
- **minor · PERF · не верифицировано (Phase-1):** Нет btree-индексов на `owner_user_id`, `responsible_user_id`, `holding_id` (есть btree на name/tax_id/source/company_type_id/category_code/country_code/email/last_activity_at). FK в PG не создают индекс на ссылающейся колонке. После фикса скоупа списка — горячая точка. Фикс: миграция с индексами (вместе с visibility-фиксом).
- **minor · SECURITY · не верифицировано (Phase-1) / 🌐 NEW-5:** index/show admin-справочников читает любой авторизованный (`Crm/Admin/*` не вызывают `authorize` на index/show); FE-роутер закрывает страницы, но API открыт. Файлы: `CountryController.php:31-45`, `CompanyTypeController.php:18-34`, `DisconnectReasonController.php:23-45`.
- **trivial · DEAD-CODE · не верифицировано (Phase-1):** `companiesApi.generateTerminationDocument` (`POST .../termination-documents/generate`) и `companiesApi.getChannelHistory` (`GET .../channel-history`) определены, но не вызываются; drawer использует общий documents API. Фикс: удалить мёртвые методы+эндпоинты либо завести drawer на выделенный эндпоинт.

### Релевантные NEW-* из live-QA

- **🌐 NEW-1 (P1, BUG) — компонент `CompanyChannelsBlock` не резолвится.** Vue warn x3 `Failed to resolve component: CompanyChannelsBlock` при загрузке страницы компании — компонент используется в шаблоне, но не импортирован/не зарегистрирован в родителе. Файл: искать `CompanyChannelsBlock` в `front/src/pages/CompanyPage/`. Влияние: блок каналов компании не рендерится (что согласуется с `company_channels = 0` и отсутствием edit-UI канала). **Рекомендация:** добавить import/registration; вместе с фиксом мёртвого PATCH-канала.
- **🌐 NEW-5 (P1, SECURITY) — `/api/admin/*` доступны manager-роли.** manager1 успешно GET-ит `/api/admin/{company-types,sources,countries,cities,contact-positions,acquisition-channels,disconnect-reasons}` — все 200. Это та же дыра, что minor «index/show admin-справочников открыт», но live-QA подняла приоритет до P1 как бизнес-разведка (каналы привлечения, причины расторжения). **Рекомендация:** решить продуктово — справочники питают фильтры списка (вероятно read-any намеренно), но `acquisition-channels`/`disconnect-reasons` чувствительнее; при необходимости добавить read-gate.
- **🌐 NEW-7 (P2, BUG) — KPI «Всего: 2» против 13 строк.** На вкладке Компании KPI-бар показывает «Всего: 2», а таблица — 13 строк. KPI-эндпоинт (`/api/contacts/kpi`) и list-эндпоинт используют разный скоупинг (KPI, похоже, фильтрует по owner, список — нет). Симптом того же корня, что Blocker #1: после починки скоупа списка число строк сойдётся с KPI. **Рекомендация:** унифицировать скоуп KPI и списка.

## 7. Расхождения со спекой (vault) и предложения по актуализации

Целевой документ: `2. Модули/Crm — CONTACTS 2.0 (Контакты, Компании, дедуп).md`.

1. **Policies > CompanyPolicy (viewAny, ~строка 178) и §Безопасность (~строка 349).** Спека: «viewAny | true (все authenticated, фильтрация по visibility scope)» и «фильтрация видимости через visibility scope middleware». **Реальность:** row-level фильтрации списка/экспорта НЕТ; `ResolveVisibility` — M0-каркас, штампующий неиспользуемый атрибут; `CompanyService.list` и `CompanyExportService` не применяют owner/department-скоуп. Live: manager1 (owns 0) видит и экспортирует все 13. **Правка:** пометить фильтрацию по видимости для LIST и EXPORT как НЕ РЕАЛИЗОВАНО (открытый блокер); либо специфицировать manager-скоуп (owner OR responsible OR поддерево отдела по образцу `DealService`) как to-build, либо явно зафиксировать, что список сейчас не скоуплен.

2. **DedupService > Merge-логика (~строки 162-166).** Спека: «Company merge: аналогично pivot; другие FK (deals, tasks, activities) — перенос в S1.3+ каждым доменным сервисом». **Реальность:** `mergeCompany` по-прежнему переносит только `crm_contact_company_links` и soft-delete'ит дубль, сирот­я `deals.company_id`, `documents.source_company_id`, `company_requisites`, `company_channels`, `company_client_status_log`, `acquisition_channel_history`, `holding_id`. Отложенный cross-FK transfer не построен. **Правка:** поднять отложенную заметку до открытого БЛОКЕРА: перечислить все company-FK таблицы под перенос (deals, documents, requisites, channels, status-log, acquisition-history, `holding_id` дочек) и пометить merge как data-lossy до завершения.

3. **CustomFieldDef / CustomFieldService (~строки 85-90, 129-132).** Спека: значения кастом-полей в `extra_fields[code]`; `CustomFieldService` валидирует/коэрсит значения по активным defs. **Реальность:** на company-пути `writeFields`/`coerce` не вызываются, `extra_fields` — nullable free-form mass-assignment, `custom_field_defs = 0`, нет FE admin-UI для создания defs. (Для Deal/automation сервис ЖИВОЙ.) **Правка:** пометить VALUE-write/валидацию кастом-полей и def-admin UI как НЕ ПОДКЛЮЧЕНО на company/contact-путях; либо специфицировать подключение `writeFields` в `CompanyService.create/update` + экран def-admin, либо задокументировать `extra_fields` как free-form.

4. **Client lifecycle / reconnect (Волна 2).** Спека: lifecycle включает reconnect (active если был `unique_client_since`, иначе prospect). **Реальность:** reconnect без FE-UI (`companiesApi.reconnect` не вызывается, нет кнопки), BE reconnect неидемпотентен (пишет active→active). **Правка:** либо добавить reconnect в FE-бэклог с требованием идемпотентности, либо пометить reconnect как out-of-scope и удалить эндпоинт.

5. **Follow-ups / backlog (B-C3 dedup adminScan).** Спека: «B-C3 (низкий): `DedupController.scanAll` — добавить adminScan policy gate (сейчас любой auth = OK)». **Реальность:** global dedup scan теперь закрыт gate `dedup-scan-all` (`AppServiceProvider:251-255`, admin/director) — B-C3 фактически решён, но gate читает колонку `users.role`. **Правка:** пометить B-C3 как done; добавить заметку, что gate читает `users.role` и должен мигрировать на единый spatie-источник.

6. **(новое из live-QA)** Добавить в раздел «Известные баги / follow-ups»: NEW-1 (`CompanyChannelsBlock` не резолвится на CompanyPage), NEW-5 (`/api/admin/*` читаемы manager — поднять приоритет read-gate для `acquisition-channels`/`disconnect-reasons`), NEW-7 (KPI-бар vs список — разный скоуп, сойдётся после фикса Blocker #1).
