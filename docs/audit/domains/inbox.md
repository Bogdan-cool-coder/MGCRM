# Аудит домена: Inbox — каналы, входящие сообщения, формы, интейк → Компания+Сделка

## 1. Назначение

Домен **Inbox** (спринт S1.9) — это слой автоматического приёма входящих лидов из внешних источников и их превращения в `Company` + `Deal`. Бизнес-смысл: внешний источник (публичная веб-форма, Telegram/WhatsApp/Email-коннектор, generic webhook) присылает заявку → система логирует её в `inbound_messages`, выполняет дедуп компании по email/телефону (`CompanyService.findForDedup`), при необходимости создаёт компанию, затем создаёт сделку в стадии `code='new'` активной воронки продаж (`DealService.createInbound` + `DealStageHistory` + событие `DealCreated`). Каждый шаг сопровождается администрируемой точкой входа (`Channel`) с дефолтами (воронка/стадия/владелец/источник лида) и опциональной публичной формой (`Form`).

**Зрелость: каркас (backend-complete, frontend-absent, не прогонялся в проде).** Обоснование: backend-конвейер реализован полностью и близко повторяет vault-спеку (edge-cases E1–E10) — три таблицы, три публичных эндпоинта (meta/submit/webhook) и 9 админских эндпоинтов, `InboundRoutingService`, `ChannelService`, `FormService`. **НО:** (а) фронтенд отсутствует целиком — ни одной страницы/роута/nav-пункта/api-модуля/стора в `front/src` не ссылается на эндпоинты Inbox; (б) живые row counts доказывают, что фича никогда не работала end-to-end: `inbound_messages = 0`, `channels = 1`, `forms = 1` (последние два — явно seed/ручной тест). Публичная страница лид-формы — заявленная спекой «core» возможность — не построена и не числится в списке отложенного. Админ-UI каналов/форм/inbox-лога осознанно отложен на спринт интеграций (vault, утверждено 2026-06-12). Итого: код есть, но в продукте домен мёртв — ни оператор, ни анонимный посетитель не могут его коснуться через UI.

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI-экран + endpoint) | Как (кратко) | Статус | Примечание |
|---|---|---|---|---|---|
| Публичный интейк веб-формы → Company+Deal | анонимный посетитель + system (`InboundRoutingService`) | UI: **НЕТ** (публичной формы в SPA нет). `GET /api/forms/public/{slug}` (meta) + `POST /api/forms/public/{slug}/submit` | rate-limit (E5) → форма по slug+is_active (404) → honeypot (E4 тихий OK) → `FormSubmissionValidator` (E7) → стабильный `external_id` (E6) → INSERT `InboundMessage` в try/catch (E1) → `route()` → дедуп/создание Company (E2) → `DealService.createInbound` (стадия `new`) → штамп `routing_status` | 🔴 сломан (как продукт) | Backend готов, но НЕДОСТИЖИМ из любого UI MGCRM. `inbound_messages=0` → ни разу не прогонялся. Корректность backend требует live-curl-пробы. |
| Generic webhook интейк → Company+Deal | внешний коннектор (с `X-Channel-Token`) + system | UI: **НЕТ** (по дизайну — зовут внешние системы). `POST /api/inbox/webhook/{channel}` | rate-limit → channel из route → проверка токена `hash_equals` (401 нет / 403 mismatch) → is_active (503, E8) → INSERT в try/catch (E1) → `router.route()` → `WebhookAckResource` 201 | 🟡 частично | Порядок проверки токена корректен. Отсутствие UI допустимо. `route()` вызывается ВНЕ try/catch (см. M-2). Не прогонялся (`inbound_messages=0`). |
| Inbound-роутинг (дедуп → resolve pipeline/stage → дедуп/создание Company → создание Deal) | system (`InboundRoutingService`) | внутр.: `InboundRoutingService.route` (`src/app/Domain/Inbox/Services/InboundRoutingService.php:48`) | E1 дедуп webhook по `external_id` → E3 `resolvePipelineStage` (channel.default → первая активная воронка → стадия `new` → fallback → null ⇒ `routing_status='failed'` + Log::error, НЕ 500) → E2 `resolveCompany` (email→phone) → `DealService.createInbound` → штамп статуса | 🟡 частично | Логика зеркалит спеку E1/E2/E3/E10. Failed-путь корректно избегает 500 и сохраняет сообщение. НО create-исключение не обёрнуто (M-2). 0 строк → не верифицировано в рантайме. |
| Админ-управление каналами (create/reveal/regenerate token, CRUD, 409 на delete-with-forms) | admin, director | UI: **НЕТ**. `/api/channels` apiResource + `/reveal-token` + `/regenerate-token` | задуман admin DataTable + диалог (токен показывается раз при создании, reveal/regenerate под admin, 409 на удаление с привязанными формами кроме `?force`). Backend `ChannelController`+`ChannelService` есть | ⚪ отсутствует | FE ЯВНО отложен на спринт интеграций (vault «Что отложено», Q3 утв. 2026-06-12). Не баг доставки, но трекаем. |
| Админ-конструктор форм (CRUD, autogen slug, редактор полей) | admin, director | UI: **НЕТ**. `/api/forms` apiResource | задуман admin DataTable + create/edit (поля как JSON в MVP). Backend `FormController`+`FormService` есть | ⚪ отсутствует | Отложено на спринт интеграций (vault). Backend присутствует. |
| Просмотр лога входящих (routing_status, raw_payload) | admin, director | UI: **НЕТ**. `GET /api/inbox` + `GET /api/inbox/{id}` | задуман read-only DataTable с фильтром по `routing_status` и drawer (raw_payload + ссылка на target_deal). Backend `InboundMessageController` есть | ⚪ отсутствует | Тот же deferral. Следствие: «failed»-интейк сегодня невидим оператору в продукте. |

> Поправка из live-QA: для домена Inbox в live-QA не было прямого journey (нет UI для прохода). Косвенно релевантна NEW-4 (`Route [login] not defined` → 500 со стек-трейсом вместо 401 JSON) — она затрагивает админские inbox-эндпоинты под `auth:sanctum` при вызове без токена.

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк в живой БД | Статус |
|---|---|---|---|---|
| `Channel` (`src/app/Domain/Inbox/Models/Channel.php`) | `channels` | Администрируемая точка входа: `kind` (tg/wa/email/web_form/api), `secret_token` (`Str::random(48)`), `config` JSON, `default_lead_source`, `default_owner_id`, `default_pipeline_id`, `default_stage_id`, `is_active`; `maskedToken()` для ресурса | **1** (seed/ручной тест) | built, CONSISTENT |
| `Form` (`src/app/Domain/Inbox/Models/Form.php`) | `forms` | Публичная форма: `name`, `public_slug` (`Str::random(11)`), `fields` JSON `[{name,label,type,required}]`, `channel_id` (nullOnDelete), `thank_you_text`, `is_active`, `created_by_user_id` | **1** (seed/ручной тест) | built, CONSISTENT |
| `InboundMessage` (`src/app/Domain/Inbox/Models/InboundMessage.php`) | `inbound_messages` | Лог входящих: `channel_id` (cascade), `external_id` (partial UNIQUE с `channel_id` WHERE `external_id IS NOT NULL`), `from_identifier/from_name/subject/body`, `raw_payload` (аудит), `target_deal_id` (FK deals nullOnDelete), `target_deal_created`, `routing_status` (routed/dedup/failed), `received_at` | **0** | built, CONSISTENT, но НИКОГДА не записывался → фича не прогонялась |

**Расхождения migration ↔ live-schema ↔ model:**
- `channels`, `forms`, `inbound_messages` — все три миграции (`2026_06_16_100000/100001/100002`) совпадают с live-schema и моделями. Вердикт по каждой: **CONSISTENT**.
- `inbound_messages.external_id` — partial UNIQUE реализован с branching по драйверу (PG: `DB::statement`; SQLite: обычный составной индекс). На PG **эффективность partial-unique не проверена в рантайме** (0 строк) — рекомендуется live-проба дедупа.
- **DRIFT (perf):** дедуп-по-телефону не имеет DB-side нормализованного/индексированного представления. `crm_companies.phone` хранится как есть (`varchar(64)`), нет колонки `phone_normalized` и нет индекса по телефону (в схеме только btree по `category_code/company_type_id/country_code/email/last_activity_at/name/source/tax_id`). Нормализация телефона выполняется в PHP во время запроса (full-table scan) — см. M-4.

**Пустые при наличии кода таблицы:** `inbound_messages` (0 строк) — полный конвейер записи реализован, но ни разу не сработал. `channels`/`forms` по 1 строке — недостаточно для подтверждения боевых сценариев.

## 4. Эндпоинты и покрытие фронтом

| Метод + Path | Контроллер@метод | Авторизация | Вызывается фронтом? | Примечание |
|---|---|---|---|---|
| `GET /api/forms/public/{slug}` | `PublicFormController@meta` | anon; `throttle:inbound` | ❌ нет | Meta публичной формы (`FormPublicResource`). 404 если inactive. **Мёртвый endpoint** — публичная страница формы не построена. (`api.php:122`) |
| `POST /api/forms/public/{slug}/submit` | `PublicFormController@submit` | anon; `throttle:inbound` | ❌ нет | Submit формы (E4 honeypot, E6 external_id, E7 валидация, E1 дедуп). 201. **Core-flow без UI-поверхности в репо.** (`api.php:123`) |
| `POST /api/inbox/webhook/{channel}` | `InboxWebhookController@webhook` | anon; `hash_equals X-Channel-Token`; `throttle:inbound` | ❌ нет (by design) | Generic webhook для внешних коннекторов. Зовут внешние системы — отсутствие FE-вызова допустимо. 401/403/503(E8)/дедуп. (`api.php:124`) |
| `GET /api/channels` | `ChannelController@index` | `auth:sanctum` (`ChannelPolicy` admin/director) | ❌ нет | Список inbox-каналов (токен замаскирован). **Нет FE.** Коллизия имени с `/api/contacts/{id}/channels` и CRM acquisition-channels — разные сущности. |
| `POST /api/channels` | `ChannelController@store` | admin/director | ❌ нет | Создание канала — полный токен показывается один раз (`ChannelSecretResource`). **Нет FE**; только через raw API. |
| `GET /api/channels/{id}` | `ChannelController@show` | `auth` | ❌ нет | **Нет FE-вызова.** |
| `PATCH /api/channels/{id}` | `ChannelController@update` | admin/director | ❌ нет | Токен иммутабелен. **Нет FE-вызова.** |
| `DELETE /api/channels/{id}` | `ChannelController@destroy` | admin/director | ❌ нет | 409 без `?force` при привязанных формах (E9). **Нет FE-вызова.** |
| `GET /api/channels/{id}/reveal-token` | `ChannelController@reveal` | admin/director | ❌ нет | Показать полный токен. Объявлен ДО apiResource (не перехватывается как `{channel}` id). **Нет FE.** (`api.php:579`) |
| `POST /api/channels/{id}/regenerate-token` | `ChannelController@regenerate` | admin/director | ❌ нет | Перегенерация токена. **Нет FE.** (`api.php:580`) |
| `GET/POST/PATCH/DELETE /api/forms` | `FormController` | admin/director | ❌ нет | Form CRUD apiResource. Admin form-builder UI не построен. **Нет FE.** |
| `GET /api/inbox` | `InboundMessageController@index` | admin/director | ❌ нет | Read-only список входящих с `routing_status`. **Нет FE.** `inbound_messages=0` → не прогонялся. (`api.php:585`) |
| `GET /api/inbox/{inboundMessage}` | `InboundMessageController@show` | admin/director | ❌ нет | Детализация входящего. **Нет FE-вызова.** (`api.php:586`) |

**Итого:** 12 эндпоинтов, **0 вызываются фронтом**. Orphaned FE-вызовов нет (фронт вообще не знает про Inbox). Мёртвые с точки зрения продукта: `forms/public/*` (нет UI-потребителя — баг), 9 админских (отложены — трекаются), webhook (допустимо без FE — зовут внешние системы).

## 5. RBAC домена

| Ресурс | Policy/правило | Где реально проверяется | Дыра? |
|---|---|---|---|
| `channels` (CRUD/reveal/regenerate) | `ChannelPolicy`: `viewAny`/`view` — любой текущий пользователь; `create`/`update`/`delete`/`reveal`/`regenerate` — admin/director (`isManager`) | На уровне Policy в контроллере (`auth:sanctum`) | Нет дыры в коде. **НО:** `viewAny`/`view` открыты любому залогиненному — список каналов (с masked-токеном) виден всем ролям; для inbox-конфигурации это шире, чем нужно. Низкий риск (нет FE, токен замаскирован). |
| `forms` (CRUD) | `FormPolicy`: все операции — admin/director (формы — админская сущность) | Policy в контроллере | Нет дыры. |
| `inbox` (index/show) | admin/director | Контроллер | Нет дыры (read-only лог под admin/director). Нет FE-поверхности для эксплуатации. |
| Публичные (`forms/public/*`, `inbox/webhook/*`) | none (анонимные) | Отдельная неавторизованная route-группа: `throttle:inbound` + webhook доп. проверяет `X-Channel-Token` через `hash_equals` | Намеренно без auth; защита honeypot/rate-limit/дедуп/токен. **Слабое место:** rate-limit ключуется только по IP (M-3), не `channel_id:ip` как требует спека E5. |

**Сводно:** авторизация в коде расставлена корректно (Policy на админских эндпоинтах, токен-верификация на webhook). Реальная проверка отсутствует только потому, что нет FE, который бы её вызывал. Единственная содержательная RBAC-нотка — `viewAny`/`view` каналов открыты любому залогиненному (не admin-only), но без FE и с masked-токеном это незначимо сегодня. Глобальная NEW-4 (вызов админского эндпоинта без токена возвращает 500 со стек-трейсом вместо 401 JSON) затрагивает и inbox-эндпоинты под `auth`, но это системная проблема, не специфичная для домена.

## 6. Бэклог проблем

### Сводная таблица

| Severity (FINAL) | Тип | Заголовок | Проверка |
|---|---|---|---|
| major | DEAD-CODE | Публичная страница лид-формы отсутствует — `GET/POST /api/forms/public/{slug}` без UI; core-flow веб-формы недостижим | ✅ подтверждено (статический + grep FE) |
| major | BUG | INSERT + `route()` не атомарны, `route()` без try/catch → orphaned NULL-status сообщение + 500 анонимному вызывающему | ✅ подтверждено (чтение код-пути) |
| minor | SECURITY | Rate-limiter ключуется только по IP — спека E5 требует `channel_id:ip` для webhook | ✅ подтверждено (понижено major→minor) |
| minor | PERF | `findForDedup` phone-путь = неавторизованный full-table scan с нормализацией в PHP | ✅ подтверждено (понижено major→minor) |
| minor | MISSING | Весь админ-домен Inbox (channels/forms/inbound) без фронта — 9 админских эндпоинтов недостижимы из SPA | не верифицировано (Phase-1) |
| minor | MISSING | «Failed»-роутинг невидим в продукте — нет админ-вьюера для `GET /api/inbox` `routing_status` | не верифицировано (Phase-1) |
| trivial | CONVENTION | Коллизия имени «channels» в 3 несвязанных доменах → риск будущего cross-wiring | не верифицировано (Phase-1) |

> NEW-4 из live-QA (P1, info-disclosure) — релевантна косвенно (затрагивает inbox-админ-эндпоинты под auth), но это системный баг exception-handler'а; разворачивается в доменах auth/foundation, здесь только отмечен.

---

### M-1 · DEAD-CODE · ✅ подтверждено (статический анализ + grep по FE)

**Заголовок:** Публичная страница лид-формы отсутствует — `GET/POST /api/forms/public/{slug}` не имеют UI; core-flow веб-формы недостижим.

**Файлы:** `src/routes/api.php:122-123`, `src/app/Http/Controllers/Inbox/PublicFormController.php`, `front/src/router/routes/base.ts` (отсутствие).

**Что происходит (evidence):** Backend `PublicFormController` отдаёт meta (`GET /api/forms/public/{slug}`) и submit (`POST .../submit`) — анонимный интейк веб-формы, который спека называет core-возможностью (vault «Inbox» §Что делает; план S1.9 §А, §Е). Фронт не имеет публичного роута: grep по `front/src` для `forms/public`, `public_slug`, `publicForm`, `/f/:`, `thank_you`, `honeypot`, `requiresAuth: false` — 0 релевантных хитов (единственный матч `pi pi-inbox` на `MyCoursesPage` не относится к делу). Нет страницы, которая бы рендерила объявленные поля + honeypot + `thank_you_text`. В отличие от webhook (зовут внешние системы) и админ-UI (явно отложен в «Что отложено»), публичная форма **НЕ числится в списке отложенного** — её отсутствие это незапланированный пробел, оставляющий submit-эндпоинт без потребителя, построенного MGCRM. `inbound_messages` live rowcount = 0, что согласуется с тем, что путь веб-формы никогда не исполнялся.

**Repro:** Открыть SPA — нет URL, рендерящего публичную лид-форму по slug. Маркетинговый сайт не может встроить/сослаться на форму, хостируемую MGCRM.

**Предлагаемый фикс:** Добавить публичный (без auth) роут + страницу, напр. `/f/:slug`, которая GET'ит meta формы, рендерит объявленные поля + honeypot, POST'ит в `.../submit` и показывает `thank_you_text`. По FE-слоистости ARCHITECTURE.md: модуль `api/inbox.ts` → composable `useForm` → page-composable → component. Альтернатива: если публичная форма намеренно хостится вне SPA — зафиксировать это решение в vault-списке отложенного (см. §7).

---

### M-2 · BUG · ✅ подтверждено (анализ код-пути, без триггера мутации)

**Заголовок:** INSERT + `route()` не атомарны, `route()` без try/catch → orphaned NULL-status сообщение + 500 анонимному вызывающему.

**Файлы:** `src/app/Http/Controllers/Inbox/PublicFormController.php:81-93`, `src/app/Http/Controllers/Inbox/InboxWebhookController.php:50-65`, `src/app/Domain/Inbox/Services/InboundRoutingService.php:48-108`.

**Что происходит (evidence):** Оба контроллера оборачивают в `try/catch (QueryException)` ТОЛЬКО `InboundMessage::create()` (`PublicFormController.php:81-91`, `InboxWebhookController.php:50-63`), затем вызывают `$this->router->route($channel, $message->fresh())` ВНЕ try/catch и без `DB::transaction` (`PublicFormController.php:93`, `InboxWebhookController.php:65`). `InboundRoutingService::route` (строки 88-106) выполняет `CompanyService::create` + `DealService::createInbound` + `forceFill` статуса. Если что-то из этого бросает (DB-ошибка, constraint, deadlock), уже закоммиченная строка `inbound_messages` остаётся с `routing_status = NULL` (никогда не штампуется routed/failed), а фреймворк возвращает 500 анонимному вызывающему — нарушая гарантию спеки E3/E8 «анонимному отправителю никогда не сообщается о внутренних проблемах» и ломая идемпотентность (повторная отправка вставит снова и может создать дубль). Проверено: `route()` self-guard'ит только случай `resolvePipelineStage == null` (штамп Failed + Log::error, возврат null на строке 73) и пути dedup/inactive — он НЕ оборачивает create-записи Company/Deal в try/catch; нет глобального exception-handler'а, маппящего это в идемпотентный ack.

**Repro:** Спровоцировать DB-сбой внутри `DealService.createInbound` (напр. транзиентный constraint) во время webhook/form submit: строка `inbound_messages` сохраняется с `routing_status NULL`, HTTP-ответ = 500 вместо идемпотентного ack.

**Предлагаемый фикс:** Обернуть `route()` (или всю последовательность INSERT+route) в try/catch: при `QueryException`/`Throwable` штамповать `routing_status=failed` + Log::error и возвращать идемпотентный ok/ack (`deal_created=false`) вместо всплытия 500. Опционально обернуть routing-записи в `DB::transaction`, чтобы не оставалась частичная Company-без-Deal.

---

### M-3 · SECURITY · ✅ подтверждено (понижено major→minor после верификации)

**Заголовок:** Rate-limiter ключуется только по IP — спека E5 требует `channel_id:ip` для webhook.

**Файлы:** `src/app/Providers/AppServiceProvider.php:265-267`, применение — `src/routes/api.php:121-125`.

**Что происходит (evidence):** `RateLimiter::for('inbound', fn (Request $r) => Limit::perMinute(config('inbox.rate_limit_per_minute', 30))->by($r->ip()))` ключует ТОЛЬКО по `$request->ip()`. Один именованный лимитер `inbound` применён ко всем трём публичным эндпоинтам (forms meta, forms submit, webhook), поэтому разные каналы за одним IP делят один бакет. План S1.9 §Д E5 явно: «Webhook — per-channel+IP (ключ `channel_id:ip`)». Следствия: (а) спам-всплеск при утечке токена на одном канале исчерпывает общий per-IP бюджет для легитимных submit'ов формы с того же NAT/proxy IP; (б) разные каналы за одним IP конкурируют за один бакет — ослабляя per-channel flood-защиту из спеки. Опровержение рассмотрено: отдельного per-channel лимитера или route-level override нет — один shared limiter на все три эндпоинта. Утверждение буквально верно.

**Понижение severity:** major → **minor** — это hardening/conformance-пробел на неавторизованных эндпоинтах, которые ни разу не прогонялись (`inbound_messages=0`) и пока не подключён ни один внешний коннектор; per-IP бакет всё ещё даёт flood-защиту. Реальный, но низкий текущий импакт.

**Repro:** Бить `POST /api/inbox/webhook/{channelA}` и `POST /api/forms/public/{slug}/submit` с одного IP — делят один per-IP бакет независимо от канала, вопреки `channel_id:ip` из спеки.

**Предлагаемый фикс:** Разделить webhook-лимитер на ключ `channel_id + IP` (напр. `->by($channel->id.':'.$request->ip())` через route-aware замыкание или отдельный лимитер `inbound-webhook`), оставив form/public лимитер per-IP. Канал брать из route-binding, когда присутствует.

---

### M-4 · PERF · ✅ подтверждено (код + схема; понижено major→minor)

**Заголовок:** `findForDedup` phone-путь = неавторизованный full-table scan с нормализацией в PHP.

**Файлы:** `src/app/Domain/Crm/Services/CompanyService.php:697-710` (вызов из `InboundRoutingService::resolveCompany`, строка 216).

**Что происходит (evidence):** При падении дедупа на телефон `findForDedup` выполняет `Company::query()->whereNull('deleted_at')->whereNotNull('phone')->orderBy('id')->get(['id','phone'])` — грузит ВСЕ компании с непустым телефоном, затем `preg_replace`-нормализует каждую в PHP-foreach для сравнения «только цифры». Путь достижим на каждом анонимном submit/webhook без email. Схема подтверждает: `crm_companies.phone` — обычный `varchar(64)` БЕЗ колонки `phone_normalized` и БЕЗ индекса по телефону. Email-путь (строка 683, `LOWER(TRIM(email))`) тоже non-sargable против обычного btree `crm_companies_email_index`. Опровержение рассмотрено: в `schema.sql` нет нормализованной колонки телефона и нет функционального/lower индекса — O(N)-скан подтверждён.

**Понижение severity:** major → **minor** — живой `crm_companies` rowcount = 13 (крошечная таблица), путь throttled и срабатывает только при отсутствии email; стоимость реальна, но линейна на сейчас тривиальной таблице. Структурный фикс остаётся валиден для масштаба.

**Repro:** Слать phone-only форму многократно — каждый запрос грузит все строки компаний с телефоном в память и нормализует в PHP. Стоимость растёт линейно с таблицей `crm_companies`.

**Предлагаемый фикс:** Хранить нормализованную колонку телефона (только цифры, напр. `phone_normalized`), поддерживаемую на save компании, проиндексировать и запрашивать по равенству — устранит PHP-скан и даст индексированный lookup и для PG, и для SQLite. Краткосрочно: хотя бы ограничить набор кандидатов и добавить функциональный/нормализованный индекс.

---

### minor / trivial (не верифицировано — Phase-1)

- **minor · MISSING** — Весь админ-домен Inbox без фронта: ни страница/роут/nav/api-модуль/стор/компонент в `front/src` не ссылается на `/api/channels` (Inbox), `/api/forms`, `/api/inbox`, reveal-token, regenerate-token. Единственный «channels»/«acquisition» UI — несвязанный CRM acquisition-channels directory (`front/src/router/routes/base.ts:254` → `AcquisitionChannelsPage` → `/api/admin/acquisition-channels`). **Принять и ТРЕКАТЬ deferral** — vault «Inbox» §Что отложено и план S1.9 §З явно откладывают админ-UI каналов/форм/inbox на спринт интеграций (Q3 утв. 2026-06-12). Не баг доставки. При сборке: read-only Inbound log первым (чтобы «failed»-интейки стали видны) + Channel/Form admin CRUD. (Файлы: `front/src/router/routes/base.ts`, `front/src/shared/nav`, `src/routes/api.php:575-586`.)
- **minor · MISSING** — «Failed»-роутинг невидим в продукте: `route()` корректно штампует `routing_status='failed'` + Log::error при нерезолвящейся воронке/стадии (лид сохранён, не потерян), но нет FE-страницы, отдающей `GET /api/inbox` / `routing_status`. Операторы не видят, почему входящее не создало Deal — bucket «не разобрано» (E3) живёт только в БД/логе. Фикс — в рамках inbox-UI спринта интеграций: read-only Inbound log DataTable с фильтром-badge по `routing_status` и drawer (raw_payload + ссылка target_deal). (Файлы: `src/routes/api.php:585-586`, `src/app/Domain/Inbox/Services/InboundRoutingService.php:73-78`.)
- **trivial · CONVENTION** — Коллизия имени «channels» в 3 несвязанных доменах: (1) CRM acquisition-channel directory → `/api/admin/acquisition-channels`; (2) контактные/компанейские communication channels → `/api/contacts/{id}/channels`; (3) Inbox intake channels → `/api/channels` apiResource (нет FE). При сборке Inbox-UI голый путь `/api/channels` и тип `Channel` легко спутать с communication-channel. Фикс: неймспейсить api-модуль/типы (`inboxApi`, `InboxChannel`), держать роут под `/inbox` или `/settings/inbox`. (Файлы: `front/src/pages/AcquisitionChannelsPage/composables/useAcquisitionChannelsPage.ts`, `front/src/api/crm/directories.ts:60-91`, `src/routes/api.php`.)

## 7. Расхождения со спекой (vault) и предложения по актуализации

**Документ:** `2. Модули/Inbox — Inbound каналы и формы.md`

1. **Секция «Что отложено» — публичная страница формы не числится отложенной.**
   - *Спека:* перечисляет отложенное (Admin UI каналов/форм → спринт интеграций, реальные TG/WA/Email-коннекторы, outbound webhooks, round-robin owner, Counterparty-mirror). Публичная лид-форма НЕ указана.
   - *Реальность:* публичной страницы формы в `front/src` нет (нет `/f/:slug`, grep `forms/public` = 0). Эндпоинты `GET/POST /api/forms/public/{slug}` без потребителя MGCRM, при этом §Что делает называет публичный интейк core-возможностью.
   - *Предложение:* либо (а) добавить публичную страницу формы в скоуп и снять подразумеваемый статус «done» с публичного flow; либо (б) добавить явный буллет под «Что отложено»: «Публичная страница формы (рендер `GET /forms/public/{slug}` + submit) — отложена на спринт интеграций» (это уже зафиксировано в плане S1.9 §З, но модульный doc этого не отражает).

2. **«Edge-cases реализованы → E5».**
   - *Спека:* E5 Rate-limit: «`throttle:inbound` (RateLimiter::for в AppServiceProvider) — DONE». Детальный план S1.9 §Д E5: ключ webhook = `channel_id:ip`.
   - *Реальность:* `AppServiceProvider.php:265` ключует единый лимитер `inbound` только по `$request->ip()` — без компонента `channel_id`.
   - *Предложение:* понизить E5 с «DONE» до «PARTIAL — webhook использует per-IP ключ, не `channel_id:ip` как требует план S1.9 §Д E5». Трекать split-limiter фикс (M-3).

3. **«Edge-cases реализованы → E3/E8 (атомарность)».**
   - *Спека:* E3 «Routing failed → status=failed, не 500» и E8 «без утечки внутренней ошибки» помечены DONE.
   - *Реальность:* контроллеры зовут `router->route()` вне try/catch без транзакции; DB-исключение при create Company/Deal даёт `routing_status=NULL` и 500 анонимному вызывающему — противоречит гарантии E3/E8 «никогда не 500 отправителю».
   - *Предложение:* добавить к E3/E8 ноту: «Гарантия держится ТОЛЬКО для пути сбоя resolvePipelineStage; исключение при создании Company/Deal всё ещё даёт 500 и оставляет routing_status NULL — нужно обернуть `route()` в try/catch + транзакцию» (M-2).

**Документ:** `5. Планы` — статус S1.9.
- Отметить S1.9 Inbox как **backend-complete / frontend-deferred**, а не «готово»: backend-конвейер и 12 эндпоинтов реализованы и совпадают со спекой, но `inbound_messages=0` (ни разу не прогонялся live), FE отсутствует целиком, и одна core-поверхность (публичная форма) — незапланированный пробел. Рекомендовать read-only live-curl-пробу submit/webhook до подключения внешних коннекторов для подтверждения, что end-to-end создаётся Company+Deal в стадии `code='new'` и что partial-UNIQUE дедуп срабатывает на PG.
