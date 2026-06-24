# Аудит домена: Миграция — AMO ETL (external_refs, migration_maps, amo_product_mappings)

## 1. Назначение

Домен `app/Domain/Migration` — это временный, ограниченный (bounded) контекст одноразовой миграции данных из **AMO CRM v4 → MGCRM**, запланированный к сносу на milestone M12 вместе с `examples/`. Он реализует 4-фазный ETL-конвейер (extract → transform → load → verify), управляемый исключительно консольной командой `php artisan amo:migrate {phase}`. HTTP-поверхности нет вообще: ни роутов, ни контроллеров, ни FormRequest/Resource/Policy — авторизация = граница CLI (оператор с shell-доступом + env `AMO_MIGRATION_TOKEN`). Сам загрузчик инженерно добротен: пер-сделочная транзакционная изоляция, гарантированный rollback в dry-run, идемпотентность через `external_refs`, деньги в копейках (×100), бэкдейченная история стадий.

**Зрелость: каркас, добротно собранный, но мёртвый в проде (dormant).** Обоснование по живым row counts: `external_refs = 0`, `migration_maps = 0`, `amo_product_mappings = 94`. `external_refs` пишется на каждую персистнутую сущность во время `load` (через `ExternalRefRegistry.remember`), поэтому **0 строк = реального прогона load/extract против живых данных никогда не было** — домен прогонялся только сидером + SQLite-юнит-тестами. Это согласуется с проектным решением (DEC) «перенос данных не нужен — тестовые данные» и со статус-заголовком билд-плана в vault «РЕАЛЬНОГО ПРОГОНА не было». Целевой слой нативных полей (N1–N7: `created_by_id`, `is_service`, `amount_locked`, `signed_at/paid_at`, `perpetual_license`, acquisition-channel resolver) **построен и присутствует в живой схеме** — то есть «приёмник» миграции в основном приземлился; провал — в неподключённой проводке product/option-маппинга, отсутствующей фазе rollback и фиктивной events-parity.

---

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI + endpoint) | Как (кратко) | Статус | Примечание |
|---|---|---|---|---|---|
| `amo:migrate extract` | Оператор с shell + `AMO_MIGRATION_TOKEN` (никакой роли MGCRM) | CLI: `php artisan amo:migrate extract [--only= --status= --limit= --resume]`. UI/endpoint нет | Abort, если `config('amo_migration.api.token')` пуст (`AmoMigrateCommand.php:88`) → тянет AMO API v4 в порядке зависимостей (leads→contacts→companies→tasks→events→notes) через Extractors → `AmoClient` (Bearer, ~6 rps, retry 429/5xx) → JSONL в staging через `StagingWriter`; чекпоинты для `--resume` | 🟡 частично (unverified) | Код построен и юнит-тестирован, но **никогда не запускался против живого AMO** (`external_refs=0` ⇒ load не шёл ⇒ staging не потреблялся). Статически поведение API на объёме подтвердить нельзя |
| `amo:migrate transform` | Оператор (CLI) | CLI: `php artisan amo:migrate transform` (= load c принудительным `dry_run`, `AmoMigrateCommand.php:71`) | Стримит staging через `StagingReader` → `MigrationLoader.load(dry_run=true)`: полный transform на сделку внутри транзакции, ВСЕГДА откатываемой (`finally`, `MigrationLoader.php:167`) → отчёт покрытия (would-create, skipped+reasons, unmapped status/user/product/country, хвост конфликтов). Не пишет ничего | ✅ работает | Статически корректно: forced dry-run + гарантированный rollback. Отчёт покрытия есть. На реальном staging не прогонялся |
| `amo:migrate load` | Оператор (CLI) | CLI: `php artisan amo:migrate load [--limit= --dry-run]` | На каждый AMO-lead в ОДНОЙ транзакции: gate на resolvable stage/pipeline/currency (иначе skip всей сделки) → loadCompany (реальная или синтетическая `lead-company`) → loadContacts (+линк к компании, upsert каналов) → loadDeal (owner/created_by через resolver-fallback, money=price×100 коп., `amount_locked=true`, signed_at/paid_at vs expected_*, perpetual_license) → loadEvents (genesis/stage_change/data_change → бэкдейченные stage_history+deal_audits+entity_logs) → loadActivities (tasks+notes → activities, raw insert, ref-keyed) → maybeMarkUniqueClient + reconcilePrimaryDeal. Идемпотентность через `ExternalRefRegistry`; пер-сделочный сбой ловится, run продолжается | 🟡 частично | (a) **никогда не запускался против живых данных** (`external_refs=0`); (b) **не создаёт `deal_products` из `amo_product_mappings`** — vault Фича 5 требует строки товаров; loader их роняет, кладя лишь сырые category/country-строки в `extra_fields`. Нативные поля (`created_by_id`, `amount_locked`, signed_at/paid_at, perpetual_license, acquisition channel) — подключены |
| `amo:migrate verify` | Оператор (CLI) | CLI: `php artisan amo:migrate verify` | `MigrationVerifier.parity()`: staging-count vs `count(external_refs by type)` для deals/contacts/companies/activities; spot-check последних сделок | 🟡 частично | Реальные parity-строки для deals/contacts/companies/activities корректны. **Строка events бессмысленна**: `row($stagingEvents, $stagingEvents)` (`MigrationVerifier.php:62`) — staging сравнивается сам с собой, diff всегда 0 |
| `amo:migrate rollback` | Оператор (CLI) по runbook vault | Vault build-plan §0 (сигнатура) + §8 step 7 предписывают `amo:migrate rollback` (чистка импортированного через `external_refs`). В коде такой фазы НЕТ | 🔴 сломан / ⚪ отсутствует | `AmoMigrateCommand.php:70-80` обрабатывает только extract\|transform\|load\|verify; `rollback` падает в ветку «Unknown phase». Откат возможен лишь ручным SQL по `external_refs` либо restore пред-load бэкапа |

**Поправка от live-QA:** в Live QA домен миграции не тестировался отдельной journey — в SPA нет ни одного экрана/пункта меню AMO/миграции (исчерпывающий поиск по `front/src` дал ноль ссылок). Это подтверждает console-only-характер домена, а не баг.

---

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк в живой БД | Статус |
|---|---|---|---|---|
| `ExternalRef` | `external_refs` | Provenance/идемпотентность: маппинг AMO-id → локальный PK (FK-less полиморф через `entity_type`+`entity_id`). Ядро идемпотентности фазы load: повтор делает upsert по `(source,entity_type,external_id)` вместо дублей | **0** | built (мёртв в проде — load не шёл) |
| `MigrationMap` | `migration_maps` | По спеке (vault Фаза-0 #2 + коммент config) — объёмная авто-карта для опций кастом-полей/продуктов AMO. На деле МЁРТВ: не пишется/не читается ни одним ETL-кодом; в resolver нет ни одной CF/option-резолюции | **0** | stub (built-but-dead) |
| `AmoProductMapping` | `amo_product_mappings` | Руками выверенная карта 94 enum-опций «Продукт» AMO → каталожный продукт/план MGCRM с `action=map\|skip\|other`. Vault Фича 5 предписывает создавать `deal_products` при импорте. Засидено (18 map / 76 skip) и тяжело покрыто тестами, но **не читается loader'ом** | **94** | partial (built, unwired) |

**Расхождения migration ↔ live-schema ↔ model:**

- **Дрейфа колонок НЕТ.** Модели `ExternalRef`/`MigrationMap`/`AmoProductMapping`, их миграции и живая `schema.sql` совпадают точно по именам, длинам, nullability, индексам и unique-констрейнтам: `external_refs` UNIQUE(`source`,`entity_type`,`external_id`); `amo_product_mappings` UNIQUE `amo_enum_id` (`uq_amo_product_mappings_enum`). Все цели raw-insert (`activities`, `deal_audits`, `entity_logs`, `deal_contacts`, `crm_companies`, `crm_contacts`, `deal_stage_history`) существуют с ожидаемыми колонками.
- **Целевые нативные поля присутствуют:** `created_by_id` FK на `deals`/`crm_contacts`/`crm_companies` (миграция `2026_06_28_120003`), `is_service` на `users` (`2026_06_28_120004`), `amount_locked` + `signed_at/paid_at` + `perpetual_license` на `deals` (`2026_06_25_120000`), `is_primary_deal` (`2026_06_27_120000`). Слой N1–N7 в основном приземлился.

**Пустые-при-наличии-кода таблицы (built-but-dead / unwired):**

- `migration_maps` (0 строк) — таблица+модель+миграция+schema-CRUD-тест существуют, но **ни один ETL-код не пишет и не читает** её. `config/amo_migration.php:11-13` и vault Фаза-0 #2 утверждают, что в ней лежат объёмные CF-option/product авто-карты — а `AmoReferenceResolver` не имеет ни одной CF/option-резолюции и никогда к ней не обращается. **Built-but-dead vs spec.**
- `amo_product_mappings` (94 строки: 18 map / 76 skip) — засеяна `AmoProductMappingSeeder` (зарегистрирован в `DatabaseSeeder.php:84`) и провалидирована тестами, но модель не импортируется ни одним loader/transformer. `DealTransformer` не импортирует данные о товарах вообще; инфо о продукте кладётся лишь сырой строкой в `Deal.extra_fields['amo_category']`. Vault Фича 5 требует драйва `deal_products` — **built, но unwired vs spec.**
- `external_refs` (0 строк) при `amo_product_mappings=94` — реальный ETL extract+load никогда не выполнялся против живых данных (только сидер + юнит-тесты). Согласуется с DEC «перенос данных не нужен» и со статус-заголовком билд-плана, но означает, что load/verify не протестированы end-to-end на реальном объёме, а 5 ожидающих native-field миграций не применены на проде.

---

## 4. Эндпоинты и покрытие фронтом

**HTTP-поверхности у домена нет.** В `src/routes/api.php` нет ни одного `Route::`, касающегося `amo`/`migration`; единственная ссылка на AMO в роутинге — `routes/console.php` (планировщик бота SalesPulse, не ETL).

| Метод+Path | Контроллер@метод | Авторизация | Вызывается фронтом? | Примечание |
|---|---|---|---|---|
| — (нет) | — | CLI-граница only (artisan): `extract` доп. gated на `AMO_MIGRATION_TOKEN`, `load` gated на наличие fallback-юзера в `AmoReferenceResolver.fallbackUserId` | Нет | Весь домен — console-only. `php artisan amo:migrate {extract\|transform\|load\|verify}` |

**Orphaned FE-вызовы:** нет. Исчерпывающий поиск по `front/src` (по `amo`, `amocrm`, `external_ref`, `migration_map`, `etl`, `cutover`, `amo:migrate`) дал **ноль** ссылок в `.vue`/`.ts`. Единственные AMO-хиты — doc-комментарии про несвязанный «AMO-style» task-card UI. Единственная страница «import» (`ProductsPage/PriceImportDialog.vue`) дёргает `catalogApi` (импорт прайса каталога — другой домен). `api/system.ts` экспонирует лишь `/api/system/reset`. Нет `api/migration.ts`, нет page/route/store. Это архитектурно корректно для временного CLI-контекста, сносимого на M12.

**Мёртвых endpoint'ов:** нет (их попросту нет).

---

## 5. RBAC домена

| Действие | Кому разрешено | Где реально проверяется | Дыра? |
|---|---|---|---|
| Запуск любой фазы `amo:migrate` (extract/transform/load/verify) | Любой оператор с доступом к контейнеру/shell; никакая роль приложения MGCRM не проверяется | Граница CLI (artisan-команда). Нет Gate/Policy/permission. `extract` доп. gated на env `AMO_MIGRATION_TOKEN` (`AmoMigrateCommand.php:88`); `load` gated на существование fallback-юзера (`AmoReferenceResolver.fallbackUserId`) | Не дыра в смысле приложения: контроль = доступ к серверу/контейнеру + знание токена. Это сознательная модель для одноразового cutover-инструмента |
| Чтение/запись `migration_maps`, `external_refs`, `amo_product_mappings` через API | N/A — HTTP-поверхности нет | Нет контроллеров/роутов; таблицы — CLI/seeder-only | Поверхности для эскалации нет: данные недостижимы из SPA ни одной ролью |

**Итог по RBAC:** домен не участвует в ролевой модели MGCRM. Авторизация = периметр инфраструктуры (shell + `AMO_MIGRATION_TOKEN`), а не роли приложения. Это корректно для temporary bounded context; никакой in-app RBAC-дыры домен не создаёт (в отличие от crm-contacts/crm-companies/sales-kpi из live-QA — это другие домены).

---

## 6. Бэклог проблем

### Сводная таблица

| # | Severity (FINAL) | Тип | Заголовок | Проверка |
|---|---|---|---|---|
| 1 | **major** | SPEC-DRIFT | `amo_product_mappings` (94 строки) не читается ETL — товары/строки дропаются при импорте, против vault Фича 5 | ✅ подтверждено (static + live DB) |
| 2 | **major** | SPEC-DRIFT | `migration_maps` (таблица+модель) полностью мертва — не пишется/не читается, против vault Фаза-0 #2 | ✅ подтверждено (static + live DB) |
| 3 | **minor** | MISSING | Фаза `amo:migrate rollback` из runbook не реализована — плохой live-load нельзя откатить инструментом | ⚠️ частично (понижено major→minor при верификации) |
| 4 | minor | SPEC-DRIFT | `config/amo_migration.php` документирует авто-карт-механизм таблиц, которого код не реализует | не верифицировано (Phase-1) |
| 5 | minor | BUG | `MigrationVerifier` events-parity сравнивает staging сам с собой — не ловит потерянный таймлайн | не верифицировано (Phase-1) |
| 6 | minor | STUB | Путь load/verify не проверен end-to-end — extract никогда не запускался (`external_refs=0`), cutover не протестирован на объёме | не верифицировано (Phase-1) |
| 7 | trivial | CONVENTION | `AmoProductMapping.action` — свободный `varchar(8)` без enum/валидации/cast | не верифицировано (Phase-1) |
| 8 | trivial | MISSING | У домена нет FE-поверхности (console-only) — нет admin import/monitor UI | не верифицировано (Phase-1) |

---

### MAJOR #1 — `amo_product_mappings` не читается ETL: товары дропаются при импорте

**Severity: major · Тип: SPEC-DRIFT · Проверка: ✅ подтверждено (static-проба + live DB, confidence 0.95)**

**Файлы:**
- `src/app/Domain/Migration/Models/AmoProductMapping.php:18`
- `src/app/Domain/Migration/Transformers/DealTransformer.php:16-17` (докблок)
- `src/app/Domain/Migration/Transformers/DealTransformer.php:159-175` (extraFields)
- `src/database/seeders/AmoProductMappingSeeder.php:37`

**Что происходит (evidence):** `AmoProductMapping` упоминается только в собственной модели, миграции, сидере и тестах — `grep -rn AmoProductMapping` по `src/app+database+config` даёт ноль ссылок в loader/transformer/resolver. `DealTransformer` не трогает товары: он лишь вытягивает строки `amo_category`/`amo_country` в `Deal.extra_fields` (`DealTransformer.php:166,171`), а его докблок прямо заявляет, что `recalcAmount` не должен пересчитывать сумму из «несуществующих line items» — то есть строки товаров намеренно отсутствуют. `AmoReferenceResolver` (прочитан целиком) не имеет ни одного метода резолюции product/enum-опции. Live: распределение `action` = 18 map / 76 skip (94 всего), `external_refs=0`. Vault «AMO-поля → нативные фичи MGCRM (дизайн)» Фича 5 (строки 22, 53-54, 114) и §N7 однозначно предписывают: AMO 590196 (multiselect) → `amo_product_mappings` → создание `deal_products` в каталоге (`action=skip` дроп, `action=map` attach). Итог: **18 выверенных каталожных маппингов не дают ничего при импорте**, а 94-строчный сидер едет в `DatabaseSeeder` — курация выглядит «живой», имея нулевой эффект.

**Repro:** засидить `AmoProductMappingSeeder` (94 строки, подтверждено live) → запустить `amo:migrate load` на lead'е с замапленным product-enum (`action=map`) → у созданной сделки нет каталожного product/plan/`deal_products`; есть лишь `Deal.extra_fields['amo_category']` (сырая строка). Таблица обходится целиком.

**Предлагаемый фикс:** подключить `AmoProductMapping` в `loadDeal`: резолвить AMO-product-enum-id на сделку в `catalog_product_id`/plan и создавать соответствующие строки `deal_products` (`action=skip` → дроп, `action=map` → attach, с `amount_locked=true`, чтобы `recalcAmount` не перезатёр импортированный бюджет — по Фиче 5). Если импорт товаров сознательно отложен за cutover — обновить vault Фича 5 (явно сказать это) и отключить/удалить сидер, чтобы курацию не приняли за живое поведение.

---

### MAJOR #2 — `migration_maps` полностью мертва (built-but-dead vs vault Фаза-0 #2)

**Severity: major · Тип: SPEC-DRIFT · Проверка: ✅ подтверждено (static-проба + live DB, confidence 0.93)**

**Файлы:**
- `src/app/Domain/Migration/Models/MigrationMap.php:17-19`
- `src/config/amo_migration.php:11-13`
- `src/app/Domain/Migration/Support/AmoReferenceResolver.php`

**Что происходит (evidence):** `grep -rn MigrationMap` по `src/app+database+config` даёт использование ТОЛЬКО в `Models/MigrationMap.php:17-19` (+ его миграция + `tests/Feature/Migration/MigrationSchemaTest.php`). Строка `'migration_maps'` встречается лишь в `Models/MigrationMap.php:19` (`$table`) и `config/amo_migration.php:12` (комментарий). `AmoReferenceResolver` (прочитан целиком) экспонирует методы `ownerUserId/userId/fallbackUserId/pipelineIdForStatus/pipelineIdByCode/pipelineIdByAmoId/stageForStatus/stageId/channelIdForEnum/channelIdByName/taxIdLabel/toDate/toDateTime` — все читают `config('amo_migration.*')` исключительно (`status_map`/`user_map`/`pipelines`/`channel_map`/`tax_id_label_map`); ни один не запрашивает `migration_maps`; CF/option-резолюции нет вовсе. Live: `migration_maps=0`. Vault build-plan §1 (строка 24) специфицирует `migration_maps` как объёмную CF-field/option авто-карту («их много»); design-doc §N7 (строка 110) числит её построенной инфраструктурой.

**Митигирующий контекст (не понижает severity):** механизм не построен, потому что gated на ожидающий список полей DEC-CF («ждём список») — то есть «не построено» частично сознательно. Актуальный дрейф — это утверждения config/vault, что слой *активен*, тогда как resolver вообще не имеет CF/option-перевода.

**Repro:** поиск по кодовой базе — единственные писатели/читатели `migration_maps` это schema-тест. Запустить любую фазу `amo:migrate` → `migration_maps` остаётся пустой; перевод CF-опций не происходит (resolver читает только config).

**Предлагаемый фикс:** определиться с намерением. Если перевод опций кастом-полей AMO нужен для cutover (DEC-CF: импорт выбранного пользователем подмножества CF) — реализовать путь resolver'а, который наполняет и читает `migration_maps`. Иначе — удалить таблицу/модель/миграцию и исправить комментарий `config/amo_migration.php`, описывающий несуществующий механизм, плюс обновить vault Фаза-0 #2.

---

### MINOR #3 — Фаза `amo:migrate rollback` из runbook не реализована

**Severity: minor (понижено major→minor при верификации) · Тип: MISSING · Проверка: ⚠️ частично (static, confidence 0.95)**

**Файлы:**
- `src/app/Console/Commands/Migration/AmoMigrateCommand.php:53-80`

**Что происходит (evidence):** vault «AMO→MGCRM — полный план миграции (build)» §0 документирует сигнатуру как `php artisan amo:migrate {extract|transform|load|verify|rollback}`, а §8 step 7 гласит: «Откат при необходимости: `amo:migrate rollback` (через external_refs вычищает импортированное)». Фактическая команда: `$signature` (`:52-58`) объявляет `phase=extract|transform|load|verify`; `handle()` `match()` (`:69-75`) роутит только эти четыре, `default→failUnknownPhase` (`:78-83`) — «Unknown phase…». `grep 'rollback'` по `app/Domain/Migration/` + `Console/Commands/Migration/` даёт единственный хит `MigrationLoader.php:167` «GUARANTEED rollback» — это транзакционный откат dry-run, а не фаза. `RollbackLoader`/undo-сервиса в `Loaders/` нет (там только `ExternalRefRegistry`, `MigrationLoader`, `MigrationVerifier`, `StagingReader`).

**Почему понижено до minor (по верификации):** (1) сам статус-заголовок билд-плана в vault уже числит «soft-dedup/rollback TBD» в разделе «Хвосты» — это известный хвост, а не скрытый провал; (2) документированный fallback — restore из пред-load бэкапа (§8 step 4 «Бэкап БД до load»), то есть плохой load восстановим без инструмента; (3) `external_refs=0` ⇒ ни одного live-load не было ⇒ сегодня реального риска нет. Реальный фикс — гигиена документации, а не код.

**Repro:** `php artisan amo:migrate rollback` → «Unknown phase rollback (expected: extract|transform|load|verify).» Подтверждено чтением `match()` и `$signature`.

**Предлагаемый фикс:** либо реализовать фазу rollback (обход `external_refs` по `entity_type` в обратном FK-порядке, удаление импортированных deals/contacts/companies/activities + их `external_refs` в транзакции, с `--dry-run`-превью) по runbook; либо, если откат считается ненужным (one-shot cutover с пред-load бэкапом), убрать токен `rollback` из сигнатуры §0 и §8 step 7, чтобы runbook был точен. Минимум — выровнять §0/§8 со статус-заголовком «rollback TBD».

---

### minor / trivial (не верифицировано — Phase-1)

- **minor · SPEC-DRIFT — `config/amo_migration.php:11-13` описывает несуществующий авто-карт-механизм.** Хедер config утверждает, что объёмные авто-карты (CF-опции, продукты) живут в `migration_maps`/`amo_product_mappings`, а resolver consults их при bulk-маппинге. Реально resolver читает только config (`status_map`/`user_map`/`pipelines`/`channel_map`/`tax_id_label_map`) и никогда не обращается к таблицам. Разделение ответственности — фикция. Фикс: привести комментарий в соответствие реальности (только config) либо реализовать табличную резолюцию; держать в синхроне с фиксами #1/#2. *(confidence 0.85)*
- **minor · BUG — `MigrationVerifier.php:62` events-parity сравнивает staging сам с собой.** Строка `'events_staged' => $this->row($stagingEvents, $stagingEvents)` — обе стороны равны staging-count, diff всегда 0. События грузятся в `deal_stage_history`/`deal_audits`/`entity_logs` (не в `external_refs`), provenance-count для сравнения нет → строка бессмысленна и никогда не флагнет, что реконструкция таймлайна молча дропнула строки (что `loadEvents`/`skipHistory` умеют). Фикс: убрать строку (честно — у событий нет provenance) либо сравнивать с реальным loaded-сигналом (count `entity_logs`/`stage_history` c import-маркерами или exposed `history_skipped`). *(confidence 0.85)*
- **minor · STUB — путь load/verify не проверен end-to-end (`external_refs=0`).** Live: `external_refs=0`, `migration_maps=0`, `amo_product_mappings=94`. `external_refs` пишется на каждую персистнутую сущность (`ExternalRefRegistry.remember`) → 0 строк = load никогда не шёл против живых данных; extract (его пререквизит) тоже не дал потреблённого staging. Прогонялись лишь сидер + SQLite-тесты. Vault: «РЕАЛЬНОГО ПРОГОНА не было». Согласуется с DEC (тестовые данные), но ~1175-строчный `MigrationLoader`, resolver-fallback'и и пер-сделочная устойчивость не имеют валидации на реальном объёме; 5 ожидающих native-field миграций не применены на проде. Фикс: при реальном cutover применить ожидающие миграции, выставить `AMO_MIGRATION_TOKEN`, прогнать bounded smoke (`extract --limit` + `transform --dry-run` + `load --limit=50` + `verify`) на staging-БД; иначе задокументировать, что AMO ETL сознательно dormant. *(confidence 0.8)*
- **trivial · CONVENTION — `AmoProductMapping.action` свободный `varchar(8)` без enum/cast.** Значения `map|skip|other` (live 18 map / 76 skip), но нет Enum-класса, нет DB-check-констрейнта, в `casts()` поле отсутствует. ARCHITECTURE.md требует доменных Enum для status-like полей. Низкий импакт: колонка сейчас не используется ETL и пишется только сидером. Фикс: ввести `AmoProductMappingAction` enum (map|skip|other) и cast'ить — либо принять вольность, т.к. таблица одноразовая (снос M12). *(файлы: `Models/AmoProductMapping.php:34-41`, `migrations/2026_06_28_120002_create_amo_product_mappings_table.php`; confidence 0.7)*
- **trivial · MISSING — у домена нет FE-поверхности (console-only).** Исчерпывающий поиск по `front/src` — ноль ссылок на amo/amocrm/external_ref/migration_map/etl/cutover/`amo:migrate`. Нет `api/migration.ts`, page, route, store. Архитектурно корректно для temporary CLI-контекста (M12). Подтверждено live-QA: ни одной AMO/migration journey в SPA. Фикс не требуется, если CLI-граница намеренна; иначе in-app cutover-консоль потребует новых BE HTTP-routes + admin-only Policy. *(файлы: `front/src/router/routes/base.ts`, `front/src/api/system.ts:20`, `src/routes/console.php:52`; confidence 0.95)*

---

### Релевантные NEW-* из live-QA

В live-QA **нет NEW-issue, относящихся к домену миграции** — все NEW-1…NEW-9 лежат в crm/sales/onboarding/auth (CompanyChannelsBlock, DealPage 403, i18n-ключ, `Route [login]` 500, `/api/admin/*` для manager, Физлица-таб, KPI «Всего: 2», «Продолжить» в курсах, SPA не на :8080). Они фиксируются в своих доменных отчётах. Косвенно релевантно: NEW-9 (SPA только на :5173) и отсутствие AMO-journey подтверждают console-only-характер домена.

---

## 7. Расхождения со спекой (vault) и предложения по актуализации

| Документ (vault) | Раздел | Спека говорит | Реальность | Предложение |
|---|---|---|---|---|
| `AMO-поля → нативные фичи MGCRM (дизайн).md` | Фича 5 — Бюджет импортированных + продукты | AMO 590196 (multiselect) → `amo_product_mappings` → создаём `deal_products` с каталожными ценами «для справки» при импорте; без аналога → skip / продукт-«прочее» | `amo_product_mappings` засеяна (18 map / 76 skip) и юнит-тестирована, но не читается НИ ОДНИМ loader/transformer. `DealTransformer` не создаёт `deal_products` вообще — кладёт лишь сырые `amo_category`/`amo_country` в `extra_fields`. Курация имеет нулевой эффект | Добавить в Фичу 5 build-status callout: «`amo_product_mappings` выверена+засеяна, но НЕ подключена в loader (на момент аудита `deal_products` при импорте не создаются). Для исполнения фичи `loadDeal` должен резолвить AMO-product-enum через таблицу и создавать `deal_products`. До тех пор импортированные сделки без товаров». Завести как открытый ETL-todo |
| `AMO→MGCRM — полный план миграции (build).md` | §0 Архитектура (сигнатура) & §8 Runbook step 7 | `php artisan amo:migrate {extract|transform|load|verify|rollback}`; §8 step 7: «Откат: `amo:migrate rollback` (через external_refs)» | Команда обрабатывает только extract\|transform\|load\|verify; `rollback` = ошибка «unknown phase». Undo-сервиса нет. Статус-заголовок уже числит «rollback TBD» | Пометить rollback как NOT BUILT (TBD) в §0 и §8 step 7 — пробросить «rollback TBD» из статус-заголовка в сигнатуру и runbook. Рекоменд.: в §0 сменить сигнатуру на `{extract|transform|load|verify}` с сноской «rollback — планируется, пока не реализован; откат = restore из пред-load бэкапа (§8 step 4)» — либо реализовать |
| `AMO→MGCRM — полный план миграции (build).md` | §0/§1 `migration_maps` (Фаза-0 #2) + коммент `config/amo_migration.php` | `migration_maps` — объёмные авто-карты для CF-полей/опций (их много); high-volume auto-maps живут в `migration_maps`/`amo_product_mappings` | `migration_maps` читается/пишется лишь schema-CRUD-тестом; resolver не имеет CF/option-резолюции, читает только config. Слой перевода CF-опций не построен | Обновить Фаза-0 #2 и хедер `config/amo_migration.php`: указать, что `migration_maps` сейчас НЕ ИСПОЛЬЗУЕТСЯ (CF-option авто-карт не реализован; это TODO, gated на список полей DEC-CF). Если импорт подмножества CF снят — удалить таблицу из плана |
| `AMO→MGCRM — миграция данных (оценка).md` | Status frontmatter / §5 (verify parity) | Parity: «count событий AMO vs загружено» среди проверок | events-parity сравнивает staging сам с собой (diff всегда 0), не валидирует загрузку таймлайна, не флагнет скип history | В §5 отметить, что events-parity не является реальной проверкой (у событий нет `external_refs`-provenance); либо убрать её, либо строить на import-маркерах `entity_logs`/`stage_history` |

**Раздел «5. Планы» / Master Roadmap:** статус M12 (cutover) корректно числит ETL как незапущенный; стоит явно зафиксировать, что (a) реального прогона load не было (`external_refs=0`), (b) `amo_product_mappings`/`migration_maps` построены, но НЕ подключены, (c) фаза rollback не реализована, (d) 5 ожидающих native-field миграций (`company_requisites`/`disconnect_reasons`/`client_status` по статус-заголовку) не применены на проде. Это снимет риск прочтения пустых таблиц как «сломанной фичи» и зафиксирует go/no-go-вопросы по cutover (нужен ли реальный импорт, нужен ли импорт товаров/CF-опций, нужен ли tool-based rollback).
