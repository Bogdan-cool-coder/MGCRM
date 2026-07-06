# Deal Create 2.0 — доменный/API-контракт

> **Автор:** backend-architect (spec-author). **Статус:** утверждён к реализации.
> **Продуктовые решения:** зафиксированы юзером 2026-07-07 (7 пунктов), менять нельзя.
> **Секвенс:** BE отдаёт формы ответов ДО того, как FE начинает строить. Порядок работ — §7.
>
> Закон стека: `ARCHITECTURE.md` + `docs/backend-standard.md` + реальный `src/app/Domain/*`.
> Бизнес-логика-референс: `examples/contracts/` (FastAPI — код НЕ копируем, смысл).
> Домены-владельцы: **Sales** = `sales-backender`, **Crm** = `crm-backender`,
> **Activity** = его специалист, **миграции/контракт** = backend-architect.

---

## 0. Резюме изменений (что трогаем)

| # | Артефакт | Домен | Тип изменения |
|---|----------|-------|---------------|
| 1 | `POST /api/deals` instant-create semantics | Sales | сервер-дефолты + `company_id` nullable |
| 2 | `deals.company_id` → nullable, FK `nullOnDelete` | Sales (миграция BA) | миграция |
| 3 | `pipelines.default_stage_id` nullable FK | Sales (миграция BA) | миграция + FormRequest + Resource |
| 4 | `StoreCompanyRequest` — required contact/classification | Crm | валидация ручного создания |
| 5 | Owner = автор (Company + Contact) | Crm | сервис-дефолт |
| 6 | Owner auto-sync правило (deal → company/contacts) | Activity → Sales listener | новый listener + событие |
| 7 | Валюта по стране при привязке компании к сделке | Sales | дёшево реализуемо — см. §6 |
| 8 | «Планируемая дата закрытия» → «План договора» унификация | Sales/i18n | см. §8 |

---

## 1. Instant-create: `POST /api/deals`

### 1.1 Продуктовое поведение

Любая кнопка «Новая сделка» (kanban, список сделок, карточка компании, command palette,
quick-action) **сразу** делает `POST /api/deals` с минимальным телом и редиректит в
полноценную карточку `/deals/{id}`. Страница-форма `/deals/new` **удаляется**; маршрут
`/deals/new?pipeline_id=X` остаётся как совместимость — триггерит instant-create и
редиректит в карточку.

### 1.2 Форма запроса (все поля опциональны кроме `pipeline_id`)

```jsonc
POST /api/deals
{
  // ОБЯЗАТЕЛЬНО (единственное):
  "pipeline_id": 3,          // required|integer|exists:pipelines,id

  // ОПЦИОНАЛЬНО — сервер подставит дефолты (§1.3):
  "company_id":  null,       // nullable|integer|exists:crm_companies,id  (было required!)
  "title":       null,       // nullable|string|max:255  (дефолт «Новая сделка»)
  "currency":    null,       // nullable|string|Rule::in(supported)  (дефолт §1.3)
  "owner_user_id": null,     // nullable|integer|exists:users,id  (дефолт = auth user)

  // как раньше (не меняем):
  "tags": null, "extra_fields": null,
  "expected_close_date": null, "expected_sign_date": null, "expected_payment_date": null
  // stage_id по-прежнему НЕ принимается
}
```

**StoreDealRequest — правки правил** (файл `src/app/Http/Requests/Sales/StoreDealRequest.php`):

```php
'pipeline_id' => ['required', 'integer', 'exists:pipelines,id'],
'company_id'  => ['nullable', 'integer', 'exists:crm_companies,id'],   // было required
'title'       => ['nullable', 'string', 'max:255'],                    // было required
'currency'    => ['nullable', 'string', Rule::in($currencies)],        // было required
'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
// tags / extra_fields / expected_* — без изменений; stage_id по-прежнему НЕ в правилах
```

> **Внимание (правило проекта):** валидация только через FormRequest, никогда inline
> `$request->validate`. Дефолты — в Service, НЕ в FormRequest (FormRequest не мутирует
> бизнес-дефолты; он только валидирует).

### 1.3 Серверные дефолты — `DealService::create()`

Порядок применения дефолтов (в `create()`, до `Deal::create`):

1. **`title`** — `$data['title'] = trim($data['title'] ?? '') !== '' ? $data['title'] : config('crm.deal.default_title')`
   где `config('crm.deal.default_title')` = `'Новая сделка'` (RU) — новый ключ конфига.
   *(Хардкод-строку не сажаем в сервис — кладём в `config/crm.php`, локаль-независимо,
   т.к. это доменное значение, а не UI-текст.)*
2. **`owner_user_id`** — `??= $creator->id` (уже есть).
3. **`department_id`** — `??= $owner?->department_id` (уже есть).
4. **`stage_id`** — **НОВОЕ правило выбора стадии (заменяет «первая non-won/lost/hidden»):**
   ```
   stage = pipeline.default_stage_id (если задан И стадия принадлежит воронке И не won/lost/hidden)
         ?? первая по sort_order из non-won / non-lost / non-hidden      // текущий fallback
   ```
   Если `default_stage_id` указывает на won/lost/hidden стадию — **игнорируем** его и
   падаем на fallback (защита от кривой настройки). Если стадий нет вовсе —
   `ValidationException` (как сейчас).
5. **`currency`** — новый дефолт-резолвер (сейчас дефолта нет, FE всегда слал явно):
   ```
   currency = request.currency
            ?? (company_id != null ? currencyByCountry(company.country_code) : null)
            ?? config('crm.currencies.default')      // напр. 'RUB' или 'KZT' — существующий/новый ключ
   ```
   `currencyByCountry` — карта country→currency (RU→RUB, KZ→KZT, UZ→UZS, AE→AED, …),
   вынести в `config('crm.currencies.by_country')` или `CurrencyResolver` (см. §6 — та же
   карта переиспользуется при поздней привязке компании). **company_id при instant-create
   обычно null → падаем на `config('crm.currencies.default')`.**
6. **`company_id`** — может быть `null`. `company_requisite_id` авто-пиннинг (N5) остаётся
   под guard'ом `! empty($data['company_id'])` (уже так) — при null тихо `null`.

**Всё остальное в `create()` без изменений:** stage history (from=null), entity_log
`Created` (уже null-safe по company_id, строка 1045), `touchEngagement`, `DealCreated::dispatch`.

### 1.4 `company_id` nullable — АУДИТ последствий (проверено по коду)

`deals.company_id` сейчас `NOT NULL` + `restrictOnDelete`. Делаем nullable. Что затронуто:

| Место | Файл | Статус | Действие |
|-------|------|--------|----------|
| Миграция схемы | `2026_06_12_120003_create_deals_table.php` | NOT NULL | **новая миграция** §2 (не правим старую) |
| `DealResource.company` | `DealResource.php:52-56` | `whenLoaded` → уже null-safe (`company_id` отдаётся как есть, `company` объект только при загрузке) | ✅ без правок |
| `DealResource.country/category` | `:64,70` | `$this->company?->…` уже null-safe | ✅ без правок |
| Broadcast payload | `BroadcastsDealChannels.php:45` | `company_id !== null ? … : null` уже null-safe | ✅ без правок |
| entity_log Created | `DealService.php:1045` | `company_id !== null ? … : null` уже null-safe | ✅ без правок |
| Won-gate primary-client | `DealMoveService.php:246-263` | уже комментирует «company_id null impossible» + **defensive null-check `if ($deal->company_id === null) return`** | ✅ работает; при null-компании won просто не конвертит client — приемлемо |
| Required-fields gate | `DealMoveService.php:371-377` | `if ($company === null || blank(...))` уже null-safe → добавит `company.<field>` в missing → move запретится | ✅ ЖЕЛАЕМОЕ: нельзя двинуть безкомпанийную сделку в стадию, требующую company-поля |
| Won-amount gate | `DealMoveService.php:104-111` | не зависит от company | ✅ |
| `touchEngagement` | `DealService.php` | engagement на company; при null — no-op на company (contacts тоже нет) | ⚠️ проверить: метод должен no-op'ить при `company_id===null`, не падать. **Задача sales-backender: добавить guard если нужно** |
| Company requisite pin | `DealService.php:1013` | под `! empty(company_id)` | ✅ |
| Kanban/list scope | `CompanyService`/`DealService::list` | сделка сама по себе не фильтруется по company; visibility по owner/department | ✅ борд покажет карточку без компании |
| Отчёты (registry/income/conversion) | `Deal*ReportService` | группировки по company могут дать «без компании» bucket | ⚠️ **задача sales-backender/analytics: NULL company в отчётах — отдельная группа «Без компании», не падение**. Проверить каждый report на `->company->` без null-check |
| FK delete компании | миграция | было `restrictOnDelete` | **меняем на `nullOnDelete`** — удаление компании обнуляет `company_id` у сделок, сделка выживает (§2). Согласовано с продуктовым «пустая сделка висит». |

> **Гейт для sales-backender:** прогнать `grep -rn "->company->" src/app/Domain/Sales`
> и `deal->company_id` — каждое разыменование `deal->company->X` без `?->` = потенциальный
> null-crash после этого изменения. Обязательный чеклист перед PR.

### 1.5 Ответ (форма) — `DealResource` (без изменений shape)

`POST /api/deals` возвращает `DealResource` с загруженными `pipeline`, `stage`, `owner`
(company грузим `whenLoaded` — при instant-create company_id=null → `company: (ключ отсутствует)`,
`company_id: null`). FE редиректит в `/deals/{id}` и там уже полный `show`.

**Контроллер `DealController@store`** должен вернуть свежую сделку с eager-load
`['pipeline','stage','owner']` (+ `company` если не null) — иначе FE-редирект не получит
stage/owner для мгновенного рендера. Форма ответа — существующий `DealResource` (§ выше).

---

## 2. Миграции (владелец — backend-architect)

### 2.1 `deals.company_id` → nullable + `nullOnDelete`

Новая миграция `YYYY_MM_DD_HHMMSS_make_deal_company_nullable.php`:

```php
public function up(): void {
    // 1. Снять старый FK (restrictOnDelete) + сделать колонку nullable.
    Schema::table('deals', function (Blueprint $t): void {
        $t->dropForeign(['company_id']);
    });
    Schema::table('deals', function (Blueprint $t): void {
        $t->unsignedBigInteger('company_id')->nullable()->change();
        $t->foreign('company_id')->references('id')->on('crm_companies')->nullOnDelete();
    });
}
public function down(): void {
    // ВНИМАНИЕ: rollback упадёт, если к моменту отката есть строки с company_id=NULL.
    // down() восстанавливает NOT NULL + restrictOnDelete; допустимо, т.к. тестовые данные.
    Schema::table('deals', function (Blueprint $t): void { $t->dropForeign(['company_id']); });
    Schema::table('deals', function (Blueprint $t): void {
        $t->unsignedBigInteger('company_id')->nullable(false)->change();
        $t->foreign('company_id')->references('id')->on('crm_companies')->restrictOnDelete();
    });
}
```

> `->change()` требует `doctrine/dbal` — проверить, что он в composer (Laravel 13
> частично нативно; если нет — `dropColumn`+`addColumn` неприемлемо (данные). Проверить
> `docker run … composer show | grep dbal`). Прогнать `migrate` + `migrate:rollback`
> оба на **pgsql** до коммита.

### 2.2 `pipelines.default_stage_id` nullable FK

**Прецедент для reuse:** `channels.default_stage_id` (миграция `2026_06_16_100000_create_channels_table.php`)
— тот же паттерн (nullable FK → `pipeline_stages`, `nullOnDelete`). Копируем форму.

Новая миграция `YYYY_MM_DD_HHMMSS_add_default_stage_id_to_pipelines.php`:

```php
public function up(): void {
    Schema::table('pipelines', function (Blueprint $t): void {
        // «Стадия для новых сделок». nullable → fallback на первую non-won/lost/hidden.
        $t->foreignId('default_stage_id')->nullable()->after('settings')
            ->constrained('pipeline_stages')->nullOnDelete();
    });
}
public function down(): void {
    Schema::table('pipelines', function (Blueprint $t): void {
        $t->dropConstrainedForeignId('default_stage_id');
    });
}
```

**Модель `Pipeline`:** добавить `'default_stage_id'` в `$fillable` + relation
`defaultStage(): BelongsTo => belongsTo(PipelineStage::class, 'default_stage_id')`.

---

## 3. Настройка воронки: `default_stage_id`

### 3.1 `UpdatePipelineRequest` — новое правило с проверкой принадлежности

Файл `src/app/Http/Requests/Sales/UpdatePipelineRequest.php`. Добавить:

```php
'default_stage_id' => [
    'sometimes', 'nullable', 'integer',
    // Стадия должна принадлежать ЭТОЙ воронке (route-bound pipeline).
    Rule::exists('pipeline_stages', 'id')->where(
        fn ($q) => $q->where('pipeline_id', $this->route('pipeline')?->id)
    ),
],
```

> Валидация принадлежности стадии воронке — обязательна (продуктовое решение #4:
> «валидация принадлежности стадии воронке»). `nullable` позволяет сбросить (вернуться к
> fallback-поведению «первая стадия»).
> **Опционально (усиление):** запретить выбор won/lost/hidden стадии как дефолт — можно
> в FormRequest через дополнительный `->where('is_won', false)->where('is_lost', false)`,
> ЛИБО (мягче) молча игнорировать в сервисе (§1.3 п.4 уже игнорирует). **Решение: валидируем
> только принадлежность воронке; won/lost/hidden отсекает сервис** (одно место истины —
> DealService выбор стадии; так не ломаем настройку, если стадию позже пометили hidden).

### 3.2 `PipelineResource` — отдать поле

Файл `src/app/Http/Resources/Sales/PipelineResource.php`. Добавить ключ:

```php
'default_stage_id' => $this->default_stage_id,   // int|null — «Стадия для новых сделок»
```

FE-редактор воронки читает это поле, рисует Select стадий (только non-won/lost/hidden как
кандидаты) и патчит через существующий `PATCH /api/pipelines/{id}`.

### 3.3 `StorePipelineRequest` (создание воронки)

При создании стадий ещё нет → `default_stage_id` не принимаем на create (или nullable без
проверки exists — но лучше не принимать, устанавливается только на update, когда стадии
существуют). Оставить create как есть.

---

## 4. Ручное создание компании: required-контракт

### 4.1 Продуктовое требование (решение #5)

Форма `/companies/new` (ручное создание): **required** = сайт, адрес, телефон
(«Контактные данные») + тип компании, страна, источник («Классификация»). Блок
«Ответственный» с формы **удаляется** (owner = автор на бэке). Меню «⋮» в шапке
create-страницы убрать (уже пусто — FE-задача).

### 4.2 Требования ТОЛЬКО на ручное создание — не ломать импорт/миграцию/merge

`StoreCompanyRequest` обслуживает `POST /api/companies` (ручное создание). Импорт (AMO ETL,
`migration-specialist`), дедуп-merge и программное создание идут **другими путями**
(`CompanyService::create` вызывается напрямую или через отдельные import-реквесты) — их эти
правила НЕ касаются. **Инвариант: ужесточаем только `StoreCompanyRequest`, не `CompanyService`.**

**`StoreCompanyRequest` — правки (файл `src/app/Http/Requests/Crm/StoreCompanyRequest.php`):**

```php
// Контактные данные — required при ручном создании:
'phone'   => ['required', 'string', 'max:64'],
'website' => ['required', 'url', 'max:255'],
'address' => ['required', 'string', 'max:1000'],
// Классификация — required:
'company_type_id' => ['required', 'integer', 'exists:crm_company_types,id'],
'country_code'    => ['required', 'string', 'size:2'],
'source'          => ['required', 'string', 'max:32'],
// email — оставить nullable (не в required-списке решения #5):
'email' => ['nullable', 'email', 'max:255'],
// responsible_user_id / owner_user_id — УБРАТЬ из правил приёма с формы
// (owner ставится сервисом = автор; см. §5). Оставить в rules нельзя —
// иначе FE сможет прислать чужой owner. Удаляем обе строки.
```

> **Открытый вопрос O1 (см. §9):** решение #5 говорит «источник обязателен». В системе есть
> два поля источника: `source` (строка-код) и `acquisition_channel_id` (FK). Делаем
> **`source` required** (это «источник» из формы классификации). `acquisition_channel_id`
> остаётся nullable. Уточнить у продукта, если имелся в виду channel.

### 4.3 Автопривязка при возврате из создания компании (returnTo)

Инвариант флоу (решение #3): из карточки сделки «+ Создать компанию» →
`/companies/new?returnTo=deal-{id}` → после сохранения возврат в карточку сделки с
**автопривязкой** созданной компании. Бэк-контракт: возврат делает FE (redirect с
`company_id`), затем FE патчит сделку `PATCH /api/deals/{id} { company_id }`. **При этом
патче срабатывает currency-подтяжка (§6) и owner-sync (§5.4).** Дискриминатор `returnTo`
меняется с литерала `deal-new` на `deal-{id}` — FE-контракт, бэка не касается.

---

## 5. Owner = автор + авто-переназначение owner

### 5.1 Сверка с `examples/contracts` (правило старой системы приоритетно)

Проверено: в старой системе (`examples/contracts/apps/api/app/services/`):
- `activities.py` — Activity полиморфна, **owner-sync к target'у ОТСУТСТВУЕТ**; активность
  не трогает owner компании/контакта.
- `contacts_v2.py` — `owner_id` контакта/компании = `owner_user_id`, копируется при создании
  зеркала; **нет task-driven переназначения**.
- `automation_executor.py` — `change_owner` (round_robin / by_product / by_country /
  by_department) существует **только как действие pipeline-автоматизации** (эпик 4), НЕ
  автоматически при создании задачи. Автоматизаций в MGCRM ещё нет.

**Вывод:** старая система **не содержит** правила «задача по сделке синхронизирует owner
компании/контактов». Конфликта нет → действует **правило из продуктового решения #6**
(ниже, §5.3–5.4). `change_owner`-автоматизация — отдельная будущая фича (эпик Automation),
в этот контракт НЕ входит.

### 5.2 Owner = автор при создании (Company + Contact)

- **Company:** `CompanyService::create()` уже ставит `owner_user_id ??= creator->id`,
  `created_by_id = creator->id`, `department_id ??= creator->department_id`. ✅ Правок нет,
  кроме того, что FormRequest больше не принимает owner (§4.2) — сервис остаётся источником.
- **Contact:** проверить `ContactService::create()` — должен ставить `owner_user_id = creator->id`
  при ручном создании (по разведке у Contact есть только `owner_user_id`, нет
  `responsible_user_id`). **Задача crm-backender:** убедиться, что ручное создание контакта
  ставит owner=автор (как у компании). Если inline-создание контакта в форме компании не
  ставит owner — исправить.

### 5.3 ФИНАЛЬНОЕ правило авто-переназначения owner

Формулировка (из решения #6, финализирована):

> **A. Задача/активность ПО СДЕЛКЕ** (target_type = `deal`):
> при назначении/смене ответственного (`responsible_id`) активности, **owner компании
> сделки И owner всех контактов, привязанных к сделке, синхронизируется с ответственным
> активности** — НО приоритет у **ответственного самой СДЕЛКИ**, не задачи. То есть:
> **owner компании/контактов сделки = `deal.owner_user_id`** (сделка — источник истины).
> Точнее — синхронизация идёт по владельцу сделки, а не по исполнителю конкретной задачи.
>
> **B. Задача ТОЧЕЧНО на контакт/компанию БЕЗ привязки к сделке** (target_type =
> `company`/`contact`, у активности нет связанной сделки):
> owner контакта/компании = **исполнитель задачи** (`responsible_id`), **НО** если у
> контакта/компании есть **активная (открытая) сделка** (stage `is_won=false AND
> is_lost=false`) — точечная задача owner **НЕ меняет** (сделка держит owner).

**Разбор семантики A (важно):** «сделка имеет приоритет» = когда есть сделка, owner
company/contacts тянется от **owner сделки**, а не от исполнителя задачи. Практический
триггер синхронизации — событие по сделке (создание/смена owner сделки, либо задача по
сделке). Реализуемо как: **при смене `deal.owner_user_id` (DealService::update) →
синхронизировать owner компании + привязанных контактов = новый owner сделки.**
Задача по сделке НЕ меняет owner напрямую — она лишь индикатор активности; owner ведёт
сделка. Это устраняет двусмысленность «owner компании = исполнитель задачи по сделке».

### 5.4 Где живёт сервис + границы DDD

Синхронизация трогает **Crm** (Company/Contact owner) по триггеру из **Sales** (Deal) и
**Activity**. Cross-domain — **только через Service** (ARCHITECTURE.md §2). Дизайн:

- **Событие-источник (Sales):** `DealOwnerChanged` (новое доменное событие) —
  `dispatch` в `DealService::update()` когда `owner_user_id` изменился (и в
  `DealService::create()` — новая сделка тоже устанавливает owner компании/контактов, если
  их owner ещё «слабее»). Событие несёт `deal_id`, `new_owner_id`, `previous_owner_id`,
  `actor`.
- **Listener (живёт в Sales, вызывает Crm-сервис):**
  `SyncDealOwnershipListener` → вызывает **новый метод `CompanyService::syncOwnerFromDeal(Company, int $ownerId, User $actor)`**
  и `ContactService::syncOwnerFromDeal(...)` для каждого привязанного контакта.
  Crm-сервис — единственный, кто пишет `companies.owner_user_id` / `contacts.owner_user_id`
  (DDD-граница: Sales НЕ делает `$company->owner_user_id = …; save()`).
- **Триггер B (точечная задача):** живёт в **Activity → listener на `ActivityAssigned`/
  `ActivityCreated`** когда `target_type in (company, contact)` и у target'а **нет активной
  сделки**. Listener (в Activity или Crm) вызывает `CompanyService::syncOwnerFromTask` /
  `ContactService::syncOwnerFromTask`. Проверку «есть активная сделка» инкапсулировать в
  Crm/Sales cross-service методе (напр. `DealService::hasOpenDealForCompany(int): bool` /
  `…ForContact`) — читается cross-domain из Crm через Sales-сервис, не прямым запросом к
  `deals` из Crm.

> **Границы (жёстко):**
> - `companies.owner_user_id` / `contacts.owner_user_id` пишет **только CompanyService /
>   ContactService**.
> - «активная сделка есть?» отвечает **только Sales** (DealService), Crm его дёргает.
> - Триггер по сделке эмитит **Sales** (DealOwnerChanged); триггер по точечной задаче эмитит
>   **Activity** (ActivityAssigned). Listener'ы — тонкие, вся логика в целевом Service.

### 5.5 Логирование в ленту

Смену owner **логировать в ленту** (решение #6). Инфраструктура есть:
`FieldLabelResolver` уже маппит `owner_user_id → «Ответственный/Владелец»`; запись —
через `EntityLogService::record(...)` с `LogAction::DataChanged` (или спец-экшен
`OwnerReassigned`). `CompanyService::syncOwnerFromDeal/FromTask` пишет entity_log на
Company (и Contact) с полями `{ field: owner_user_id, from, to, reason: 'deal_sync'|'task' }`.
Существующий feed отрисует это как «Ответственный: X → Y».

### 5.6 Edge-cases (обязательны к обработке)

| Кейс | Поведение |
|------|-----------|
| Сделка won/lost (закрыта) | Закрытая сделка **НЕ** держит owner точечной задачи (правило B «активная» = open). Смена owner закрытой сделки — синхронизировать всё равно? **Решение: DealOwnerChanged синкает независимо от статуса** (владелец компании должен следовать владельцу сделки, даже если won). Для правила B «активная сделка блокирует» — считаем только open. |
| Несколько сделок у компании | Правило B: любая **одна** активная сделка блокирует смену owner точечной задачей. Правило A: если несколько активных сделок с разными owner — **последнее изменение owner любой из них** перезапишет owner компании (last-write-wins; лог покажет цепочку). Это осознанный компромисс — компания имеет один owner. |
| Самоназначение | owner уже == новый owner → **no-op**, событие не эмитим / listener рано выходит (нет лишней записи в лент). |
| Контакт без owner (null) | Правило B ставит owner = исполнитель (заполняет пустоту). Правило A ставит = owner сделки. |
| Задача переназначена туда-обратно | Каждая смена = запись в лент (last-write-wins); дубли не схлопываем (аудит). |
| Компания удалена (owner sync в полёте) | `nullOnDelete` уже обнулит company_id сделки; sync на несуществующую компанию — listener null-check, no-op. |

> **Реализация owner-sync — задача crm-backender + sales-backender совместно** (Crm владеет
> записью owner, Sales — триггером и «есть ли активная сделка»). Activity-backender добавляет
> listener для триггера B. backend-architect предоставляет события/сигнатуры (§5.4).

---

## 6. Валюта по стране при привязке компании к сделке

### 6.1 Реализуемость — ДЁШЕВО, реализуем

Правило (из задачи): при привязке компании к сделке — **если валюта сделки не менялась
вручную И позиций (deal_products) нет** — подтянуть валюту по стране компании.

Реализуемо дёшево, потому что:
- «позиций нет» = `deal.products()->doesntExist()` (один cheap EXISTS-запрос).
- «валюта не менялась вручную» — **нет флага `currency_manually_set`**. Дешёвый прокси:
  привязка компании к пустой (только что instant-created) сделке — типичный случай, и
  валюта там = `config('crm.currencies.default')`. **Прокси-правило: подтягиваем валюту по
  стране, только если текущая `deal.currency === config('crm.currencies.default')` И
  продуктов нет.** Это покрывает главный сценарий (instant-create → привязка компании) без
  нового поля. Если юзер уже менял валюту на не-дефолт — не трогаем.
- `currencyByCountry` — та же карта из §1.3 (`config('crm.currencies.by_country')` /
  `CurrencyResolver::forCountry(?string): ?string`). Переиспользуется, дублирования нет.

### 6.2 Где живёт

В `DealService::update()`: когда в `$data` меняется `company_id` (с null/другого на
компанию с известной `country_code`):

```php
// Currency auto-pull on company link (cheap heuristic — no currency_manually_set flag):
if (array_key_exists('company_id', $data) && $data['company_id'] !== null) {
    $company = Company::find($data['company_id']);
    $mapped  = $this->currencyResolver->forCountry($company?->country_code);
    if ($mapped !== null
        && $deal->currency === config('crm.currencies.default')   // не трогали вручную
        && ! $deal->products()->exists()                          // позиций нет
    ) {
        $data['currency'] = $mapped;
    }
}
```

> **Открытый вопрос O2 (§9):** если продукт захочет строгое «валюта не менялась вручную»
> вместо прокси — потребуется boolean-колонка `deals.currency_manually_set` (+ миграция,
> ставится в true при явной смене валюты юзером). Пока **прокси-эвристика** — отмечаем как
> реализуемо-дёшево-сейчас, строгий флаг — **отложено** до запроса продукта.

---

## 7. Порядок работ BE → FE и что отдаём фронту

### 7.1 Секвенс (контракт до фронта)

**Волна 1 — миграции + схема (backend-architect):**
1. Миграция `deals.company_id` nullable + `nullOnDelete` (§2.1).
2. Миграция `pipelines.default_stage_id` (§2.2) + `Pipeline` fillable/relation.
3. `config/crm.php`: `deal.default_title = 'Новая сделка'`, `currencies.default`,
   `currencies.by_country` (+ `CurrencyResolver`).
4. `migrate` + `migrate:rollback` на pgsql. Pint. **Тесты пишет backend-architect** (§7.3).

**Волна 2 — Sales домен (sales-backender), по контракту §1/§3/§6:**
5. `StoreDealRequest` — company_id/title/currency nullable (§1.2).
6. `DealService::create` — дефолты title/currency/stage (§1.3).
7. `DealController@store` — eager-load `pipeline,stage,owner` в ответе (§1.5).
8. `UpdatePipelineRequest` + `PipelineResource` — `default_stage_id` (§3).
9. `DealService::update` — currency auto-pull (§6) + `DealOwnerChanged` эмит (§5.4).
10. `DealService::hasOpenDealForCompany/ForContact` (для правила B, §5.4).
11. Аудит null-company (grep `->company->`) + отчёты «Без компании» (§1.4).

**Волна 3 — Crm домен (crm-backender), по контракту §4/§5:**
12. `StoreCompanyRequest` — required contact/classification, убрать owner (§4.2).
13. `ContactService::create` — owner=автор (§5.2).
14. `CompanyService::syncOwnerFromDeal/FromTask` + `ContactService::…` + entity_log (§5.4/§5.5).

**Волна 4 — Activity (его специалист):**
15. Listener на `ActivityAssigned`/`ActivityCreated` для триггера B (§5.4).

**Волна 5 — FE (frontend / sales-frontender / crm-frontender):**
16. Instant-create кнопки, удаление `/deals/new`-формы, редирект в карточку.
17. Подсветка required-полей в карточке (§8 / решение #2), AutoComplete компании без
    шеврона, «+ Создать компанию» один плюс (решение #3).
18. Настройка «Стадия для новых сделок» в редакторе воронки.
19. Форма «Новая компания» — секции контактов/классификации required, убрать блок
    «Ответственный» и «⋮».
20. i18n «План договора» унификация (§8).

> **Секвенс-инвариант:** волны 1-4 (BE) отдают endpoint'ы и формы ответов ДО волны 5 (FE).
> FE строит против уже существующих контрактов.

### 7.2 Формы ответов, которые FE получает (сводка контракта)

| Endpoint | Что нового во форме |
|----------|---------------------|
| `POST /api/deals` (тело) | `company_id`, `title`, `currency`, `owner_user_id` — все опциональны; обязателен только `pipeline_id` |
| `POST /api/deals` (ответ) | `DealResource` с `company_id: null` допустимо; `company` ключ отсутствует при null; `pipeline`/`stage`/`owner` загружены |
| `PATCH /api/deals/{id}` (тело) | `company_id` можно прислать → сервер может авто-сменить `currency` (§6) и эмитит owner-sync (§5) |
| `PATCH /api/deals/{id}` (ответ) | `DealResource`; `currency` может отличаться от присланного (авто-подтянут) — FE читает из ответа, не предполагает |
| `GET/PATCH /api/pipelines/{id}` | `PipelineResource.default_stage_id: int|null` (новый ключ) |
| `PATCH /api/pipelines/{id}` (тело) | принимает `default_stage_id` (nullable, стадия ∈ воронке) |
| `POST /api/companies` (тело) | `phone`/`website`/`address`/`company_type_id`/`country_code`/`source` — **required**; `owner_user_id`/`responsible_user_id` больше НЕ принимаются |
| feed компании/контакта | новые записи «Ответственный: X → Y» (owner-sync лог, §5.5) |

### 7.3 Тесты (владелец — backend-architect, PHPUnit+SQLite для всех слоёв)

- Feature: `POST /api/deals` только с `pipeline_id` → 201, дефолты (title=«Новая сделка»,
  owner=auth, currency=default, stage=default_stage_id ?? первая), company_id=null.
- Feature: `default_stage_id` задан → сделка в нём; указывает на won/lost → fallback.
- Feature: `PATCH /api/pipelines/{id}` `default_stage_id` чужой воронки → 422.
- Feature: `POST /api/companies` без phone/website/address/type/country/source → 422 (каждое).
- Feature: `POST /api/companies` не принимает `owner_user_id` (игнор/reject); owner=автор.
- Feature: удаление компании с активными сделками → сделки выживают, `company_id=null`.
- Feature/Unit: owner-sync A (смена owner сделки синкает company+contacts, лог в feed).
- Feature/Unit: owner-sync B (точечная задача; активная сделка блокирует; закрытая — нет).
- Feature: currency auto-pull при привязке компании (дефолт-валюта+нет продуктов → сменилась;
  не-дефолт валюта ИЛИ есть продукты → не сменилась).
- Feature: DealMoveService required_fields на company при `company_id=null` → move 422.

---

## 8. «Планируемая дата закрытия» → «План договора» (решение #7)

**Находка (важная):** это **НЕ** одно поле. По разведке:
- `expected_close_date` — «Ожидаемая/Планируемая дата закрытия» (в create-форме и move-диалоге,
  один i18n-ключ `sales.deal.create.fields.expectedCloseDate`).
- `expected_sign_date` — «**План договора**» (`sales.deal.info.fields.plannedContract`).
- `expected_payment_date` — «План оплаты» (`sales.deal.info.fields.plannedPayment`).

Решение #7 говорит: «Планируемая дата закрытия» = «План договора» = одно поле. **По данным —
это разные колонки** (`expected_close_date` vs `expected_sign_date`). 

**Контрактное решение:** «План договора» в карточке = `expected_sign_date` (уже так).
Задача #7 — про **i18n-унификацию подписи**: убрать/переименовать оставшиеся подписи
«Планируемая дата закрытия», чтобы одно и то же поле не называлось по-разному в разных
экранах. **НЕ мержить колонки** `expected_close_date` и `expected_sign_date` (это сломает
фильтры/отчёты, которые их различают). 

- **FE/i18n-задача:** привести подписи к единообразию — карточное «План договора» =
  `expected_sign_date`; «Ожидаемая дата закрытия» (`expected_close_date`) — оставить как
  отдельное поле-прогноз, но убедиться, что нигде не подписано «План договора».
- **Backend:** правок колонок НЕТ. Отчёты/фильтры на `expected_close_date` и
  `expected_sign_date` не трогаем.

> **Открытый вопрос O3 (§9):** если продукт РЕАЛЬНО хочет схлопнуть два поля в одно
> (`expected_close_date` удалить, везде использовать `expected_sign_date`) — это отдельная
> миграция + правка всех отчётов/фильтров/KPI, где фигурирует `expected_close_date`. По
> умолчанию трактуем #7 как **переименование подписей, без миграции**. Подтвердить у продукта.

---

## 9. Открытые вопросы (эскалация продукту/main)

- **O1 — «источник» в required-компании:** делаем `source` (строка-код) required;
  `acquisition_channel_id` остаётся nullable. Если под «источник» имелся в виду channel —
  уточнить.
- **O2 — «валюта не менялась вручную»:** реализуем прокси-эвристикой (currency == default &&
  нет продуктов). Строгий флаг `deals.currency_manually_set` — отложено до явного запроса
  (потребует миграцию + запись флага при ручной смене валюты).
- **O3 — «План закрытия» = «План договора»:** трактуем #7 как i18n-переименование подписей
  без слияния колонок (`expected_close_date` и `expected_sign_date` различаются в отчётах).
  Реальное слияние — отдельный крупный тикет; подтвердить у продукта.
- **O4 — owner-sync правило A уточнение:** финализировано как «owner компании/контактов
  следует за owner СДЕЛКИ» (не за исполнителем задачи по сделке) — §5.3. Задача по сделке
  сама owner не двигает; двигает смена owner сделки. Подтвердить, что это и есть смысл
  «сделка имеет приоритет».
- **O5 — `doctrine/dbal` для `->change()`:** проверить наличие в composer перед миграцией
  §2.1; если нет — согласовать установку (это инфра-исключение, не бизнес-пакет).
