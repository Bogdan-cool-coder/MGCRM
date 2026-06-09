# DOCUMENTS.md — Раздел «Документы» (генератор PDF/Word)

> Single source of truth по новому разделу. Лежит рядом с `PROJECT.md` / `FRONTEND.md` / `chats_frontend.md`.
> Деплой-порядок, ACL, entity-паттерны и AI-флоу наследуют соглашения из `PROJECT.md` — этот файл только дополняет, не дублирует.

---

## Контекст и назначение

В Vizion три раздела работы с данными MacroData:

| Раздел | Сущность | Суть |
|---|---|---|
| **Отчёты** | `Report` | Таблицы с данными, фильтры, пагинация |
| **Дашборды / Виджеты** | `Widget` + `Dashboard` | Агрегаты + ECharts |
| **Документы** ← новый | `DocumentTemplate` + `GeneratedDocument` | Генератор PDF/Word из MacroData-данных |

**Цель:** пользователь выбирает шаблон, система тянет поля из MacroData клиента, подставляет их и отдаёт готовый файл на скачивание.

### Два типа документов

**`html`** — красивые коммерческие предложения (КП) на конкретный объект недвижимости:
- Брендинг клиента: логотип, палитра, шрифты, шапка/подвал, реквизиты
- Данные объекта (EstateSells + связанные) из MacroData
- Калькулятор акций/скидок (таблица `promotions`)
- Экспорт в PDF через Gotenberg (Chromium HTML→PDF)
- Аналог отчётов: системный засиженный шаблон **или** кастомный через AI

**`docx`** — Word-шаблон с плейсхолдерами:
- Клиент загружает `.docx` с плейсхолдерами вида `${field}`
- Система подставляет данные через `phpoffice/phpword TemplateProcessor`
- Результат: docx + pdf (конвертация через Gotenberg LibreOffice)
- Модалка-справочник доступных полей по «?»
- AI-фича: нейронка читает загруженный docx и предлагает маппинг `плейсхолдер → поле MacroData`

### Референс-платформа

`contracts.macroglobal.tech`: docx-шаблон → PHPWord подстановка → Gotenberg конвертация → скачивание docx + pdf.
Наша надстройка: HTML-КП с брендингом + AI-генерация — оригинальная фича, у референса её нет.

---

## Зафиксированные решения

- **Рендер-движок:** Gotenberg (отдельный Docker-контейнер `gotenberg/gotenberg:8`, REST API). HTML→PDF через Chromium, docx→PDF через LibreOffice. `GOTENBERG_URL=http://gotenberg:3000` в `.env` / `config/services.php`.
- **Word-подстановка:** `phpoffice/phpword` `TemplateProcessor` (`setValue`, `cloneRow` для табличных циклов). Добавляется в `composer.json` на M5.
- **Порядок фаз:** Фаза 1 = HTML-КП (M0–M4). Фаза 2 = Word + AI (M5–M8).
- **AI в Word-типе:** реализуется внутри Фазы 2, не откладывается на отдельную итерацию.
- **Брендинг:** полный бренд-эдитор в настройках компании (под-секция), per-company, таблица `company_brandings`.
- **Генерация файлов:** async через `GenerateDocumentJob` (queue `default`), файлы на disk `documents` (`config/filesystems.php`, `driver=local`, `visibility=public` → 0644/0755, не веб-публичный). Статус отслеживается через `GET /api/documents/generated/{id}`. Disk `documents` — отдельный от `local` чтобы изолировать `visibility=public` (нужен для чтения www-data файлов, записанных root в queue-worker) без изменения дефолтного `local`-диска.
- **AI-chat:** новый `type='document_template'` + `scope_type='document'` + `chats.document_id`. `DocumentTool` — зеркало `WidgetTool`. SSE-событие `document_fields_proposed`.
- **Диск:** перед добавлением Gotenberg-образа (~89% заполненности сервера) — `data-steward` чистит место.
- **Managed-окружения:** деплой docker-compose с Gotenberg на `devizion` / `vizion.macroglobal.tech` — **только по явной просьбе** (backlog `deploy_compose_not_synced`).

---

## Новые сущности и миграции

Эталон полей/индексов: `src/database/migrations/2026_03_16_051957_create_reports_table.php` и миграции `widgets`/`dashboards` от 2026-05-24.

### `document_templates` (config-entity, зеркало `widgets`)

```
id
company_id            FK → companies, cascadeOnDelete
user_id               nullable, FK → users, nullOnDelete; null = системный шаблон
name                  jsonb (translatable: {ru, en})
description           jsonb nullable (translatable)
type                  varchar(8), enum: 'html' | 'docx'
config                jsonb — конфигурация шаблона (поля, маппинги, настройки рендера)
source_path           varchar nullable — путь к загруженному docx на disk local
is_system             bool default false
is_published          bool default false
sort_order            int nullable
chat_message_id       nullable FK → chat_messages, nullOnDelete
metadata              jsonb nullable — AI pipeline flags
created_at
updated_at
```

Индексы: `[is_system, company_id]`, `[user_id, company_id]`, `[company_id, type]`.

Скоупы модели (по образцу `Widget`): `scopeSystem`, `scopeUserCreated`, `scopePublished`, `scopeForCompany`, `scopeForUser`.

### `generated_documents` (история генераций)

```
id
document_template_id  FK → document_templates, cascadeOnDelete
company_id            int  — намеренно без FK (snapshot-таблица; компания может быть удалена без потери истории генераций)
user_id               nullable int
title                 varchar — человекочитаемое название (объект + шаблон)
params                jsonb — estate_sell_id, promotion_id, discount, снапшот подставленных значений
status                varchar: 'pending' | 'processing' | 'done' | 'error', default 'pending'
pdf_path              varchar nullable
docx_path             varchar nullable
error                 text nullable
created_at
updated_at
```

Файлы хранятся на disk `documents` (`config/filesystems.php`; root = `storage/app/private`, не веб-публичный). Повторное скачивание через Sanctum Bearer + read-ACL шаблона (`Storage::disk(config('documents.disk'))->download()`).

### `company_brandings` (one-to-one с company)

```
id
company_id            unique FK → companies, cascadeOnDelete
logo_path             varchar nullable — путь к логотипу на disk public
colors                jsonb — {primary, secondary, accent, text, bg}
fonts                 jsonb — {heading, body}
header                jsonb nullable (translatable) — текст шапки
footer                jsonb nullable (translatable) — текст подвала
requisites            jsonb nullable — реквизиты компании
updated_by            nullable int
created_at
updated_at
```

### `promotions` (акции/промо, per-company)

```
id
company_id            FK → companies, cascadeOnDelete
name                  jsonb (translatable: {ru, en})
description           jsonb nullable
discount_type         varchar: 'percent' | 'absolute'
discount_min          decimal  — намеренно decimal (промо = Vizion-конфиг, не MacroData-деньги; стандарт «деньги в int» к акционным % не применяется)
discount_max          decimal
is_active             bool default true
sort_order            int nullable
created_by            nullable int
created_at
updated_at
```

Индекс: `[company_id, is_active]`.

### `chats.document_id` (Фаза 2, AI)

Nullable FK → `document_templates`, nullOnDelete. Добавить в `$fillable`, метод `document()` belongsTo, ветка в `Chat::scopeForScope()`. Относится к M7.

### Модели

`DocumentTemplate`, `GeneratedDocument`, `CompanyBranding`, `Promotion`:
- `declare(strict_types=1)` во всех новых файлах
- `HasTranslations` там, где есть translatable jsonb-поля (`name`, `description`, `header`, `footer`)
- `$fillable` + `casts()` обязательны

---

## Backend-компоненты

### ACL и контроллеры (`backend-specialist`)

**`DocumentController`** — структурный близнец `WidgetController`:
- `index` — role-visibility (системные + опубликованные + свои)
- `show`, `store`, `update` (отклоняет `is_system`), `destroy`
- `publish` / `unpublish` — admin компании / superadmin; системные отклоняются
- `canWrite()` helper, `buildDocumentPayload()` tight-проекция ответа
- ACL: подключить `AssertsConfigEntityReadAccess` напрямую (не плодить обёртку)
- Active company — из middleware (`$request->attributes->get('active_company_id')`), не из query-param

**Генерация и скачивание:**

| Method | URL | Auth | Description |
|---|---|---|---|
| POST | `/api/documents/{id}/generate` | Sanctum | Создаёт `GeneratedDocument(pending)` + диспатчит `GenerateDocumentJob` → 202 + `{generated_document_id, message}` |
| GET | `/api/documents/generated/{id}` | Sanctum | Статус генерации (`status`, `pdf_path`, `docx_path`, `error`) |
| GET | `/api/documents/generated/{id}/download` | Sanctum | Скачать файл (`?format=pdf\|docx`); `format=docx` → 409 если `docx_path` null (html-генерация). ACL → `Storage::disk('documents')->download()` |
| POST | `/api/documents/{id}/preview-html` | Sanctum | Синхронный HTML-рендер КП (без Gotenberg, без `GeneratedDocument`). Body: `{estate_sell_id?, promotion_id?, discount?, locale?}`. Ответ: `{html: string}`. ACL идентичен `show()` — viewer включён. |

**Docx source-file и поля (добавлено в M5/M6):**

| Method | URL | Auth | Description |
|---|---|---|---|
| POST | `/api/documents/{id}/source-file` | Sanctum | Загрузить `.docx` (multipart, поле `file`, max 10 MB). Write-ACL = update-ACL. 403 для системных. 422 для html-типа или нон-`.docx`-расширения. Ответ: `{message, source_path}` |
| GET | `/api/documents/{id}/placeholders` | Sanctum | `${...}` токены из загруженного `.docx`. 422 если source_path отсутствует. Ответ: `{placeholders: string[]}` |
| GET | `/api/documents/field-catalog` | Sanctum | Справочник подставляемых полей: `{groups: {object: [...], branding: [...], discount: [...]}}`. Только auth + company.access (viewer OK). Source of truth — `config/documents.php['field_catalog']`. Маршрут регистрируется **до** `apiResource('documents')` |

**Валидация source-file upload:**
- `mimetypes` whitelist: `application/vnd.openxmlformats-officedocument.wordprocessingml.document`, `application/zip`, `application/octet-stream` (libmagic в контейнере может не знать docx mime → octet-stream — допустимо; битый ZIP отвалится в PHPWord при генерации).
- Extension guard (после validate): `getClientOriginalExtension() !== 'docx'` → 422 с `documents.must_be_docx`. Заменяет `mimes:docx` (finfo-зависимый, сбоит на контейнере без docx в mime.types).
- `max:10240` (KB → 10 MB).

⚠ Все literal-пути (`/documents/field-catalog`, `/documents/generated/...`, `/documents/{id}/source-file`, `/documents/{id}/placeholders`) регистрировать **до** `apiResource('documents', ...)`, иначе `{id}` перехватит слово.

**Сидер `DocumentTemplateSeeder`:**
- Idempotent upsert по `name->ru` + `is_system=true` (паттерн `ReportSeeder`)
- `sort_order` в массиве
- 1–2 системных HTML-КП шаблона на M1

### Рендер-движок (`backend-specialist` + `deploy-engineer`)

**`App\Services\Documents\GotenbergClient`** — HTTP-клиент к Gotenberg:
- `htmlToPdf(string $html, array $assets, array $opts): string` — POST `/forms/chromium/convert/html`, multipart с `index.html` + ассеты (логотип, шрифты)
- `officeToPdf(string $docxPath): string` — POST `/forms/libreoffice/convert`
- `GOTENBERG_URL` в `.env`, читается через `config/services.php`

**`App\Services\Documents\HtmlDocumentService`** — сборщик HTML:
- Принимает: конфиг шаблона + данные объекта MacroData + `CompanyBranding` + применённую скидку
- Многостраничность через CSS `@page` / page-breaks
- Возвращает HTML-строку (используется и для превью, и для Gotenberg)

**`App\Services\Documents\DocxTemplateService`** (реализовано в M5):
- `fill(sourceDocxPath, data, fieldMapping, targetPath?)` — заполняет все токены шаблона через `TemplateProcessor::setValue()`. Разрешение: `fieldMapping[token]` → `data[mapped]`, затем `data[token]`, иначе пустая строка (не 422).
- `extractPlaceholders(docxPath)` — возвращает `string[]` токенов (без `${}`). Используется `GET /api/documents/{id}/placeholders` и M7 AI flow.
- `fillTable(sourceDocxPath, rowKey, rows, data, fieldMapping, targetPath?)` — `cloneRow` + построчный setValue. Пути: `${rowKey}#N`. Не покрыт тестами (tech-debt).
- Все методы выбрасывают `RuntimeException` при отсутствующем файле. Пустые/null-значения → пустая строка (никаких сырых `${...}` в результате).

**`GenerateDocumentJob` docx-ветка:**
- Проверяет `source_path` наличие → `RuntimeException` (без него `status=error`, не silent empty).
- `file_get_contents($filledDocx) === false` → `RuntimeException` (ранее возможен тихий empty-file).
- Merges data: `renderData + docxBrandingValues + docxDiscountValues` → передаёт в `fill()`.
- Branding в docx: только текст (`${brand_header}`, `${brand_footer}`, `${req_<ключ>}`). Логотип/палитра не подставляются.

**`GenerateDocumentJob`** (queue `default`):
- `ConnectionService::connect(company)` → собрать данные объекта → render (html/docx ветки) → сохранить файлы → `GeneratedDocument(done, pdf_path, docx_path)`
- On fail: `status=error`, сообщение в `error`

### MacroData-компоненты (`macrodata-engineer`)

**Поиск объекта для КП:**

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/macrodata/estate-sells/search` | Sanctum | `?q=&limit=` → `[{value: estate_sell_id, label: "кв.45, ЖК X, 65м²"}]` |
| GET | `/api/macrodata/estate-sells/{id}` | Sanctum | Envelope `{data: {<25 fields>}, label: string}` — поля объекта + читаемое имя |
| GET | `/api/macrodata/schema` | Sanctum | `?model=EstateDeals` → `{model, table, fields: [{name, type}]}`; 422 при модели вне whitelist, 503 при недоступном MacroData |

Реализация `search`: `ConnectionService::connect()` + `EstateSells` query (`geo_flatnum`, `with('estateHouses.geoCityComplex')`) → форматированные лейблы. Существующий `filter-options` привязан к `report.id` — для документов нужен независимый endpoint.

Реализация `schema`: `DB::connection('macrodata')->getSchemaBuilder()->getColumnListing($table)` после `connect()` + `$model->casts()` для типов. Опционально: artisan `macrodata:export-schema --json` для статического реестра.

**Новые поля объекта для КП:** `estate_price`, `estate_area`, `estate_floor`, `estate_restoration_price`, адрес через `estateHouses.geoCityComplex.geo_complex_name`, договор через `estateDeals.agreement_number`.

Per-company справочники (типы отделки, статусы) — при необходимости через `ConfigResolver` + новые `semantic_key` в `company_macrodata_mappings` (без правки сервиса).

### Брендинг (`backend-specialist`)

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/companies/{id}/branding` | Sanctum | Получить брендинг компании |
| PUT | `/api/companies/{id}/branding` | Sanctum | Обновить брендинг (admin своей компании / superadmin) |
| POST | `/api/companies/{id}/branding/logo` | Sanctum | Загрузить логотип → disk `public` |

ACL: admin своей компании / superadmin любой. Analyst/viewer — read-only (нужно для рендера КП).

### Акции/промо (`backend-specialist`)

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/promotions` | Sanctum | Список акций активной компании |
| POST | `/api/promotions` | Sanctum | Создать акцию (admin/superadmin) |
| GET | `/api/promotions/{id}` | Sanctum | Одна акция |
| PUT | `/api/promotions/{id}` | Sanctum | Обновить (admin своей / superadmin) |
| DELETE | `/api/promotions/{id}` | Sanctum | Удалить (admin своей / superadmin) |

Применение скидки при генерации: `params.promotion_id` + `params.discount` валидируется в диапазоне `[discount_min, discount_max]` промо. Analyst/viewer задают `discount` в диапазоне; admin редактирует сами промо.

### Инфра (Milestone 0, `deploy-engineer` + `data-steward`)

- `data-steward` чистит место на диске перед pull Gotenberg-образа (~89%)
- `docker-compose.yml`: новый сервис `gotenberg` (`gotenberg/gotenberg:8`, bind `127.0.0.1`, внутренняя сеть `vizion-network`); `app` и `queue-worker` получают `GOTENBERG_URL=http://gotenberg:3000`
- `composer.json`: `phpoffice/phpword` добавляется на M5 (Фаза 2; Фаза 1 — только Gotenberg HTTP)
- Локальный rebuild `vizion.lazarewww.ru` (app + gotenberg) + полный пересид (правило `local_reseed_after_rebuild`)
- Деплой на managed-окружения (`devizion` / `vizion.macroglobal.tech`) — **только по явной просьбе пользователя** (backlog `deploy_compose_not_synced`)

---

## AI-слой (Фаза 2, `chat-ai-engineer`)

Зеркало `widget_generation`. Все точки интеграции — по аналогии с существующим `WidgetTool`.

### Интеграция в Chat

- `Chat::SCOPE_DOCUMENT = 'document'` + добавить в массив `SCOPES`
- `chats.document_id` nullable FK → `document_templates`, nullOnDelete
- Ветка в `Chat::scopeForScope()`
- Бэк-линк в `ChatService::runForJob()` (рядом с widget_generation-веткой)
- Новый `type='document_template'` — добавить ветку в `ChatService::runForJob()` (toolset + prompt)
- Валидация `type` в `ChatController` (4 точки проверки: `scope_type`, `required_if document_id`)

### DocumentTool (`App\Services\AI\DocumentTool`)

Зеркало `src/app/Services/AI/WidgetTool.php`.

Три инструмента:

**`probe_data`** — переиспользуется verbatim из `ReportTool` / `WidgetTool`. Никаких правок.

**`propose_document_fields`** (двухшаговый, Фаза 2 — Word):
- По тексту загруженного docx + списку токенов предлагает маппинг `плейсхолдер → поле MacroData`
- Валидирует существование поля через reflection (как `prevalidateRelationAggregates`)
- Эмитит SSE `document_fields_proposed`; payload: `{placeholders: [{token, suggested_field, model, source, confidence}]}` — `source` = `catalog` | `macrodata`
- Аналог `propose_widget_variants`

**`generate_document_template`** (HTML-КП через AI):
- Создаёт / обновляет `DocumentTemplate` с конфигом
- Dry-run валидация после сохранения
- Семантический retry с лимитом (аналог `ReportTool`)
- Аналог `create_report`

### SSE-событие

`ChatMessageEvent::TYPE_DOCUMENT_FIELDS_PROPOSED = 'document_fields_proposed'` + добавить в `ChatEventEmitter::ALLOWED_TYPES`.

Payload: `{placeholders: [{token, suggested_field, model, confidence}]}`.

Добавить `case` в `ChatService::summariseToolCallArguments()`, иначе событие будет пустым на стрим-провайдере.

### Каскад AI

`config/ai.php` — ключ `'document_template'` в обоих провайдерах (glm + anthropic), копия `report_generation`-каскада. `context_overflow_fallback` держать включённым — инжект docx-текста риск overflow на GLM 128K.

### Извлечение текста docx (`DocxTextExtractor`)

Класс `App\Services\Documents\DocxTextExtractor` — лёгкий unzip `word/document.xml` + strip XML (без PHP-зависимости; phpoffice/phpword задействован только для `DocxTemplateService`).

Константы (контроль размера для context overflow):
- `CONTEXT_RADIUS = 160` — символов контекста вокруг каждого токена `${...}` в обе стороны
- `MAX_TOTAL_CHARS = 6000` — суммарный лимит текста, инжектируемого в AI-запрос

AI важен **контекст вокруг плейсхолдера**, не весь документ — извлекается абзац вокруг каждого токена.

---

## Frontend (`frontend-specialist`, по явной просьбе; `qa-tester` после каждого UI-milestone)

Архитектурная цепочка (как в Reports / Dashboards):

```
route → page (index.vue тонкий) → composables/use<X>Page
  → services/<X>Service → api/<x>.ts + api/types/<x>.ts
    → entities/<x>/{types,mappers,index}
```

### Навигация и доступ

**Роуты** (`front/src/router/routes/base.ts`):
- `/documents` → `DocumentsPage`
- `/documents/:id` → `DocumentPage`
- `meta: { requiresAuth, requiresCompanyScope }`, до catch-all

**Пункт меню** — `front/src/components/Toolbox/Toolbox.vue` (`navItems`, иконка `pi-file` / `pi-file-pdf`) + ключи i18n `Toolbox/locale/{ru,en}.json`.

**Capabilities** (`front/src/shared/auth/capabilities.ts`):

| Capability | Описание |
|---|---|
| `canManageDocuments` | Создание / редактирование шаблонов (analyst+) |
| `canManageDocumentPublication(role)` | Publish / unpublish шаблона (admin+) |
| `canDeleteDocument(role, isOwner, isSystem)` | Копия `canDeleteReport` |
| `canManageBranding` | Редактирование брендинга (admin+) |
| `canManagePromotions` | CRUD акций (admin+) |
| `canSetDiscount` | Установить скидку в диапазоне промо (все роли) |

### Страницы — Фаза 1 (HTML-КП)

**`DocumentsPage`** (`/documents`):
- Трёхсекционный split: системные шаблоны / опубликованные / личные
- Фильтр по типу (`html` / `docx`)
- Реактивен к активной компании (`useScopedResource` + `useCompanySelection`)
- Паттерн: `composables/useDocumentsPageData.ts` (аналог `useDashboardsPageData.ts`)

**`DocumentPage`** (`/documents/:id`) — html-флоу:
- Async-поиск объекта (через `GET /api/macrodata/estate-sells/search`)
- Калькулятор акций: выбор промо + слайдер/инпут скидки в диапазоне промо
- Брендинг применяется автоматически
- HTML-превью: sandboxed `<iframe :srcdoc>`
- Кнопка «Сгенерировать → Экспорт PDF» → polling статуса → blob-скачивание
- Кнопка-шестерёнка → redirect в настройки акций

**Настройки компании:**
- Под-секция **Брендинг**: логотип (`FileUpload` custom-uploader → `apiClient` FormData), палитра (color-pickers), шрифты, шапка/подвал, реквизиты
- Под-секция **Акции**: CRUD-таблица промо (admin only)

**Greenfield-утилиты:**
- `front/src/utils/fileDownload.ts` — blob download: `apiClient.get(url, {responseType: 'blob'})` → `createObjectURL` → click `<a download>` → `revokeObjectURL`
- Загрузка файлов: `FileUpload` custom-mode + `apiClient` с Bearer

### Страницы — Фаза 2 (Word-тип)

**`DocumentPage` docx-флоу:**
- Загрузка docx-шаблона (`FileUpload` custom-uploader через `apiClient`, поле `file`) — только для `canManageDocuments && !isSystem`
- UI маппинга плейсхолдеров: таблица токенов с Select из каталога (grouped по bucket). Авто-маппинг при загрузке. `req_*` — wildcard, исключён из select. Сохранение через `PUT /api/documents/{id}` с `config.field_mapping`
- Модалка-справочник полей по «?» (`FieldCatalogModal`) — данные из `GET /api/documents/field-catalog` (НЕ `/api/macrodata/schema`)
- Генерация → polling → скачивание docx + pdf (`download?format=docx|pdf`)
- viewer (и любой без `canManageDocuments`) без source: заглушка «нет шаблона»; при наличии source — только объект + скидка + генерация + скачивание

**Composables (docx):**
- `useDocumentDocx` — field catalog, placeholders, mappingDraft, uploadSource, catalogModal. Гейтирован на `isDocx && canManage`.
- `useDocumentPage` — оркестратор: собирает `useDocumentPageData` + `useDocumentDocx` + `useDocumentPageActions`; вычисляет `isDocx`, `canManage`.

**`config.field_mapping`:** jsonb-объект внутри `document_templates.config`. Ключи — токены без `${}`, значения — ключи каталога. При генерации `GenerateDocumentJob` передаёт маппинг в `DocxTemplateService::fill()`.

### AI-интеграция (Фаза 2)

- **`DocumentGenerationModal`** + store `stores/documentGenerationModal.ts` — зеркало `ReportGenerationModal` + `stores/reportGenerationModal.ts`. Монтируется в `layouts/DefaultLayout/index.vue`. Lazy-create через `POST /api/chats/messages` с `type='document_template'`, `scope_type='general'` (HTML-КП флоу). Навигация на созданный шаблон через `router.push('/documents/{id}')` после settle.
- **`composables/useDocumentGenerationModalChat.ts`** — state machine модалки, зеркало `useWidgetGenerationModalChat`. Tracking `createdDocumentId` через `currentChat.documentId` после каждого settle.
- Контекст документа для mini-chat: `stores/documentContext.ts` — хранит snapshot открытой страницы документа (`documentId`, `type`, `title`, `placeholderCount`, `mappedCount`); ветка `'document'` в `composables/useMiniChat.ts` (resume + sendInline с `document_id`).
- SSE `document_fields_proposed` — rобрабатывается в `composables/useDocumentFieldsProposal.ts`. Payload валидируется через `parseProposalsPayload`; только записи с непустыми `token` + `suggested_field` добавляются в `proposals`. Рендер: `DocumentFieldsProposedPanel.vue` + `DocumentFieldProposalCard.vue` (зеркало `WidgetVariantsPanel.vue`). Принятие маппинга → `PUT /api/documents/{id}` с `config.field_mapping`. `useChatStream.ts` переиспользуется как есть (добавлен `'document_fields_proposed'` в `TYPED_EVENT_TYPES`).
- Action-marker `redirect_to_document_generation` зарегистрирован в `useChatActionMarker.ts`. **Статус: dormant** — backend (M7) ещё не эмитит этот маркер; ветка future-ready.

### i18n

`pages/DocumentsPage/locale/{ru,en}.json`, `pages/DocumentPage/locale/{ru,en}.json` через `useLocalI18n`. Симметрия RU ↔ EN. Нет hardcoded текста.

---

## Матрица ролей

| Действие | viewer | analyst | admin | superadmin |
|---|---|---|---|---|
| Просмотр / скачивание из системного/опубликованного шаблона | ✅ | ✅ | ✅ | ✅ |
| Установить скидку в диапазоне промо | ✅ | ✅ | ✅ | ✅ |
| Создать / редактировать шаблон (в т.ч. через AI) | ❌ | ✅ (свои) | ✅ (компания) | ✅ (все) |
| Загрузить docx-шаблон | ❌ | ✅ | ✅ | ✅ |
| Publish / unpublish шаблона | ❌ | ❌ | ✅ (компания) | ✅ |
| Редактировать акции (промо) | ❌ | ❌ | ✅ (компания) | ✅ |
| Редактировать брендинг компании | ❌ | ❌ | ✅ (компания) | ✅ |
| Системные шаблоны (`is_system=true`) | read-only для всех; редактирование = клонировать |

---

## Milestones

Каждый milestone завершается `product-manager` review. После UI-milestone'ов — `qa-tester` на `vizion.lazarewww.ru`. Деплой/push — только по явной просьбе.

| Milestone | Описание | Агенты |
|---|---|---|
| **M0** | Инфра / deps: `data-steward` чистит диск → Gotenberg в `docker-compose.yml` + `GOTENBERG_URL` → локальный rebuild + пересид | `data-steward` + `deploy-engineer` |
| **M1** | Фундамент backend: миграции (4 таблицы) + модели + `DocumentController` + `GotenbergClient` + `HtmlDocumentService` + `GenerateDocumentJob` + generate/download endpoints + routes + `DocumentTemplateSeeder` + тесты | `backend-specialist` |
| **M2** | Брендинг + MacroData-поиск: `CompanyBranding` + контроллер + загрузка логотипа; estate-sells search / detail + schema endpoint | `backend-specialist` + `macrodata-engineer` |
| **M3** | Акции backend: `Promotion` CRUD + ACL + валидация диапазона скидки + применение в `HtmlDocumentService` | `backend-specialist` |
| **M4** | Фронт фундамент + HTML-КП: route / nav / capabilities / i18n → `DocumentsPage` → `DocumentPage` html-флоу → бренд-эдитор + акции в настройках → `fileDownload` util → qa-tester | `frontend-specialist` → `qa-tester` |
| **M5** | Word backend: `phpoffice/phpword` в composer + локальный rebuild → `DocxTemplateService` + загрузка docx + `source_path` + docx-fill + docx→pdf через Gotenberg + тесты | `deploy-engineer` + `backend-specialist` |
| **M6** | Word фронт + backend-добавления: загрузка docx (source-file endpoint), UI маппинга (`config.field_mapping`), модалка-справочник полей (`FieldCatalogModal` + `GET /api/documents/field-catalog`), генерация → скачивание docx + pdf, `CreateDocumentDialog`, viewer-ограничение, 3 bugfix (config `present\|array`, `file_get_contents` → RuntimeException, docx mime extension-guard). qa-tester PASS. | `frontend-specialist` + `backend-specialist` → `qa-tester` |
| **M7** | AI backend: `chats.document_id` + `scope='document'` + `type='document_template'` + `DocumentTool` (probe_data + propose_document_fields + generate_document_template) + извлечение текста docx + SSE event + каскад `ai.php` + `ChatController`-валидации | `chat-ai-engineer` |
| **M8** | AI фронт: `DocumentGenerationModal` + store `documentGenerationModal` + `documentContext` store + `useDocumentGenerationModalChat` + `useDocumentFieldsProposal` + `DocumentFieldsProposedPanel` / `DocumentFieldProposalCard` + `useMiniChat` document-ветка + `useChatStream` `document_fields_proposed` в `TYPED_EVENT_TYPES` + action-marker `redirect_to_document_generation` (dormant) → qa-tester PASS | `frontend-specialist` → `qa-tester` |

---

## Verification (чеклист на каждом milestone)

### Backend (M1–M3, M5, M7)

```bash
docker compose exec app php artisan test
# sqlite :memory:, тройная изоляция от live PG
```

Feature-тесты:
- ACL: viewer не может создать/удалить; analyst только свои; системные шаблоны отклоняются
- Generate → download флоу (smoke: `GeneratedDocument(done)`, `pdf_path` не пуст)
- Gotenberg smoke: `htmlToPdf()` → `Content-Type: application/pdf`, `size > 0`
- docx→pdf аналогично (M5)

Локальный PG-стек (`vizion.lazarewww.ru`) для проверки ORDER BY / JOIN (sqlite-зелёные ≠ PG-зелёные).

### MacroData (M2)

- `estate-sells/search?q=` возвращает объекты реальной компании
- `estate-sells/{id}` отдаёт все нужные поля для подстановки
- `schema?model=EstateDeals` возвращает колонки + типы

### Фронт / UX (qa-tester на vizion.lazarewww.ru после M4, M6, M8)

**M4 happy path:**
1. Залогиниться → `/documents` виден в меню (по роли)
2. Открыть системный HTML-КП → выбрать объект → выставить скидку → превью рендерится
3. Экспорт PDF → файл скачивается
4. Брендинг: загрузить логотип, поменять палитру → КП меняет вид
5. Акции: admin создаёт промо → analyst видит и ставит скидку в диапазоне

**M6 happy path:**
1. Создать docx-шаблон через «+ Создать» → открыть его
2. Загрузить `.docx` с `${field}` → таблица маппинга появляется, авто-маппинг срабатывает
3. Открыть справочник по «?» → список полей 3 групп
4. Сохранить маппинг → сгенерировать → скачать docx и pdf
5. Viewer: видит существующий шаблон, но кнопок загрузки/редактирования нет

**M8 happy path:**
1. Генерация кастомного КП через модалку
2. Word: загрузить docx → AI предлагает маппинг полей (SSE-карточки) → принять → сгенерировать

Роли: прокликать матрицу (viewer download-only, analyst own, admin company, superadmin cross-company).
Собрать console + network ошибки, скриншоты, PASS/FAIL отчёт.

---

## Открытые вопросы (не блокируют старт)

- Email / Telegram / Drive-выгрузка готового документа — отдельная фаза при необходимости
- «Сумма прописью» и авто-подсказки дат в форме КП — nice-to-have
- Генерализация `schema` / `object-search` на произвольную MacroData-модель (сейчас заточено под `estate-sells`)

---

## ⚠ Сверка с PROJECT.md

Расхождений в архитектурных решениях нет. Новые сущности и паттерны органично продолжают зафиксированные соглашения. Ниже — точки, которые **требуют дополнений в PROJECT.md** (тип C — новая фича без записи):

| # | Тип | Что | Предложение |
|---|---|---|---|
| 1 | C | Новый контейнер `gotenberg` не описан в `PROJECT.md §Docker` (таблица контейнеров) | Добавить строку `\| gotenberg \| gotenberg/gotenberg:8 \| internal \| PDF/DOCX renderer (Chromium + LibreOffice) \|` |
| 2 | C | `Application Sections` в PROJECT.md описывает только Chat + Reports | Добавить «3. Documents» — аналогично секции Reports |
| 3 | C | Новые endpoints (Documents, Branding, Promotions, MacroData search/schema) не описаны в `PROJECT.md §API Endpoints` | Добавить таблицы после существующих разделов (Documents / Branding / Promotions / MacroData) |
| 4 | C | Новые сущности (`document_templates`, `generated_documents`, `company_brandings`, `promotions`) не в `PROJECT.md §PostgreSQL Entities` | Добавить блоки по образцу `widgets` / `dashboards` |
| 5 | C | Новый `type='document_template'` и `scope_type='document'` для чатов — `PROJECT.md §Chat` (`chats` таблица) не упоминает эти значения | Дополнить поле `type` и `scope_type` в описании таблицы `chats` |
| 6 | C | `AI Layer` в PROJECT.md не упоминает `DocumentTool` | Добавить строку в список `AI Services` |
| 7 | C | `Installed Packages` не содержит `phpoffice/phpword` | Добавить после M5 (пакет появляется только тогда) |

**Все пункты 1–7 добавлены в `PROJECT.md` в ходе финального doc-sync раздела (2026-05-27).** Расхождений не осталось.
