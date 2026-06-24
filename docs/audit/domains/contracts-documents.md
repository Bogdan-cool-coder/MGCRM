# Аудит домена: Договоры — Документы (генерация, ревизии, ремарки, согласования, нумерация)

> Срез: live-окружение на 2026-06-24. Severity для blocker/major приведены ПОСЛЕ верификации (Phase 2 verdicts), minor/trivial — из Phase-1 без независимой проверки. Дубли облачной синхронизации (« 2.ext») игнорируются.

## 1. Назначение

Домен «Договоры — Документы» — это универсальный движок юридических документов CRM: генерация договоров (и сопутствующих документов — termination_agreement, invoice, act, reconciliation) из шаблонов `.docx` через PHPWord → Gotenberg → PDF, со снапшот-ревизиями, машинными/ручными ремарками, N-стадийным маршрутом согласования (approval), автоматической нумерацией договоров по городу/стране, кросс-доменным «won-gate» (запрет выигрыша сделки без активного договора) и текстовыми шаблонами сообщений (message_templates с `{{key}}`-подстановкой). Архитектурно это крупная, аккуратно слоёная подсистема (агрегат `Document` + Items/Revisions/Remarks/Attachments + ApprovalService + ContractNumberingService), совпадающая со спекой по структуре.

**Зрелость: каркас, не работающий в проде (built, not verified live).** Обоснование строго по live row counts: `template_versions = 0` и `templates.current_version_id = NULL` для всех 6 шаблонов — ни один `.docx` не загружен, поэтому `ContractGenerationService` всегда отдаёт `422 'Шаблон не загружен'`. Как следствие каскадно пусто: `document_items = 0`, `approvals = 0`, `contract_number_sequences = 0`, `document_attachments = 0`, `document_remarks = 0`, `document_revisions = 1` (одна демо-строка). При этом `documents = 8` (live SELECT показывал 9 — расхождение со снапшотом), но «approved» документы (id 2,3,4) и «submitted» (id 6) имеют `docx_path = NULL` и `number = NULL` — состояние, недостижимое через реальный submit-гард, то есть это прямые вставки из `DemoDealsSeeder`. Движок согласования никогда не отрабатывал end-to-end. Код есть и в основном корректно слоён, но фича операционно мертва «из коробки».

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI + endpoint) | Как (шаги) | Статус | Примечание |
|---|---|---|---|---|---|
| Машина состояний документа | admin, lawyer, author (manager/director); approvers стадий | DocumentPage; `DocumentService.transition()` + `ApprovalService` | draft→submitted (нужен docx_path + активный route + снапшот)→in_review→{approved\|needs_rework\|rejected}; needs_rework→submitted; approved→signed (нужен signed_scan)→uploaded (M11-заглушка 409)→archived | 🔴 сломан | Описан и загард­жен в коде, но НИКОГДА не отрабатывал live (approvals=0, revisions=1). Live «approved» docs 2/3/4 и «submitted» doc 6 имеют NULL docx_path+number — демо/seed-строки в обход машины |
| Генерация DOCX + PDF | admin/lawyer/author | POST `/documents/{id}/generate` (+ deal/company); `ContractGenerationService` | resolve шаблона по kind → getDocxPath (нужен template_versions) → резерв номера (SELECT FOR UPDATE) → ContractContextBuilder → PHPWord TemplateProcessor cloneRow → Gotenberg PDF → диск `documents` → recordGenerated → createRevision | 🔴 сломан | template_versions=0, current_version_id=NULL для всех 6 шаблонов → каждый generate 422 «Шаблон не загружен». Ни один документ не сгенерирован |
| Нумерация договоров | система (внутри generate) | `ContractNumberingService` внутри транзакции generate | normalizeCityCode (3 кириллицы upper) + country upper → SELECT FOR UPDATE sequence → create(start 220)/increment → формат `{CITY}-{n}/{COUNTRY}` (напр. `ТШК-220/UZ`) | 🔴 сломан | contract_number_sequences=0; все live-документы number=NULL — не запускалось, т.к. генерация заблокирована |
| N-стадийное согласование | approvers из user_ids стадий route (live default Юрист→Директор); author исключён | POST `/documents/{id}/submit` + `/decide`; MyApprovalsPage; `ApprovalService` | submit создаёт Approval-строки стадии 1 на currentAttempt; per-стадия approved-голоса ≥ min_required → следующая; последняя → approved. reject/needs_rework + remark; row-lock; self-approval запрещён; UNIQUE(doc,attempt,stage,user) | 🔴 сломан | Live не отрабатывал (approvals=0). attempt double-increment отвязывает currentAttempt от номера раунда; submit к тому же недостижим без docx_path |
| Won-gate (кросс-домен в Sales) | manager/director при переводе сделки в won-стадию | `DealMoveService` → `DocumentService.hasActiveContractForDeal` | при входе в стадию с is_won && won_gate && won_gate_contract_required → hasActiveContractForDeal (status в approved/signed/uploaded) → иначе WonGateException 409 | 🟡 частично | Логика построена, НО демо-docs 2/3/4 (status=approved, NULL docx_path) ложно проходят gate для deals 10/11/12 → их можно выиграть без реального договора. Гейт проверяет только status, не docx_path |
| Ремарки + вложения | approvers (машинные ремарки), admin/lawyer (ручные), author/admin/lawyer (resolve, upload, sign-scan) | DocumentRemarksTab + DocumentAttachmentsTab; `RemarkService` + `AttachmentService` | машинная ремарка от ApprovalService на reject/needs_rework; ручная POST `/remarks` (admin/lawyer); resolve toggle is_resolved; вложения на диск `documents`, signed_scan гейтит Sign | 🔴 сломан | document_remarks=0, document_attachments=0. FE рендерит неверные имена полей → автор/тело/резолвер пусты даже при наличии данных. IDOR на resolve |
| Рендер + контекст-матч шаблонов сообщений | admin/lawyer (CRUD), manager/director (read/preview); automation/inbox (findForContext) | MessageTemplatesPage; `MessageTemplateService` | `{{key}}` str_replace через buildVars (deal/company/contact/user/document/date); bindings AND-match scoring | 🟡 частично | 3 шаблона + 2 binding засидено; CRUD+preview подключены. Нет FE-потребителя findForContext; GET `/context` и DELETE template — мёртвые из FE |
| Генерация termination_agreement | admin/lawyer | POST `/companies/{company}/termination-documents/generate`; `TerminationDocumentService` | создаёт Document kind=termination_agreement и генерит docx/pdf как договор | 🔴 сломан | 3 live-черновика termination_agreement (docs 8,9,10), но валидация ApprovalRoute запрещает этот kind → ни один route не матчится → submit 422; плюс генерация заблокирована отсутствием template_versions |

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк в live | Статус |
|---|---|---|---|---|
| Document | `documents` | Агрегат юр.документа (kind=contract\|termination_agreement\|invoice\|act\|reconciliation); машина состояний + деньги (копейки) + context JSONB | 8 (snapshot) / live SELECT показывал 9 | 🟡 partial — слоён, но никогда не генерировался; «approved»/«submitted» строки — seed/demo с NULL docx_path+number |
| DocumentItem | `document_items` | Строка-позиция, снапшотит name_snapshot+unit_price; драйвит итоги через recalcTotals | 0 | 🟡 built (никогда не использован) |
| DocumentRevision | `document_revisions` | Иммутабельный снапшот context+paths; version_number + attempt | 1 (doc 6, v1/attempt1) | 🟡 partial — колонка attempt перегружена двумя писателями |
| DocumentRemark | `document_remarks` | Ремарка approver'а (машинная/ручная), резолвимая; поле `text` | 0 | 🟡 built (не использован) |
| DocumentAttachment | `document_attachments` | Загруженные файлы (signed_scan гейтит Sign); диск `documents` | 0 | 🟡 built (sign-flow не запускался) |
| ContractNumberSequence | `contract_number_sequences` | Монотонный счётчик номеров по city_code+country_code (старт 220) | 0 | 🟡 built (номер ни разу не зарезервирован) |
| ApprovalRoute | `approval_routes` | Конфигурируемый N-стадийный маршрут (stages JSON); матч по kind+template_id, затем kind+is_default | 1 | 🟡 built (1 default: Юрист→Директор); валидация запрещает termination_agreement |
| Approval | `approvals` | Голос approver'а per (document,attempt,stage_order,user); UNIQUE(...); иммутабелен после решения | 0 | 🔴 built (НИКОГДА не использован — движок согласования не работал live) |
| MessageTemplate | `message_templates` | Текстовые шаблоны рассылки с `{{key}}` + preview + context-match | 3 | ✅ built |
| MessageTemplateBinding | `message_template_bindings` | Иммутабельный scope (channel_kind/pipeline/stage/activity_type/automation_slot); AND-match scoring | 2 | ✅ built |
| TemplateVersion | `template_versions` | Версионированная загрузка docx Template + AI-check; ТРЕБУЕТСЯ для генерации | 0 | 🔴 built, но ПУСТ — ни один docx не загружен; current_version_id=NULL для всех → генерация невозможна (корневая аномалия) |

**Расхождения migration ↔ live-schema ↔ model:**
- **template_versions** — корневая аномалия. Миграция создаёт таблицу + FK `templates.current_version_id` (nullOnDelete), но `TemplateSeeder.php:86` хардкодит `current_version_id => null` и НЕ создаёт ни одной `TemplateVersion`. В репо нет ни одного `.docx` (`find -iname '*.docx'` пусто). `migrate --seed` воспроизводит сломанное состояние by design. Это не схемный mismatch, а незасиженная предпосылка.
- **documents.status vs docx_path/number** — инвариант нарушен в данных: id 2,3,4 = approved и id 6 = submitted при NULL docx_path & number, хотя `ApprovalService.submit` жёстко требует docx_path. Источник — `DemoDealsSeeder::ensureContractForGatedWonDeal`, прямая вставка status=Approved без docx, специально чтобы удовлетворить won-gate.
- **documents row count** — snapshot rowcounts.txt = 8, live SELECT = 9 (присутствуют 3 черновика termination_agreement). Минорный дрейф снапшота.
- **document_revisions.attempt** — семантика колонки перегружена: пишется двумя сервисами (generation + submit) и читается как счётчик раунда согласования; vault предполагает два РАЗНЫХ счётчика.
- **approval_routes.document_kind** — varchar(32); валидация `in:[contract,invoice,act,reconciliation]`, но `DocumentKind` enum включает `termination_agreement` (реальные live-строки) → реальный kind без допустимого route.
- **Двойной источник роли** — `users.role` (string-зеркало) + spatie `roles`/`model_has_roles` оба присутствуют live; политики читают `users.role` enum. Риск дрейфа.

**Пустые при наличии кода:** все дочерние таблицы (`document_items`, `document_remarks`, `document_attachments`, `approvals`, `contract_number_sequences`) и `template_versions` — код полностью написан, но ни разу не выполнялся в live из-за корневого блокера генерации.

## 4. Эндпоинты и покрытие фронтом

| Метод+Path | Контроллер@метод | Авторизация | Зовётся FE? | Примечание |
|---|---|---|---|---|
| GET `/api/documents` | DocumentController@index | Policy viewAny=true (любой auth); НЕТ author-scoping в service | да (DocumentsPage, DealTabDocuments `?deal_id=`) | 🔴 кросс-юзер утечка (см. backlog) |
| POST `/api/documents` | DocumentController@store | Policy create=true | да (CreateDocumentDialog) | — |
| GET `/api/documents/{document}` | DocumentController@show | Policy view: admin/lawyer/director все, прочие — свои | да (DocumentPage) | per-doc author-check корректен |
| PATCH `/api/documents/{document}` | DocumentController@update | Policy update: admin/lawyer или author; service проверяет editable status | да (autosave context + signed_at) | — |
| DELETE `/api/documents/{document}` | DocumentController@destroy | Policy delete: admin only; controller гард status=draft | нет (в списке/детали не подключено) | мёртвый из FE |
| POST `/api/documents/{document}/generate` | DocumentGenerateController@generate | Policy generate: admin/lawyer или author | да (кнопка Generate) | 422 «Шаблон не загружен» всегда |
| GET `/api/documents/{document}/download/docx` | DocumentGenerateController@downloadDocx | Policy view | да | — |
| GET `/api/documents/{document}/download/pdf` | DocumentGenerateController@downloadPdf | Policy view | да | — |
| POST `/api/documents/{document}/submit` | DocumentApprovalController@submit | Policy submit; service жёстко требует docx_path + route + self-approval гард | да (Submit/Resubmit) | недостижим без docx_path |
| POST `/api/documents/{document}/decide` | DocumentApprovalController@decide | Policy decide: любой auth КРОМЕ author; service проверяет членство в стадии (403) | да (ApprovalPanel, MyApprovals, DealTabDocuments) | — |
| GET `/api/documents/{document}/approval-summary` | DocumentApprovalController@approvalSummary | Policy approvalSummary | да (poll 3s) | — |
| GET `/api/approvals/my` | DocumentApprovalController@myApprovals | implicit user_id=self (нет policy) | да (MyApprovalsPage, sidebar badge) | History-вкладка сломана (см. backlog) |
| GET `/api/approvals/{approval}` | DocumentApprovalController@showApproval | INLINE role-check (`$user->role->value`) — нарушение конвенции | нет наблюдаемого caller'а | мёртвый из FE + конвенция |
| POST `/api/documents/{document}/sign` | DocumentController@sign | Policy sign; service требует signed_scan | да (DocumentPage / DealTabDocuments) | — |
| POST `/api/documents/{document}/unsign` | DocumentController@unsign | Policy unsign: admin/lawyer only | да | FE canUnsign шире BE → author получает 403 |
| POST `/api/documents/{document}/archive` | DocumentController@archive | Policy archive; блок при in_review | да | — |
| POST `/api/documents/{document}/unarchive` | DocumentController@unarchive | Policy unarchive: admin/lawyer only | да | — |
| POST `/api/documents/{document}/upload-drive` | DocumentController@uploadDrive | Policy uploadDrive; всегда 409 not_yet_implemented | нет | M11-заглушка |
| GET `/api/documents/{document}/items` | DocumentItemController@index | Policy view(document) | да (DocumentItemsTab) | — |
| POST `/api/documents/{document}/items` | DocumentItemController@store | Policy update(document) | да | — |
| PATCH `/api/documents/{document}/items/{item}` | DocumentItemController@update | Policy update(document) ТОЛЬКО — item не проверен на принадлежность | да | 🔴 IDOR |
| DELETE `/api/documents/{document}/items/{item}` | DocumentItemController@destroy | Policy update(document) ТОЛЬКО — item не проверен | да | 🔴 IDOR |
| GET `/api/documents/{document}/revisions` | DocumentRevisionController@index | read-only (scoping не проверен) | да (DocumentRevisionsTab) | unscoped binding (см. ниже) |
| GET `/api/documents/{document}/revisions/{revision}` | DocumentRevisionController@show | read-only | нет caller'а | **live-доказано unscoped**: GET `/documents/5/revisions/1` вернул revision doc 6 |
| GET `/api/documents/{document}/remarks` | DocumentRemarkController@index | Policy view(document) | да (`?attempt=`) | — |
| POST `/api/documents/{document}/remarks` | DocumentRemarkController@store | Policy createRemark: admin/lawyer | нет UI ручной ремарки | мёртвый из FE |
| POST `/api/documents/{document}/remarks/{remark}/resolve` | DocumentRemarkController@toggleResolve | Policy resolveRemark(document) ТОЛЬКО — remark не проверен | да | 🔴 IDOR |
| GET `/api/documents/{document}/attachments` | DocumentAttachmentController@index | Policy view (проверить) | да | — |
| POST `/api/documents/{document}/attachments` | DocumentAttachmentController@store | Policy uploadAttachment | да | — |
| GET `/api/documents/{document}/attachments/{attachment}/download` | DocumentAttachmentController@download | Policy + проверка принадлежности (проверить) | да (window.open) | проверить scoped binding (тот же паттерн IDOR) |
| DELETE `/api/documents/{document}/attachments/{attachment}` | DocumentAttachmentController@destroy | Policy deleteAttachment (проверить ownership) | да | проверить scoped binding |
| GET `/api/approval-routes` | ApprovalRouteController@index | Policy viewAny=true | да (ApprovalRoutesPage) | — |
| POST `/api/approval-routes` | ApprovalRouteController@store | Policy create: admin/lawyer; валидация запрещает termination_agreement | да (drawer) | блокирует termination kind |
| GET `/api/approval-routes/{approvalRoute}` | ApprovalRouteController@show | Policy view=true | да | — |
| PATCH `/api/approval-routes/{approvalRoute}` | ApprovalRouteController@update | Policy update: admin/lawyer; валидация запрещает termination_agreement | да | — |
| DELETE `/api/approval-routes/{approvalRoute}` | ApprovalRouteController@destroy | Policy delete: admin only (soft) | да | — |
| GET `/api/message-templates` | MessageTemplateController@index | Policy viewAny: admin/lawyer/director/manager | да | FE router над-ограничивает до admin/lawyer |
| GET `/api/message-templates/context` | MessageTemplateController@context | Policy viewAny | нет caller'а | мёртвый endpoint |
| POST `/api/message-templates` | MessageTemplateController@store | Policy create: admin/lawyer | да | — |
| PATCH `/api/message-templates/{messageTemplate}` | MessageTemplateController@update | Policy update: admin/lawyer | да | — |
| DELETE `/api/message-templates/{messageTemplate}` | MessageTemplateController@destroy | Policy delete: admin only (soft) | нет Delete-кнопки в UI | orphaned FE API (deleteMessageTemplate существует, кнопки нет) |
| POST `/api/message-templates/{messageTemplate}/preview` | MessageTemplateController@preview | Policy view | да | — |
| POST `/api/message-templates/{messageTemplate}/bindings` | MessageTemplateController@bindingStore | Policy update | да | — |
| DELETE `/api/message-templates/{messageTemplate}/bindings/{binding}` | MessageTemplateController@bindingDestroy | Policy update + abort_if binding.template_id != template | да | ownership проверен — корректно |
| POST `/api/deals/{deal}/documents/generate` | DealDocumentController@generate | Policy generate; возвращает GenerateResultResource{document_id} | да (DealTabDocuments, GenerateDocumentDialog) | 🔴 FE ждёт DocumentDto{id} → MISMATCH |
| POST `/api/companies/{company}/documents/generate` | CompanyDocumentController@generate | Policy generate; GenerateResultResource{document_id} | да (CompanyDocuments, GenerateDocumentDialog) | 🔴 тот же MISMATCH |
| POST `/api/companies/{company}/termination-documents` | TerminationDocumentController@store | N6 — проверить | проверить | — |
| POST `/api/companies/{company}/termination-documents/generate` | TerminationDocumentController@generate | N6; kind=termination_agreement без валидного route | проверить | submit недостижим |

## 5. RBAC домена

**Где авторизация реально проверяется (корректно):**
- **documents write** — update/submit/generate/sign/archive = admin/lawyer или author; delete = admin only (только draft); unsign/unarchive/upload-drive = admin/lawyer only. Per-document author-check в `show`/`update`/`sign` работает.
- **decide** — любой auth кроме author; service дополнительно проверяет членство в стадии (403). Self-approval заблокирован и в submit, и в decide.
- **message-templates bindings destroy** — abort_if проверяет, что binding принадлежит шаблону (единственное место, где child-ownership проверен правильно).

**Где дыры:**
- 🔴 **documents.index unscoped** (major по верификации) — `DocumentPolicy.viewAny` возвращает true, `DocumentService.list` НЕ форсит author-scoping, у модели НЕТ глобального скоупа, FE-роуты `/documents` без role-meta. Live-проба под manager1 (id=4): `GET /api/documents?per_page=100` вернул 9 строк от 4 авторов, только 2/9 принадлежат вызывающему. Прямое нарушение doc-комментария самой политики «manager/accountant/cfo see own only».
- 🔴 **child resources (items/remarks/revisions)** (blocker) — `authorize()` выполняется только над родителем `{document}`, дочерний `{item}/{remark}/{revision}` route-bound независимо и НЕ проверяется на принадлежность. Live read-only проба на идентично-паттернированном `revisions.show` вернула чужой документ. Распространяется на mutating items.update/destroy и remarks.resolve.
- 🟡 **showApproval** (minor) — inline role-check `in_array($user->role->value, ['admin','lawyer'])` вместо Policy. Нарушение ARCHITECTURE.md §3.
- 🟡 **message-templates** (minor) — BE-policy допускает читать director/manager, FE-router режет до admin/lawyer (над-ограничение, director/manager не доходят до UI).
- 🟡 **Двойной источник роли** (minor) — `users.role` (зеркало) + spatie таблицы; политики читают зеркало; при дивергенции authz берёт неверный источник.
- **Связь с live-QA NEW-5:** под manager1 успешно отдают 200 эндпоинты `/api/admin/*` (company-types, sources, countries, cities, contact-positions, acquisition-channels, disconnect-reasons). Хотя это каталоги CRM-домена, часть из них (acquisition-channels, disconnect-reasons) — sensitive; общий паттерн «нет role-gate на admin-ресурсах» совпадает с дырой documents.index.

## 6. Бэклог проблем

### Сводная таблица

| Severity (FINAL) | Тип | Заголовок | Проверка |
|---|---|---|---|
| blocker | DATA-INCONSISTENCY | Генерация невозможна — нет template_versions, current_version_id=NULL для всех 6 шаблонов (корень) | ✅ подтверждено (live SQL + GET /api/templates) + 🌐 live-QA |
| blocker | SECURITY | IDOR — items.update/destroy и remarks.resolve не проверяют принадлежность child к {document} | ✅ подтверждено (live-проба на revisions.show) |
| blocker | BUG | Generate-from-deal/company: shape mismatch — doc.id undefined после генерации | ✅ подтверждено (wrapping-проба + статика 3 callers) |
| major | DATA-INCONSISTENCY | Фейк-approved демо-документы обходят машину состояний и ложно проходят won-gate | ✅ подтверждено (live DB + трассировка DemoDealsSeeder) |
| major | SECURITY | documents.index unscoped — managers видят ВСЕ документы | ✅ подтверждено (live-проба под manager1) |
| major | BUG | attempt double-increment — generation и submit оба инкрементят document_revisions.attempt | ✅ подтверждено (статика 3 сервисов) |
| major | BUG | MyApprovals History всегда пуста — status='decided' против enum без 'decided' | ✅ подтверждено (структурно) |
| major | BUG | MyApprovals: undefined-колонки — ApprovalResource не отдаёт document_number/kind/company_name/stage_name | ✅ подтверждено (диф полей) |
| major | BUG | DocumentItem: имя продукта не отображается — FE читает product_name, BE отдаёт name_snapshot (и нет currency в DTO) | ✅ подтверждено (точный диф полей) |
| major | BUG | Revisions tab: пустые версия и автор, скачивание по сырому storage-пути | ✅ подтверждено (статика resource+tab) |
| major | BUG | Remarks tab: пустые автор и тело — FE читает approver_name/body/resolved_by_name, BE отдаёт author.full_name/text/resolved_by.full_name | ✅ подтверждено (диф полей) |
| major | BUG | termination_agreement без валидного route — валидация ApprovalRoute запрещает kind | ✅ подтверждено (статика requests+service) |
| major | BUG | Валюта позиции не сохраняется — saveCurrency мутирует только локальный ref | ✅ подтверждено (статика + неподключённый родитель) |
| minor | BUG | ApprovalRoutesPage рендерит template_code, которого ApprovalRouteResource не отдаёт | не верифицировано (Phase-1) |
| minor | CONVENTION | showApproval использует inline role-check вместо Policy | не верифицировано (Phase-1) |
| minor | BUG | Имя загрузившего и размер файла вложения не отображаются | не верифицировано (Phase-1) |
| minor | BUG | MessageTemplatesPage над-ограничена admin/lawyer; BE допускает director/manager | не верифицировано (Phase-1) |
| minor | DEAD-CODE | Мёртвые FE/endpoints — Duplicate-заглушка, deleteMessageTemplate, GET /message-templates/context | не верифицировано (Phase-1) |
| minor | DATA-INCONSISTENCY | Двойной источник роли (users.role + spatie) может разойтись | не верифицировано (Phase-1) |
| minor | SPEC-DRIFT | CreateDocumentDialog хардкодит продукты/страны вместо каталога/справочника | не верифицировано (Phase-1) |
| trivial | BUG | FE canUnsign шире BE — author видит Unsign, но BE 403 | не верифицировано (Phase-1) |

---

### BLOCKER 1 — Генерация невозможна: нет template_versions (корневая аномалия)

**Severity: blocker · Тип: DATA-INCONSISTENCY · Проверка: ✅ подтверждено (live SQL + GET /api/templates) + 🌐 live-QA (B.6)**

**Файлы:**
- `src/app/Domain/Contracts/Services/ContractGenerationService.php:88` (вызов getDocxPath)
- `src/app/Domain/Contracts/Services/ContractGenerationService.php:89-92` (catch RuntimeException → 422 «Шаблон не загружен»)
- `src/app/Domain/Contracts/Services/TemplateService.php:123-128` (throw при null currentVersion/docx_path)
- `src/database/seeders/TemplateSeeder.php:86` (`current_version_id => null`, ни одной TemplateVersion)
- `src/database/seeders/DatabaseSeeder.php:48,74`

**Что происходит:** Live SQL: `SELECT id,code,current_version_id FROM templates` → все 6 строк `current_version_id=NULL`; `template_versions=0`. GET `/api/templates` (admin token) → все 6 шаблонов `current_version_id=null`. `getDocxPath` бросает 422 до проверки наличия файла. В репо нет ни одного `.docx`. `TemplateSeeder` явно ставит null и не создаёт версию → `migrate --seed` воспроизводит сломанное состояние by design. Live-QA подтвердил визуально: в детали шаблона «История версий: Нет версий». (Уточнение из верификации: TemplateSeeder перечисляет 7 шаблонов, live DB — 6; на блокер не влияет, master_skeleton всё равно версии не имеет.)

**Repro:** Login admin → POST `/api/documents/{id}/generate` на любом черновике → `422 'Шаблон не загружен'`.

**Предлагаемый фикс:** Залить минимум `master_skeleton.docx` через `/api/templates/{id}/upload`, чтобы появилась `TemplateVersion` и проставился `templates.current_version_id`; либо реальный сидер для `template_versions`. До этого весь домен документов мёртв. Добавить в план live-readiness-гейт.

---

### BLOCKER 2 — IDOR: items.update/destroy и remarks.resolve не проверяют принадлежность child к {document}

**Severity: blocker · Тип: SECURITY · Проверка: ✅ подтверждено (live read-only проба на идентичном revisions.show)**

**Файлы:**
- `src/app/Http/Controllers/Contracts/DocumentItemController.php:58` (update authorize только parent)
- `src/app/Http/Controllers/Contracts/DocumentItemController.php:70` (destroy authorize только parent)
- `src/app/Http/Controllers/Contracts/DocumentRemarkController.php:66-68` (toggleResolve authorize $document, service получает только $remark)
- `src/app/Domain/Contracts/Services/DocumentService.php:201-221` (updateItem работает с $item напрямую, без проверки document_id)
- `src/app/Domain/Contracts/Services/DocumentService.php:226-234` (deleteItem — то же)
- `src/app/Domain/Contracts/Services/RemarkService.php` toggleResolve (нет parent-параметра)
- `src/routes/api.php:700-720` (вложенная группа, НЕТ scopeBindings, plain {item}/{remark})
- `src/app/Domain/Contracts/Policies/DocumentPolicy.php:54-61` (update = author РОДИТЕЛЯ)

**Что происходит:** Route-model binding для вложенных `{item}/{remark}/{revision}` не scoped (нет `Route::scopeBindings`, нет custom-key `{item:document_id}`). Ни контроллеры, ни сервисы, ни FormRequest, ни политики, ни глобальный конфиг не проверяют, что child принадлежит `{document}` из URL. `authorize('update'/'resolveRemark', $document)` проверяет только родителя, которым атакующий легитимно владеет. **Live-доказательство:** GET `/api/documents/5/revisions/1` → HTTP 200 с `{"document_id":6}` (revision принадлежит doc 6, получен через URL doc 5); GET `/api/documents/6/revisions/999` → 404 (sanity binding). Тот же механизм лежит под mutating items.update/destroy и remarks.resolve.

**Repro:** Как автор черновика doc A: `DELETE /api/documents/A/items/{id}`, где `{id}` принадлежит doc B (чужой editable-документ) → позиция удаляется, итоги A пересчитываются, B молча мутируется.

**Предлагаемый фикс:** `Route::scopeBindings()` на группе ИЛИ custom-key `{document}/items/{item:document_id}`; ИЛИ `abort_unless($item->document_id === $document->id, 404)` в каждом контроллере/сервисе. Применить к items.update, items.destroy, remarks.toggleResolve; **проаудитить тем же паттерном attachments.download и attachments.destroy**.

---

### BLOCKER 3 — Generate-from-deal/company: doc.id undefined после генерации (shape mismatch)

**Severity: blocker · Тип: BUG · Проверка: ✅ подтверждено (wrapping-проба live + статика всех 3 FE callers)**

**Файлы:**
- `src/app/Http/Resources/Contracts/GenerateResultResource.php:43-53` (отдаёт `document_id`, number, docx_url, pdf_url, warnings — НЕТ `id`)
- `src/app/Http/Controllers/Contracts/DealDocumentController.php:38,48`
- `src/app/Http/Controllers/Contracts/CompanyDocumentController.php:39,49`
- `front/src/api/documents.ts:217-226` (generateFromDeal типизирует `{data: DocumentDto}`, возвращает `response.data.data`)
- `front/src/api/documents.ts:228-237` (generateFromCompany — то же)
- `front/src/entities/document.ts:26,29,57` (DocumentDto имеет `id`/`status` — оба отсутствуют в payload генерации)
- `front/src/pages/DealPage/components/DealTabDocuments.vue:375-376` (activeDocId=doc.id=undefined)
- `front/src/pages/DocumentPage/components/GenerateDocumentDialog.vue:158` (emit('created', doc.id=undefined))
- `front/src/pages/CompanyPage/components/CompanyDocumentsTab.vue:146-147` (router.push(`/documents/undefined`))

**Что происходит:** Контроллеры возвращают `GenerateResultResource`, которая при дефолтном Laravel-wrapping (проверено live — GET `/api/documents/10` обёрнут в `data` с присутствующим `data.id`) сериализуется в `{data:{document_id, number, docx_url, pdf_url, warnings}}` — ключ `document_id`, не `id`. FE читает `response.data.data` как `DocumentDto` и каждый caller обращается к `doc.id` → undefined → `router.push('/documents/undefined')` в CompanyDocumentsTab, undefined activeDocId и сломанный list-prepend в DealTabDocuments.

**Repro:** Открыть Сделку → вкладка Документы → выбрать шаблон → Generate → новая карточка пустая/невыбираемая; через GenerateDocumentDialog уходит на `/documents/undefined`.

**Предлагаемый фикс:** Либо типизировать generateFromDeal/Company как `{document_id:number; number; docx_url; pdf_url; warnings}` и читать `document_id`, либо вернуть из обоих контроллеров `DocumentResource`. Выровнять всех FE-callers. (Этот баг сейчас маскируется BLOCKER 1: генерация всё равно 422, но станет видимым сразу после заливки шаблона.)

---

### MAJOR 1 — Фейк-approved демо-документы обходят машину состояний и ложно проходят won-gate

**Severity: major (понижено с blocker — строки из демо-сидера, не runtime) · Тип: DATA-INCONSISTENCY · Проверка: ✅ подтверждено (live DB + трассировка DemoDealsSeeder)**

**Файлы:**
- `src/app/Domain/Contracts/Services/ApprovalService.php:61-65` (submit жёстко требует docx_path — approved недостижим через реальный flow)
- `src/app/Domain/Contracts/Services/DocumentService.php:410-420` (hasActiveContractForDeal: только status, без docx_path/number)
- `src/app/Domain/Sales/Services/DealMoveService.php:93-99` (единственный caller won-gate; доп.проверки контракта нет)
- `src/database/seeders/DemoDealsSeeder.php:124-158` (ensureContractForGatedWonDeal вставляет status=Approved без docx_path/number)

**Что происходит:** Live: documents id 2,3,4 = approved, id 6 = submitted, ВСЕ с NULL docx_path+number, title с префиксом `[DEMO]`. Это недостижимо через реальный submit-гард → строки вставлены напрямую сидером. `hasActiveContractForDeal` считает только `status IN (approved,signed,uploaded)`, поэтому пустые контракты удовлетворяют won-gate для deals 10/11/12 (их source_deal_id). Стадия 8, где сидят эти сделки, имеет is_won=t, won_gate=t, won_gate_contract_required=t.

**Repro:** Перевести deal 10 в won-стадию с won_gate_contract_required → gate проходит, хотя у контракта нет docx и он не проходил движок согласования.

**Предлагаемый фикс:** Удалить фейк-демо-документы (или пересоздать через реальный flow). Захардить `hasActiveContractForDeal`: дополнительно требовать `docx_path IS NOT NULL`. Добавить инвариант/тест: approved/signed-документы обязаны иметь docx_path.

---

### MAJOR 2 — documents.index unscoped: managers видят ВСЕ документы

**Severity: major (понижено с blocker — read-only утечка метаданных внутреннего персонала, без привилегированного действия) · Тип: SECURITY · Проверка: ✅ подтверждено (live-проба под manager1)**

**Файлы:**
- `src/app/Domain/Contracts/Policies/DocumentPolicy.php:16` (doc-комментарий: managers видят только своё)
- `src/app/Domain/Contracts/Policies/DocumentPolicy.php:23-26` (viewAny=true)
- `src/app/Http/Controllers/Contracts/DocumentController.php:32-40` (index передаёт raw query, без user-scoping)
- `src/app/Domain/Contracts/Services/DocumentService.php:48-88` (list применяет только опциональные фильтры; author_id на 75-77 — opt-in)
- `src/app/Domain/Contracts/Models/Document.php:153-164` (нет глобального скоупа; только локальные scopeActive/scopeForStatus)
- `src/routes/api.php:674` (GET documents → index, только auth, без role-middleware)
- `front/src/router/routes/base.ts:127`

**Что происходит:** Полный путь index без обязательного author-scoping: viewAny=true, контроллер форвардит `$request->query()` нетронутым, фильтр author_id в сервисе опционален. У модели нет глобального скоупа. Live-проба под manager1@mgcrm.test (id=4): `GET /api/documents?per_page=100` → 9 строк от 4 авторов (admin id=1 — 5 docs, manager1 — 2, Петрова id=5 — 1, Сидоров id=6 — 1); только 2/9 принадлежат вызывающему, включая admin-черновики. Прямое нарушение собственного doc-комментария политики.

**Repro:** Login manager1, GET `/api/documents` → возвращаются документы других авторов.

**Предлагаемый фикс:** В `DocumentService.list`, если вызывающий не admin/lawyer/director, форсить `where('author_user_id', $currentUserId)`. Прокинуть user в вызов (контроллер уже имеет его). Выровнять doc-комментарий политики с фактическим поведением.

---

### MAJOR 3 — attempt double-increment: generation и submit оба инкрементят document_revisions.attempt

**Severity: major · Тип: BUG · Проверка: ✅ подтверждено (статика 3 сервисов; live не триггерится — approvals=0)**

**Файлы:**
- `src/app/Domain/Contracts/Services/ContractGenerationService.php:303` (createRevision: attempt=lastRevision.attempt+1 на КАЖДОМ generate)
- `src/app/Domain/Contracts/Services/DocumentService.php:500` (createRevisionSnapshot: attempt+1 на КАЖДОМ submit)
- `src/app/Domain/Contracts/Services/ApprovalService.php:457-464` (currentAttempt = max(attempt), штампует/читает Approval-строки по нему)

**Что происходит:** Оба писателя инкрементят ОДНУ колонку `document_revisions.attempt`, а `ApprovalService.currentAttempt` читает `max(attempt)`. Vault (S2.4 ~line 322 vs S2.6 ~line 166-172) явно требует, чтобы счётчик generation-attempt и счётчик раунда согласования были РАЗНЫМИ. Цикл `needs_rework → regenerate(+1) → resubmit(+1)` раздувает раунд на 2 за цикл и отвязывает его от последовательности 1,2,3. Уточнение из верификации: худший сценарий «generate после submit поднимает max(attempt) выше pending approvals» частично блокирован гардом generate (только draft/needs_rework, `ContractGenerationService:64`), поэтому регенерация во время submitted/in_review невозможна — ядро дефекта остаётся, severity major (не blocker).

**Repro:** Generate (attempt 1) → submit (attempt 2, approvals на 2) → reject (needs_rework) → regenerate (attempt 3) → resubmit (attempt 4). currentAttempt прыгает на 2 за раунд; нумерация раунда ≠ 1,2,3.

**Предлагаемый фикс:** Не инкрементить attempt на генерации (генерация инкрементит только version_number); ИЛИ разделить на две колонки (generation_seq vs approval_attempt). currentAttempt должен считать submit-циклы. Тест: раунд растёт ровно на 1 за resubmit независимо от числа регенераций.

---

### MAJOR 4 — MyApprovals History всегда пуста: status='decided' против enum без значения 'decided'

**Severity: major · Тип: BUG · Проверка: ✅ подтверждено (структурно)**

**Файлы:**
- `src/app/Http/Controllers/Contracts/DocumentApprovalController.php:94-95` (`$query->where('decision', $request->query('status'))`)
- `front/src/pages/MyApprovalsPage/index.vue:175` (History зовёт `getMyApprovals({status:'decided'})`)
- `front/src/api/approvals.ts`

**Что происходит:** Контроллер делает сырой `where('decision', status)` без маппинга. `ApprovalDecision` enum = pending|approved|rejected|needs_rework — значения 'decided' нет, колонка никогда им не равна. Даже когда approvals появятся, History всегда пуста. Pending-вкладка работает (status='pending' матчит decision='pending').

**Repro:** Approver с решёнными документами → /my-approvals → History → всегда пусто.

**Предлагаемый фикс:** Маппить status='decided' → `whereIn('decision',['approved','rejected','needs_rework'])`, status='pending' → `where('decision','pending')`.

---

### MAJOR 5 — MyApprovals: undefined-колонки (ApprovalResource не отдаёт document_number/kind/company_name/stage_name)

**Severity: major · Тип: BUG · Проверка: ✅ подтверждено (диф полей)**

**Файлы:**
- `src/app/Http/Resources/Contracts/ApprovalResource.php:18-32`
- `front/src/entities/approval.ts:9-25`
- `front/src/pages/MyApprovalsPage/index.vue` (колонки :26 document_number, :31 document_kind, :34 company_name, :37 stage_name)

**Что происходит:** `ApprovalResource` отдаёт только id, document_id, attempt, stage_order, user_id, user{id,full_name}, decision, comment, decided_at, created_at. Контроллер eager-загружает document (id,title,status,kind,number), но resource его не сериализует; company не загружается вообще; у стадии нет поля name. FE ждёт document_number/document_kind/company_name/stage_name → пустые колонки, document-колонка падает в fallback `#draft-{id}`.

**Repro:** /my-approvals с pending-голосом → Document = `#draft-N`, Kind/Company/Stage пусты.

**Предлагаемый фикс:** Отдельный `MyApprovalResource`, выставляющий document_number/document_kind из загруженного документа, eager-load company name, stage_name из соответствующей стадии route, map status из decision.

---

### MAJOR 6 — DocumentItem: имя продукта не отображается (FE product_name vs BE name_snapshot; нет currency в DTO)

**Severity: major · Тип: BUG · Проверка: ✅ подтверждено (точный диф полей)**

**Файлы:**
- `src/app/Http/Resources/Contracts/DocumentItemResource.php:23` (отдаёт name_snapshot, currency, plan_id, qty, unit_price, line_total, sort_order)
- `front/src/entities/document.ts:65` (DocumentItemDto.product_name; нет currency)
- `front/src/pages/DocumentPage/components/DocumentItemsTab.vue:19` (`{{ data.product_name }}`)

**Что происходит:** Resource отдаёт `name_snapshot`, FE читает `product_name` → undefined → пустая ячейка продукта для каждой строки. Под-claim про валюту подтверждён: resource отдаёт `currency`, но DTO её не объявляет.

**Repro:** Добавить строку-позицию → ячейка продукта пуста.

**Предлагаемый фикс:** Переименовать FE-поле в `name_snapshot` (entity + шаблон), добавить `currency` в DocumentItemDto; либо отдавать `product_name` из resource.

---

### MAJOR 7 — Revisions tab: пустые версия и автор, скачивание по сырому storage-пути

**Severity: major · Тип: BUG · Проверка: ✅ подтверждено (статика resource + tab)**

**Файлы:**
- `src/app/Http/Resources/Contracts/DocumentRevisionResource.php:21,28` (version_number, created_by_user_id — без имени)
- `front/src/entities/document.ts:78,84` (version, created_by_name)
- `front/src/pages/DocumentPage/components/DocumentRevisionsTab.vue:14` (`v{{data.version}}` → 'v'+undefined), `:22` (`{{data.created_by_name ?? '—'}}` → всегда —), `:91-93` (download: `window.open(data.docx_path)` на disk-relative пути)

**Что происходит:** Resource отдаёт `version_number` (не `version`) и `created_by_user_id` без имени. FE рендерит `v` + undefined и автора `—`. download делает `window.open` по storage-относительному пути `contracts/{id}/contract.docx`, а не через served `/api/documents/{id}/download/docx|pdf` → 404. (Колонка `attempt` названа корректно — `#{{data.attempt}}` рендерится.)

**Repro:** Сгенерировать doc, открыть Revisions → версия 'v', автор '—', download открывает необслуживаемый путь.

**Предлагаемый фикс:** Выровнять FE на `version_number`; выставить имя created_by в resource (whenLoaded); скачивание — через `/documents/{id}/download` эндпоинты.

---

### MAJOR 8 — Remarks tab: пустые автор и тело (FE approver_name/body/resolved_by_name vs BE author.full_name/text/resolved_by.full_name)

**Severity: major · Тип: BUG · Проверка: ✅ подтверждено (диф полей — данные на сервере ЕСТЬ, чистый FE-mismatch)**

**Файлы:**
- `src/app/Http/Resources/Contracts/DocumentRemarkResource.php:24-35` (text, author{full_name}, resolved_by{full_name})
- `src/app/Domain/Contracts/Services/RemarkService.php:112` (eager-load author+resolvedBy — данные есть)
- `front/src/pages/DocumentPage/components/DocumentRemarksTab.vue:34` (remark.approver_name), `:54` (remark.body), `:55` (remark.resolved_by_name)

**Что происходит:** Verification отдельно проверила, что author/resolvedBy eager-загружены (`RemarkService:112`), то есть данные на сервере присутствуют — это чистый FE-mismatch имён полей. FE читает approver_name/body/resolved_by_name, которых resource не отдаёт (он шлёт author.full_name, text, resolved_by.full_name) → пустые автор/тело/резолвер.

**Repro:** Создать ремарку (reject/needs_rework) → Remarks tab показывает пустой автор и текст.

**Предлагаемый фикс:** Обновить FE entity + шаблон на text, author.full_name, resolved_by.full_name.

---

### MAJOR 9 — termination_agreement без валидного route: валидация ApprovalRoute запрещает kind

**Severity: major · Тип: BUG · Проверка: ✅ подтверждено (статика requests + service); сейчас замаскировано upstream-блокером генерации**

**Файлы:**
- `src/app/Http/Requests/Contracts/StoreApprovalRouteRequest.php:22` (`Rule::in(['contract','invoice','act','reconciliation'])`)
- `src/app/Http/Requests/Contracts/UpdateApprovalRouteRequest.php:22` (то же)
- `src/app/Http/Controllers/Contracts/TerminationDocumentController.php`
- `src/app/Domain/Contracts/Services/ApprovalService.php:71` (submit безусловно зовёт matchForDocument), `ApprovalRouteService:70` (422 при отсутствии route)

**Что происходит:** `document_kind` валидируется как `in:[contract,invoice,act,reconciliation]`, но `termination_agreement` — реальный kind (enum + TerminationDocumentController + live-строки 8,9,10). Route с этим kind нельзя создать/обновить через API; `matchForDocument` не находит route → 422; submit-байпаса для termination-kind нет. Caveat: live termination-docs все в status=draft и генерация тоже заблокирована, так что это замаскировано upstream-блокером, но валидационно-роутинговый пробел независим.

**Repro:** Создать ApprovalRoute с document_kind=termination_agreement → 422; затем submit termination-документа → 422 «no route».

**Предлагаемый фикс:** Добавить `termination_agreement` в `Rule::in` для approval-routes (и сверить покрытие enum DocumentKind). Решить, нужен ли termination-документам approval вообще; если нет — освободить их от требования submit→route.

---

### MAJOR 10 — Валюта позиции не сохраняется: saveCurrency мутирует только локальный ref

**Severity: major · Тип: BUG · Проверка: ✅ подтверждено (статика + неподключённый родитель)**

**Файлы:**
- `front/src/pages/DocumentPage/components/DocumentItemsTab.vue:207-210` (saveCurrency: только `currency.value=val`, мёртвый комментарий `// emit for autosave`)
- `front/src/pages/DocumentPage/components/DocumentItemsTab.vue:179-181` (declared totalsChange emit — не вызывается для currency)
- `front/src/pages/DocumentPage/components/DocumentItemsTab.vue:89` (@update:model-value=saveCurrency)
- `front/src/pages/DocumentPage/index.vue:107-115` (DocumentItemsTab смонтирован БЕЗ @totals-change / @update:currency)

**Что происходит:** `saveCurrency` не emit-ит и не PATCH-ит документ; родитель к тому же не слушает событие. Смена валюты документа в UI теряется на reload, а новые строки снапшотят старую `doc.currency` (`DocumentService.addItem:182`).

**Repro:** Открыть позиции, сменить валюту → reload → валюта откатилась; строки используют старую валюту.

**Предлагаемый фикс:** Emit-ить totalsChange/currency (родитель уже умеет autosave) или PATCH `/api/documents/{id}` с новой валютой; убрать вводящий в заблуждение комментарий.

---

### minor / trivial (не верифицировано — Phase-1)

- **minor · BUG** — `ApprovalRoutesPage` рендерит `template_code`, которого `ApprovalRouteResource` не отдаёт → колонка всегда пуста; нет UI для template_id binding. Фикс: выставить template_code (whenLoaded) или убрать колонку + добавить выбор template_id в drawer.
- **minor · CONVENTION** — `showApproval` (`DocumentApprovalController.php:106,112`) делает inline `in_array($user->role->value,['admin','lawyer'])` вместо Policy. Нарушает ARCHITECTURE.md §3. Фикс: `ApprovalPolicy::view` (approver OR admin/lawyer) + `$this->authorize('view', $approval)`.
- **minor · BUG** — вложения: имя загрузившего и размер файла не отображаются (`DocumentAttachmentResource` отдаёт uploaded_by_user_id/path без resolved name и size). Фикс: выставить uploaded_by full_name (whenLoaded) + size (filesize), выровнять FE.
- **minor · BUG** — `MessageTemplatesPage` над-ограничена `['admin','lawyer']` (`base.ts:170`), а `MessageTemplatePolicy.viewAny` (`:20-24`) допускает director/manager. Фикс: добавить director/manager в router-роли на read, CRUD оставить гард-нутым.
- **minor · DEAD-CODE** — Duplicate-заглушка (`DocumentPage/index.vue:265`, no-op), `deleteMessageTemplate` API без Delete-кнопки, GET `/message-templates/context` без FE-caller. Фикс: реализовать Duplicate или убрать пункт меню; подключить/удалить delete и `/context`.
- **minor · DATA-INCONSISTENCY** — двойной источник роли: `users.role` (зеркало, `0001_01_01_000000_create_users_table.php:23`) + spatie `roles`/`model_has_roles`; политики читают зеркало. Фикс: выбрать один авторитет; если `users.role` — read-путь, добавить observer-синхронизацию + тест.
- **minor · SPEC-DRIFT** — `CreateDocumentDialog` хардкодит списки продуктов/стран вместо каталога `/api/admin/products` и справочника стран (commit e29b081). Фикс: тянуть опции из каталога и справочника стран.
- **trivial · BUG** — FE `canUnsign = isAuthorOrPrivileged` шире BE `DocumentPolicy.unsign` (admin/lawyer only, `:122`) → author видит Unsign, получает 403. Фикс: сузить FE canUnsign до admin/lawyer.

### Связанные NEW-* из live-QA

- **🌐 B.6 / «no template_versions»** — подтверждает BLOCKER 1: все 6 шаблонов «Нет версий», генерация невозможна (источник = live-QA).
- **🌐 NEW-5 (P1)** — под manager1 200-ответ от `/api/admin/*` (включая acquisition-channels, disconnect-reasons). Это CRM-каталоги (формально не наш домен), но тот же класс дыры «нет role-gate на admin-ресурсах», что и documents.index — отметить при фиксе RBAC (источник = live-QA).
- **🌐 NEW-4 (P1)** — `Route [login] not defined` 500 при запросе без Bearer (раскрытие stack-trace) — кросс-доменный (auth-middleware), затрагивает и documents-эндпоинты; фикс в `bootstrap/app.php` (источник = live-QA).

## 7. Расхождения со спекой (vault) и предложения по актуализации

| Документ vault | Спека говорит | Реальность | Предложение |
|---|---|---|---|
| Спринт 2 — S2.4 / S2.6 (attempt) | S2.4: каждая генерация создаёт ревизию с attempt++, и это НЕ то же, что attempt в машине согласования. S2.6: attempt = max(DocumentRevision.attempt), резолвится при submit/decide | Оба `ContractGenerationService.createRevision` и `DocumentService.createRevisionSnapshot` инкрементят ОДНУ `document_revisions.attempt`; `ApprovalService.currentAttempt` = max(attempt) → две сущности, которые спека требует разделить, слиты | Разрешить противоречие в спеке: отдельная колонка для раунда согласования (approval_attempt) vs generation-sequence; ИЛИ зафиксировать, что генерация НЕ бампает attempt (только version_number). Документировать единый источник currentAttempt |
| Contracts — Документы / S2.6 (DocumentKind / route kind) | DocumentKind = Contract/Invoice/Act/Reconciliation; ApprovalRoute.document_kind принимает значения enum | Live-документы включают kind=termination_agreement (8,9,10) с отдельным TerminationDocumentController, но валидация route запрещает этот kind | Добавить termination_agreement в документированный набор DocumentKind и в допустимые kind ApprovalRoute, ЛИБО явно описать, что termination минует движок согласования (+ кодовый путь для этого) |
| Contracts — Документы / S2.2 (видимость документов) | DocumentPolicy header: «manager/accountant/cfo see own documents only» | `DocumentService.list` без author-scoping, viewAny=true, FE-роуты без role-guard → managers видят ВСЕ документы | Документировать правило list-scoping + добавить в acceptance-чеклист; обновить комментарий политики под фактическое поведение после фикса |
| Спринт 2 — Документы (план) | Модули S2.1–S2.8 status=done | Генерация невозможна live (нет template_versions, current_version_id=NULL); approvals=0, items=0, numbering=0 — домен не отрабатывал end-to-end; «approved» строки демо-сидер в обход гардов | Ввести live-readiness-гейт: реальный master_skeleton.docx загружен и один документ прошёл generate→submit→approved ДО маркировки спринта operationally done; понизить «done» до «built, not verified live» |

**Дополнительно для актуализации (из верификации):**
- В «5. Планы» отметить, что блокер генерации — **by design сидера** (`TemplateSeeder.php:86` хардкодит null, `DemoDocumentsSeeder`/`DemoDealsSeeder` версию не создают), а не транзиентная среда; нужен сидер `template_versions` или ручная заливка как часть live-readiness.
- В «2. Модули» зафиксировать класс дефекта **unscoped nested route-model binding** (IDOR) как системный риск домена — он повторяется на items/remarks/revisions/attachments; рекомендовать `Route::scopeBindings()` как стандарт для всех вложенных групп.
