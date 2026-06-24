# Аудит домена: Договоры — лицензиары, шаблоны, переменные, AI-проверка

> Домен `app/Domain/Contracts` (часть S2.1 + S2.3). Backend: Laravel 13 (`src/`). Frontend: Vue 3.5 SPA (`front/`).
> Аудит на живой среде от 2026-06-24. Severity по каждому blocker/major — финальный, после adversarial-верификации (Phase 2) и/или браузерного QA (Phase 3).

---

## 1. Назначение

Домен отвечает за фундамент генерации договоров: **лицензиары** (наши собственные юрлица по странам — директор, ИНН/БИН, банк, счёт, которые подставляются в договор по `country_code`), **трёхслойную систему шаблонов** (`master_skeleton`/`termination_agreement` как docx-скелеты + YAML-оверлеи по продукту/стране), **каталог переменных** `{{ custom.<key> }}` (которые менеджер заполняет при создании документа) и **AI-проверку загруженного docx-шаблона** (каскад Prism `document_template` → ремарки → тест-конвертация в PDF через Gotenberg). Сама генерация документа (`ContractGenerationService` → PHPWord `TemplateProcessor` → Gotenberg PDF) потребляет эти артефакты.

**Зрелость: каркас, частично рабочий, но генерация документов МЁРТВА в проде.** Backend-слой структурно зрелый и соответствует ARCHITECTURE.md (FormRequest → тонкий Controller → Service → Model → Resource, политики, DDD-границы). Однако по живым данным: `template_versions` = **0 строк**, у всех 6 docx/yaml-шаблонов `current_version_id = NULL`, все строки `documents` имеют `docx_path = NULL`/`pdf_path = NULL`, `template_versions`/`document_items`/`document_attachments`/`document_remarks`/`contract_number_sequences` = 0. Это значит: **весь конвейер «загрузка docx → AI-проверка → генерация» НИ РАЗУ не отрабатывал на живой среде**, и генерация не может выдать документ ни для одной компании (каждый запрос упирается в `getDocxPath()` → `RuntimeException` → перехват → 422 «Шаблон не загружен»). Каталог переменных (5 строк, все активны) и лицензиары (2 юрлица + 4 банк-счёта) засеяны и читаются генерацией, но множество FE↔BE-расхождений делает фронтовые фильтры/контролы no-op, а UI лицензиаров отсутствует целиком. Итог: основа есть и корректна по сигнатурам, но фича-результат (готовый PDF-договор) недостижим до ручной загрузки реального `master_skeleton.docx`.

---

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI + endpoint) | Как (кратко) | Статус | Примечание |
|---|---|---|---|---|---|
| Загрузка docx-шаблона + AI-проверка | admin, lawyer (BE); в навигации видно admin+director | TemplatePage `/admin/templates/:id` → `POST /api/templates/{template}/upload` | store docx → `createVersion()` в txn (version=pending, set `current_version_id`) → dispatch `CheckTemplateJob` → извлечение текста (PHPWord) → Prism `document_template` + `TEMPLATE_CHECK_PROMPT.md` → разбор `{remarks:[]}` → тест-конвертация Gotenberg → `pdf_ok` → `checked`/`failed` | 🔴 сломан | НИКОГДА не выполнялся live — `template_versions` = 0. Wiring сигнатурно корректен, но не проверен против реального Prism/Gotenberg. FE-кап загрузки 10 MB vs BE 20 MB. |
| AI-перепроверка / override | admin, lawyer | TemplatePage → `POST /versions/{v}/check` (reset pending + redispatch, 202) / `POST /versions/{v}/override` (`ai_overridden=true`, 200) | check: сброс в pending + dispatch job. override: `ai_overridden=true`, remarks сохраняются | 🟡 частично | Построено, не проверено live (0 версий). `/check` без guard против версии в статусе `checking` (concurrency). FE не может отрисовать бейдж `ai_overridden` (нет поля в entity). |
| Машина статусов AI-проверки | system (queue worker) | `CheckTemplateJob` | `pending → checking → checked \| failed`. `null ai_remarks`=не запускалось, `[]`=чисто. Назад в pending только через `/check` (без guard) | ⚪ не верифицировано | Код присутствует и выглядит корректно, но ни разу не прогонялся на реальных данных. Требует worker `--timeout=600 --tries=1` + `retry_after=660`. |
| Генерация договора (потребление шаблона) | manager, lawyer, admin (кто создаёт Document) | server-side `ContractGenerationService::generate` (`GenerateContractJob`/generate endpoint) | `resolveTemplate(doc)` → `termination_agreement`/`master_skeleton` → `getDocxPath()` → нет docx → catch `RuntimeException` → `ValidationException` 422 «Шаблон не загружен»; иначе PHPWord `setValue`/`cloneRow` по `ContractContextBuilder` → Gotenberg PDF | 🔴 сломан | **Не может выдать документ в live**: docx не загружен ни для одного шаблона → любая генерация = 422 (НЕ 500, корректно деградирует). 🌐 подтверждено в браузере (B.6: «История версий: Нет версий», генерация невозможна). |
| Резолв лицензиара для генерации | system | `ContractContextBuilder::build` → `YamlTemplateParser::buildContext` (`YamlTemplateParser.php:59`) | `LicensorEntity::forCountry(code)->first()` из БД; если есть — `toArray()` перекрывает YAML-блок licensor; иначе fallback на `country['licensor']` YAML; иначе null. Flatten в `licensor.*` | 🟡 частично | БД-лицензиар ИСПОЛЬЗУЕТСЯ (опровергает pre-merge «данные мертвы»). НО: `LicensorService::forCountry()`/`primaryAccountForCurrency()` без вызовов; `licensor_override_id` не подключён; подбор банк-счёта по валюте не реализован (`flattenSection` пропускает вложенные `bank_accounts`). |
| Каталог переменных → подстановка `custom.*` | admin/lawyer (определяют), manager (заполняет), system (подставляет) | TemplateVariablesPage CRUD; `ContractContextBuilder::buildCustomSection` | active+forContext-переменные грузятся; required валидируется (termination-scoped vars исключаются на не-termination документах); значения типизируются (checkbox→Да/Нет, date→DD.MM.YYYY, number→форматирование) в `custom.{key}` | ✅ работает | Логика подстановки здравая, CRUD каталога работает. FE create-диалог никогда не шлёт `default_value`/`product_codes`/`country_codes` (всегда wildcard). Неактивные vars нельзя вывести в списке (FE active-фильтр no-op). |
| Guard удаления переменной | admin, lawyer | `DELETE /api/template-variables/{id}` | если `documents.context->custom->{key}` непустой хоть в одном документе → abort 409; иначе физическое удаление | ⚪ не верифицировано | Построено и доступно через API, но НЕТ кнопки/меню удаления в UI → guard недостижим из приложения. Срабатывание 409 нуждается в live-данных. |
| CRUD лицензиаров / банк-счетов | admin, lawyer | 8 admin-endpoint'ов (без UI) | REST CRUD через `LicensorEntityController`/`LicensorBankAccountController`; сброс primary атомарно в txn | 🟡 частично | BE работает (2 entity + 4 счёта засеяны), но ЗЕРО фронтенда — только API. Vault §Ж: UI отложен на S2.10, до сих пор не построен. |

**Итог по статусам:** ✅ работает — 1; 🟡 частично — 3; 🔴 сломан — 2; ⚪ не верифицировано — 2.

---

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк в живой БД | Статус |
|---|---|---|---|---|
| `LicensorEntity` | `licensor_entities` | Наше юрлицо по стране (директор, ИНН/БИН, адрес, банк) — подставляется в договор по `country_code`; строка БД перекрывает YAML-блок licensor | **2** (kz=Проптехсервис Казахстан, uz=Construction software solutions) | partial |
| `LicensorBankAccount` | `licensor_bank_accounts` | Мультивалютные банк-счета лицензиара; один primary на (licensor, currency) | **4** (lic1: KZT primary, USD primary; lic2: UZS primary, USD primary) | partial |
| `Template` | `templates` | Шаблон генерации. `kind=docx` (`master_skeleton`, `termination_agreement`) держит бинарь через `TemplateVersion`; `kind=yaml` (`product_*`/`country_*`) держит оверлей в `content` | **6** (`master_skeleton`[docx, `current_version_id=NULL`], `product_macrocrm/macrosales/macroerp`[yaml], `country_kz/uz`[yaml]) | partial |
| `TemplateVersion` | `template_versions` | Иммутабельный снапшот загрузки docx + результат AI-проверки | **0** (НИКОГДА не создавалась — docx не загружался; весь конвейер upload+AI+generation не прогонялся) | stub |
| `TemplateVariable` | `template_variables` | Каталог переменных `{{ custom.<key> }}`, заполняемых при создании документа; wildcard-скоуп по продукту/стране | **5** (`training_hours`[number], `payment_date`[date], `start_date`[date], `city`[text], `custom_clause`[textarea]) — все активны | built |

**Сопутствующие таблицы (генерация/документы), подтверждающие неотработанность конвейера:** `documents` = **8**, `document_revisions` = **1**, `document_items` = 0, `document_attachments` = 0, `document_remarks` = 0, `contract_number_sequences` = 0.

### Расхождения migration ↔ live-schema ↔ model

1. **`templates`: 3-way drift по числу строк.** `TemplateSeeder.php` определяет **7** шаблонов (включая `termination_agreement`, `kind=docx`, category=cancellation), live DB = **6** (`termination_agreement` отсутствует), vault module-doc говорит «6 шаблонов». Live DB не пересевалась после добавления `termination_agreement` в сидер. Внутренний drift и в самом сидере: docblock-комментарий говорит «6 шаблонов», а массив содержит 7.

2. **`templates.current_version_id = NULL` (docx).** Засеяно NULL **по дизайну** (docx грузится через S2.3 upload endpoint). Live: `master_skeleton.current_version_id = NULL`, `template_versions = 0`. Соответствует задокументированному MVP-состоянию (vault §К: генерация недоступна до первой загрузки docx), но означает, что фича нерабочая до post-deploy загрузки.

3. **Тип `kind`.** БД хранит `docx`/`yaml`/`text`. BE `TemplateResource` возвращает `kind` как сырую строку. **FE `TemplateDto.kind` типизирован как `DocumentKind`** (`contract`/`invoice`/`act`/`reconciliation`) — НЕВЕРНЫЙ тип, корень бага kind-фильтра (см. major #1).

4. **`TemplateVersion` FE-entity vs BE-resource.** BE возвращает `ai_check_status`, `ai_remarks`, `ai_overridden`, `ai_checked_at`, `pdf_ok`, `docx_path`, `created_by_user_id`. FE `TemplateVersionDto` **изобретает** `override_by`/`override_at`/`created_by`/`created_by_name` (ни одно не возвращается) и **опускает** `ai_overridden` → автор всегда «—», бейджа override нет (major #5).

5. **`Template` FE-entity vs BE-resource (category).** Таблица: `category` (одиночная строка, nullable) + `client_category_codes` json. BE возвращает `category`(string) + `client_category_codes[]`. FE `TemplateDto` ждёт `category_codes[]` + `is_active` — ни того ни другого нет в resource (FE неверен и против vault, и против кода). Meta-строка категорий и состояние active не отрисовываются (minor).

6. **`licensor_bank_accounts` потребление.** 4 счёта засеяны с per-currency primaries; данные есть, но **НИКОГДА не читаются генерацией** — `ContractContextBuilder.flattenSection` пропускает вложенные массивы (ставит `''`), `primaryAccountForCurrency` без вызовов (major #8).

**Пустые-при-наличии-кода таблицы:** `template_versions` (0 при полностью реализованном upload/AI-конвейере), `document_items`/`document_attachments`/`document_remarks`/`contract_number_sequences` (0 — генерация ни разу не доходила до создания позиций/PDF).

---

## 4. Эндпоинты и покрытие фронтом

| Метод + Path | Контроллер@метод | Авторизация | Вызывается FE? | Примечание |
|---|---|---|---|---|
| GET `/api/admin/licensor-entities` | `Admin\LicensorEntityController@index` | `LicensorPolicy::viewAny` → любой аутентифицированный | ❌ нет | НЕТ фронтенда для лицензиаров целиком (major #7). Раскрывает `tax_id`/`bank`/`account` всем ролям (minor #13). |
| POST `/api/admin/licensor-entities` | `Admin\LicensorEntityController@store` | `StoreLicensorEntityRequest::authorize` → `can('create')` → admin\|lawyer | ❌ нет | Нет UI. |
| GET `/api/admin/licensor-entities/{licensorEntity}` | `Admin\LicensorEntityController@show` | `view` → любой аутентифицированный | ❌ нет | Нет UI. |
| PATCH `/api/admin/licensor-entities/{licensorEntity}` | `Admin\LicensorEntityController@update` | `UpdateLicensorEntityRequest::authorize` → `can('update')` → admin\|lawyer | ❌ нет | Нет UI — невозможно править юрлицо из приложения. |
| GET `/api/admin/licensor-entities/{licensorEntity}/bank-accounts` | `Admin\LicensorBankAccountController@index` | `view` (LicensorEntity) → любой аутентифицированный | ❌ нет | Нет UI. |
| POST `/api/admin/licensor-entities/{licensorEntity}/bank-accounts` | `Admin\LicensorBankAccountController@store` | `StoreLicensorBankAccountRequest::authorize` → `can('update', licensorEntity)` → admin\|lawyer | ❌ нет | Нет UI. |
| PATCH `/api/admin/bank-accounts/{bankAccount}` | `Admin\LicensorBankAccountController@update` | `UpdateLicensorBankAccountRequest::authorize` → `can('update', licensor)` → admin\|lawyer | ❌ нет | Нет UI (shallow route). |
| DELETE `/api/admin/bank-accounts/{bankAccount}` | `Admin\LicensorBankAccountController@destroy` | controller `authorize('update', bankAccount->licensor)` → admin\|lawyer | ❌ нет | Нет UI (shallow route). Vault: delete должен быть admin-only; код допускает lawyer — drift (trivial). |
| GET `/api/templates` | `TemplateController@index` | `TemplatePolicy::viewAny` → любой аутентифицированный | ✅ да | FE шлёт `kind` (значения `DocumentKind`!) + `search`. Controller форвардит только `kind`/`category`/`product_code`/`country_code`; `search` no-op; `kind=contract` не матчит ничего (col хранит docx/yaml). majors #1, #3. |
| GET `/api/templates/{template}` | `TemplateController@show` | `view` → любой аутентифицированный | ✅ да | Детали + refresh после действий. Возвращает `category`(string) + `client_category_codes`; FE ждёт `category_codes[]` + `is_active` → строки не отрисовываются (minor). |
| PATCH `/api/templates/{template}` | `TemplateController@update` | `UpdateTemplateRequest::authorize` → `can('update')` → admin\|lawyer | ❌ нет | **Orphaned FE-вызов:** `patchTemplate()` определён (`templates.ts:23`), но НИКОГДА не вызывается — у кнопки Edit нет диалога (major #4). |
| POST `/api/templates/{template}/upload` | `TemplateVersionController@upload` | controller `authorize('uploadVersion')` → admin\|lawyer | ✅ да | Поле `file` совпадает. BE max 20480 KB=20 MB; FE кап 10 MB → файлы 10–20 MB отвергаются на клиенте (minor). |
| GET `/api/templates/{template}/versions` | `TemplateVersionController@index` | controller `authorize('viewVersions')` → любой аутентифицированный | ✅ да | Рендерит `v.created_by_name`, которого BE не возвращает → всегда «—» (major #5). |
| GET `/api/templates/{template}/versions/{version}` | `TemplateVersionController@show` | `authorize('viewVersions')`; `abort_if` `version.template_id != template` (404) | ✅ да | Поллинг каждые 3 c при pending/checking. |
| POST `/api/templates/{template}/versions/{version}/check` | `TemplateVersionController@check` | controller `authorize('checkVersion')` → admin\|lawyer | ✅ да | Recheck. Сбрасывает `ai_check_status=pending` безусловно и redispatch — нет guard против версии в `checking` (concurrency, minor). |
| POST `/api/templates/{template}/versions/{version}/override` | `TemplateVersionController@override` | controller `authorize('overrideVersion')` → admin\|lawyer | ✅ да | Override-кнопка. BE возвращает `ai_overridden=true`, но FE-entity не имеет поля → состояние override не показать (major #5). |
| GET `/api/template-variables` | `TemplateVariableController@index` | `TemplateVariablePolicy::viewAny` → любой аутентифицированный | ✅ да | FE шлёт `is_active`/`var_type`/`search`; controller читает `active_only`(default true)+`group`, ЛИБО `forContext` при наличии `product_code`&`country_code`. `is_active`/`var_type`/`search` игнорируются → active-чекбокс не выводит неактивные vars (major #2). |
| GET `/api/template-variables/{templateVariable}` | `TemplateVariableController@show` | `view` → любой аутентифицированный | ❌ нет | **Мёртвый endpoint** — FE правит объект строки уже из списка. |
| POST `/api/template-variables` | `TemplateVariableController@store` | `StoreTemplateVariableRequest::authorize` → `can('create')` → admin\|lawyer | ✅ да | Create. VariableDialog не шлёт `default_value`/`product_codes`/`country_codes` → всегда wildcard/null (minor). |
| PATCH `/api/template-variables/{templateVariable}` | `TemplateVariableController@update` | `UpdateTemplateVariableRequest::authorize` → `can('update')` → admin\|lawyer | ✅ да | Edit + inline-тоггл active. |
| DELETE `/api/template-variables/{templateVariable}` | `TemplateVariableController@destroy` | controller `authorize('delete')` → admin\|lawyer; 409-guard при использовании в `documents.context->custom` | ❌ нет | **Orphaned:** `deleteTemplateVariable()` определён (`templateVariables.ts:43`), но НЕТ кнопки/меню удаления → endpoint + 409-guard недостижимы из UI (minor). |

**Сводка покрытия:** из 21 endpoint'а 9 вообще не вызываются фронтом (8 licensor + `template-variables/{id}` show), 2 имеют orphaned FE-функции (`patchTemplate`, `deleteTemplateVariable` определены, но не привязаны к UI).

---

## 5. RBAC домена

**Модель ролей:** роль берётся из enum `User` (`spatie/laravel-permission`). Авторизация на запись реально проверяется в FormRequest::authorize() → Policy (canWrite) либо в контроллере через `authorize(...)`. Чтение почти везде открыто.

| Действие | Кому разрешено | Где реально проверяется | Дыра / замечание |
|---|---|---|---|
| Просмотр лицензиаров/шаблонов/переменных (чтение) | любой аутентифицированный (manager, director, lawyer, admin) | `LicensorPolicy`/`TemplatePolicy`/`TemplateVariablePolicy` `viewAny`+`view` → все возвращают `true` | ⚠️ `LicensorEntityResource` отдаёт `tax_id`/`bank`/`account` собственной компании любому read-only пользователю (minor #13 — info-leak). Согласуется с live-QA NEW-5 (manager успешно читает admin-only каталоги). |
| Создание/правка лицензиара и банк-счетов | admin, lawyer | Store/Update LicensorEntity + Store/Update LicensorBankAccount `Request::authorize()` → policy canWrite | ✅ Проверка корректна. |
| Удаление лицензиара | admin (политика есть) — но DELETE-роута НЕТ | `LicensorPolicy::delete` → admin; роут отсутствует (намеренно по spec: лицензиары не удаляют) | Ок (роут не выставлен). |
| Удаление банк-счёта | admin, lawyer (controller `authorize('update', licensor)`) | controller | ⚠️ Vault: delete должен быть admin-only → drift (trivial #15). |
| Правка метаданных шаблона | admin, lawyer | `UpdateTemplateRequest::authorize` → `TemplatePolicy::update` (canWrite) | ✅ Проверка корректна (но UI кнопки Edit мёртв — major #4). |
| Загрузка docx / recheck / override AI | admin, lawyer | controller `authorize('uploadVersion'/'checkVersion'/'overrideVersion')` → `TemplatePolicy` canWrite | ✅ Проверка корректна. |
| Просмотр версий шаблона (poll AI) | любой аутентифицированный | `TemplatePolicy::viewVersions` → `true` | Ок. |
| Создание/правка/удаление переменной | admin, lawyer | FormRequest authorize (store/update) + controller `authorize('delete')` | ✅ Проверка корректна. |
| Видимость пунктов навигации Templates/Template-Variables | admin + director (adminOnly nav-gate); lawyer — только по прямому URL; manager заблокирован на роуте | `navItems.ts filterNavByRole:307` (adminOnly → admin\|\|director); route meta `roles=[admin,lawyer,director]` (`base.ts:145/154`) | ⚠️ **Несогласованность:** director видит страницы + кнопки записи, но BE отвергает запись (policy admin\|lawyer) → 403. lawyer, наоборот, имеет право записи, но не видит пункт в навигации (доступ только по прямому URL). |

**Вывод по RBAC:** запись защищена корректно во всех точках (FormRequest/controller → Policy). Главные дефекты — не дыры в защите записи, а (а) слишком широкое **чтение** банковских реквизитов лицензиара (info-leak), (б) рассинхрон видимости навигации с реальными правами (director видит то, что не может изменить; lawyer не видит то, что может).

---

## 6. Бэклог проблем

### Сводная таблица (FINAL severity после верификации)

| # | Severity | Тип | Заголовок | Проверка |
|---|---|---|---|---|
| 0 | **blocker** | BUG | Генерация документов нерабочая в live — docx-версия никогда не загружалась (`template_versions` пуст) | ✅ подтверждено (DB+code+live API) · 🌐 подтверждено в браузере |
| 1 | **major** | BUG | kind-фильтр TemplatesPage шлёт значения `DocumentKind`, а `templates.kind` хранит docx/yaml/text — фильтр даёт 0 строк; колонка «Тип» показывает сырое `docx`/`yaml` | ✅ подтверждено (live probe) |
| 2 | **major** | BUG | Фильтры TemplateVariablesPage (active-only/type/search) молча игнорируются — имена параметров FE↔BE не совпадают; неактивные переменные НЕЛЬЗЯ вывести | ✅ подтверждено (live probe + code) |
| 3 | **major** | DEAD-CODE | Поиск на TemplatesPage — no-op; list endpoint игнорирует `search` | ✅ подтверждено (live probe) |
| 4 | **major** | DEAD-CODE | Кнопка «Редактировать» на TemplatePage мёртвая — диалога нет, `patchTemplate` не вызывается | ✅ подтверждено (static, отсутствие Dialog dispositive) |
| 5 | **major** | DEAD-CODE | FE↔BE рассинхрон полей `TemplateVersionDto` — автор версии всегда «—»; бейдж AI-override неотрисуем | ✅ подтверждено (static cross-check контрактов) |
| 6 | **major** | DATA-INCONSISTENCY | `termination_agreement` отсутствует в live DB (сидер=7, vault=6, live=6) — генерация termination деградирует до master_skeleton → 422 | ✅ подтверждено (live DB) |
| 7 | **major** | MISSING | Нет фронтенда для CRUD `LicensorEntity`/`LicensorBankAccount` (8 endpoint'ов) — править юр/банк-данные лицензиара из приложения невозможно | ✅ подтверждено (grep: 0 файлов) |
| 8 | **major** | DEAD-CODE | `LicensorService::forCountry()`/`primaryAccountForCurrency()` мертвы; `licensor_override_id` и выбор банк-счёта по валюте не подключены к генерации | ✅ подтверждено (static + grep, 0 вызовов) |
| 9 | minor | DATA-INCONSISTENCY | FE ждёт `category_codes[]`+`is_active`, BE возвращает `category`(string) — meta-строки не рендерятся | не верифицировано (Phase-1) |
| 10 | minor | BUG | FE-кап загрузки docx 10 MB при BE 20 MB — файлы 10–20 MB молча отвергаются на клиенте | не верифицировано (Phase-1) |
| 11 | minor | DEAD-CODE | DELETE template-variable + его 409-guard недостижимы из UI (нет кнопки удаления) | не верифицировано (Phase-1) |
| 12 | minor | BUG | AI-recheck (`/check`) сбрасывает `ai_check_status=pending` без guard против версии в `checking` | не верифицировано (Phase-1) |
| 13 | minor | SECURITY | `LicensorEntityResource` раскрывает `tax_id`/`bank`/`account` собственной компании всем read-only пользователям | не верифицировано (Phase-1) · соотносится с 🌐 live-QA NEW-5 |
| 14 | trivial | DEAD-CODE | `licensor_entities.is_default` fillable/cast/seeded, но никогда не запрашивается; `scopeForCountry` игнорирует его как tiebreaker | не верифицировано (Phase-1) |
| 15 | trivial | SPEC-DRIFT | Удаление банк-счёта разрешено lawyer; vault S2.1 говорил admin-only | не верифицировано (Phase-1) |

---

### Blocker

#### #0 · Severity: blocker · Тип: BUG · Проверка: ✅ подтверждено (DB+code+live API) · 🌐 подтверждено в браузере
**Заголовок:** Генерация документов нерабочая в live — docx-версия никогда не загружалась (`template_versions` пуст).

**Файлы:**
- `src/app/Domain/Contracts/Services/TemplateService.php:119-131` (`getDocxPath` бросает `RuntimeException` при null `currentVersion`/`docx_path`)
- `src/app/Domain/Contracts/Services/ContractGenerationService.php:87-93` (catch `RuntimeException` → `ValidationException` 422), `:207-232` (`resolveTemplate` всегда → `master_skeleton`)
- `src/database/seeders/TemplateSeeder.php:30,37,82` (`content=''` и `current_version_id=null`)

**Что происходит (evidence):** Live DB: `SELECT count(*) FROM template_versions` = **0**; `master_skeleton.current_version_id = NULL`, `content_len = 0` (все 6 шаблонов `current_version_id=NULL`); все строки `documents` — `docx_path=NULL`/`pdf_path=NULL`. Live API `GET /api/templates/1` (admin token) → `current_version_id=null`, `current_version=null`, `kind=docx`. На диске контейнера есть **осиротевшие** артефакты (`templates/1/v1-v3/template.docx`, `contracts/4,5,7/contract.docx`, mtime 2026-06-12..14) без матчащих строк БД — БД пересевалась после тех загрузок (сидер `updateOrCreate` форсит `current_version_id=null`), оставив stale-файлы без линковки. Это **подтверждает**, а не опровергает: механизм отрабатывал однажды, но версии стёрты. Браузерный QA (B.6): «История версий: Нет версий», генерация невозможна. Важная корректировка относительно pre-merge BE-claim: генерация **НЕ 500-ит** — она чисто деградирует в 422 «Шаблон не загружен». Блокер не для стабильности приложения, а для самой фичи: основная доменная функция (выдать PDF-договор) мертва на деплое.

**Repro:** Создать Document и запустить генерацию (`GenerateContractJob`/generate endpoint) → 422 «Шаблон не загружен». Эквивалентно: `GET` любого docx-шаблона показывает `current_version=null`.

**Предлагаемый фикс:** На деплое загрузить реальный `master_skeleton.docx` (и `termination_agreement.docx`) через `POST /api/templates/{id}/upload`, чтобы `template_versions` заполнилась и `current_version_id` был установлен; либо поставлять засеянный docx + `TemplateVersion` вместе с миграциями. Сообщение 422 корректно — блокер именно в отсутствующем артефакте.

---

### Majors

#### #1 · Severity: major · Тип: BUG · Проверка: ✅ подтверждено (live probe)
**Заголовок:** kind-фильтр TemplatesPage использует значения `DocumentKind`, но `templates.kind` хранит docx/yaml/text — фильтр возвращает 0 строк; колонка «Тип» показывает сырое `docx`/`yaml`.

**Файлы:** `front/src/pages/TemplatesPage/composables/useTemplatesPage.ts:35-40`, `front/src/entities/template.ts:41`, `src/app/Domain/Contracts/Services/TemplateService.php:37`, `front/src/pages/TemplatesPage/index.vue:44`.

**Что происходит:** Live API (admin): `GET /api/templates?kind=contract` → 0 строк; `GET /api/templates?kind=docx` → 1 строка. `kindOptions` строит Select из `DocumentKind=contract|invoice|act|reconciliation`; `TemplateDto.kind` типизирован `DocumentKind`. BE делает литеральный `where('kind',$kind)` (`TemplateService.php:37`) → `kind=contract` не матчит ничего. Колонка «Тип» рендерит `t('documents.kinds.'+data.kind, data.kind)` (`index.vue:44`), а ключа `documents.kinds.docx` нет → fallback на сырое `docx`/`yaml`. Алиаса kind на BE нет.

**Repro:** Открыть `/admin/templates`, выбрать любой kind → пустая таблица; в колонке «Тип» сырое `docx`/`yaml`.

**Предлагаемый фикс:** Выставить фильтр с реальными значениями (`docx`/`yaml`/`text`) + добавить i18n-ключи `documents.kinds.docx/yaml/text`, либо убрать фильтр. Исправить тип `TemplateDto.kind` на реальный template-kind-enum, не `DocumentKind`.

#### #2 · Severity: major · Тип: BUG · Проверка: ✅ подтверждено (live probe + code)
**Заголовок:** Фильтры TemplateVariablesPage (active-only/type/search) молча игнорируются — имена параметров FE↔BE не совпадают; неактивные переменные НЕЛЬЗЯ вывести.

**Файлы:** `front/src/pages/TemplateVariablesPage/composables/useTemplateVariablesPage.ts:30-32`, `src/app/Http/Controllers/Contracts/TemplateVariableController.php:32-37`, `src/app/Domain/Contracts/Services/TemplateVariableService.php:26`, `front/src/api/templateVariables.ts`.

**Что происходит:** Live API (admin): `GET /api/template-variables?is_active=false` → 5 строк (параметр игнорируется, возвращён дефолтный active-сет); `?var_type=number` → 5 строк со смешанными типами (фильтр типа игнорируется). Controller читает только `product_code`+`country_code` (`forContext`) ЛИБО `active_only`(default true)+`group`. `Service::list(activeOnly, group)` не имеет аргументов `var_type`/`search`. FE шлёт `var_type`/`is_active`/`search` дословно. **Структурный вывод «неактивные нельзя вывести»** верен: BE чтит только `active_only` (default true), а FE никогда не шлёт `active_only` — шлёт `is_active`, который BE игнорирует. Все 5 текущих vars активны, поэтому runtime-проба не показывает скрытую неактивную, но код-путь доказывает.

**Repro:** На `/admin/template-variables` снять «Активные» → список без изменений (неактивные остаются скрыты). Выбрать тип / ввести поиск → без изменений.

**Предлагаемый фикс:** Согласовать имена (FE шлёт `active_only`, либо BE читает `is_active`). Добавить `var_type`+`search` в `TemplateVariableService::list` и прочитать в контроллере. Снятие active-only должно выводить неактивные переменные.

#### #3 · Severity: major · Тип: DEAD-CODE · Проверка: ✅ подтверждено (live probe)
**Заголовок:** Поле поиска на TemplatesPage — no-op; list endpoint игнорирует параметр `search`.

**Файлы:** `front/src/pages/TemplatesPage/composables/useTemplatesPage.ts:24`, `src/app/Http/Controllers/Contracts/TemplateController.php:26-31`, `src/app/Domain/Contracts/Services/TemplateService.php:30-45`.

**Что происходит:** Live API (admin): `GET /api/templates?search=country_kz` → 6 строк (все шаблоны) — поиск не применён. Controller форвардит только `kind`/`category`/`product_code`/`country_code`; сигнатура `TemplateService::list` не имеет `search` и никогда не фильтрует title/code. FE шлёт `search`, но он отбрасывается серверно. (Severity major сохранена по соответствию находке, но на 6-строчном списке реальное влияние мало.)

**Repro:** Ввести текст в поиск на `/admin/templates` → список не сужается.

**Предлагаемый фикс:** Добавить `search` в `TemplateService::list` (`where title/code ILIKE`) и форвардить `$request->query('search')`; либо убрать поле ввода.

#### #4 · Severity: major · Тип: DEAD-CODE · Проверка: ✅ подтверждено (static; отсутствие Dialog в исходнике dispositive)
**Заголовок:** Кнопка «Редактировать» на TemplatePage мёртвая — диалога нет, `patchTemplate` не вызывается.

**Файлы:** `front/src/pages/TemplatePage/index.vue:39`, `front/src/pages/TemplatePage/composables/useTemplatePage.ts:170,188`, `front/src/api/templates.ts:23,82`.

**Что происходит:** Клик по Edit выставляет `editDialogVisible=true` (`index.vue:39`), но `index.vue` не рендерит ни одного `Dialog`, привязанного к `editDialogVisible` — только карточки Upload/AiCheck/Versions/Meta + `ConfirmDialog`. `editDialogVisible` объявлен/возвращён, но никем не наблюдается, кроме `@click`. `patchTemplate` встречается только в `templates.ts` (определение + barrel-export), вызывающих нет. Метаданные шаблона нельзя править из UI; `PATCH /api/templates/{id}` orphaned.

**Repro:** Открыть детали шаблона, нажать «Редактировать» → ничего не происходит.

**Предлагаемый фикс:** Построить edit-диалог (title + multiselect'ы скоупа), привязанный к `templatesApi.patchTemplate`, либо убрать кнопку Edit до реализации.

#### #5 · Severity: major · Тип: DEAD-CODE · Проверка: ✅ подтверждено (static cross-check контрактов)
**Заголовок:** FE↔BE рассинхрон полей `TemplateVersionDto` — автор версии всегда «—»; бейдж AI-override неотрисуем.

**Файлы:** `front/src/entities/template.ts:28-32`, `src/app/Http/Resources/Contracts/TemplateVersionResource.php:18-30`, `front/src/pages/TemplatePage/components/TemplateVersionsCard.vue:21`.

**Что происходит:** BE `TemplateVersionResource` возвращает `ai_overridden`(bool), `ai_checked_at`, `created_by_user_id` и НЕ возвращает `override_by`/`override_at`/`created_by_name`. FE `TemplateVersionDto` объявляет `override_by`/`override_at`/`created_by`/`created_by_name` и опускает `ai_overridden`. `TemplateVersionsCard.vue:21` рендерит `v.created_by_name ?? '—'` → всегда «—». После override бейджа не появляется (нет привязки `ai_overridden`). Сейчас замаскировано live (0 версий, строки не рендерятся), но контрактный рассинхрон определителен.

**Repro:** Колонка «Автор» в карточке версий всегда «—»; override AI-вердикта не показывает бейдж «overridden».

**Предлагаемый фикс:** Согласовать entity с BE: `created_by_user_id`(number), `ai_overridden`(bool), `ai_checked_at`(string). Рендерить тег «overridden» из `ai_overridden`. Либо BE join+expose `created_by_name`, либо резолв имени client-side.

#### #6 · Severity: major · Тип: DATA-INCONSISTENCY · Проверка: ✅ подтверждено (live DB)
**Заголовок:** `termination_agreement` отсутствует в live DB (сидер=7, vault=6, live=6) — генерация termination деградирует до master_skeleton → 422.

**Файлы:** `src/database/seeders/TemplateSeeder.php:11,33-39`, `src/app/Domain/Contracts/Services/ContractGenerationService.php:219-228`.

**Что происходит:** Live DB: `SELECT code FROM templates` → 6 строк (`country_kz`, `country_uz`, `master_skeleton`, `product_macrocrm`, `product_macroerp`, `product_macrosales`); `termination_agreement` ОТСУТСТВУЕТ. Сидер определяет 7, включая `termination_agreement` — но его собственный docblock-комментарий говорит «6 шаблонов» (внутренний drift). Vault module-doc:113 буквально пишет «6 шаблонов». `resolveTemplate` маппит `DocumentKind::TerminationAgreement` → `'termination_agreement'`; при отсутствии (и `kindCode != master_skeleton`) делает `firstOrFail('master_skeleton')`, у которого `current_version_id=NULL` → `getDocxPath` бросает → перехват → 422. Live DB не пересевалась после добавления `termination_agreement`. Даже после пересева генерация всё равно 422-ит до загрузки docx (связь с blocker #0).

**Repro:** Сгенерировать документ termination → lookup промахивается → fallback на master_skeleton → 422 missing docx.

**Предлагаемый фикс:** Перезапустить `TemplateSeeder` (идемпотентный `updateOrCreate`) на live DB, чтобы `termination_agreement` появился + загрузить его docx. Обновить vault module-doc на «7 шаблонов». Разобраться, почему live DB предшествует записи сидера для `termination_agreement`.

#### #7 · Severity: major · Тип: MISSING · Проверка: ✅ подтверждено (grep: 0 файлов)
**Заголовок:** Нет фронтенда для CRUD `LicensorEntity`/`LicensorBankAccount` (8 endpoint'ов) — править юр/банк-данные лицензиара из приложения невозможно.

**Файлы:** `src/app/Http/Controllers/Contracts/Admin/LicensorEntityController.php`, `front/src/api` (отсутствует licensor-модуль), `Спринт 2 — S2.1 ... (детальный план).md:650`.

**Что происходит:** `grep -rln 'licensor|Licensor' front/src` → 0 файлов. Нет api-модуля, страницы, entity, роута или пункта навигации. 8 admin-endpoint'ов существуют BE-side без единого FE-вызывающего. Поскольку данные `LicensorEntity` питают генерацию (`YamlTemplateParser.php:59-64`), нет внутри-приложенческого способа исправить банк/`tax_id` лицензиара — только через прямую БД или YAML. Vault S2.1 §Ж отложил этот UI на S2.10; не построен. Влияние реально: нельзя править юрлицо, чьи банк/`tax_id` штампуются в каждый договор.

**Repro:** Искать любую admin-страницу лицензиаров в SPA — её нет.

**Предлагаемый фикс:** Построить S2.10-страницу `/admin/licensor-entities` (таблица + edit-диалог + секция bank-accounts), привязанную к 8 endpoint'ам; добавить `licensorApi`-модуль + entity-типы.

#### #8 · Severity: major · Тип: DEAD-CODE · Проверка: ✅ подтверждено (static + grep, 0 вызовов)
**Заголовок:** `LicensorService::forCountry()`/`primaryAccountForCurrency()` мертвы; `licensor_override_id` и подбор банк-счёта по валюте не подключены к генерации.

**Файлы:** `src/app/Domain/Contracts/Services/LicensorService.php:15,27`, `src/app/Domain/Contracts/Services/YamlTemplateParser.php:59-60`, `src/app/Domain/Contracts/Services/ContractContextBuilder.php:149-156`.

**Что происходит:** `LicensorService` инжектится ТОЛЬКО в два Admin CRUD-контроллера, в генерацию — никогда. `primaryAccountForCurrency` имеет НОЛЬ вызовов (только определение). Генерация резолвит лицензиара через model-scope `LicensorEntity::forCountry()` напрямую (`YamlTemplateParser.php:60`), минуя `LicensorService::forCountry()`. `override_id` фигурирует только в docblock-комментарии `LicensorService` (line 15) — никакого читателя `override_id` в `ContractContextBuilder` или где-либо. `flattenSection` пишет вложенные массивы (`bank_accounts`) как `''` (`ContractContextBuilder.php:154-155`), поэтому `licensor.account` всегда основной счёт entity независимо от валюты договора. **Конкретный дефект корректности:** USD-договор для KZ отрендерит KZT-основной счёт, а не USD `LicensorBankAccount`. Опровергает pre-merge «все данные лицензиара мертвы» — данные entity ИСПОЛЬЗУЮТСЯ, мёртв только сервисный слой + per-currency счета.

**Repro:** Сгенерировать USD-договор для KZ — `licensor.account` рендерит основной (KZT) счёт, а не USD `LicensorBankAccount`; установка `Document.context['licensor_override_id']` не имеет эффекта.

**Предлагаемый фикс:** Либо подключить `ContractContextBuilder` к `LicensorService::forCountry(country, override_id)` + `primaryAccountForCurrency(currency)` и выставить `licensor.account_for_currency`; либо удалить неиспользуемые сервисные методы и задокументировать поведение «только YAML/основной счёт». Привести в соответствие с vault S2.1 §4 (приоритет `override_id > country_code > YAML`).

---

### Minor / Trivial (не верифицировано — Phase-1)

- **#9 (minor, DATA-INCONSISTENCY)** — `TemplateDto` ждёт `category_codes[]` + `is_active`, но BE возвращает одиночную строку `category` и `client_category_codes[]` без `is_active` → meta-строки категорий и состояние active никогда не заполняются. `front/src/entities/template.ts:44`, `src/app/Http/Resources/Contracts/TemplateResource.php:24-28`.
- **#10 (minor, BUG)** — FE-кап загрузки docx 10 MB (`TemplateUploadCard.vue:11`, `max-file-size=10*1024*1024`) при BE 20 MB (`UploadTemplateVersionRequest.php:31`, `max:20480`) → файлы 10–20 MB молча отвергаются на клиенте. Свести к единому источнику истины.
- **#11 (minor, DEAD-CODE)** — `deleteTemplateVariable()` определён (`templateVariables.ts:43`), но нет кнопки/меню удаления в TemplateVariablesPage → endpoint + 409-guard недостижимы из приложения.
- **#12 (minor, BUG)** — `check()` безусловно ставит `ai_check_status=Pending` и redispatch'ит `CheckTemplateJob` (`TemplateVersionController.php:122-124`) без guard против версии в `checking` → два конкурентных job'а на одной версии, поздняя запись побеждает. Фикс: `abort_if(version.ai_check_status === Checking, 409)` либо `WithoutOverlapping` по `versionId`.
- **#13 (minor, SECURITY)** — `LicensorEntityResource` (`:30-36`) отдаёт `tax_id`/`bank`/`bank_code`/`account` любому аутентифицированному read-only (`LicensorPolicy::viewAny/view` → true). manager/accountant читают банк-реквизиты компании через `GET /api/admin/licensor-entities`. Низкое влияние (внутренние данные), но шире write-поверхности. **Соотносится с live-QA NEW-5** (🌐 manager успешно GET'ит admin-only каталоги). Фикс: гейтить чтение банк-полей на `canWrite` через `$this->when(...)`.
- **#14 (trivial, DEAD-CODE)** — `licensor_entities.is_default` fillable/cast/seeded (обе строки `is_default=true`), но grep не находит запросов, фильтрующих/сортирующих по нему; `scopeForCountry` матчит только `country_code` → при двух entity на одну страну `->first()` недетерминирован. Фикс: `->orderByDesc('is_default')` в `scopeForCountry`, либо удалить `is_default`.
- **#15 (trivial, SPEC-DRIFT)** — Удаление банк-счёта (`LicensorBankAccountController::destroy`) авторизует `'update'` на родительском лицензиаре → admin\|lawyer; vault §Е (line 632) говорил admin-only. Привести контроллер к `authorize('delete', licensor)` (admin-only) либо обновить vault-таблицу на admin\|lawyer.

### Релевантные NEW-* из live-QA

- **NEW-5 (🌐 подтверждено в браузере, P1):** manager успешно GET'ит `/api/admin/*` каталоги (company-types, sources, countries, cities и др.) → 200. Хотя перечисленные эндпоинты не из этого домена, **тот же паттерн открытого чтения** проявляется здесь как minor #13 (банк-реквизиты лицензиара читаемы любой ролью). Стоит решать как сквозную политику чтения admin-ресурсов.
- **B.6 (🌐 подтверждено в браузере):** прямое подтверждение blocker #0 — «История версий: Нет версий» для всех 6 шаблонов, генерация невозможна.

---

## 7. Расхождения со спекой (vault) и предложения по актуализации

**1. `2. Модули/Contracts — Документы (шаблоны, лицензиары, переменные).md` — §5 Сиды (~line 113).**
Спека говорит: «TemplateSeeder — 6 шаблонов: master_skeleton (docx, content=''), product_macrocrm/macrosales/macroerp (yaml), country_kz/uz (yaml)».
Реальность: `TemplateSeeder.php` сеет **7** шаблонов — добавлен `termination_agreement` (kind=docx, category=cancellation). Live DB всё ещё 6 (`termination_agreement` отсутствует — сид не пересевался).
Предложение: обновить doc на «7 шаблонов» с `termination_agreement`; добавить заметку, что live DB надо пересеять и что оба docx-шаблона требуют загрузки docx до работы генерации.

**2. `5. Планы/Спринт 2 — S2.1 ... (детальный план).md` — §4 Приоритет подстановки лицензиара / §Е.**
Спека говорит: приоритет резолва: 1) `Contract.context['licensor_override_id']` → `find(id)`; 2) lookup по `country_code` в БД; 3) fallback на YAML страны. Per-currency primary банк-счёт выбирается по валюте договора (`primaryAccountForCurrency`).
Реальность: реализованы только шаги 2 и 3 (`YamlTemplateParser.php:59` использует scope по `country_code`, БД перекрывает YAML). Нет пути `licensor_override_id` в `ContractContextBuilder`; per-currency выбор счёта не подключён — `licensor.account` всегда основной счёт entity (`flattenSection` пропускает вложенные `bank_accounts`). `LicensorService::forCountry()`/`primaryAccountForCurrency()` без вызовов.
Предложение: либо пометить `override_id` + per-currency-выбор как «deferred / not implemented», либо завести follow-up на подключение `ContractContextBuilder` через `LicensorService`. Прояснить фактическое поведение (DB-or-YAML, только основной счёт).

**3. `5. Планы/Спринт 2 — S2.1 ... (детальный план).md` — §Ж Frontend-скоуп (UI отложен на S2.10).**
Спека говорит: S2.10 построит TemplatePage, `/admin/licensor-entities` (таблица + edit-диалог + секция bank-accounts) и UI каталога TemplateVariable.
Реальность: страницы Templates + TemplateVariables существуют (с рядом FE↔BE-рассинхронов), но ЗЕРО фронтенда для лицензиаров/банк-счетов. Edit-диалог шаблона и UI удаления переменной тоже отсутствуют.
Предложение: трекать admin-UI лицензиаров, edit-диалог шаблона и delete-UI переменной как открытые S2.10-задачи; зафиксировать FE↔BE-рассинхроны (kind/search/active-фильтры, `category_codes` vs `category`, поля автора/override версии) как дефекты к фиксу на S2.10-полировке.

**4. `5. Планы/Спринт 2 — S2.1 ... (детальный план).md` — §Е API-таблица, роль DELETE bank-accounts.**
Спека говорит: `DELETE /api/admin/bank-accounts/{bankAccount}` — admin.
Реальность: код авторизует `'update'` на родительском лицензиаре → admin\|lawyer могут удалять банк-счета.
Предложение: обновить таблицу на admin\|lawyer под код, либо ужесточить контроллер до admin-only под исходный замысел.

**5. Общий комментарий по §К (MVP-состояние генерации).** Vault §К корректно фиксирует, что генерация недоступна до первой загрузки docx — это ожидаемое MVP-состояние. Но стоит явно отметить статус «генерация = blocker до post-deploy загрузки `master_skeleton.docx`», чтобы команда не приняла каркас за готовую фичу.
