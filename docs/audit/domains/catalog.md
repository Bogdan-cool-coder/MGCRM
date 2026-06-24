# Аудит домена: Каталог — продукты, планы, цены, курсы валют

> key: `catalog` · backend: `app/Domain/Catalog` · frontend: `front/src/pages/{ProductsPage,ProductPage,ExchangeRatesPage}` · дата: 2026-06-24
> Severity-теги после верификации (Phase 2 verdicts + Phase 3 live-QA). minor/trivial — из Phase-1 json, независимо не верифицировались.

## 1. Назначение

Домен `Catalog` — это товарная номенклатура CRM: единый каталог продаваемых продуктов (`catalog_products`), их группы-семейства (`catalog_product_groups`), тарифные планы/тиры (`catalog_product_plans`, биллинг-юниты year/one_time/minute/package/perpetual) и мультивалютные цены в копейках (`catalog_product_prices`). Это источник истины для Sales (через `ProductService::getPriceSnapshot()` фиксируется снэпшот цены в `deal_products`) и для Документов (`document_items`). Отдельная подсистема — курсы валют (`catalog_exchange_rates`), которую кросс-доменно потребляет Finance (M9) и Sales через `ExchangeRateService::getRate()/convertAmount()` для кросс-валютной конвертации. Есть Excel-импорт прайс-листа (PhpSpreadsheet) с заявленным dry-run preview.

**Зрелость: частично зрелый (товарная часть — зрелая, FX-часть — мёртвая в проде).** Обоснование: ядро каталога реально нагружено живыми данными — 8 групп, 32 продукта, 21 план, **164 цены** (live rowcounts), слои чистые по ARCHITECTURE.md (FormRequest authz → тонкий контроллер → `ProductService`/`ExchangeRateService` → модель → API Resource), деньги-копейки соблюдены для цен, RBAC single-source (`users.role`, зеркало spatie) корректен — manager на запись получает 403 (проверено live). НО подсистема курсов валют **полностью мертва: `catalog_exchange_rates` = 0 строк**, дневной job — постоянный no-op, `GET /convert` всегда 422 (проверено live); и «preview» Excel-импорта на деле пишет в БД. Две blocker-проблемы плюс пакет major'ов по нескейпленным вложенным роутам и мёртвым FE-кнопкам опускают общую зрелость до «частично».

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI + endpoint) | Как (шаги) | Статус | Примечание |
|---|---|---|---|---|---|
| Список/просмотр продуктов, групп, планов, цен | все аутентиф. (admin/director/lawyer/manager) | ProductsPage `/admin/products`, ProductPage `/admin/products/:id`; `GET /products`, `GET /products/{id}` | Lazy-пагинация с фильтрами q/group/pricing_type/active; деталь eager-грузит plans+prices; expand строки = матрица план×цена | ✅ работает | 32 продукта / 21 план / 164 цены live; слои чистые |
| CRUD продукта/группы/плана/цены (admin write) | admin, director | ProductsPage drawer / ProductPage dialogs; `POST/PATCH/DELETE /products`, `/products/{product}/plans`, `/products/{product}/prices` | FormRequest authz → `ProductService` CRUD; delete-гарды (409 на `deal_products`/группа с продуктами); one-perpetual-plan guard на create плана | 🟡 частично | Happy-path работает, НО: нет UI удаления цены; вложенные роуты не скейпятся на родителя (binding leak); plan_id цены не проверяется на принадлежность продукту |
| Ежедневное обновление курсов (system) | system (scheduler) | `Schedule::job(UpdateExchangeRatesJob)->dailyAt('03:00')` (`routes/console.php:31`); `php artisan catalog:refresh-rates` | Job → `fetchAndUpsertFromApi` → `GET api.exchangerate.host/latest?base=USD` → `data['rates']` → кросс-курсы → upsert | 🔴 сломан | API требует access_key (env пуст); HTTP-200 с `{success:false}`; сервис считает успехом, пишет 0 строк. Таблица пуста с деплоя (live 0 строк) |
| Ручной ввод/правка/удаление курса | admin, director | ExchangeRatesPage ManualRateDialog; `POST/PATCH/DELETE /exchange-rates` | `StoreExchangeRateRequest` → `ExchangeRateService::upsertRate` (source='manual'); 409 на unique (from,to,date) | 🟡 частично | Endpoint работает, но таблица фактически пуста — это единственный путь заполнить FX, т.к. авто-job мёртв и Refresh = 405 |
| On-demand Refresh курсов (FE-кнопка) | все роли (нет гарда) | ExchangeRatesPage Refresh + `ExchangeRateAgeWarning`; `POST /exchange-rates/refresh` | FE → `catalogApi.refreshRates` → POST на несуществующий роут | 🔴 сломан | Роута нет → 405 (verdict confirmed). Кнопка без `canWrite`-гарда — видна manager/lawyer |
| Кросс-валютная конвертация (для Finance/Deals) | все аутентиф. (GET) / внутренние Finance-вызовы | `GET /exchange-rates/convert`; `ExchangeRateService::convertAmount/getRate` | `getRate(from,to,date)` → `latestForPair` → `convertAmount = (int) round(amount * (float)rate)` | 🔴 сломан | Всегда 422 live (нет курсов). При наличии курсов — float-каст decimal(20,6) (см. бэклог, понижен до minor) |
| Excel-импорт прайса — реальный (idempotent upsert) | admin, director | PriceImportDialog confirm; `POST /price-import` | PhpSpreadsheet → map header → per-row validate → firstOrCreate группа → upsert продукт по code → upsert план → `ProductPrice::updateOrCreate` ×100. Частичные записи, без полного rollback, 422 при ошибках | 🟡 частично | Пишет, НО amount читается `getFormattedValue()`+`(float)` (locale-риск); и preview на том же роуте тоже пишет — см. blocker |
| Excel-импорт прайса — dry-run preview | admin, director | PriceImportDialog file-select; FE → `POST /price-import` (dry_run=1) | FE ждёт неразрушающий preview; backend `store()` хардкодит `dryRun:false`, флаг игнорит | 🔴 сломан | **Preview МУТИРУЕТ БД.** Настоящий dry-run роут `/price-import/preview` существует, но FE его не вызывает (blocker) |
| Скачать шаблон импорта | admin, director | ProductsPage Import-меню + PriceImportDialog; `GET /price-import/template` | `<a download>` на `/api/catalog/price-import/template` | 🔴 сломан | Роута нет → 404 live (confirmed). Пользователь не может получить шаблон |
| Delete-гард продукта / one-perpetual-plan guard | admin, director | `DELETE /products/{id}`; `POST /products/{id}/plans` (unit=perpetual) | `ProductService::delete` в tx → 409 если есть `deal_products`; `createPlan` → 422 если perpetual-план уже есть | ✅ работает | Гарды на BE корректны; но FE plan-dialog не умеет выбрать 'perpetual' — из UI вечный план не создать |
| getPriceSnapshot (интеграция Sales DealProduct) | system (Sales add-product) | `ProductService::getPriceSnapshot(productId, planId, currencyCode): int` | Возвращает копеечный снэпшот текущей цены; используется S1.3 DealProduct для фиксации unit_price | ⚪ не проверено (тут) | Catalog-сторона есть; реально дёргается из Sales — подтверждать в Sales-аудите |

Сводка статусов: ✅ работает — 3, 🟡 частично — 3, 🔴 сломан — 4, ⚪ не проверено — 1.

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк в живой БД | Статус |
|---|---|---|---|---|
| `ProductGroup` | `catalog_product_groups` | Семейства продуктов (верхняя группировка) | 8 | ✅ built |
| `Product` | `catalog_products` | Продаваемый товар; корень для планов/цен; точка интеграции с Sales (`getPriceSnapshot`) | 32 | ✅ built |
| `ProductPlan` | `catalog_product_plans` | Тарифный план/тир под продуктом (year/one_time/minute/package/perpetual) | 21 | ✅ built |
| `ProductPrice` | `catalog_product_prices` | Мультивалютная цена продукта/плана; amount = integer-копейки | 164 | ✅ built |
| `ExchangeRate` | `catalog_exchange_rates` | Курсы для FX; потребляет Finance через `ExchangeRateService` | **0** | 🔴 stub (код есть, данных нет — мёртв в проде) |

**Расхождения migration ↔ live-schema ↔ model ↔ vault:**

- **`catalog_product_prices` unique (DRIFT, major).** Live `\d` и `pg_indexes` показывают `uq_catalog_product_prices = UNIQUE (product_id, plan_id, currency_code)` **без WHERE** (безусловный), совпадает с миграцией `2026_06_11_120003:35`. Vault-спека требует частичный unique только для базовых цен (`valid_from/valid_to IS NULL`). Последствия: (a) две time-bounded цены на одну пару (product,plan,currency) с разными окнами невозможны; (b) `plan_id` nullable → Postgres трактует NULL как различные → дубль базовых цен (plan_id IS NULL, тот же product+currency) на уровне БД **не запрещён**. Комментарий в миграции (строки 31-34) обещает partial/app-layer enforcement, но шипнут безусловный unique.
- **`BillingUnit` enum (DRIFT, minor).** BE-enum `BillingUnit.php` — **5 кейсов** включая `Perpetual='perpetual'` (label «Вечная лицензия»), от него зависит one-perpetual guard. FE-тип `entities/catalog.ts:10` — **4 кейса** (нет perpetual), vault-спека — тоже 4. Итог: BE=5, FE=4, vault=4.
- **Провайдер/URL курсов (DRIFT + BROKEN).** Код-дефолт `api_url=https://api.exchangerate.host` (`crm.php:141`), сервис бьёт `/latest` и ждёт `data['rates']`. Vault называет `exchangerate-api.com` v6 (отдаёт `conversion_rates`). Ни тот ни другой не наполняет таблицу (env-ключ пуст) → 0 строк.
- **`ExchangeRate` только `created_at` без `updated_at` (CONSISTENT).** Намеренно: курсы append/upsert, `upsertRate` пишет `created_at` вручную, список обновляемых полей = `['rate','source']`. Совпадает со спекой.
- **`catalog_product_prices.amount` (CONSISTENT).** `unsignedBigInteger` (копейки), 164 строки, `(int)` каст, Resource отдаёт int — совпадает с vault.

**Пустые при наличии кода:** `catalog_exchange_rates` (0 строк) — полностью реализованный CRUD + job + command + FE-страница, но данных нет ни одной строки → подсистема мёртвая.

## 4. Эндпоинты и покрытие фронтом

| Метод + Path | Контроллер@метод | Авторизация | Зовётся фронтом? | Примечание |
|---|---|---|---|---|
| GET `/api/catalog/product-groups` | `ProductGroupController@index` | policy viewAny → любой аутентиф. | ✅ да | `catalogApi.getProductGroups` (фильтры + drawer) |
| POST `/api/catalog/product-groups` | `ProductGroupController@store` | `StoreProductGroupRequest` → create → admin/director | ❌ нет | `createProductGroup` определён, но **нет экрана создания группы** (orphaned FE-метод) |
| GET `/api/catalog/product-groups/{id}` | `ProductGroupController@show` | policy view → любой аутентиф. | ❌ нет | нет FE-вызова (мёртвый endpoint для UI) |
| PATCH `/api/catalog/product-groups/{id}` | `ProductGroupController@update` | `UpdateProductGroupRequest` → update → admin/director | ❌ нет | `updateProductGroup` неиспользуем (orphaned FE-метод) |
| DELETE `/api/catalog/product-groups/{id}` | `ProductGroupController@destroy` | policy delete → admin/director; 409 если есть продукты | ❌ нет | `deleteProductGroup` неиспользуем (orphaned FE-метод) |
| GET `/api/catalog/products` | `ProductController@index` | policy viewAny → любой аутентиф. | ✅ да | `useProductsPageData.load` |
| POST `/api/catalog/products` | `ProductController@store` | `StoreProductRequest` → create → admin/director (manager 403 live) | ✅ да | `ProductCreateDrawer` |
| GET `/api/catalog/products/{id}` | `ProductController@show` | policy view → любой аутентиф. | ✅ да | `useProductPageData.load` (eager plans+prices) |
| PATCH `/api/catalog/products/{id}` | `ProductController@update` | `UpdateProductRequest` → update → admin/director | ✅ да | toggleActive + drawer update |
| DELETE `/api/catalog/products/{id}` | `ProductController@destroy` | policy delete → admin/director; 409 если есть `deal_products` | ✅ да | deleteProduct (409-aware toast) |
| GET `/api/catalog/products/{product}/plans` | `ProductPlanController@index` | policy view(product) | ❌ нет | `getProductPlans` определён, но UI берёт eager-loaded `product.plans` |
| POST `/api/catalog/products/{product}/plans` | `ProductPlanController@store` | `StoreProductPlanRequest` → update(product); one-perpetual guard (422) | ✅ да | createPlan |
| GET `/api/catalog/products/{product}/plans/{plan}` | `ProductPlanController@show` | policy view(product); **{plan} НЕ скейплен к {product}** | ❌ нет | нет FE-вызова; **binding-leak BUG** (live 200 при mismatch) |
| PATCH `/api/catalog/products/{product}/plans/{plan}` | `ProductPlanController@update` | `UpdateProductPlanRequest` update(product); **{plan} НЕ скейплен** | ✅ да | updatePlan |
| DELETE `/api/catalog/products/{product}/plans/{plan}` | `ProductPlanController@destroy` | policy update(product); 409 если `deal_products`; **{plan} НЕ скейплен** | ✅ да | deletePlan (409-aware) |
| GET `/api/catalog/products/{product}/prices` | `ProductPriceController@index` | policy view(product) | ❌ нет | цены приходят eager на продукте; index не зовётся |
| POST `/api/catalog/products/{product}/prices` | `ProductPriceController@store` | `UpsertProductPricesRequest` update(product); **exists-check plan_id НЕ скейплен к продукту** | ✅ да | savePrice upsert (по одной цене) |
| DELETE `/api/catalog/products/{product}/prices/{price}` | `ProductPriceController@destroy` | policy update(product); **{price} НЕ скейплен** | ❌ нет | **НЕТ FE-вызова — нет UI удаления цены**; удалить цену из UI невозможно (мёртвый endpoint) |
| GET `/api/catalog/exchange-rates` | `ExchangeRateController@index` | policy viewAny → любой аутентиф.; live `[]` (0 строк) | ✅ да | `useExchangeRatesPage.load` |
| POST `/api/catalog/exchange-rates` | `ExchangeRateController@store` | `StoreExchangeRateRequest` → create → admin/director (manager 403 live) | ✅ да | createExchangeRate (manual dialog) |
| GET `/api/catalog/exchange-rates/{id}` | `ExchangeRateController@show` | policy view → любой аутентиф. | ❌ нет | нет FE-вызова |
| PATCH `/api/catalog/exchange-rates/{id}` | `ExchangeRateController@update` | `UpdateExchangeRateRequest` update → admin/director; 409 на unique | ✅ да | updateExchangeRate |
| DELETE `/api/catalog/exchange-rates/{id}` | `ExchangeRateController@destroy` | policy delete → admin/director | ✅ да | deleteExchangeRate |
| GET `/api/catalog/exchange-rates/convert` | `ExchangeRateController@convert` | policy viewAny → любой аутентиф.; ВСЕГДА 422 live | ❌ нет | convert backend-internal (Finance). Нет FE-вызова. Всегда 422 (FX мёртв) |
| POST `/api/catalog/price-import` | `PriceImportController@store` | `ImportPriceRequest` → Gate `admin-write` → admin/director | ✅ да | **И** importPreview(dry_run=1) **И** importConfirm(dry_run=0) бьют сюда; сервер хардкодит `dryRun:false` → preview тоже ПИШЕТ |
| POST `/api/catalog/price-import/preview` | `PriceImportController@preview` | `ImportPriceRequest` → admin-write → admin/director | ❌ нет | **Настоящий dry-run роут FE НИКОГДА не зовёт** (мёртвый endpoint; FE ошибочно бьёт `/price-import` с флагом, который сервер выбрасывает) |
| POST `/api/catalog/exchange-rates/refresh` | (роута НЕТ) | n/a | ✅ да (orphaned) | **DEAD FE-вызов:** `catalogApi.refreshRates` → 405 live. Refresh-кнопка сломана для всех ролей |
| GET `/api/catalog/price-import/template` | (роута НЕТ) | n/a | ✅ да (orphaned) | **DEAD FE-вызов:** `downloadTemplateUrl` → 404 live. Шаблон импорта недостижим |

**Orphaned FE-вызовы (зовут несуществующий backend):** `POST /exchange-rates/refresh` (405), `GET /price-import/template` (404).
**Мёртвые endpoint'ы (backend есть, FE не зовёт):** все CRUD групп кроме `index`, `plans/{plan}` GET, `prices` index, `DELETE prices/{price}`, `exchange-rates/{id}` GET, `convert`, **`price-import/preview` (настоящий dry-run, который и нужен FE)**.

## 5. RBAC домена

Источник роли — `users.role` (зеркало spatie), проверяется в policy через `isAdminOrDirector()`. Модель: **чтение открыто всем, запись — только admin/director.**

| Действие | Роли | Где реально проверяется | Дыра? |
|---|---|---|---|
| Просмотр продуктов/групп/планов/цен/курсов (read) | все аутентиф. | `ProductPolicy`, `ProductGroupPolicy`, `ExchangeRatePolicy` viewAny/view (всегда true); `$this->authorize()` в контроллере | Намеренно открыто (read для всех) |
| Create/update/delete продукта/группы/плана/цены/курса (write) | admin, director | `FormRequest::authorize` → policy create/update/delete → `isAdminOrDirector(users.role)`; **live: manager POST /products = 403** | ✅ корректно |
| Excel-импорт прайса (real + preview) | admin, director | `ImportPriceRequest::authorize` → Gate `admin-write` (`AppServiceProvider.php:243`) | ✅ корректно (но preview всё равно пишет — blocker B2) |
| Конвертация валют (GET /convert) | все аутентиф. | `ExchangeRateController@convert` → `authorize('viewAny')` | ОК (internal-only по факту) |
| Refresh-кнопка курсов (FE) | **все роли видят** (нет `canWrite`) | `ExchangeRatesPage/index.vue:9-16` БЕЗ `v-if=canWrite`, в отличие от Add-manual (`:18`) | ⚠️ дыра в FE-гарде (но endpoint 405 для всех — реального вреда нет) |

**Реальная дыра по части авторизации в домене — это не RBAC-байпас записи (он корректен), а нескейпленные вложенные роуты (B-major #1):** authz проверяет `can('update', route('product'))` — то есть продукт из path, а не реального владельца плана/цены. Передав чужой `{product}` с правом на него, admin/director может PATCH/DELETE чужой план/цену (см. бэклог). Для не-admin это не эскалация привилегий (всё равно нужен write-доступ), но это нарушение целостности «дочерний объект под своим родителем».

**Контекст из live-QA (NEW-5, не Catalog-домен):** manager успешно читает `/api/admin/company-types|sources|countries|cities|contact-positions|acquisition-channels|disconnect-reasons` (все 200). Это справочники CRM-домена (под `/api/admin/*`), а не Catalog (`/api/catalog/*`); на странице Settings они визуально соседствуют с разделом «Каталог», но к этому домену не относятся — фиксируется в CRM-аудите. Эндпоинты самого Catalog под `/api/catalog/*` имеют корректные read-для-всех/write-для-admin policy.

## 6. Бэклог проблем

### Сводная таблица (FINAL severity после верификации)

| Severity | Тип | Заголовок | Проверка |
|---|---|---|---|
| 🔴 blocker | BUG | FX-подсистема мертва: курсы не наполняются, convert всегда 422 | ✅ подтверждено (live probe + код) |
| 🔴 blocker | BUG | Price-import «preview» реально пишет в БД (backend игнорит dry_run) | ✅ подтверждено (route-list + код; мутацию не запускали) |
| 🟠 major | SECURITY | Вложенные plan/price-роуты не скейпятся на родителя (binding leak) | ✅ подтверждено в браузере (live 200 на mismatch) |
| 🟠 major | BUG | Price-upsert принимает plan_id чужого продукта | ✅ подтверждено (код; мутацию не запускали) |
| 🟠 major | DATA-INCONSISTENCY | Unique цены игнорит окно валидности; NULL-plan базовые цены не дедуплятся в БД | ✅ подтверждено (live `\d` + pg_indexes) |
| 🟠 major | DEAD-CODE | Refresh-кнопка курсов → 405 + видна всем ролям | ✅ подтверждено (route-инспекция + grep) |
| 🟡 minor | BUG | Конвертация кастит decimal-курс к float (потеря точности денег) | ⚠️ частично (понижено major→minor) |
| 🟡 minor | DEAD-CODE | «Download template» → 404 | ✅ подтверждено (live 404), понижено major→minor |
| 🟡 minor | SPEC-DRIFT | FE BillingUnit + plan-dialog без 'perpetual' | ✅ подтверждено (код), понижено major→minor |
| 🟡 minor | DEAD-CODE | Нет UI удаления цены — `DELETE prices/{price}` мёртв | не верифицировано (Phase-1) |
| 🟡 minor | BUG | Excel-импорт читает amount через `getFormattedValue()`+`(float)` (locale-риск) | не верифицировано (Phase-1) |
| ⚪ trivial | SPEC-DRIFT | Scheduler в 03:00, vault говорит daily 00:05 UTC | не верифицировано (Phase-1) |

---

### BLOCKER #1 — FX-подсистема мертва: курсы не наполняются, convert всегда 422
**Severity: blocker · Тип: BUG · Проверка: ✅ подтверждено (live probe + код)**
**Файлы:** `src/app/Domain/Catalog/Services/ExchangeRateService.php:142,144,153,154,156-160` · `src/config/crm.php:141-142` · `src/routes/console.php:31`
**Что происходит:** `fetchAndUpsertFromApi()` делает `Http::get(config('crm.exchange_rate.api_url')='https://api.exchangerate.host' . '/latest', base=USD)`. Этот endpoint теперь требует `access_key`; при пустом `EXCHANGE_RATE_API_KEY` он отдаёт **HTTP 200** с телом `{"success":false,"error":{"code":101,"type":"missing_access_key"}}` и без ключа `rates`. Код на строке 144 проверяет только `$response->successful()` (true для 200) → провал не логируется; строка 154 `$rates = $data['rates'] ?? []` пуста → строки 156-160 пишут безобидный warning «No rates» и молча выходят. Дневной job — **постоянный no-op**. Live: `catalog_exchange_rates = 0 строк`; `GET /convert?from=KZT&to=RUB&amount=100000` → **422** `{message:'No exchange rate found for KZT/RUB...'}`. `printenv` в `macro-crm-app-dev` — нет `EXCHANGE_RATE_*`; нет в `src/.env`/`.env.example`. Verdict: confirmed, finalSeverity=blocker, confidence 0.98. Последствие: любая кросс-валютная фича Finance(M9)/Sales получает `null` без какой-либо ошибки выше log-warning.
**Repro:** `docker exec macro-crm-db-dev psql -U macro_crm -d macro_crm -c 'select count(*) from catalog_exchange_rates'` → 0; `curl -s -o /dev/null -w '%{http_code}' 'http://localhost:8080/api/catalog/exchange-rates/convert?from=KZT&to=RUB&amount=100000' -H 'Authorization: Bearer <admin>'` → 422. Upstream: `curl 'https://api.exchangerate.host/latest?base=USD&symbols=RUB,USD,EUR,KZT,UZS,AED'` → 200 `{success:false,...}` без `rates`.
**Предлагаемый фикс:** 1) Определиться с провайдером (vault называет `exchangerate-api.com` v6 → `conversion_rates`, а не `rates`) и проставить рабочий `EXCHANGE_RATE_API_KEY`; либо мигрировать на keyless-источник (ЦБ РФ / ECB). 2) Трактовать «HTTP-200 + success:false» как провал: проверять `$data['success']===false`/`$response->json('error')`, делать `Log::error` + `throw`, чтобы job ретраился/алертил, а не молча no-op'ил. 3) Громко поднимать `null` из `getRate/convertAmount` в Finance, а не молча подставлять 0.

---

### BLOCKER #2 — Price-import «preview» реально пишет в БД (backend игнорит dry_run)
**Severity: blocker · Тип: BUG · Проверка: ✅ подтверждено (route-list + код; деструктивную мутацию не запускали по read-only-правилу)**
**Файлы:** `front/src/api/catalog.ts:302-303` · `front/src/pages/ProductsPage/components/PriceImportDialog.vue:26,195` · `src/app/Http/Controllers/Catalog/PriceImportController.php:27,39-47` · `src/app/Http/Requests/Catalog/ImportPriceRequest.php:20` · `src/app/Domain/Catalog/Services/PriceImportService.php:81-93,108-191`
**Что происходит:** FE `importPreview()` POST'ит файл на `/api/catalog/price-import` с полем `dry_run='1'` (`catalog.ts:302-303`), ожидая неразрушающий preview; `PriceImportDialog` вызывает его **автоматически на `@select` файла** (`:26,195`), до подтверждения пользователем. Но `PriceImportController@store` **хардкодит `dryRun:false`** (`:27`) и не читает валидированный `dry_run` (его валидирует `ImportPriceRequest:20`, но store игнорирует). `PriceImportService::importFromExcel` в ветке `dryRun:false` (`:81-93,108-191`) делает реальные `Product::create/update`, `ProductGroup::firstOrCreate`, `plans()->create/update`, `ProductPrice::updateOrCreate` **без транзакции/rollback**. Итог: каталог мутируется в тот момент, как пользователь выбрал валидный файл; затем «Import» пишет второй раз (двойной импорт). Настоящий dry-run роут `/price-import/preview` существует (`store/preview` оба в `api.php:363-364`), но FE его никогда не зовёт (grep-verified). Verdict: confirmed, finalSeverity=blocker, confidence 0.98.
**Repro:** ProductsPage под admin: Import → выбрать любой валидный .xlsx. В Network виден `POST /api/catalog/price-import` (dry_run=1) с `inserted/updated > 0`; строки в БД меняются ДО клика «Import».
**Предлагаемый фикс:** (предпочтительно) Перенаправить FE `importPreview` на `POST /api/catalog/price-import/preview` (выделенный dry-run роут), `importConfirm` оставить на `/price-import` — это совпадает с дизайном BE и чище. Альтернатива: в `store()` читать `$request->boolean('dry_run')` и пробрасывать в `importFromExcel`. Дополнительно: завернуть реальный импорт в `DB::transaction` с rollback при ошибках строк (сейчас частичные записи).

---

### MAJOR #1 — Вложенные plan/price-роуты не скейпятся на родителя (binding leak)
**Severity: major · Тип: SECURITY · Проверка: 🌐 подтверждено в браузере (live 200 на mismatch)**
**Файлы:** `src/routes/api.php:339-341,346` · `src/app/Http/Controllers/Catalog/ProductPlanController.php:39,46,51` · `src/app/Http/Controllers/Catalog/ProductPriceController.php:41`
**Что происходит:** Роуты вкладывают `plans/{plan}` и `prices/{price}` под `products/{product}`, но у группы **нет `->scopeBindings()`**; implicit binding резолвит дочерний объект по глобальному id; контроллеры **никогда не проверяют** `$plan->product_id===$product->id` (или `$price->product_id`). Live: `GET /api/catalog/products/6/plans/7` → **HTTP 200**, тело `data.id=7, data.product_id=7` — принят неверный родитель. Тот же shape у PATCH/DELETE плана и DELETE цены: write может обновить/удалить чужой план/цену передачей mismatched `{product}` (authz проверяет только `can('update', route('product'))`, т.е. path-продукт, а не реального владельца). Verdict: confirmed, finalSeverity=major, confidence 0.97.
**Repro:** `curl -s -o /dev/null -w '%{http_code}' 'http://localhost:8080/api/catalog/products/6/plans/7' -H 'Authorization: Bearer <admin>'` → 200 (план 7 принадлежит продукту 7).
**Предлагаемый фикс:** Добавить `->scopeBindings()` к группе `products/{product}` (Laravel implicit scoping через relations `plans()`/`prices()`), ЛИБО в каждом хендлере `abort_unless($plan->product_id===$product->id, 404)` / то же для `$price`.

---

### MAJOR #2 — Price-upsert принимает plan_id чужого продукта
**Severity: major · Тип: BUG · Проверка: ✅ подтверждено (код; мутацию не запускали)**
**Файлы:** `src/app/Http/Requests/Catalog/UpsertProductPricesRequest.php:23` · `src/app/Domain/Catalog/Services/ProductService.php:178-190` · `src/app/Domain/Catalog/Services/PriceImportService.php:181`
**Что происходит:** Правило `prices.*.plan_id` = `['nullable','integer','exists:catalog_product_plans,id']` без ограничения, что план принадлежит `{product}`. `ProductService::upsertPrices` пишет `ProductPrice` с `product_id=$product->id` и `plan_id` из запроса дословно, без проверки принадлежности. Безусловный unique `(product_id, plan_id, currency_code)` это не предотвращает. Результат: можно создать строку с `product_id=A`, `plan_id=B` (план принадлежит продукту C) — план «прицеплен» к чужому продукту. Verdict: confirmed, finalSeverity=major, confidence 0.9. Один корневой класс с MAJOR #1.
**Repro:** `POST /api/catalog/products/6/prices {prices:[{plan_id:7,currency_code:'RUB',amount:1000}]}` где план 7 принадлежит продукту 7 → строка с `product_id=6, plan_id=7` (cross-product).
**Предлагаемый фикс:** Scoped `Rule::exists`: `Rule::exists('catalog_product_plans','id')->where('product_id', $this->route('product')->id)`; ИЛИ валидировать в `ProductService::upsertPrices`, что каждый `plan_id` принадлежит `$product` (abort 422). То же — в `PriceImportService`.

---

### MAJOR #3 — Unique цены игнорит окно валидности; NULL-plan базовые цены не дедуплятся в БД
**Severity: major · Тип: DATA-INCONSISTENCY · Проверка: ✅ подтверждено (live `\d` + pg_indexes)**
**Файлы:** `src/database/migrations/2026_06_11_120003_create_catalog_product_prices_table.php:35` (+ комментарий 31-34) · `src/app/Domain/Catalog/Services/ProductService.php:179-184`
**Что происходит:** Live `\d catalog_product_prices` / `pg_indexes`: `uq_catalog_product_prices = UNIQUE btree (product_id, plan_id, currency_code)` **без WHERE** (безусловный), совпадает с миграцией. Два следствия: (a) две time-bounded цены на одну пару (product,plan,currency) с разными `valid_from/valid_to` не могут сосуществовать → time-bounded pricing невозможен (колонки `valid_from/valid_to` есть, но не работают); (b) `plan_id` nullable → Postgres трактует NULL как различные → два базовых ценника (`plan_id IS NULL`, тот же product+currency) на уровне БД **не запрещены**. Нормальный FE-путь идёт через `updateOrCreate` keyed на `(product_id, plan_id, currency_code)` (`ProductService.php:180-184`) и остаётся идемпотентным, но import-путь / сырые insert'ы / NULL-plan дубли БД не защищает. Verdict: confirmed, finalSeverity=major, confidence 0.85.
**Repro:** `\d catalog_product_prices` → UNIQUE без WHERE; две базовые цены (`plan_id NULL`) на один product/currency вставляются обе.
**Предлагаемый фикс:** Заменить на частичные индексы: `UNIQUE (product_id, currency_code) WHERE plan_id IS NULL AND valid_from IS NULL AND valid_to IS NULL` + `UNIQUE (product_id, plan_id, currency_code) WHERE plan_id IS NOT NULL AND valid_from IS NULL AND valid_to IS NULL`; либо включить окно валидности в ключ. Добавить upsert-гард на пересечение окон. Сначала ответить на open question: нужен ли time-bounded pricing для S1.x вообще (если нет — выпилить `valid_from/valid_to` или задокументировать как future).

---

### MAJOR #4 — Refresh-кнопка курсов → 405 + видна всем ролям
**Severity: major · Тип: DEAD-CODE · Проверка: ✅ подтверждено (route-инспекция + grep; POST не запускали — несуществующий роут даёт 405 без мутации)**
**Файлы:** `front/src/api/catalog.ts:327-328` · `front/src/pages/ExchangeRatesPage/composables/useExchangeRatesActions.ts:45` · `front/src/pages/ExchangeRatesPage/index.vue:9-16,31` · `src/routes/api.php` (catalog-блок 336-364)
**Что происходит:** `catalogApi.refreshRates()` POST'ит `/api/catalog/exchange-rates/refresh`. Такого роута нет (catalog-блок содержит только `convert` + `apiResource` + `price-import`(+preview)); grep по `src/routes` для `exchange-rates/refresh`|`refresh-rates`|`refreshRates` = пусто. POST на неопределённый роут → Laravel **405**. Кнопка Refresh (`index.vue:9-16`) **без `v-if=canWrite`** (в отличие от Add-manual `:18`) → видна manager/lawyer; `ExchangeRateAgeWarning` тоже `@refresh=refreshRates` (`:31`). Каждый клик → error-toast. Нет backend-роута триггернуть `UpdateExchangeRatesJob` по требованию. Verdict: confirmed, finalSeverity=major, confidence 0.96.
**Repro:** `/admin/exchange-rates` любой ролью → клик Refresh → error-toast. Network: `POST /api/catalog/exchange-rates/refresh` → 405.
**Предлагаемый фикс:** Добавить backend-роут+action (admin/director), который диспатчит `UpdateExchangeRatesJob` и возвращает 202, затем закрыть FE-кнопку под `canWrite`; ИЛИ убрать Refresh + `refreshRates()` + refresh-проводку `ExchangeRateAgeWarning`, пока FX не починен. (Даже с роутом FX мёртв до фикса провайдера — BLOCKER #1.)

---

### minor / trivial (не верифицировано независимо)

- **🟡 minor · BUG · ⚠️ частично (понижено major→minor) — Конвертация кастит decimal-курс к float.** `ExchangeRateService.php:99` `return (int) round($amountKopecks * (float) $rate)` — умножение integer-копеек на float-каст decimal(20,6); cross-rate тоже float (`:170-177`). Нарушение money-rule/стиля. НО при реальных величинах (суммы ~1e8-1e10 копеек × курс ~1e2-1e3 = ≤~1e13, внутри точного диапазона IEEE-754 до 2^53≈9e15) `round()` даёт верную копейку; потеря только при экстремальных магнитудах (>~1e15). Плюс FX сейчас мёртв → `convertAmount` всё равно возвращает null. Фикс: bcmath (`bcmul((string)$amountKopecks, $rate, 0)`), курс держать строкой end-to-end, не кастить к float; cross-rate через `bcdiv`.
- **🟡 minor · DEAD-CODE · ✅ подтверждено live (понижено major→minor) — «Download template» → 404.** `catalog.ts:329` `downloadTemplateUrl()` = `/api/catalog/price-import/template`; роута нет; live `GET` → 404. Используется в ProductsPage Import-меню (`index.vue:378`) и в `PriceImportDialog.vue:251`. `<a download>` уходит на 404. Понижено до minor: UX/feature-gap (колонки можно описать inline), без security/data-риска. Фикс: добавить `GET .../template`, отдающий sample .xlsx (admin-write), ИЛИ убрать обе affordance и описать колонки в диалоге.
- **🟡 minor · SPEC-DRIFT · ✅ подтверждено (понижено major→minor) — FE BillingUnit + plan-dialog без 'perpetual'.** BE-enum `BillingUnit.php:13` — 5 кейсов с `Perpetual='perpetual'` (label «Вечная лицензия»), от него зависит one-perpetual guard. FE-тип `entities/catalog.ts:10` — 4 кейса; `ProductPlanCreateDialog.vue:145-150` `unitOptions` — только 4. Нельзя создать вечный план из UI; у существующего perpetual-плана в диалоге нет выбранной опции (пустой unit). Edit submit'ит `form.unit` дословно (`:185`), так что правка других полей не сбросит unit, но переключить/перевыбрать 'perpetual' нельзя. BE и one-perpetual guard корректны, perpetual достижим через import/API → minor. Фикс: добавить 'perpetual' в FE-тип и `unitOptions` + i18n-ключ `catalog.products.unit.perpetual`; добавить Perpetual в vault-enum.
- **🟡 minor · DEAD-CODE · не верифицировано (Phase-1) — Нет UI удаления цены.** Backend `DELETE /api/catalog/products/{product}/prices/{price}` (`ProductPriceController@destroy`) без FE-вызова — prices-tab поддерживает только upsert (`savePrice`). Нет affordance удалить строку цены; единственный способ «очистить» — выставить amount=0. Фикс: добавить delete-affordance (clear/trash на ячейке) → `DELETE prices/{price}`; ИЛИ убрать endpoint, если delete намеренно не поддерживается.
- **🟡 minor · BUG · не верифицировано (Phase-1) — Excel-импорт читает amount через `getFormattedValue()`+`(float)`.** `PriceImportService.php:230` читает ячейки `$cell->getFormattedValue()` (строка отображения с разделителями тысяч / валютным символом / locale-запятой), затем `(float)$rowData['amount']` (`:113,267`) ×100 → копейки. Ячейка `'1 200,50'`/`'1,200.50'`/`'1200,5'` может распарситься в 1.0 или 0 в зависимости от locale → молча неверные цены. Фикс: читать сырое числовое значение (`getValue()`/`getCalculatedValue()`) для колонки amount, либо нормализовать строку (убрать разделители, унифицировать десятичный) до `(float)`; валидировать parsed > 0.
- **⚪ trivial · SPEC-DRIFT · не верифицировано (Phase-1) — Scheduler 03:00 vs vault 00:05 UTC.** `console.php:31` `->dailyAt('03:00')`; vault-спека «Курсы валют (Job + Command)» говорит `->daily()` (00:05 UTC). Фикс: выровнять код к доке или обновить vault на '03:00' (чинить вместе с провайдером — BLOCKER #1).

### Релевантные NEW-* из live-QA

- **NEW-5 (P1, источник = live-QA) — manager читает `/api/admin/*` справочники.** Manager успешно GET'ит `/api/admin/{company-types,sources,countries,cities,contact-positions,acquisition-channels,disconnect-reasons}` (все 200). **Это НЕ Catalog-домен** — это справочники CRM под `/api/admin/*` (визуально соседствуют с «Каталог» на Settings, но принадлежат CRM/Settings-домену). Фиксируется в CRM-аудите; здесь упомянуто как смежный контекст, т.к. `acquisition-channels`/`disconnect-reasons` — чувствительная business-intelligence, которую manager читать не должен. Эндпоинты самого Catalog (`/api/catalog/*`) имеют корректную авторизацию.

Прочие NEW-1..NEW-9 относятся к CRM-компаниям/контактам, Deals, Onboarding, auth-middleware — вне Catalog-домена.

## 7. Расхождения со спекой (vault) и предложения по актуализации

Документ: **`2. Модули/Catalog — Каталог продуктов и цены.md`** (+ затрагивает `5. Планы`).

1. **Секция «Курсы валют (Job + Command)».** Спека: `UpdateExchangeRatesJob → exchangerate-api.com (v6, USD-base)`, `Scheduler: ->daily() (00:05 UTC)`, config `api_url/api_key env`. Реальность: код-дефолт `api_url=https://api.exchangerate.host` (НЕ exchangerate-api.com), endpoint `/latest`; scheduler `->dailyAt('03:00')` (`console.php:31`); endpoint требует `access_key`, job написал **0 строк** с деплоя (подсистема мертва). **Изменить:** задокументировать фактический провайдер/url и требование `access_key`; зафиксировать scheduler 03:00 ИЛИ поправить код; добавить пометку «FX сейчас сломан (0 строк)» и что провайдер должен отдавать известный response-shape (`exchangerate-api.com` v6 → `conversion_rates` vs `exchangerate.host` → `rates`). Выбрать один провайдер в коде и доке синхронно.

2. **Секция «Enums (BillingUnit)».** Спека: 4 кейса (Year, OneTime, Minute, Package). Реальность: BE `BillingUnit.php` — 5 кейсов с `Perpetual='perpetual'` (label «Вечная лицензия»), от него зависит one-perpetual guard; FE-тип/диалог — только 4 (отдельный FE-drift). **Изменить:** добавить кейс Perpetual в vault-enum; отметить, что `ProductPlan.unit` может быть 'perpetual' и что создание 2-го perpetual-плана на продукт блокируется (422).

3. **Секция «ProductPrice — UNIQUE».** Спека: `UNIQUE (product_id, plan_id, currency_code)` для базовых цен (`valid_from/to IS NULL`); `plan_id IS NULL` = базовая цена (т.е. частичный unique). Реальность: миграция шипит **безусловный** unique без WHERE → time-bounded цены невозможны, NULL-plan базовые не дедуплятся (Postgres NULL-distinct). **Изменить:** либо пометить partial-unique как нереализованный follow-up, либо описать фактический безусловный unique и ограничение по NULL-plan; согласовать с выбранным фиксом MAJOR #3.

4. **Секция «Frontend-страницы / Известные follow-up».** Спека описывает рабочие refresh-кнопку, dry-run preview и download-template. Реальность: Refresh → 405 (нет роута); «preview» пишет (store игнорит dry_run); «Download template» → 404 (нет роута) — ни одна из трёх affordance не работает end-to-end. **Изменить:** добавить подсекцию «Known broken»: (1) `/exchange-rates/refresh` роута нет, (2) `/price-import` preview пишет (неверный роут), (3) `/price-import/template` роута нет — с владельцами фиксов.

**Открытые вопросы (для PM):** (a) целевой FX-провайдер — `exchangerate-api.com` v6 (vault) или `exchangerate.host` (config)? фикс зависит от auth + response-shape; (b) нужен ли time-bounded pricing (колонки `valid_from/valid_to`) для S1.x или они спекулятивны; (c) должен ли FE уметь удалять цену или «set amount=0» — намеренный способ; (d) подтвердить в Sales-аудите, что `DealProduct` хранит копеечный снэпшот (не FK) на момент добавления; (e) нужен ли on-demand FX-refresh из UI (оправдывает новый роут) или Refresh-кнопку выпилить.
