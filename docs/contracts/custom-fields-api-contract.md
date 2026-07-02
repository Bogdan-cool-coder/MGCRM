# API-контракт — Управление кастомными полями (CustomFieldDef)

> **Стадия:** contract (spec-author), **финализирован** под продуктовые решения (§10). Только этот `.md` — кода/миграций в этой стадии нет.
> **Эталон стека:** `docs/backend-standard.md` + `ARCHITECTURE.md` + реальный `src/app/Domain/*`.
> **Бизнес-логика:** `examples/contracts/apps/api/app/routers/custom_fields.py` + `.../services/custom_fields.py` (смысл, не код).
> **Владелец домена:** `Domain/Crm` (crm-backender). Потребители контракта: `crm-frontender` + `crm-backender`.
>
> **TL;DR состояния:** админ-CRUD дефиниций **УЖЕ построен и покрыт тестами** (контроллер/сервис/ресурс/2 FormRequest/роуты/фичи). Это **не** greenfield. Контракт ниже — (1) фиксирует **единый** финальный shape ответа/запроса как канон для фронта и (2) точечно перечисляет `to-build`-дельту. Раздел [§7 Gap-анализ](#7-gap-анализ) — единственный список работ для `crm-backender`; принятые продуктовые решения — [§10](#10-решения-контракта-принято).
>
> **⚠️ Обнаружен ЖИВОЙ рассинхрон fe↔be** (см. §3.1): фронт-TS (`front/src/entities/crm.ts:364-373`) шлёт/ждёт `scope`, а backend Resource отдаёт `entity_scope`; плюс фронт-TS не знает половины полей ответа. Контракт фиксирует канон и обязывает обе стороны сойтись на нём — это **синхронный PR fe+be** (G11).

---

## 0. Модель домена (что уже есть в коде)

| Артефакт | Файл | Заметка |
|---|---|---|
| Таблица | `src/database/migrations/2026_06_11_110008_create_custom_field_defs_table.php` | `unique(entity_scope, code)`, `index(entity_scope)`, `index(is_active)` |
| Модель | `src/app/Domain/Crm/Models/CustomFieldDef.php:16-46` | fillable+casts, без логики |
| Enum scope | `src/app/Domain/Crm/Enums/CustomFieldScope.php:7-12` | `contact` · `company` · `deal` → **+`contract` (G1, решено §10)** |
| Enum type | `src/app/Domain/Crm/Enums/CustomFieldType.php:7-18` | text·textarea·number·date·select·multiselect·boolean·url·user_ref |
| Сервис | `src/app/Domain/Crm/Services/CustomFieldService.php:23-174` | CRUD дефиниций + read/write значений на entity |
| Контроллер (дефиниции) | `src/app/Http/Controllers/Crm/CustomFieldDefController.php:24-110` | index·store·show·update·destroy·schema |
| Контроллер (чтение значений deal) | `src/app/Http/Controllers/Sales/DealCustomFieldController.php:17-31` | read-only enriched values |
| Resource | `src/app/Http/Resources/Crm/CustomFieldDefResource.php:12-33` | ручной JsonResource |
| FormRequest store | `src/app/Http/Requests/Crm/StoreCustomFieldDefRequest.php:12-36` | |
| FormRequest update | `src/app/Http/Requests/Crm/UpdateCustomFieldDefRequest.php:11-33` | |
| Роуты | `src/routes/api.php:324-335` (defs) · `:664` (deal values read) | |
| Тесты | `tests/Feature/Crm/CustomFieldTest.php` · `CompanyCustomFieldTest.php` · `Unit/Crm/CustomFieldServiceTest.php` | |

**Модель хранения значений (канон, не менять):** значения кастом-полей живут в JSONB-колонке `<entity>.extra_fields` (map `code → value`). Отдельной таблицы `custom_field_values` **нет и не заводим** — это осознанный house-выбор. `CustomFieldDef` — только *определения* (справочник). Значения читаются/пишутся через `CustomFieldService::readFields/writeFields`, который валидирует ключи против активных дефиниций scope и коэрсит типы.

---

## 1. Эндпоинты — CRUD дефиниций (существуют, канон)

Все под `Route::middleware('auth:sanctum')`, префикс `/api`. Чтение — любой авторизованный; запись — gate `admin-write` (см. §5).

| Метод | Путь | Назначение | Authz | Статус кода |
|---|---|---|---|---|
| `GET` | `/api/crm/custom-fields?scope=<scope>&include_inactive=1` | Список дефиниций; фильтр `?scope=contact\|company\|deal\|contract`; `include_inactive` (см. §1.1) | auth | ✅ есть / 🔨 флаг to-build |
| `GET` | `/api/crm/custom-fields/schema?entity_scope=<scope>` | Дефиниции (**только active**), сгруппированные по `group`, для рендера формы | auth | ✅ есть |
| `POST` | `/api/crm/custom-fields` | Создать дефиницию | `admin-write` | ✅ есть |
| `GET` | `/api/crm/custom-fields/{customFieldDef}` | Одна дефиниция | auth | ✅ есть |
| `PATCH`/`PUT` | `/api/crm/custom-fields/{customFieldDef}` | Обновить | `admin-write` | ✅ есть |
| `DELETE` | `/api/crm/custom-fields/{customFieldDef}` | Удалить дефиницию (значения в `extra_fields` НЕ затираются — просто перестают рендериться) | `admin-write` | ✅ есть |
| `PATCH` | `/api/crm/custom-fields/reorder?entity_scope=<scope>` | **Bulk `sort_order`** для одного scope (drag-sort админки) | `admin-write` | 🔨 **to-build (G5, решено §10)** |

> **Порядок роутов (критично):** `schema` и `reorder` объявляются **до** `apiResource` — иначе `custom-fields/{schema}` перехватит статический сегмент как параметр. В `api.php:324` для `schema` это уже так; `reorder` добавить туда же перед `apiResource`.

### 1.1 `GET /api/crm/custom-fields` — список (админский, включает inactive)

**Query:**
- `scope` (опц., enum `CustomFieldScope`). Без scope — все дефиниции, отсортированы `entity_scope, sort_order`.
- `include_inactive` (опц., `bool`, **default `true` для этого эндпоинта**). 🔨 **G5b, решено §10 (ОВ-1).**

**Решение по inactive (ОВ-1):** админке нужно видеть `is_active=false` (иначе нельзя ре-активировать выключенное поле). Поэтому **`index` — админский список и по умолчанию отдаёт ВСЕ дефиниции (active + inactive)**; `schema` (форма рендера сущности) остаётся **только active**. Разделение чистое: `index` = «управление справочником» (видно всё), `schema` = «нарисовать форму» (только активные). Дополнительный флаг `?include_inactive=0` позволяет фронту при желании сузить `index` до active — но дефолт `index` = всё. Отдельный `/inactive`-эндпоинт **не заводим** (лишний surface).

**Текущее поведение (до G5b):** `index` уже отдаёт inactive, когда фильтр по `scope` **не** задан (ветка `CustomFieldDef::orderBy(...)->get()` — без `where is_active`, `CustomFieldDefController:74`). Но при `?scope=company` идёт через `defsForScope()`, который жёстко фильтрует `is_active=true` (`CustomFieldService:32-36`) — то есть **отфильтрованный по scope список теряет inactive**. Это баг для админки. **G5b чинит:** `index` со `scope` должен по умолчанию тоже включать inactive (не звать `defsForScope`, а собственный запрос с `where scope` + опциональным `is_active`), а `defsForScope` оставить active-only для форм-рендера/значений.

**Ответ 200:** `{ "data": [ CustomFieldDefResource, ... ] }` (коллекция ресурсов — обёртка `data` автоматом). `is_active` присутствует в каждом элементе — фронт различает active/inactive по нему.

### 1.2 `GET /api/crm/custom-fields/schema?entity_scope=<scope>` — для рендера формы

**Query:** `entity_scope` (**required**, enum). **Ответ 200:**
```json
{ "data": [
  { "group": "Основное", "fields": [ CustomFieldDefResource, ... ] },
  { "group": null,        "fields": [ ... ] }
] }
```
Только `is_active=true`, внутри группы — по `sort_order`. Backs `CustomFieldRenderer.vue`.

### 1.3 `POST` / `PATCH` / `DELETE` — форма запроса/ответа

- **store/update** возвращают `{ "data": CustomFieldDefResource }`, коды `201` / `200`.
- **destroy** возвращает `{ "message": "Custom field definition deleted." }`, код `200`.

> **Дельта для консистентности (to-build, low-effort):** привести `store→201`, `destroy→204 No Content` (сейчас 200+message). Не обязательно, но выравнивает с остальным API и с эталоном contracts (`204`). Решение — на `reviewer`; фронт должен трактовать `200 || 204` на delete как успех в любом случае.

---

## 2. Эндпоинты — чтение/запись ЗНАЧЕНИЙ на сущностях

**Ключевое архитектурное решение (канон, не менять):** значения кастом-полей пишутся **НЕ** отдельным `/extra-fields`-эндпоинтом, а через **обычные update-эндпоинты сущности** — единым полем `extra_fields` в теле. Так уже работает Contact/Company/Deal. Это отличается от FastAPI-эталона (там были отдельные `PATCH /<entity>/{id}/extra-fields`), и это осознанно: одна транзакция записи сущности, единый аудит-diff, один owner-guard (Policy сущности), без дублирования визибилити.

### 2.1 Запись значений (существует для contact/company/deal)

| Метод | Путь | Тело (фрагмент) |
|---|---|---|
| `POST` | `/api/companies`, `/api/contacts`, `/api/deals` | `{ ..., "extra_fields": { "<code>": <value> } }` |
| `PATCH` | `/api/companies/{id}`, `/api/contacts/{id}`, `/api/deals/{id}` | `{ "extra_fields": { "<code>": <value> } }` |

Правила (реализованы):
- `extra_fields` в FormRequest — `['nullable','array']` (store) / `['sometimes','nullable','array']` (update). Per-key валидация — **в сервисе через `CustomFieldService::writeFields`**, не в FormRequest (ключи динамические — их набор зависит от дефиниций).
- Нет активных дефиниций для scope → free-form pass-through (обратная совместимость).
- Есть дефиниции → неизвестный ключ ⇒ `422` `{ "errors": { "extra_fields": "..." } }`; значение коэрсится по типу.
- `PATCH` без ключа `extra_fields` — существующие значения сохраняются; явный `[]`/`null` — очищает.
- Каждое изменение попадает в аудит-diff сущности (Deal — уже; `DealService.php:1300`).

### 2.2 Чтение значений (enriched)

- **Deal:** `GET /api/deals/{deal}/custom-fields` → `{ "data": [ {id,code,label,field_type,required,options,group,sort_order,value}, ... ] }` (`DealCustomFieldController:23-30`, `authorize('view',$deal)`). Значение = `extra_fields[code] ?? default_value`.
- **Contact/Company (РЕШЕНО §10, G8):** **единый enriched-read НЕ добавляем.** Фронт собирает форму из **`GET /crm/custom-fields/schema?entity_scope=<scope>`** (дефиниции, только active, сгруппированы) **+ `extra_fields`** из основного `Resource` сущности (карточка Contact/Company уже отдаёт `extra_fields` как map `code→value`). Значение поля = `entity.extra_fields[def.code] ?? def.default_value` (мёрджит фронт). Причина: Deal-эндпоинт исторический (его форма «Основное» строилась до `schema`); для Contact/Company `schema`+`extra_fields` полностью самодостаточны, отдельный эндпоинт дублировал бы `readFields`. `CustomFieldRenderer.vue` уже умеет этот паттерн (`getSchema` в `front/src/api/crm/customFields.ts:23`). **G8 закрыт как «не делаем».**

> **Форма ответа Deal-эндпоинта** (историческая, `readFields`) использует поля `field_type`/`sort_order`/`value` — совместима с каноном §3, но это НЕ `CustomFieldDefResource` (у него добавлен `value`, нет `entity_scope`/`help_text`/`default_value`/`is_active`/timestamps). Не унифицируем — это value-read, а не def-read.

---

## 3. `CustomFieldDefOut` — ЕДИНЫЙ финальный shape (канон, source of truth)

Ручной `JsonResource` (`src/app/Http/Resources/Crm/CustomFieldDefResource.php`). **Это единственный источник истины формы дефиниции.** Фронт-TS `CustomFieldDef` **приводится к нему** (см. §3.1).

**Канонические имена полей (зафиксированы):** `entity_scope` (НЕ `scope`), `field_type` (НЕ `kind`), `label` (НЕ `label_ru`). Это уже наши house-имена в backend Resource — фронт мигрирует на них.

### 3.1 JSON-ответ (то, что backend ОТДАЁТ — все поля Resource перечислены исчерпывающе)

```jsonc
{
  "id": 12,                            // int
  "entity_scope": "company",           // "contact"|"company"|"deal"|"contract" (enum value; в ответе всегда непустой)
  "code": "crm_segment",               // string, slug ^[a-z][a-z0-9_]*$, immutable после создания
  "label": "CRM Сегмент",              // string, display-имя
  "help_text": null,                   // string|null, подсказка под полем
  "field_type": "select",              // enum value (§3.3)
  "options": ["A", "B", "C"],          // string[], ВСЕГДА массив (null нормализуется в []); непусто для select/multiselect
  "default_value": null,               // string|number|boolean|string[]|null — значение по умолчанию
  "required": false,                   // bool
  "group": "Основное",                 // string|null — секция UI-группировки
  "sort_order": 0,                     // int, порядок в scope (drag-sort)
  "is_active": true,                   // bool — inactive не рендерятся в форме, но видны в админ-списке
  "created_at": "2026-07-02T10:00:00+00:00",  // ISO-8601 string
  "updated_at": "2026-07-02T10:00:00+00:00"   // ISO-8601 string
}
```

### 3.2 TS-интерфейс (то, что фронт ДОЛЖЕН иметь — канон для `front/src/entities/crm.ts`)

```ts
export type CustomFieldScope = 'contact' | 'company' | 'deal' | 'contract'  // +contract (G1)

export type CustomFieldType =
  | 'text' | 'textarea' | 'number' | 'date'
  | 'select' | 'multiselect' | 'boolean' | 'url' | 'user_ref'   // 'boolean' (НЕ 'bool'); убрать legacy 'bool'

export interface CustomFieldDef {
  id: number
  entity_scope: CustomFieldScope       // was `scope` — RENAME (G11)
  code: string
  label: string
  help_text: string | null             // NEW — было отсутствующим в TS
  field_type: CustomFieldType
  options: string[]                    // всегда массив (не `| null`)
  default_value: string | number | boolean | string[] | null   // NEW
  required: boolean                    // NEW
  group: string | null                 // NEW
  sort_order: number
  is_active: boolean
  created_at: string                   // NEW (ISO-8601)
  updated_at: string                   // NEW (ISO-8601)
}
```

### 3.3 `field_type` — канонический enum (backend `CustomFieldType`, полный)

`text` · `textarea` · `number` · `date` · `select` · `multiselect` · `boolean` · `url` · `user_ref`.
**Никакого `bool`** — только `boolean` (фронт-TS сейчас содержит и `bool`, и `boolean`: `entities/crm.ts:360-361` — legacy `bool` **удалить**, backend его никогда не слал).

### 3.4 РАЗРЕШЕНИЕ рассинхрона (что расходится сегодня → к чему приводим)

| Поле фронт-TS сейчас (`entities/crm.ts:364-373`) | Backend Resource сейчас | **КАНОН (обе стороны приводятся)** |
|---|---|---|
| `scope` | `entity_scope` | **`entity_scope`** — фронт RENAME |
| `field_type` (union с `'bool'` и `'boolean'`) | `field_type` (`boolean`) | **`field_type`**, union без `'bool'` |
| `label` | `label` | `label` (совпадает ✅) |
| `options?: string[] \| null` | `options` (всегда `[]`) | `options: string[]` (не nullable) |
| — (нет) | `help_text` | **добавить** в TS |
| — (нет) | `default_value` | **добавить** |
| — (нет) | `required` | **добавить** |
| — (нет) | `group` | **добавить** |
| — (нет) | `created_at`/`updated_at` | **добавить** |
| `CustomFieldScope = 'deal'\|'company'\|'contact'` | enum без `contract` | **+`contract`** (G1) обе стороны |

> Backend Resource **менять не нужно** (уже канон) — правки только фронт-TS (G11). Единственная backend-правка формы — enum `+contract` (G1), общая для fe+be.

---

## 4. FormRequest — правила валидации (канон + дельта)

### 4.1 `StoreCustomFieldDefRequest` (есть, `authorize()=true`; gate — в контроллере)

```php
'entity_scope' => ['required','string', Rule::enum(CustomFieldScope::class),
                    Rule::unique('custom_field_defs')->where('code', $this->input('code'))], // 🔨 to-build: unique(scope,code)
'code'         => ['required','string','max:64','regex:/^[a-z][a-z0-9_]*$/'],  // 🔨 to-build: якорь на букву (сейчас /^[a-z0-9_]+$/)
'label'        => ['required','string','max:255'],
'help_text'    => ['nullable','string','max:512'],
'field_type'   => ['required','string', Rule::enum(CustomFieldType::class)],
'options'      => ['nullable','array'],
'options.*'    => ['string'],
'default_value'=> ['nullable'],
'required'     => ['boolean'],
'group'        => ['nullable','string','max:128'],
'sort_order'   => ['nullable','integer','min:0'],
'is_active'    => ['boolean'],
```

**Дельта store (to-build):**
1. **Уникальность `(entity_scope, code)` на уровне валидации** → отдаёт `422` с полем `code` вместо `500` от БД-констрейнта. Эталон: `custom_fields.py:176-181`.
2. **`code`-regex** выровнять на `^[a-z][a-z0-9_]*$` (начало с буквы) — эталон `custom_fields.py:83`. Сейчас допускает старт с цифры/`_`.
3. **`options` обязателен для `select`/`multiselect`** — условное правило `required_if:field_type,select,multiselect` (+ `min:1`). Иначе создаётся select без вариантов.

### 4.2 `UpdateCustomFieldDefRequest` (есть)

Не принимает `entity_scope`/`code` (их менять нельзя — сломает целостность значений в `extra_fields`; эталон `custom_fields.py:91`). Прочие поля — `sometimes`. **Дельта:** то же условие `options required_if select|multiselect`, если `field_type` присутствует.

### 4.3 Reorder FormRequest (to-build)

`ReorderCustomFieldDefsRequest`:
```php
'entity_scope' => ['required','string', Rule::enum(CustomFieldScope::class)], // из query или body
'items'        => ['required','array','min:1'],
'items.*.id'         => ['required','integer','exists:custom_field_defs,id'],
'items.*.sort_order' => ['required','integer','min:0'],
```
`entity_scope` в scope-фильтре при массовом апдейте — чтобы не переставить чужой scope (deal↔contact в одной таблице). Эталон `custom_fields.py:239-262`.

---

## 5. Policy / авторизация (IAM-1 закрыт)

**Текущее (канон):** запись дефиниций — через глобальный gate `admin-write` (`$this->authorize('admin-write')` в контроллере, `store/update/destroy`, а также будущий `reorder`). Это spatie-permission, авто-зарегистрированный как Gate (см. `docs/backend-standard.md §4`). Чтение — любой `auth:sanctum`. Это **корректно и достаточно** для «admin + director управляют».

- **РЕШЕНО §10:** кастом-полями управляют **admin + director**. Реализуется через выдачу права `admin-write` роли **director** в сидере (**G12**). `crm-backender` проверяет `RolePermissionSeeder.php`: если у `director` уже есть `admin-write` — no-op; если нет — добавить в его permission-набор. **Важно:** `admin-write` — глобальный gate (не только кастом-поля), поэтому расширение прав director'а на `admin-write` затрагивает ВСЕ admin-write-эндпоинты. Если это нежелательно (director не должен получать весь admin-write), альтернатива — **гранулярный permission `custom-fields.manage`** + отдельный gate на роутах кастом-полей (см. §5.1). **Дефолт контракта: сначала проверить, что уже покрывает `admin-write` у director; расширять минимально.** `reviewer`/PM подтверждают охват перед мержем.
- Запись значений на сущность (`extra_fields`) авторизуется **Policy самой сущности** (`update`/`view` Deal/Contact/Company/Document) в её update-эндпоинте — отдельной authz для кастом-значений не нужно. Owner/visibility — через `VisibilityResolver`, как везде.

### 5.1 Гранулярная альтернатива (если `admin-write` для director слишком широк)

Если PM решит, что director НЕ должен получать весь `admin-write`, вводится узкий permission:
- `custom-fields.manage` (spatie permission) → выдаётся admin + director.
- Роуты `store/update/destroy/reorder` gate'ятся `can:custom-fields.manage` вместо `admin-write` (или `$this->authorize('custom-fields.manage')`).
- Это чище по blast-radius, но добавляет новый permission в матрицу и меняет authz существующих (уже задеплоенных) эндпоинтов — **breaking для текущего admin-only поведения только в сторону расширения** (admin сохраняет доступ, добавляется director). **Решение admin-write-vs-granular — за `reviewer`+PM**; контракт фиксирует ОБА пути, дефолт — минимальное расширение (проверить `admin-write` у director первым).

**Дельта (опц., to-decide):** можно ввести отдельный `CustomFieldDefPolicy` (viewAny/create/update/delete) вместо глобального `admin-write`, если понадобится гранулярнее «director управляет, manager нет». Пока **не требуется** — глобальный gate проще и уже работает. Не плодить Policy ради Policy.

> **Жёстко:** никаких inline `if ($user->role === 'admin')` в контроллере/сервисе. Только gate/Policy (`docs/backend-standard.md §4`).

---

## 6. Reuse / library-first

| Правило | Как применяем здесь |
|---|---|
| **Safe LIKE (§6.1)** | В управлении дефинициями поиска по строке **нет** (фильтр только по `scope`/`is_active`). Если фронт попросит `?q=` по `label`/`code` — **обязателен `whereLikeCi()`**, не raw `like`. Сейчас не нужно. |
| **Manual JsonResource** | `CustomFieldDefResource` — ручной, уже так. Никакого spatie/laravel-data. |
| **FormRequest-only** | Вся валидация — в FormRequest (кроме динамических per-key значений `extra_fields`, которые по природе валидируются в сервисе против дефиниций). ⚠️ `schema()` и `index()` в контроллере используют inline `$request->validate([...])` для query-параметра `entity_scope`/`scope` (`CustomFieldDefController:41,64`) — это **известное отклонение от §1 black-list**. To-build: вынести в `SchemaCustomFieldRequest` / query-валидацию FormRequest'ом, либо оставить как задокументированное исключение (query-only, не тело) — решение `reviewer`. |
| **No new packages** | Ничего ставить не нужно — enum/JSONB/spatie-permission/Sanctum уже в проекте (`docs/backend-standard.md §7`). |
| **Reorder** | Переиспользовать паттерн существующих reorder-эндпоинтов (`PipelineStageController::reorder` — `api.php:578`; quiz/module reorder). Не изобретать свой формат. |
| **Coerce типов** | Уже в `CustomFieldService::coerce()`. **Дельта:** привести к полноте эталона — сейчас coerce покрывает number/boolean/date/default; `multiselect`→`array`, `select`→строгая проверка вхождения в `options`, `url`, лимиты длины (`MAX_VALUE_LEN`, `MAX_MULTISELECT_ITEMS`) — **не реализованы** (эталон `services/custom_fields.py:90-239`). |

---

## 7. Gap-анализ (вердикт: ~80% построено; дельта — точечная)

**Что уже есть и работает (НЕ трогать, канон):**
- ✅ Таблица + модель + 2 enum (`CustomFieldDef`, `CustomFieldType`, `CustomFieldScope`).
- ✅ Полный админ-CRUD дефиниций: `CustomFieldDefController` (index/store/show/update/destroy/schema) + сервис + ресурс + 2 FormRequest + роуты (`api.php:324-335`).
- ✅ Gate `admin-write` на записи, чтение — любой auth.
- ✅ Запись значений через update-эндпоинты Contact/Company/Deal (`extra_fields`), валидация ключей против дефиниций, coerce, аудит-diff (Deal), clear/preserve-семантика на PATCH.
- ✅ Чтение enriched-значений Deal (`GET /api/deals/{deal}/custom-fields`).
- ✅ Тесты: `CustomFieldTest`, `CompanyCustomFieldTest`, `Unit/Crm/CustomFieldServiceTest`.

**Что `crm-backender` (+ `crm-frontender` для G11) должен построить (delta, с учётом решений §10):**

| # | Задача | Файлы (создать/править) | Приоритет |
|---|---|---|---|
| **G1** | **Contract-scope (РЕШЕНО, делаем):** `case Contract = 'contract'` в enum; замапить `Document` в `CustomFieldService::scopeFor()`; `Document` уже имеет `extra_fields` JSONB (`Document.php:75,84`); запись значений — инъекцией `CustomFieldService` в `DocumentService` (не прямой доступ) | `Enums/CustomFieldScope.php`; `Services/CustomFieldService.php:130-140`; `Contracts/Services/DocumentService.php` | ⭐ высокий |
| **G5** | **Reorder-эндпоинт (РЕШЕНО, делаем):** `PATCH /crm/custom-fields/reorder?entity_scope=` + `ReorderCustomFieldDefsRequest` + `CustomFieldService::reorder()` (bulk `sort_order` в одной транзакции, scope-фильтр) + роут ПЕРЕД `apiResource`; переиспользовать паттерн `PipelineStageController::reorder` (`api.php:578`) | контроллер, новый FormRequest, сервис, `api.php:~324` | ⭐ высокий |
| **G5b** | **`index` показывает inactive (РЕШЕНО ОВ-1):** `index` со `scope` не должен звать active-only `defsForScope()` — собственный запрос `where entity_scope` без `is_active`-фильтра по умолчанию (+ опц. `?include_inactive=0` сужает до active). `defsForScope()` и `schema` остаются active-only | `CustomFieldDefController.php:62-77`; возможно новый `CustomFieldService::listDefs(scope, includeInactive)` | ⭐ высокий |
| **G12** | **Director управляет (РЕШЕНО):** выдать `director` право `admin-write` (или гранулярный `custom-fields.manage`, §5.1) в сидере; проверить текущий охват прежде расширять | `database/seeders/RolePermissionSeeder.php` | ⭐ высокий |
| **G11** | **Sync фронт-TS к канону (§3, РЕШЕНО):** переименовать `scope→entity_scope`, добавить `help_text/default_value/required/group/created_at/updated_at`, убрать legacy `'bool'`, `options: string[]` (не nullable), `+contract` в scope-union. Затронуть места чтения `.scope` в компонентах | `front/src/entities/crm.ts:351-373`; `front/src/api/crm/customFields.ts`; потребители `.scope` (grep) | ⭐ высокий (fe, синхронно с G1) |
| G2 | **Unique (scope,code) в FormRequest** → `422` с полем `code` вместо `500` БД-констрейнта | `StoreCustomFieldDefRequest.php` | высокий |
| G3 | **`code`-regex** `^[a-z][a-z0-9_]*$` (старт с буквы), синхронно поправить комментарий миграции | `StoreCustomFieldDefRequest.php:23` | средний |
| G4 | **`options required_if select\|multiselect`** (store+update, `min:1`) | обе FormRequest | средний |
| G6 | **Полный coerce/validate значений** до паритета эталона: `multiselect`→array + вхождение в options, `select`→строгая проверка options, `url`, `boolean`, лимиты длины (`MAX_VALUE_LEN`, `MAX_MULTISELECT_ITEMS`) | `CustomFieldService.php:161-173` (расширить `coerce`, добавить options-guard) | средний |
| G7 | **Inline-validate в контроллере** (`schema`/`index` query) — вынести в FormRequest (query-валидация) или задокументировать исключение (query-only) | `CustomFieldDefController.php:41,64` | низкий (решение `reviewer`) |
| ~~G8~~ | ~~Единый enriched-read Contact/Company~~ — **РЕШЕНО: НЕ делаем** (§2.2; фронт собирает `schema`+`extra_fields`) | — | ❌ закрыт |
| G9 | **`store→201` / `destroy→204`** выравнивание кодов (опц.) | контроллер | низкий |
| G10 | **Тесты на дельту**: contract-scope CRUD (G1), reorder (G5), index-показывает-inactive (G5b), director-can-manage (G12), unique-code 422 (G2), options-required 422 (G4), multiselect/select/boolean-валидация значений (G6) | `tests/Feature/Crm/*`, `tests/Unit/Crm/CustomFieldServiceTest` | обязателен для каждого пункта выше |

**Миграции:** **новых миграций для этой фичи НЕ нужно.** G1 не требует схемы (Contract-scope хранит значения в существующем `documents.extra_fields`; в `custom_field_defs` scope — строка, `unique(entity_scope,code)` уже есть). G5/G5b/G6 — код. G3 меняет regex/комментарий, не схему. G12 — сидер, не миграция.

**Порядок работ (секвенирование):** G1+G11 синхронно (contract-scope на be+fe вместе, иначе фронт-union разойдётся) → G5+G5b+G12 (admin-функциональность reorder/inactive/director) → G2/G3/G4/G6 (ужесточение валидации, обратно совместимо) → G7/G9 (косметика) → G10 (тесты — вместе с каждым пунктом, не в конце).

---

## 8. Границы домена

- **Владелец:** `Domain/Crm` — определения кастом-полей и сервис read/write значений (`CustomFieldService`) живут здесь.
- **Cross-domain доступ к значениям — только через `CustomFieldService`.** Sales (`DealService`) и Contracts (при G1) **не** читают/пишут `extra_fields` напрямую — вызывают `$this->customFieldService->writeFields($entity, $values)` / `readFields($entity)`. Deal уже так (`DealService.php:97,1136`), Company/Contact — так (`CompanyService:773`, `ContactService:464`). Contract при G1 — тем же способом, инъекцией `CustomFieldService` в `DocumentService`, **без** прямого доступа к чужой модели.
- **`scopeFor()` — единая точка маппинга `Model → CustomFieldScope`** (`CustomFieldService.php:130`). Любая новая сущность с кастом-полями регистрируется ТОЛЬКО здесь; доменные сервисы не хардкодят scope-строки.
- **Значения физически** лежат в JSONB-колонке владельца-сущности (`crm_companies.extra_fields`, `crm_contacts.extra_fields`, `deals.extra_fields`, `documents.extra_fields`) — это осознанно: сущность владеет своими данными, `Crm` владеет *правилами* их валидации. Чужой домен не тянется в `custom_field_defs` мимо сервиса.

---

## 9. Секвенирование (contract → back → front)

1. Контракт зафиксировал ЕДИНЫЙ shape (§3) → `crm-frontender` строит админку дефиниций/`CustomFieldRenderer` на `GET /crm/custom-fields`, `/schema`, `POST/PATCH/DELETE` (в проде) **+ приводит фронт-TS к §3.2 (G11)**.
2. `crm-backender` выполняет дельту G1–G12. **Меняют surface API:** reorder (G5, аддитивно), contract-scope (G1, аддитивно), index-inactive-дефолт (G5b). Остальное — ужесточение валидации/сидер, обратно совместимо.
3. **Breaking-точки:**
   - **fe-sync (G11)** — фронт-TS `scope→entity_scope` и новые поля: это правка ФРОНТА под уже существующий backend-ответ (backend не менялся, просто фронт читал не то поле-имя). Синхронный PR fe.
   - `reorder` (G5) — аддитивный эндпоинт, не ломает.
   - `422`-формат на unique-code (G2) — фронт должен показывать `errors.code`.
   - `+contract` в enum (G1) — общий для be (enum) + fe (union), синхронно.
   Все backend-изменения — аддитивные/обратно совместимые; единственная «правка чтения» — на стороне фронта (G11).

---

## 10. Решения контракта (ПРИНЯТО)

Продуктовые решения (юзер) + технические решения spec-author'а. **Это финальные вводные для `crm-backender`/`crm-frontender` — не переоткрывать без нового product-решения.**

| # | Вопрос | Решение | Влияет на |
|---|---|---|---|
| P1 | drag-sort дефиниций? | **ДА** — reorder-эндпоинт нужен | G5 |
| P2 | Кто управляет кастом-полями? | **admin + director** — выдать director `admin-write` (минимально; альтернатива — гранулярный `custom-fields.manage`, §5.1) | G12, §5 |
| P3 | Contract-scope сейчас? | **ДА** — `case Contract` в enum + маппинг `Document` в `scopeFor()` | G1, §8 |
| ОВ-1 | Админке видеть `is_active=false`? | **ДА** — `index` = админский список, по умолчанию отдаёт active+inactive (`schema` остаётся active-only). Отдельный `/inactive`-эндпоинт НЕ заводим; флаг `?include_inactive` для сужения | G5b, §1.1 |
| ОВ-2 | Contact/Company enriched-read (как Deal)? | **НЕТ** — фронт собирает из `schema` + `extra_fields` ресурса сущности. G8 закрыт | §2.2, G8 |
| ОВ-3 | Канонические имена полей ответа? | **`entity_scope` / `field_type` / `label`** (наши backend-имена). Фронт-TS приводится к ним (G11). Backend Resource — уже канон, не трогаем | §3, G11 |
| ОВ-4 | `field_type` enum? | Полный: `text·textarea·number·date·select·multiselect·boolean·url·user_ref`. **Только `boolean`, без `bool`** (убрать legacy из фронт-TS) | §3.3, G11 |
| — | `destroy` семантика | **Soft** — значения в `extra_fields` осиротевают, но не рендерятся (подтверждено эталоном `custom_fields.py:220`). Каскадной чистки значений НЕТ | §1 |
| — | Новые пакеты | **НЕТ** — всё на enum/JSONB/spatie-permission/Sanctum (уже в проекте) | §6 |
| — | Новые миграции | **НЕТ** для этой фичи (§7) | §7 |

**Остаётся на `reviewer`+PM (техническое, не product):** `admin-write`-vs-гранулярный-`custom-fields.manage` для P2 (§5.1) — проверить blast-radius `admin-write` у director перед мержем; `store→201`/`destroy→204` (G9); вынос inline-validate (G7).
