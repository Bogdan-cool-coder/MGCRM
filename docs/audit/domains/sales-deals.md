# Аудит домена: Продажи — Сделки и Kanban (воронки, стадии, скидки, won-gate, аудит)

> Аудит: 2026-06-24 · ключ `sales-deals` · backend `src/app/Domain/Sales` · frontend `front/src/pages/DealsPage`, `front/src/pages/DealPage`, `front/src/pages/PipelineSettingsPage`
> Live-данные (rowcounts): `pipelines=7`, `pipeline_stages=82`, `deals=13`, `deal_products=4`, `deal_contacts=3`, `deal_stage_history=1`, `deal_audits=0`, `lost_reasons=5`, `crm_saved_views=2`, `pipeline_automations=0`.

## 1. Назначение

Домен — ядро воронки продаж MGCRM по модели **DEALS 2.0 / Deal-on-Company**: отдельной сущности Lead нет, лид = сделка в первой стадии, воронка строится вокруг компании (`deals.company_id NOT NULL`). Домен ведёт настраиваемые воронки (`Pipeline`) с 11 фиксированными AmoCRM-подобными стадиями (`PipelineStage`), сами сделки (`Deal`) с позициями-снапшотами цены (`DealProduct`), M2M-контактами (`DealContact`), статус-машиной переходов через `DealMoveService` (транзакция + row-lock + идемпотентность + lost-gate 422 + required-fields gate 422 + жёсткий won-gate 409), журналами (`DealStageHistory`, `DealAudit`), справочником причин проигрыша (`LostReason`), Kanban-доской и list-видом с серверной фильтрацией/сортировкой/KPI, bulk-операциями, XLSX-экспортом и canvas graph_layout (Phase 2).

**Зрелость: частично-зрелый (наиболее зрелый домен проекта, но с одним финансовым blocker'ом).** Архитектурно это образцовая реализация ARCHITECTURE.md: FormRequest → тонкий Controller → Domain Service → Model → API Resource, деньги в копейках (bigint), visibility-scoped policy, переходы стадий только через `DealMoveService` (настоящая граница безопасности). Модели чисто сходятся с live-схемой, паритет с vault высокий. Однако зрелость снижают: (1) **blocker финансовой корректности** — `discount_percent` на уровне сделки игнорируется в `deals.amount`, поэтому все денежные агрегаты завышены для сделок со скидкой (live: 2 из 13 сделок имеют скидку); (2) мёртвая access-control-конфигурация (видимость воронок/стадий хранится, но нигде не применяется); (3) практически пустые журналы (`deal_audits=0`, `deal_stage_history=1` на 13 сделок) — таймлайн в проде почти не отработан; (4) пакет FE-багов фильтрации (мёртвые фильтры owner/tags, бюджет 100×, кросс-валютный итог). Live-QA подтвердил, что доска и карточка сделки в проде открываются и работают (journey A.1/A.2 = PASS).

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI + endpoint) | Как (кратко) | Статус | Примечание |
|---|---|---|---|---|---|
| Настройка воронки и стадий (CRUD/reorder/duplicate/delete) | admin, director | PipelineSettingsPage → `/api/pipelines[/{id}]`, `/stages[/{id}]`, `/stages/reorder`, `/duplicate` | Создание воронки auto-seed 3 системных стадий; `is_won/is_lost` запрещены на create/update; reorder нормализует sort 1..N; duplicate клонирует стадии; delete блокирует (409 при сделках / 422 последней sales-воронки) | ✅ работает | Соответствует vault S1.5 + Wave-2 duplicate |
| Persist graph_layout (canvas, Phase 2) | admin, director | PipelineSettingsPage canvas → `PATCH /api/pipelines/{id}` `{graph_layout}` | Косметический `{nodes:{...:{x,y}}}` через fillable; soft-валидация; null = не размещено | ✅ работает | Колонка `pipelines.graph_layout json` есть live; FE-обвязка canvas статически не проверена глубоко |
| Создание сделки (Deal-on-Company) | любой аутентифицированный | DealCreateDrawer → `POST /api/deals` | `company_id` обязателен; stage = первая стадия воронки; department штампуется от owner (NULL, если у owner нет department — все 13 live-сделок NULL); amount=0/из позиций | ✅ работает | Сущности Lead нет by design |
| Просмотр сделок — Kanban (rotting/health/мультивалюта) | любой (row-scoped) | DealsPage board → `GET /api/deals?view=board` + `/kpi` | По колонкам (limit 30), `next_task`/`primary_product` батч (ROW_NUMBER), `days_in_stage`, `amounts_by_currency` (GROUP BY), `sum_amount` в базовой валюте через ExchangeRate с `multi_currency_warning` | 🟡 частично | `sum_amount`/`amounts_by_currency` завышены для сделок со скидкой (gross); load-more теряет `next_task`/`days_in_stage`/`primary_product`; toolbar-итог суммирует валюты как ₽ |
| Просмотр сделок — List (серверная сортировка/фильтр/KPI) | любой (row-scoped) | DealsPage list → `GET /api/deals?view=list` + `/kpi` | Пагинация, whitelist сортировки, 11-мерный `applyFilters`; visibility-scope ДО фильтров | 🟡 частично | Фильтры owner & tags мёртвы в UI (пустые опции); `budget_from/to` шлётся рублями против копеечной колонки (100×); колонка amount = gross |
| Фильтр и пресеты воронки | любой | DealsFilterOverlay → re-trigger `/api/deals` + `/api/deals/kpi` | Пресеты open/mine/won/lost/noTask/overdue; multi-dim фильтры; reset | 🟡 частично | `only_mine` — единственный рабочий фильтр по owner; MultiSelect owner и список Tags без опций; bug бюджета |
| Перенос сделки между стадиями (статус-машина) | любой с move-правом | Board drag&drop / MoveDealDialog → `POST /api/deals/{id}/move` | `DealMoveService`: транзакция + row-lock + идемпотентность (Redis 24h); lost-gate (422, нужна причина); required-fields gate (422); won-gate (HARD 409 при `won_gate + won_gate_contract_required` без живого контракта); пишет `DealStageHistory`, штампует `stage_changed_at`/`closed_at`/`max_stage_id` | ✅ работает | Настоящая граница безопасности. Жёсткий won-gate 409 реализован (S2.8) — vault DEALS 2.0 line 35 ещё описывает его как «мягкий» (SPEC-DRIFT). `deal_stage_history=1` строка на 13 сделок — фича слабо отработана |
| Редактирование полей сделки (inline + DealPage) | любой с update-правом | DealInfoPanel / заголовок карточки → `PATCH /api/deals/{id}` | FormRequest валидирует; `stage_id` запрещён; `discount_percent` clamp [0,50]; смена company пере-пинит requisite/department; `extra_fields` через CustomFieldService; дифф `AUDITED_FIELDS` → `deal_audits` + entity log | 🟡 частично | Смена `discount_percent` НЕ запускает `recalcAmount` (amount остаётся gross); whitelist аудита узкий; `deal_audits` пуст |
| Позиции сделки (продукты) snapshot-цена + скидка | любой с update-правом | DealProductsGroup → `/api/deals/{id}/products[/{pid}]` | `addProduct` снапшотит цену (`ProductService::getPriceSnapshot`), дедупит по `product_id`, per-line абсолютная скидка; `amount=max(0, round(qty*price)-discount)`; всегда `recalcAmount` (кроме `amount_locked`) | ✅ работает | Per-line скидка работает; deal-level `discount_percent` НЕ сворачивается в `deals.amount` (display-only) |
| Контакты сделки (M2M + кросс-домен) | любой с update-правом | DealContacts group → `/api/deals/{id}/contacts[/{cid}]` | `addContact` пивотит deal↔contact (single-primary invariant) + `ContactService::linkCompany`; removeContact | ✅ работает | 3 live-строки |
| Feed / history / log таймлайн | любой с view-правом | DealPage tabs → `/feed`, `/history`, `/log` | Feed мёржит stage_history + activities + deal_audits (field_change); history читает `deal_stage_history`; log читает `entity_logs` | 🟡 частично | Ветка `field_change` никогда не дала событие (`deal_audits=0`); `stage_history=1`; таймлайн в live почти не отработан |
| Отметка KP/контракт отправлен; архив/разархив; фикс оплаты | любой с update-правом | DealPage key actions → `/kp-sent`, `/contract-sent`, `/archive`, `/unarchive`, `PATCH` | Штампует `kp_sent_at`/`contract_sent_at`; toggle `archived_at`; ставит `paid_at`/`paid_amount`/`payment_currency` | ✅ работает | `paid_*`/даты не аудируются (см. процесс редактирования) |
| Bulk-операции | любой (all-or-nothing authz) | DealsBulkToolbar → `PATCH/DELETE /api/deals/bulk`, `POST /api/activities/bulk` | change_owner/change_stage/set_field/edit_tags/add-task/delete; каждая сделка авторизуется под update/delete (403 если хоть одна не прошла) | ✅ работает | Надёжная all-or-nothing авторизация |
| Экспорт сделок XLSX | любой (row-scoped) | DealsToolbar → `GET /api/deals/export` | PhpSpreadsheet; колонки amount (копейки) + amount/100 + currency + status + tags + даты | 🟡 частично | Экспортирует GROSS `deals.amount` — завышает выручку для сделок со скидкой (`DealExportService:93-94`) |
| Справочник причин проигрыша | read: любой; write: admin/director | `/api/lost-reasons`; MoveDealDialog читает при lost-gate | CRUD-справочник; структурный FK `deal.lost_reason_id` + free-text `deal.lost_reason` | ✅ работает | 5 live-строк |
| Сохранённые именованные виды (saved views) | любой | intended DealsPage → `/api/crm/saved-views` | INTENDED серверные пресеты по сущности | ⚪ отсутствует | `SavedViewEntity` без case `Deal`; доска юзает только localStorage toggle kanban/list; фильтры сбрасываются при reload |
| Гейтинг видимости воронок/стадий | admin/director (config) | intended PipelineSettingsPage + row scope | INTENDED ограничить воронку/стадию по ролям/департаментам/юзерам | 🔴 сломан | Колонки хранятся/cast/surfaced, но нигде не применяются; pipeline-level недостижимы на запись; stage-level пишутся через API, но нет FE-редактора и enforcement |
| KPI-чипы сделок | любой (row-scoped) | DealsKpiChips → `GET /api/deals/kpi` | scoped `kpiBaseQuery` отдаёт счётчики/суммы воронки | 🟡 частично | KPI-суммы используют gross amount; чипы page/scope-local (полный серверный full-funnel агрегат ещё в backlog B-S1) |

Сводка статусов: ✅ работает — 8, 🟡 частично — 6, 🔴 сломан — 1, ⚪ отсутствует — 1.

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк live | Статус |
|---|---|---|---|---|
| `Pipeline` | `pipelines` | Контейнер воронки (kind=sales); settings, graph_layout (Phase 2), неиспользуемый visibility-config | 7 | built |
| `PipelineStage` | `pipeline_stages` | Стадия/колонка: won/lost-флаги, rotting (warn/danger_days), won-gate (+ `won_gate_contract_required`), required_fields, task_types, SLA, (неприменяемая) видимость | 82 | built |
| `Deal` | `deals` | Master Deal-on-Company. Нет колонки status (вычисляется из флагов стадии). Soft-delete + archive | 13 | built |
| `DealProduct` | `deal_products` | Позиция: snapshot-цена + per-line абсолютная скидка (копейки). `amount=max(0,round(qty*unit_price)-discount)`. Deal-level `discount_percent` НЕ сворачивается сюда | 4 | built |
| `DealContact` | `deal_contacts` | M2M deal↔contact (single-primary invariant: partial unique WHERE is_primary) | 3 | built |
| `DealStageHistory` | `deal_stage_history` | Append-only лог переходов стадий (строка создания `from_stage_id=null`) | 1 | partial |
| `DealAudit` | `deal_audits` | Append-only per-field лог изменений (feed type=field_change). Пуст; узкий whitelist | 0 | partial |
| `LostReason` | `lost_reasons` | Справочник причин проигрыша (FK `deal.lost_reason_id` + free-text) | 5 | built |
| `SavedView (Crm)` | `crm_saved_views` | Серверные пресеты list-видов. `entity_type` enum (`SavedViewEntity`) поддерживает только contact/company — НЕ deal | 2 | partial |

**Расхождения migration ↔ live-schema ↔ model:**

- **`deals.discount_percent`** — live-схема имеет `smallint DEFAULT 0 NOT NULL` (migration `2026_06_29`), model fillable/cast есть. **Семантический дрейф:** применяется только в `DealResource` (display); канонический `deals.amount` остаётся gross, `recalcAmount` его игнорирует и не пере-запускается на смену скидки → это и есть blocker #B0.
- **`deals.department_id`** — колонка nullable, **ВСЕ 13 live-сделок имеют `department_id=NULL`** (проверено). Department-scope читает `department_id`, но ни одна роль не маппится в Department → dormant. Backfill не запускался. Дыра в данных: Department-scope вернул бы 0 строк; latent policy↔query расхождение.
- **`deal_audits`** — таблица построена, **0 строк**. `AUDITED_FIELDS` узкий (`[title,amount,currency,owner_user_id,tags]`); `amount` недостижим через PATCH (derived). Большинство редактируемых полей не аудируются → built-but-unexercised, ветка field_change никогда не дала событие.
- **`deal_stage_history`** — построена, **1 строка на 13 сделок**. Либо сделки почти не двигали после создания, либо строки создания не пишутся; metric stage-changes (`DealService:1316`) считает переходы кроме null-from строки. Вместе с `deal_audits=0` таймлайн в live почти не протестирован.
- **`crm_saved_views.entity_type`** — колонка `varchar(255)`, **без DB-level CHECK**. `SavedViewEntity` enum ограничивает кодом до contact|company; case Deal нет. БД физически приняла бы 'deal', но приложение отклонит.
- **`pipelines.graph_layout`** — `json`, есть live (Phase 2 отгружен). Fillable + cast + reused `PATCH /api/pipelines/{id}`; UpdatePipelineRequest валидирует soft. Консистентно end-to-end.
- **`pipelines.visible_role / visible_user_ids`** — колонки есть. Fillable+cast+resource, но НЕ в `StorePipelineRequest`/`UpdatePipelineRequest` rules → недостижимы на запись через API; нигде не применяются → **dead config (write-unreachable + unenforced)**.
- **`pipeline_stages.visible_department_ids / visible_user_ids`** — колонки есть. Fillable+cast+resource И приняты `Store/UpdateStageRequest` (пишутся через API), но нет FE-редактора и нигде не применяются → **dead config (writable but unenforced + no UI)**.

**Пустые-при-наличии-кода таблицы:** `deal_audits` (0/13), `deal_stage_history` (1/13), `pipeline_automations` (0) — построены, но в проде почти/совсем не наполнены.

## 4. Эндпоинты и покрытие фронтом

| Метод + Path | Контроллер@метод | Авторизация | Вызывается фронтом? | Примечание |
|---|---|---|---|---|
| `GET /api/deals` | `Sales\DealController@index` | `IndexDealRequest→DealPolicy@viewAny` (always true); строки фильтруются в `DealService::scopedQuery` | да — `useDealsList`, load-more | viewAny всегда true; реальная защита — row-scoping |
| `GET /api/deals?view=board` | `DealController@index→board` | как index; per-column scoped query | да — `useDealsBoard` | `sum_amount`/`amounts_by_currency` gross |
| `POST /api/deals` | `DealController@store` | `StoreDealRequest→DealPolicy@create` (always true) | да — DealCreateDrawer | create открыт любому аутентифицированному |
| `GET /api/deals/export` | `DealController@export` | `IndexDealRequest` (viewAny); scoped | да — DealsToolbar XLSX | экспорт gross amount |
| `GET /api/deals/kpi` | `DealKpiController@__invoke` | viewAny; scoped `kpiBaseQuery` | да — DealsKpiChips | суммы gross |
| `PATCH /api/deals/bulk` | `DealController@bulkUpdate` | `BulkDealActionRequest→viewAny`; per-deal `update` all-or-nothing 403 | да — DealsBulkToolbar | надёжно |
| `DELETE /api/deals/bulk` | `DealController@bulkDestroy` | viewAny; per-deal `delete` all-or-nothing | да — BulkDeleteDialog | надёжно |
| `GET /api/deals/{deal}` | `DealController@show` | `DealPolicy@view` (own/department/all) | да — DealPage; drop-into-lost | live-QA: 403 для чужой сделки manager1 (deal #12) корректен, но FE не ловит ошибку (NEW-2) |
| `PATCH /api/deals/{deal}` | `DealController@update` | `UpdateDealRequest→DealPolicy@update`; `stage_id` запрещён | да — DealInfoPanel, заголовок | не запускает recalc на смену discount |
| `DELETE /api/deals/{deal}` | `DealController@destroy` | `DealPolicy@delete` (soft delete, 204) | да — DealsListView, DealPage | |
| `POST /api/deals/{deal}/move` | `DealController@move` | `MoveDealRequest→DealPolicy@move`; gates внутри `DealMoveService` (lost 422 / required 422 / won 409) | да — drag&drop, MoveDealDialog | граница безопасности |
| `POST /api/deals/{deal}/kp-sent` | `DealController@markKpSent` | `DealPolicy@update` | да — DealPage | |
| `POST /api/deals/{deal}/contract-sent` | `DealController@markContractSent` | `DealPolicy@update` | да — DealPage | |
| `POST /api/deals/{deal}/archive` `unarchive` | `DealController@archive/unarchive` | `DealPolicy@update` | да — DealPage | |
| `GET/POST/PATCH/DELETE /api/deals/{deal}/products[/{id}]` | `DealProductController@*` | FormRequest→`can('update',deal)`; `assertBelongsToDeal` 404 (нет IDOR) | да — DealProductsGroup | |
| `GET/POST/PATCH/DELETE /api/deals/{deal}/contacts[/{id}]` | `DealContactController@*` | `can('view'\|'update',deal)`; belonging asserted | да — DealContacts | |
| `GET /api/deals/{deal}/history` | `DealHistoryController@index` | `DealPolicy@view`; читает `deal_stage_history` | да — DealPage history | 1 строка live |
| `GET /api/deals/{deal}/feed` | `DealFeedController@index` | `DealPolicy@view`; мёрж stage_history + activities + deal_audits | да — DealFeed | live-QA NEW-3: i18n key `dealCreatedVerb` отсутствует |
| `GET /api/deals/{deal}/log` | `Log\EntityLogController@dealLog` | deal view | да — DealPage log | |
| `GET /api/deals/{deal}/custom-fields` | `DealCustomFieldController@index` | deal view | да — DealPage extra_fields | |
| `POST /api/deals/{deal}/meeting-report` | `Activity\MeetingReportController@save` | deal update | да — DealComposer | |
| `GET/POST/PATCH/DELETE /api/pipelines[/{id}]` | `PipelineController@*` | `PipelinePolicy`: read=all; CRUD=admin\|director (users.role enum) | да — DealsPipelineMenu (read), PipelineSettingsPage (CRUD) | FE не пре-скрывает write-контролы по роли (полагается на 403) |
| `POST /api/pipelines/{id}/duplicate` | `PipelineController@duplicate` | `PipelinePolicy@create` (admin/director) | да — PipelineList duplicate | |
| `GET/POST/PATCH/DELETE /api/pipelines/{id}/stages[/{id}]` | `PipelineStageController@*` | `authorize('view'\|'update', pipeline)` — отдельной StagePolicy нет; `assertStageInPipeline` 404; `is_won/is_lost` запрещены в UpdateStageRequest | да — StageEditorList, StageEditDrawer | stage visible_* принимаются, но нет UI |
| `PATCH /api/pipelines/{id}/stages/reorder` | `PipelineStageController@reorder` | `authorize('update', pipeline)`; sort 1..N | да — vuedraggable | |
| `GET/POST/PATCH/DELETE /api/lost-reasons[/{id}]` | `LostReasonController@*` | `LostReasonPolicy`: read=all; write=admin\|director | да — MoveDealDialog (read); settings CRUD | |
| `GET /api/contacts/{contact}/deals` | `Sales\ContactDealsController@index` | contact scope; `DealService::listForContact` | да — Contact card deals tab | |
| `GET /api/companies/{company}/deals` | `Sales\CompanyDealsController@index` | company scope; `DealService::listForCompany` | да — Company card deals tab | live-QA: company KPI отдаёт gross per_currency (см. #B0) |

**Orphaned FE-вызовы:** не выявлено — все вызовы фронта имеют backend-маршрут.
**Мёртвые/недостижимые на запись endpoint'ы:** pipeline-level `visible_role`/`visible_user_ids` не объявлены в pipeline FormRequests → `validated()` их отбрасывает (write-unreachable, см. minor). `/api/crm/saved-views` существует, но для deals не вызывается и `entity_type='deal'` отклоняется enum'ом (saved views для deals отсутствуют).

## 5. RBAC домена

Авторизация в домене реализована **двумя механизмами** (как у Vizion): per-row visibility scope для сделок и role-enum-policy для конфигурации воронок.

- **Видимость строк сделок** (`DealService::scopedQuery` + `VisibilityScope::forRole`): `admin/director/lawyer = All`, `manager/accountant/cfo = Own`. **Ни одна роль не маппится в Department** (dormant). GET-by-id защищён `DealPolicy@view`. Применяется реально и в list, и в board (визибилити ДО фильтров). Здесь дыр нет — это контраст с crm-contacts/crm-companies, где live-QA подтвердил утечку (manager видит все компании/контакты).
- **Мутация сделок** (`DealPolicy view/update/delete/move` через `VisibilityResolver` own/department/all). **`create` и `viewAny` всегда true** для любого аутентифицированного — это by design (создавать сделку может каждый; защита — на row-scoping для чтения).
- **Sub-resources** (products/contacts/feed/history/custom-fields/meeting-report/kp-sent/contract-sent): все гейтятся на `update` (или `view`) родительской сделки; дочерние строки проверяют принадлежность (`assertBelongsToDeal` 404). **IDOR отсутствует.** Это надёжный участок.
- **Bulk-операции**: `BulkDealService` авторизует КАЖДУЮ выбранную сделку под update/delete all-or-nothing (403 если хоть одна не прошла). Надёжно.
- **Воронки / стадии / причины проигрыша (write)**: только admin|director через `PipelinePolicy`/`LostReasonPolicy` по `users.role` enum (НЕ spatie). Отдельной `StagePolicy` нет — операции со стадиями авторизуются против родительской воронки. FE НЕ пре-скрывает write-контролы по роли — полагается на API 403 (приемлемо, но менеджер видит кнопки, которые вернут 403).

**Где дыра:** **видимость воронок/стадий** (`visible_role`/`visible_user_ids`/`visible_department_ids`) — INTENDED row-level гейтинг воронок/стадий **НЕ применяется нигде** (мёртвая access-control-конфигурация, major #M1). Воронка, помеченная `visible_role='director'`, всё равно листается и бордится менеджером (с учётом только per-deal owner-scope). Это **ложное обещание контроля доступа**: конфиг присутствует в API/ресурсах, но не имеет эффекта.

## 6. Бэклог проблем

### Сводная таблица (FINAL severity после верификации)

| # | Severity | Тип | Заголовок | Проверка |
|---|---|---|---|---|
| B0 | 🔴 blocker | BUG | `discount_percent` игнорируется в `deals.amount` — все денежные агрегаты завышают выручку | ✅ подтверждено (live probe + static) |
| M1 | 🟠 major | DEAD-CODE | Видимость воронок/стадий хранится/surfaced, но нигде не применяется | ✅ подтверждено (static grep) |
| M2 | 🟠 major | BUG | Фильтры Owner и Tags рендерятся с пустыми списками опций — обе размерности мертвы в UI | ✅ подтверждено (static) |
| M3 | 🟠 major | BUG | Фильтр бюджета шлёт рубли как копейки — под-фильтрует в 100× | ✅ подтверждено (static) |
| M4 | 🟠 major | STUB | `deal_audits` пуст + узкий whitelist — feed field_change пропускает большинство полей | ✅ подтверждено (static + rowcount) |
| M5 | 🟠 major | BUG | Toolbar-итог воронки суммирует валюты и хардкодит ₽ / млн / тыс. | ✅ подтверждено (static) |
| m6 | 🟡 minor | BUG | DealPolicy Department-scope ↔ DealService query расхождение (dormant) | ⚠️ частично (понижено major→minor: dormant) |
| m7 | 🟡 minor | MISSING | Серверные saved views не поддерживают deals (только localStorage toggle) | не верифицировано (Phase-1) |
| m8 | 🟡 minor | DEAD-CODE | Pipeline `visible_role`/`visible_user_ids` недостижимы на запись через API | не верифицировано (Phase-1) |
| m9 | 🟡 minor | DEAD-CODE | Stage-видимость пишется через API, но нет UI в StageEditDrawer и enforcement | не верифицировано (Phase-1) |
| m10 | 🟡 minor | BUG | Kanban load-more теряет `next_task`/`days_in_stage`/`primary_product` на дозагруженных картах | не верифицировано (Phase-1) |
| m11 | 🟡 minor | DATA-INCONSISTENCY | `deal_stage_history` почти пуст (1/13) — контракт событий переходов недо-отработан | не верифицировано (Phase-1) |
| m12 | 🟡 minor | BUG | DealsListView rotting-порог хардкод 7/14 вместо stage.warn_days/danger_days | не верифицировано (Phase-1) |
| t13 | ⚪ trivial | BUG | `BoardRawColumnDto` несовпадение ключа `rate_available` ↔ `fx_rate_available` | не верифицировано (Phase-1) |
| N3 | ⚪ trivial | BUG | Отсутствует i18n-ключ `sales.deal.feed.events.dealCreatedVerb` (live-QA NEW-3) | 🌐 подтверждено в браузере |
| N2 | 🟡 minor | BUG | Необработанный 403 в mounted-хуке DealPage (live-QA NEW-2) | 🌐 подтверждено в браузере |

---

### B0 · blocker · BUG · ✅ подтверждено (live probe + static)

**Deal-level `discount_percent` игнорируется в `deals.amount` — каждый денежный агрегат (board/list/KPI/company/contact/export/FE-карты) завышает выручку.**

**Файлы:**
- `src/app/Domain/Sales/Services/DealService.php:1289-1303` (`recalcAmount` = SUM(deal_products.amount), без discount_percent)
- `src/app/Domain/Sales/Services/DealService.php:944-1000` (`update` клампит discount_percent, делает `$deal->update`, но НЕ вызывает recalcAmount на смену скидки)
- `src/app/Domain/Sales/Services/DealService.php:596,642` (board `amounts_by_currency`/`sum_amount` gross)
- `src/app/Domain/Sales/Services/DealService.php:1182,1249` (`aggregateForCompany`/`aggregateForContact` sum gross amount)
- `src/app/Domain/Sales/Services/DealExportService.php:93-94` (export `$deal->amount` gross)
- `src/app/Http/Resources/Sales/DealResource.php:19,135,171-198` (amount gross; `discountedTotals` net только на SHOW при загруженных products)
- `src/app/Domain/Sales/Services/DealProductService.php:125-129` (`netAmount` использует только per-line discount, не deal-level)
- `front/src/pages/DealsPage/components/DealsKanbanCard.vue:38`, `DealsListView.vue:86`, `front/src/api/sales.ts:74` (FE рендерит gross amount, нет net-поля)

**Что происходит (evidence):** `recalcAmount()` устанавливает `deals.amount = SUM(deal_products.amount)` (только per-line скидка), а `update()` не пере-запускает recalc при смене `discount_percent`. Deal-level `discount_percent` применяется ТОЛЬКО в `DealResource::discountedTotals()` для display-поля `products_net_total` (присутствует только на SHOW при загруженных products). Канонический `deals.amount` остаётся **GROSS**. Все агрегаты — board column `sum_amount`/`amounts_by_currency`, `aggregateForCompany/Contact` (`sum('amount')`), XLSX-экспорт (`$deal->amount`) — и весь FE (карты board/list, KPI-чипы, toolbar-итог) читают gross. **Live-проба:** `SELECT id,currency,discount_percent,amount_locked,amount FROM deals WHERE discount_percent>0` → #12 RUB pct=30 amount=6 432 000 000 коп.; #13 KZT pct=50 amount=3 000 000 000 коп. `GET /api/deals/13` → `amount=3000000000`, `products_gross_total=3000000000`, `products_net_total=1500000000` (ровно 2×). `GET /api/companies/3` → `deal_totals.per_currency.KZT=3000000000` (GROSS == 2× от net). Сделка #13 open (`is_won=f is_lost=f`) → попадает в `aggregateForCompany`. Опровержения (4 шт.) отвергнуты статикой: per-line netAmount не содержит discount_percent; `amount_locked=false` у обеих сделок (recalc-путь живой); агрегаты суммируют gross; FE net нигде не рендерит.

**Repro:** `GET /api/deals/13` → `amount=3 000 000 000` коп., но `products_net_total=1 500 000 000` коп. Открыть её board-колонку / KPI компании / экспорт / карту → везде gross. В рублёвом эквиваленте: для сделки #13 (50%) выручка показывается как 30 000 000 ₽ вместо 15 000 000 ₽; для #12 (30%) — 64 320 000 ₽ вместо ~45 024 000 ₽.

**Предлагаемый фикс:** выбрать ОДИН источник истины. Предпочтительно (соответствует vault «amount derived from line items»): свернуть discount_percent в `recalcAmount`, чтобы `deals.amount = round(SUM(line.amount) * (1 - discount_percent/100))`, и вызывать `recalcAmount` в `DealService::update()` при любой смене `discount_percent`. Тогда все агрегаты + FE-карты исправляются автоматически. Альтернатива: оставить amount gross by design, но применять discount_percent в board `sum_amount`/`amounts_by_currency`, `aggregateForCompany/Contact`, export и выставить net-поле в DTO list/board-карты. Решение требует ответа на openQuestion: amount — это GROSS (бюджет) со скидкой только в display, или NET?

---

### M1 · major · DEAD-CODE · ✅ подтверждено (static grep)

**Видимость воронок и стадий хранится/surfaced, но нигде не применяется (мёртвая access-control-конфигурация).**

**Файлы:**
- `src/app/Domain/Sales/Models/Pipeline.php:36-37`, `src/app/Domain/Sales/Models/PipelineStage.php:47-48`
- `src/app/Http/Resources/Sales/PipelineResource.php:24`, `PipelineStageResource.php:35-36`
- `src/app/Domain/Sales/Services/DealService.php:1431-1437` (`scopedQuery` ключ только на `department_id`/`owner_user_id`)
- `src/app/Domain/Sales/Policies/DealPolicy.php`

**Что происходит:** `pipelines.visible_role/visible_user_ids` и `pipeline_stages.visible_department_ids/visible_user_ids` — fillable, cast и эмитятся ресурсами, но **ни один query/scope/policy их не читает**, чтобы ограничить, кто видит воронку или стадию. Grep по PHP даёт ссылки ТОЛЬКО в fillable/casts/resources/FormRequests/duplicate-copy (`PipelineService:173/209-210` — чистый clone-copy, не enforcement) — **ноль** в любом WHERE/scope/policy. Воронка, помеченная `visible_role='director'`, всё равно листается и бордится менеджером (с учётом только per-deal owner-scope).

**Repro:** Установить `pipelines.visible_role='director'` на воронку → менеджер всё равно видит её через `GET /api/pipelines` и может листать/бордить её сделки.

**Предлагаемый фикс:** либо реализовать фильтрацию видимости воронок/стадий в `PipelineService::list`, `DealService` board/list (пересечение с ролью/департаментом/id юзера) и `PipelinePolicy@view`; либо удалить колонки + ключи ресурсов + правила FormRequest, чтобы не рекламировать несуществующий контроль доступа.

---

### M2 · major · BUG · ✅ подтверждено (static)

**Фильтры Owner и Tags рендерятся с пустыми списками опций — обе размерности мертвы в UI.**

**Файлы:** `front/src/pages/DealsPage/index.vue:45-46` (хардкод `:users="[]"` `:tags="[]"`); `front/src/pages/DealsPage/components/DealsFilterOverlay.vue:52` (owner MultiSelect `:options="users"`), `:128`/`328-331` (Tags итерирует `filteredTags`→`props.tags`).

**Что происходит:** DealsPage передаёт хардкод-пустые `:users="[]"` и `:tags="[]"` в DealsFilterOverlay. Overlay биндит owner MultiSelect к `props.users` (пусто → нет опций) и чеклист Tags к `props.tags` (пусто → только placeholder). Backend `IndexDealRequest` + `DealService::applyFilters` полностью поддерживают `owner_ids[]`/`tags[]`, композаблы их форвардят, но пользователь не может выбрать ни owner, ни tag. `only_mine` — единственный рабочий owner-related фильтр. Проверено: index.vue не грузит users/tags в onMounted и не перекрывает `[]`; overlay не имеет своего fetch.

**Repro:** Открыть filter overlay на `/deals` → «Ответственный» MultiSelect без опций; «Теги» список пуст, несмотря на существующие теги сделок.

**Предлагаемый фикс:** загрузить users (`usersApi.getUsers → {id,name}`) и distinct-набор тегов (endpoint тегов или агрегат из сделок) в `DealsPage` onMounted и передать вместо `[]`. Зеркалить DealPage, который уже вызывает `usersApi.getUsers()`.

---

### M3 · major · BUG · ✅ подтверждено (static)

**Фильтр бюджета шлёт рубли как копейки — под-фильтрует в 100×.**

**Файлы:** `front/src/pages/DealsPage/components/DealsFilterOverlay.vue:104-116` (InputNumber suffix `" ₽"` → рубли); `front/src/pages/DealsPage/composables/useDealsList.ts:66-67`, `useDealsBoard.ts:84-85` (форвардят `f.budget_from ?? undefined` без `*100`); `src/app/Http/Requests/Sales/IndexDealRequest.php:150-152` (валидирует как integer «amount range (kopecks)»); `DealService:418-423` (`where('amount','>=',(int)budget_from)` против копеечной колонки).

**Что происходит:** Overlay budget-поля — InputNumber с суффиксом « ₽» (юзер вводит рубли). Значение проходит без изменений через `DealsFilters.budget_from/to` и шлётся как есть. Backend сравнивает с `deals.amount` в КОПЕЙКАХ. `toKopecks()` нет нигде. Ввод «1 000 000 ₽» фильтрует сделки с `amount >= 1 000 000` коп. = 10 000 ₽.

**Repro:** Фильтр `budget_from = 1 000 000` (₽) → возвращает сделки ≥ 10 000 ₽ вместо ≥ 1 000 000 ₽.

**Предлагаемый фикс:** умножать на 100 перед отправкой в композаблах/`applyOverlayFilters` (`budget_from: f.budget_from != null ? f.budget_from*100 : undefined`), сохранив суффикс ₽.

---

### M4 · major · STUB · ✅ подтверждено (static + rowcount)

**`deal_audits` пуст + узкий audit-whitelist — feed field_change пропускает большинство редактируемых полей.**

**Файлы:** `src/app/Domain/Sales/Services/DealService.php:50-56` (`AUDITED_FIELDS`), `:1071-1100` (`buildAuditDiff`); `src/app/Domain/Sales/Services/DealAuditService.php:26-66`; `src/app/Domain/Sales/Services/DealFeedService.php:164-183`.

**Что происходит:** `AUDITED_FIELDS = [title, amount, currency, owner_user_id, tags]` (+extra_fields). `buildAuditDiff` итерирует только их. `UpdateDealRequest` **никогда не принимает `amount`** (derived) → `amount` в whitelist недостижим через PATCH. Редактируемые, но НЕ аудируемые: `discount_percent`, `perpetual_license`, `amount_locked`, `company_id`, `department_id`, `expected_*_date`, `signed_at`, `paid_at`, `paid_amount`, `payment_currency`. Live `deal_audits = 0` строк на 13 сделок — ветка field_change feed никогда не дала событие.

**Repro:** `PATCH /api/deals/{id} {"discount_percent":20,"signed_at":"2026-01-01"}` → нет строки `deal_audits`, ничего в `GET /api/deals/{id}/feed?types[]=field_change`.

**Предлагаемый фикс:** расширить `AUDITED_FIELDS` до пользовательских бизнес-полей (`discount_percent`, даты, `paid_*`, `perpetual_license`, `amount_locked`, `company_id`, `department_id`); убрать недостижимый `amount`. Добавить Feature-тест, что PATCH пишет `deal_audits` и feed это показывает.

---

### M5 · major · BUG · ✅ подтверждено (static)

**Toolbar-итог воронки суммирует валюты и хардкодит ₽ / млн / тыс. (i18n bypass).**

**Файлы:** `front/src/pages/DealsPage/index.vue:312-318` (`totalSumFormatted`).

**Что происходит:** `totalSumFormatted` редьюсит `sum_amount` ВСЕХ `visibleColumns` в одно копеечное число, делит на 100 и форматирует хардкод-литералами `'₽'` плюс `'млн ₽'`/`'тыс. ₽'`. Каждая колонка несёт `base_currency` + `amounts_by_currency` + `multi_currency_warning` (заголовок колонки корректно рендерит `≈` + разбивку по валютам), но toolbar всё это игнорирует и складывает KZT/USD/EUR-копейки как рубли. Для мультивалютной воронки (live-сделки включают KZT) «итог» — бессмысленное смешанное число с лейблом ₽. `млн`/`тыс.` — нелокализованные литералы. (Дополнительно: `sum_amount` сам по себе gross — пересекается с B0.)

**Repro:** На воронке с KZT + RUB сделками DealsToolbar-итог показывает, например, «30,0 млн ₽», суммируя KZT-копейки как рубли.

**Предлагаемый фикс:** подавлять единый итог, когда колонки охватывают несколько валют (показывать «—» или per-currency), либо считать per `base_currency` через `formatCurrency` + i18n-форматирование чисел. Вынести `млн`/`тыс.` в i18n.

---

### m6 · minor (понижено с major) · BUG · ⚠️ частично

**DealPolicy Department-scope разрешает own-сделки, но DealService query их исключает — policy↔query расхождение (dormant).**

**Файлы:** `src/app/Domain/Sales/Policies/DealPolicy.php:66-78`; `src/app/Domain/Sales/Services/DealService.php:1431-1437`; `src/app/Domain/Iam/Enums/VisibilityScope.php:31-37`.

**Что происходит / почему понижено:** `DealPolicy::inDepartmentSubtree()` возвращает true для own-сделки независимо от `department_id` (line 69). Но Department-ветка `scopedQuery` — голый `whereIn('department_id', subtree)` без owner-OR-ветки → own-сделка с NULL/чужим department невидима в list/board, но GET-by-id вернёт 200 → list и detail расходятся. Верификация подтвердила: расхождение реально в коде, **но полностью DORMANT** — ни одна роль не маппится в Department (`VisibilityScope::forRole`: admin/director/lawyer→All, manager/accountant/cfo→Own), и все 13 live-сделок имеют `department_id=NULL`, поэтому даже при активации query вернул бы 0 строк. Реально-но-уже: dormant-мина, не текущий дефект. **major→minor.**

**Предлагаемый фикс:** сделать Department-ветку `scopedQuery` зеркалом policy: `->where(fn($q)=>$q->where('owner_user_id',$user->id)->orWhereIn('department_id',$subtree))`. Также backfill `deals.department_id` (все NULL сегодня), иначе Department-scope бессмыслен.

---

### N2 · minor · BUG · 🌐 подтверждено в браузере (live-QA NEW-2)

**Необработанный 403 в mounted-хуке DealPage.**

**Файлы:** `front/src/pages/DealPage/index.vue:151`, `front/src/pages/DealPage/composables/useDealPage.ts` (загрузка через `useAsyncResource.ts`).

**Что происходит:** при навигации на чужую сделку (live-QA: manager1 → `GET /api/deals/12` = 403, ожидаемо) экран «Сделка не найдена» отображается корректно (FE ловит на уровне компонента), но Vue lifecycle всё равно бросает необработанное исключение: `[Vue warn]: Unhandled error during execution of mounted hook` + `AxiosError: status code 403`. Мониторинг ошибок (Sentry и т.п.) получит ложные срабатывания.

**Repro:** Залогиниться менеджером, открыть `/deals/12` (admin-owned) → экран ошибки показан, но в консоли необработанный 403.

**Предлагаемый фикс:** обернуть загрузку сделки в try/catch или обработать ошибку в `useAsyncResource.ts`, чтобы 403/404 не всплывал как unhandled.

---

### N3 · trivial · BUG · 🌐 подтверждено в браузере (live-QA NEW-3)

**Отсутствует i18n-ключ `sales.deal.feed.events.dealCreatedVerb`** (отсутствует и в RU, и в EN). Feed показывает fallback («created сделку» или похожее). Фикс: добавить ключ в `front/src/locales/ru.json` и `en.json` под `sales.deal.feed.events.dealCreatedVerb`. (Примечание: эти файлы фигурируют в текущем git-diff `front/src/locales/*.json` — вероятно, уже в работе.)

---

### Прочие minor / trivial (не верифицировано — Phase-1)

- **m7 · minor · MISSING** — серверные saved views не поддерживают deals (`SavedViewEntity` без case `Deal`; доска юзает только localStorage `deals_active_view`). Фильтры сбрасываются при reload. Файлы: `src/app/Domain/Crm/Enums/SavedViewEntity.php:13-14`, `front/src/stores/salesStore.ts:12,52`.
- **m8 · minor · DEAD-CODE** — pipeline `visible_role`/`visible_user_ids` недостижимы на запись через API (не объявлены в `StorePipelineRequest`/`UpdatePipelineRequest` rules → `validated()` их дропает). Файлы: `src/app/Http/Requests/Sales/StorePipelineRequest.php:20-25`, `UpdatePipelineRequest.php:19-33`, `Pipeline.php:36-37`.
- **m9 · minor · DEAD-CODE** — stage-видимость пишется через API (`Store/UpdateStageRequest:52-59`), но нет UI в `StageEditDrawer` и нет enforcement. Файлы: `front/src/entities/sales.ts:27-28`, `front/src/pages/PipelineSettingsPage/components/StageEditDrawer.vue`.
- **m10 · minor · BUG** — Kanban load-more (`useDealsBoard.ts:123,147-149`) мапит дозагруженные карты с `days_in_stage:null`/`next_task:null`/`primary_product:null` → карты за первой страницей без health/task-индикаторов, в отличие от первых 30.
- **m11 · minor · DATA-INCONSISTENCY** — `deal_stage_history` почти пуст (1 строка / 13 сделок); либо сделки не двигают, либо строки создания не пишутся. Вместе с `deal_audits=0` таймлайн в live недо-протестирован. Файлы: `src/app/Domain/Sales/Services/DealMoveService.php`, `DealStageHistory.php`.
- **m12 · minor · BUG** — `DealsListView::rottingClass` хардкодит пороги 7/14 вместо `stage.warn_days`/`danger_days` → list и board расходятся в «протухании». Файл: `front/src/pages/DealsPage/components/DealsListView.vue`.
- **t13 · trivial · BUG** — несовпадение ключа `rate_available` ↔ `fx_rate_available` между board JSON и FE-адаптером в `BoardRawColumnDto` → флаг доступности курса может не биндиться (префикс `≈`). Файлы: `front/src/api/sales.ts`, `src/app/Http/Resources/Sales/BoardColumnResource.php`.

> Примечание по live-QA: NEW-4 (Laravel `Route [login] not defined` 500 при `GET /api/deals?per_page=5` без Bearer) проявился на эндпоинте сделок, но это **сквозной auth-middleware-баг** (раскрытие стектрейса вместо 401 JSON для API-роутов), не дефект домена sales-deals — относится к Фундаменту/auth (`bootstrap/app.php`). Зафиксирован здесь как контекст, владелец — auth-домен. NEW-5 (`/api/admin/*` доступны менеджеру) и contacts/companies-утечки (A.3/A.3b) — вне домена.

## 7. Расхождения со спекой (vault) и предложения по актуализации

Документ: `2. Модули/Sales — DEALS 2.0 (воронка, сделки, Kanban).md`.

1. **Won-gate (line 35 + строка Won-gate в «Решения/отложено» line 181 vs «Следующие шаги» line 301).** Спека всё ещё говорит «won-gate в S1.3 — мягкое предупреждение (`won_gate_warning: true`); жёсткий 409 → S2». **Реальность:** жёсткий 409 реализован — `DealMoveService:89-95` бросает 409, когда `toStage->won_gate && won_gate_contract_required` и нет живого approved/signed/uploaded контракта (S2.8); line 301 уже отмечает «Won-gate жёсткий 409 уже реализован в S2». **Предложение:** обновить line 35 и строку Won-gate в «Решения/отложено», что жёсткий 409 отгружен (S2.8); убрать формулировку «soft → S2», чтобы спека не противоречила line 301.

2. **«amount derived» (line 34) — добавить FINANCIAL CORRECTNESS-заметку.** Спека: «amount — derived из суммы DealProduct (копейки)». **Реальность:** amount derived ТОЛЬКО из per-line amounts; deal-level `discount_percent` (migration `2026_06_29`) применяется только в `DealResource` для display (`products_net_total`); `deals.amount` остаётся GROSS, `recalcAmount` не пере-запускается на смену скидки. **Предложение:** добавить явное правило: «`discount_percent` — скидка на уровне сделки; `deals.amount = round(SUM(line.amount) * (1 - discount_percent/100))`; `recalcAmount` обязан пере-запускаться на любую смену line ИЛИ `discount_percent`». Зафиксировать единый источник истины по деньгам, чтобы FE/finance/analytics никогда не читали gross.

3. **Видимость воронок/стадий (новые поля Pipeline/PipelineStage).** Спека перечисляет `visible_role`/`visible_user_ids` (pipeline) и `visible_department_ids`/`visible_user_ids` (stage) как поля «+visibility», surfaced в `PipelineStageResource`. **Реальность:** колонки хранятся/cast/surfaced, но НИГДЕ не применяются; pipeline-level недостижимы на запись (нет в pipeline FormRequests), stage-level пишутся через API, но без FE-редактора. **Предложение:** либо явно пометить видимость воронок/стадий как «backlog / not enforced», либо написать спеку реального enforcement (где фильтруется, какая policy). Не подавать как рабочую возможность.

4. **`crm_saved_views` / saved views для Sales.** Спека (через Frontend/list views) подразумевает `crm_saved_views` как Sales-релевантную. **Реальность:** `SavedViewEntity` поддерживает только contact/company; доска deals юзает localStorage toggle kanban/list; серверных saved views для deals нет. **Предложение:** указать, что у deals НЕТ серверных saved views (только localStorage toggle), либо запланировать `SavedViewEntity::Deal` + FE-контрол как backlog. Убрать любой намёк, что saved views покрывают deals.

5. **(Новое, из live-QA)** Добавить в «Следующие шаги» строку про i18n-ключ `sales.deal.feed.events.dealCreatedVerb` (отсутствует RU+EN) и про необработанный 403 в mounted-хуке DealPage (ложные алерты мониторинга).
