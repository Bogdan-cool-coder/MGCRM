Достаточно контекста. Теперь у меня полная картина существующей архитектуры. Пишу ТЗ.

---

## ТЗ: Эпик 8 — Карточка 2.0

**Зачем:** Дать менеджерам и администраторам расширяемость карточек (кастомные поля), защиту от дублей данных, полную историю изменений, быстрый поиск по всей системе и сохранённые фильтры-сегменты — переходя от «плоских» карточек к профессиональному CRM-инструменту уровня AmoCRM.

---

## Фича 1: Custom Fields (extra_fields)

### Маршруты

- `/admin/custom-fields` — страница управления дефинициями кастомных полей (только admin/director)
- Без отдельных `/new` и `/[id]` — весь CRUD через Modal на той же странице

### Wireframe — /admin/custom-fields

```
┌────────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ [PageHeader: Кастомные поля]    [+ Добавить поле]     │
│            ├────────────────────────────────────────────────────────┤
│ - ...      │                                                        │
│ - Кастом.  │  ┌──────────────────────────────────────────────────┐ │
│   поля  ←  │  │ Tabs: Лид | Контакт | Компания | Контрагент |    │ │
│            │  │       Сделка | Договор | Подписка                │ │
│            │  ├──────────────────────────────────────────────────┤ │
│            │  │ [Поиск по названию...]              [+ Добавить] │ │
│            │  ├──────────────────────────────────────────────────┤ │
│            │  │ # │ Название │ Код │ Тип │ Обяз. │ Активно │ ··· │ │
│            │  ├──────────────────────────────────────────────────┤ │
│            │  │ = │ Регион   │ region │ text │ нет │ ● да  │ ✎ ✕ │ │
│            │  │ = │ Потенциал│ potential│ select│ да │ ● да │ ✎ ✕ │ │
│            │  │ = │ Дата KPI │ kpi_date │ date │ нет│ ○ нет│ ✎ ✕ │ │
│            │  └──────────────────────────────────────────────────┘ │
│            │                                                        │
│            │ Empty (нет полей для скоупа):                         │
│            │  ┌────────────────────────────────┐                   │
│            │  │  [bi-layout-text-window-reverse]│                  │
│            │  │  Нет полей для «Лид»            │                   │
│            │  │  Добавь первое кастомное поле   │                   │
│            │  │  [+ Добавить поле]              │                   │
│            │  └────────────────────────────────┘                   │
└────────────────────────────────────────────────────────────────────┘
```

### Modal — создание / редактирование поля

```
┌─────────────────────────────────────────────────────┐
│ Новое поле / Редактировать поле          [×]         │
├─────────────────────────────────────────────────────┤
│ Сущность *                                           │
│ [select: Лид ▼]                                      │
│                                                      │
│ Название поля *          Код (snake_case) *          │
│ [input: Регион]          [input: region]             │
│                          текст: только a-z_0-9       │
│                                                      │
│ Тип поля *               Порядок сортировки          │
│ [select: text ▼]         [input: 10]                 │
│                                                      │
│ ── (показать если тип = select / multiselect) ──     │
│ Варианты ответа *                                    │
│ [textarea: по одному на строку, напр. «KZ\nRU\nUA»]  │
│                                                      │
│ Значение по умолчанию                                │
│ [input: —]                                           │
│                                                      │
│ [x] Обязательное поле                                │
│ [x] Активное (показывать в карточках)                │
│                                                      │
│ [inline error если есть]                             │
├─────────────────────────────────────────────────────┤
│ [btn-ghost: Отмена]           [btn-primary: Сохранить]│
└─────────────────────────────────────────────────────┘
```

### Wireframe — CustomFieldsBlock в карточке (напр. карточка Lead)

```
┌────────────────────────────────────────────────────┐
│ card: Дополнительные поля                   [✎ ред.]│
├────────────────────────────────────────────────────┤
│ Регион            [input text или display-значение] │
│ Потенциал  *      [select: Высокий ▼]               │
│ Дата KPI          [date: 15.06.2026]                │
│ Комментарий PM    [textarea: ...]                   │
│                                                     │
│ (если режим редактирования — кнопки внизу)          │
│ [btn-ghost: Отмена]   [btn-primary: Сохранить поля] │
└────────────────────────────────────────────────────┘
```

Блок появляется в карточках Lead / Contact / Company / Counterparty / Deal / Contract / Subscription как отдельная card ниже основных данных, но выше Timeline. Если активных полей для сущности нет — блок не рендерится совсем (не занимает место).

### Композиция и компоненты

**Новые файлы:**

```
apps/web/src/app/(app)/admin/custom-fields/page.tsx
apps/web/src/components/CustomFields/CustomFieldsBlock.tsx
apps/web/src/components/CustomFields/CustomFieldDefModal.tsx
apps/web/src/components/CustomFields/CustomFieldInput.tsx
```

**`CustomFieldDefModal`** — Modal (width="md") для create/edit дефиниции:
```typescript
interface CustomFieldDefModalProps {
  open: boolean;
  def: CustomFieldDef | null;        // null = создание, non-null = редактирование
  defaultScope?: EntityScope;        // подставить скоуп из активного таба
  onClose: () => void;
  onSaved: () => void;
}
```
State внутри: `form: Partial<CustomFieldDef>`, `saving: boolean`, `error: string | null`.

**`CustomFieldsBlock`** — отображение + редактирование extra_fields в карточках:
```typescript
type EntityScope =
  | "lead" | "contact" | "company" | "counterparty"
  | "deal" | "contract" | "subscription";

interface CustomFieldsBlockProps {
  entityScope: EntityScope;
  entityId: number;
  extraFields: Record<string, unknown>;   // из основного SWR объекта
  onSaved?: () => void;                   // mutate родителя
}
```
Внутри делает `useSWR<CustomFieldDef[]>(\`/custom-field-defs?scope=${entityScope}&is_active=true\`, fetcher)`. Рендерит в режиме view (read-only grid) с кнопкой «Редактировать поля» в заголовке card. По клику — переходит в режим edit (inline inputs). PATCH `/api/${entityScope}s/${entityId}/extra-fields` (см. исключения в маппинге entity→route ниже).

**Маппинг entityScope → api path:**
```
lead        → /leads/:id/extra-fields
contact     → /contacts/:id/extra-fields
company     → /companies/:id/extra-fields
counterparty→ /counterparties/:id/extra-fields
deal        → /deals/:id/extra-fields
contract    → /contracts/:id/extra-fields
subscription→ /subscriptions/:id/extra-fields
```

**`CustomFieldInput`** — один инпут по `kind`:
```typescript
interface CustomFieldInputProps {
  def: CustomFieldDef;
  value: unknown;
  onChange: (v: unknown) => void;
  disabled?: boolean;
  error?: string;
}
```
Поддержать: `text` → `<input className="input">`, `textarea` → `<textarea className="input">`, `number` → `<input type="number">`, `date` → `<input type="date">`, `select` → `<select className="input">` из `def.options`, `multiselect` → checkboxes, `url` → `<input type="url">`, `checkbox` → `<input type="checkbox">`. Для `url` — показывать ссылку в view-режиме.

**Страница `/admin/custom-fields`:**
- `PageHeader` с кнопкой «Добавить поле»
- Tabs по `entity_scope` (7 вкладок), активная вкладка — state
- Таблица в `card` с колонками: drag-handle (будущий реордер — пока disabled), Название, Код, Тип, Обязательное, Активно, Действия
- Строка «Активно» — toggle (PATCH `/custom-field-defs/:id` с `{is_active: bool}`)
- Drag-handle иконка `bi-grip-vertical text-gray-400` — не интерактивна в этой версии (заготовка)
- «Тип» — badge: `text-xs font-mono bg-gray-100 text-gray-700 px-1.5 rounded`

### States — /admin/custom-fields

**Loading:** skeleton-строки таблицы (3 строки с `animate-pulse`, высота строки 40px).

**Empty (нет полей для скоупа):**
- Иконка `bi-layout-text-window-reverse` size xl (3rem), `text-gray-300`
- Заголовок: `Нет полей для «${activeTabLabel}»`
- Описание: `Добавь первое кастомное поле — оно появится во всех карточках этого типа`
- CTA: `btn-primary` «Добавить поле»

**Error:**
- Inline `text-danger bg-danger/10 px-3 py-2 rounded text-sm` над таблицей

**Save success в Modal:**
- Modal закрывается, таблица ревалидируется (mutate), inline-подтверждения нет (нет toast)

**Ошибка кода (дубль code+scope):**
- Inline под полем «Код»: `Поле с таким кодом уже существует для этой сущности`

### States — CustomFieldsBlock в карточках

**Loading:** skeleton одной card с 3 строками `animate-pulse`

**Empty (нет активных полей):** блок не рендерится вообще (return null)

**Edit + saving:** кнопка «Сохранить поля» disabled + текст «Сохраняем…»

**Error при save:** inline под блоком кнопок `text-danger`

### Interactions — /admin/custom-fields

| Элемент | Действие | Результат |
|---|---|---|
| Tab «Контакт» | click | фильтрует таблицу по scope=contact, SWR-ключ меняется |
| Кнопка «+ Добавить поле» | click | открывает `CustomFieldDefModal` с defaultScope=activeTab |
| Иконка `bi-pencil` в строке | click | открывает `CustomFieldDefModal` с заполненным `def` |
| Иконка `bi-trash` в строке | click | confirm нативный `window.confirm` → DELETE `/custom-field-defs/:id` |
| Toggle «Активно» | click | PATCH `/custom-field-defs/:id` `{is_active: !current}`, optimistic update |
| Modal: кнопка «Сохранить» | click | POST (создание) или PATCH (редактирование) → mutate → закрыть Modal |
| Modal: поле «Тип» = select/multiselect | change | показать textarea «Варианты ответа» |

### Interactions — CustomFieldsBlock в карточке

| Элемент | Действие | Результат |
|---|---|---|
| Кнопка «Редактировать поля» (иконка `bi-pencil`) | click | переключает блок в режим edit (inline inputs) |
| Кнопка «Сохранить поля» | click | PATCH `/api/${entityScope}s/${entityId}/extra-fields` body: `{extra_fields: {…}}` → onSaved() → режим view |
| Кнопка «Отмена» | click | сброс к исходным значениям, режим view |

### API контракт — Custom Fields

**Дефиниции (CRUD):**

```
GET    /api/custom-field-defs?scope=lead&is_active=true
POST   /api/custom-field-defs
PATCH  /api/custom-field-defs/:id
DELETE /api/custom-field-defs/:id
```

**DTO `CustomFieldDef`:**
```typescript
interface CustomFieldDef {
  id: number;
  entity_scope: EntityScope;
  code: string;           // snake_case, уникален в паре (scope, code)
  label_ru: string;
  kind: "text" | "textarea" | "number" | "date" | "select" | "multiselect" | "url" | "checkbox";
  is_required: boolean;
  default_value: string | null;
  options: string[];      // пустой массив если not select/multiselect
  sort_order: number;
  is_active: boolean;
  created_at: string;
}
```

**PATCH extra-fields на сущностях:**
```
PATCH /api/leads/:id/extra-fields
PATCH /api/contacts/:id/extra-fields
PATCH /api/companies/:id/extra-fields
PATCH /api/counterparties/:id/extra-fields
PATCH /api/deals/:id/extra-fields
PATCH /api/contracts/:id/extra-fields
PATCH /api/subscriptions/:id/extra-fields

body: { extra_fields: Record<string, unknown> }
response 200: { extra_fields: Record<string, unknown> }
```
Требуется, чтобы основной GET сущности (напр. `GET /api/leads/:id`) возвращал поле `extra_fields: Record<string, unknown>`.

**Открытые вопросы — Custom Fields:**

1. Требуется правка backend: создать таблицу `custom_field_defs`, добавить поле `extra_fields jsonb default '{}'` во все 7 сущностей, 8 новых эндпоинтов.
2. Валидация на backend: при PATCH extra-fields проверять обязательные поля? Или клиентская только? Рекомендуем: backend проверяет `is_required=true` + возвращает 422 с perField errors.
3. Удаление дефиниции: что делать с существующими данными в extra_fields? Рекомендуем: soft-delete (is_active=false), данные не трогать — пользователь сам решит.
4. Права доступа: только admin создаёт/редактирует дефиниции. Все роли видят и заполняют поля в карточках.

---

## Фича 2: Auto-обнаружение дублей + Merge UI

### Маршруты

- `/admin/duplicates` — страница дублей (только admin/director)

### Wireframe — /admin/duplicates

```
┌────────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ [PageHeader: Дубликаты]    [bi-arrow-repeat Сканировать]│
│            ├────────────────────────────────────────────────────────┤
│ - Дублик.←│                                                        │
│            │  Tabs: Контрагенты (12) | Контакты (3) | Компании (0) │
│            │        | Лиды (5)                                      │
│            │                                                        │
│            │  [Последнее сканирование: 31.05.2026 в 14:22]         │
│            │  ┌────────────────────────────────────────────────────┐│
│            │  │ Группа 1 из 12               Схожесть: 94%         ││
│            │  │ ┌────────────────┐  ┌────────────────┐            ││
│            │  │ │ ООО Ромашка    │  │ ООО "Ромашка"  │            ││
│            │  │ │ ИНН: 771234567 │  │ ИНН: 771234567 │            ││
│            │  │ │ email: r@m.kz  │  │ email: —       │            ││
│            │  │ │ тел: +7 701... │  │ тел: +7 701... │            ││
│            │  │ └────────────────┘  └────────────────┘            ││
│            │  │ [btn-primary: Объединить]  [btn-ghost: Не дубль]   ││
│            │  └────────────────────────────────────────────────────┘│
│            │  ┌────────────────────────────────────────────────────┐│
│            │  │ Группа 2 из 12 ...                                 ││
│            │  └────────────────────────────────────────────────────┘│
│            │                                                        │
│            │  Empty (нет дублей):                                   │
│            │  ┌────────────────────────────────┐                   │
│            │  │  [bi-check-circle]              │                   │
│            │  │  Дублей не обнаружено           │                   │
│            │  │  Данные в порядке               │                   │
│            │  └────────────────────────────────┘                   │
└────────────────────────────────────────────────────────────────────┘
```

### Wireframe — MergeModal

```
┌──────────────────────────────────────────────────────────────────────┐
│ Объединить записи                                            [×]      │
│ Выбери, какие данные сохранить в итоговой записи                      │
├──────────────────────────────────────────────────────────────────────┤
│                                                                       │
│ Поле               Запись A (оригинал)      Запись B (дубль)          │
│ ─────────────────────────────────────────────────────────────────    │
│ Название      [●] ООО Ромашка          [ ] ООО "Ромашка"             │
│ ИНН           [●] 771234567            [●] 771234567  (совпадают)     │
│ Email         [●] r@ромашка.kz         [ ] —                          │
│ Телефон       [ ] —                    [●] +7 701 234 5678            │
│ Адрес         [●] ул. Ленина, 1        [ ] —                          │
│ Примечания    [●] Клиент с 2023...     [ ] —                          │
│                                                                       │
│ ── Итоговая запись ──────────────────────────────────────────────── │
│ [preview card: показывает результат в real-time по radio-выборам]     │
│                                                                       │
│ Связанные записи переедут с B → A: 2 сделки, 1 договор               │
│                                                                       │
│ [btn-ghost: Отмена]                  [btn-primary: Объединить →]      │
└──────────────────────────────────────────────────────────────────────┘
```

### Композиция и компоненты

**Новые файлы:**
```
apps/web/src/app/(app)/admin/duplicates/page.tsx
apps/web/src/components/Duplicates/DuplicateGroupCard.tsx
apps/web/src/components/Duplicates/MergeModal.tsx
apps/web/src/components/Duplicates/DuplicateSimilarityBadge.tsx
```

**`DuplicateGroupCard`:**
```typescript
interface DuplicateGroup {
  id: string;               // uuid группы
  entity: "counterparty" | "contact" | "company" | "lead";
  records: DuplicateRecord[];
  similarity_score: number; // 0-100
  scanned_at: string;
}

interface DuplicateRecord {
  id: number;
  display_name: string;
  fields: Record<string, string | null>; // email, phone, tax_id, name
}

interface DuplicateGroupCardProps {
  group: DuplicateGroup;
  onMerged: () => void;        // mutate после объединения
  onNotDuplicate: () => void;  // mutate после dismissal
}
```
Рендерит горизонтальный flex с карточками-записями (2+). Кнопки «Объединить» и «Не дубль» внизу группы.

**`MergeModal`:**
```typescript
interface MergeModalProps {
  open: boolean;
  group: DuplicateGroup;
  onClose: () => void;
  onMerged: () => void;
}
```
State: `selections: Record<string, "a" | "b">` — для каждого поля какую запись выбрать. Инициализируется: поле от `a` если `a` не null, иначе `b`. Рендер — таблица с radio. Preview — `card bg-gray-50` показывает итоговые поля в real-time.

**`DuplicateSimilarityBadge`:**
```typescript
interface DuplicateSimilarityBadgeProps {
  score: number; // 0-100
}
```
Цвета: `score >= 90` → `bg-danger/10 text-danger`, `score >= 70` → `bg-warning/10 text-warning`, иначе → `bg-gray-100 text-gray-600`.

### States — /admin/duplicates

**Scanning (кнопка «Сканировать» нажата):**
- В PageHeader кнопка меняется на disabled + текст «Сканируем…» + spinner `bi-arrow-repeat animate-spin`
- После завершения — mutate, кнопка возвращается, показываем дату последнего скана

**Loading групп:** 3 skeleton-card `animate-pulse` высотой 120px

**Empty (нет групп):**
- Иконка `bi-check-circle` 3rem `text-success`
- Заголовок: `Дублей не обнаружено`
- Описание: `Все записи уникальны. Запусти сканирование ещё раз, если добавлял новые данные`
- Кнопка: `btn-secondary` «Сканировать повторно»

**Tabs с count=0:** tab активна но показывает Empty

**Error при merge:** inline `text-danger` под preview в MergeModal

**Merge success:** Modal закрывается, группа исчезает из списка (mutate)

### Interactions — /admin/duplicates

| Элемент | Действие | Результат |
|---|---|---|
| Кнопка «Сканировать» в PageHeader | click | POST `/api/duplicates/scan` → показать scanning state → mutate данных |
| Tab «Контакты» | click | фильтрует список по entity=contact |
| Кнопка «Объединить» на DuplicateGroupCard | click | открывает MergeModal |
| Кнопка «Не дубль» на DuplicateGroupCard | click | POST `/api/duplicates/groups/:id/dismiss` → mutate → группа исчезает |
| Radio в MergeModal | change | real-time preview обновляется |
| Кнопка «Объединить →» в MergeModal | click | POST `/api/duplicates/merge` с payload → success → onMerged() |

### API контракт — Duplicates

```
POST   /api/duplicates/scan?entity=counterparty
       body: {} (запускает async-сканирование или возвращает cached результат)
       response: { groups: DuplicateGroup[]; scanned_at: string }

GET    /api/duplicates/groups?entity=counterparty
       response: { groups: DuplicateGroup[]; scanned_at: string | null }

POST   /api/duplicates/groups/:id/dismiss
       response: 204

POST   /api/duplicates/merge
       body: {
         group_id: string;
         entity: string;
         primary_id: number;           // "победитель" — запись, которая остаётся
         duplicate_id: number;         // поглощается
         field_overrides: Record<string, unknown>;  // финальные значения полей
       }
       response: { merged_id: number }
```

**Открытые вопросы — Duplicates:**

1. Требуется правка backend: сканирование может быть тяжёлым (fuzzy по 120+ записям). Рекомендуем: sync, с лимитом 5 сек, cached в Redis/DB. Если async — нужен polling endpoint `GET /api/duplicates/scan/status`.
2. Merge: что происходит с FK (deals, contracts, subscriptions, activities) — они должны перепривязаться к `primary_id`. Это критичная логика — уточнить у backend.
3. Для 3+ записей в группе: MergeModal сейчас проектируется для 2-х. При N>2 нужно сначала выбрать «первичную» запись, потом мерджить остальные по очереди или за раз. Предлагаем: кнопка «Выбрать основную» + мерджить остальных в неё без поля-за-полем.
4. Права: только admin/director делает merge.

---

## Фича 3: Audit Log

### Wireframe — таб «История изменений» в карточке

```
┌────────────────────────────────────────────────────────────────────┐
│ Tabs: Обзор | ... | История  | История изменений  | ...            │
│              (Timeline)    (Audit Log — НОВЫЙ)                     │
├────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌────────────────────────────────────────────────────────────┐    │
│  │ Фильтр: [select: Все действия ▼]   [btn-ghost: Экспорт]   │    │
│  └────────────────────────────────────────────────────────────┘    │
│                                                                     │
│  ── 31.05.2026 ────────────────────────────────────────────────    │
│  ● 15:42  Иванов И.  обновил поле «Этап»:                          │
│            «Qualification» → «HOT deals»                           │
│  ● 14:10  Иванов И.  обновил поле «Владелец»:                      │
│            «Петров П.» → «Сидоров В.»                              │
│  ● 12:01  Иванов И.  обновил поле «Примечания»:                    │
│            (старое значение) → «Связались по телефону, ...»        │
│                                                                     │
│  ── 30.05.2026 ────────────────────────────────────────────────    │
│  ● 18:30  Система  автоматизация изменила поле «Этап»:             │
│            «Warm deals» → «HOT deals»                              │
│  ● 09:15  Петров П.  создал запись                                 │
│                                                                     │
│  [btn-ghost: Показать ещё (ещё 14 изменений)]                      │
└────────────────────────────────────────────────────────────────────┘
```

### Маппинг — в каких карточках добавляем таб

| Сущность | Файл карточки | Новый таб |
|---|---|---|
| Lead | `leads/[id]/page.tsx` | «История изменений» рядом с разделом «История» |
| Contact | `contacts/[id]/page.tsx` | новый таб «История изм.» |
| Company | `companies/[id]/page.tsx` | новый таб «История изм.» |
| Counterparty | `counterparties/[id]/page.tsx` | рядом с табом «timeline» |
| Deal | `deals/[id]/page.tsx` (если есть) | новый таб |
| Contract | `contracts/[id]/page.tsx` | новый таб |
| Subscription | в реестре, карточка подписки | новый таб |

Для Lead, Contact, Company — они сейчас inline (не табы). Нужно принять решение: оборачивать в таб-навигацию или добавлять блок снизу. Рекомендую: **добавить блок снизу после Timeline** с заголовком «История изменений» (чтобы не ломать текущий layout без таб-навигации).

Для Counterparty — уже есть табы, добавить новый таб «История изм.» с иконкой `bi-journal-text`.

Для Deal / Contract — уточнить у backend/product: есть ли карточка `/deals/[id]`? Если нет — создавать не в скоупе этого эпика.

### Композиция и компоненты

**Новые файлы:**
```
apps/web/src/components/AuditLog/AuditLogTimeline.tsx
apps/web/src/components/AuditLog/AuditLogEntry.tsx
apps/web/src/components/AuditLog/AuditActionBadge.tsx
```

**`AuditLogTimeline`:**
```typescript
type AuditEntityType =
  | "lead" | "contact" | "company" | "counterparty"
  | "deal" | "contract" | "subscription";

interface AuditLogTimelineProps {
  entityType: AuditEntityType;
  entityId: number;
  initialLimit?: number;   // default 20
}
```
Делает `useSWR` на `/audit-logs?entity_type=${entityType}&entity_id=${entityId}&limit=${limit}`. Группирует по дате (аналогично `Timeline`). Кнопка «Показать ещё» как в `Timeline`.

**`AuditLogEntry`:**
```typescript
interface AuditLogEntry {
  id: number;
  entity_type: AuditEntityType;
  entity_id: number;
  user_id: number | null;
  user_name: string | null;      // джойн на backend
  action: "create" | "update" | "delete" | "bulk_action";
  diff_json: AuditDiff | null;
  occurred_at: string;
}

interface AuditDiff {
  fields?: Record<string, { old: unknown; new: unknown }>;
  bulk_action?: string;
}

interface AuditLogEntryProps {
  entry: AuditLogEntry;
}
```
Рендерит одну строку аудита. Для `action=update` — разворачивает `diff_json.fields` в список изменений. Для `create` — «создал запись». Для `delete` — «удалил запись» (текст красным).

**`AuditActionBadge`:**
```typescript
interface AuditActionBadgeProps {
  action: "create" | "update" | "delete" | "bulk_action";
}
```
Mapping: `create` → `bg-success/10 text-success`, `update` → `bg-info/10 text-info`, `delete` → `bg-danger/10 text-danger`, `bulk_action` → `bg-warning/10 text-warning`.

**Фильтр действий в `AuditLogTimeline`:**
- select с вариантами: «Все действия», «Только создание», «Только изменения», «Только удаление»
- Передаётся как `action` query param

### States — AuditLogTimeline

**Loading:** 5 строк skeleton `animate-pulse`, высота 24px каждая, разные ширины (`w-3/4`, `w-2/3`, `w-4/5`, `w-1/2`, `w-2/3`).

**Empty:**
- `text-sm text-gray-500 text-center py-8`
- Текст: `Изменений пока не зафиксировано`

**Error:** `text-sm text-danger bg-danger/10 px-3 py-2 rounded`

**Показать ещё:** `btn-ghost` — «Показать ещё (ещё ${remaining} изменений)». Если `remaining` не известен — просто «Показать ещё».

### Interactions — AuditLogTimeline

| Элемент | Действие | Результат |
|---|---|---|
| Select фильтра | change | обновляет SWR-ключ, перезагружает список |
| «Показать ещё» | click | `limit += initialLimit`, новый SWR-запрос |
| Строка с длинным diff | — | при >3 изменённых полях показывать первые 3, ссылка «+ ещё N полей» разворачивает всё |

### API контракт — Audit Log

```
GET /api/audit-logs
    ?entity_type=lead
    &entity_id=42
    &action=update          // optional
    &limit=20               // default 20
    &offset=0
    response: {
      items: AuditLogEntry[];
      total: number;
    }
```

Требуется правка backend:
1. Таблица `audit_logs(id serial, entity_type varchar, entity_id int, user_id int FK nullable, action varchar, diff_json jsonb, occurred_at timestamp)`.
2. Middleware или service-слой: перехватывать create/update/delete на ключевых роутерах и писать лог. Список роутеров для покрытия: leads, contacts, companies, counterparties, deals, contracts, subscriptions.
3. diff_json для `update`: сравнивать `old` (данные до PATCH) и `new` (после). Не логировать поля `updated_at`, `extra_fields` (или логировать отдельно, по желанию).
4. `user_name` — джойн с `users.full_name` на backend (или в DTO), чтобы фронтенд не делал N+1.
5. Для автоматизаций: `user_id = null`, `user_name = "Автоматизация"`.

**Открытые вопросы — Audit Log:**

1. Подписки: есть ли endpoint `GET /subscriptions/:id`? Нужно уточнить где живёт карточка подписки.
2. «Экспорт» кнопка — в скоупе эпика? Рекомендуем: оставить заглушкой `btn-ghost` disabled с tooltip «Скоро» — не реализовывать в Эпике 8.
3. Объём логов: индексировать по `(entity_type, entity_id)` на backend — критично для производительности.

---

## Фича 4: Сохранённые сегменты (SavedFilter)

### Маршруты

- Без отдельной страницы. Всё через Sidebar + модалки в фильтр-барах.
- Опционально: `/admin/segments` для глобальных сегментов (только admin). Это «открытый вопрос» — реши с продуктом.

### Wireframe — Sidebar (новая секция)

```
┌──────────────────────┐
│ [Logo]               │
├──────────────────────┤
│ - Дашборд            │
│ - Договоры           │
│ - Контрагенты        │
│ - Контакты           │
│ - Компании           │
│ - Сделки             │
│ - Лиды               │
│ - Входящие           │
│ - Реестр клиентов    │
├──────────────────────┤
│ ▼ Мои сегменты       │  ← новая секция (раскрывающаяся)
│   📌 Горячие лиды KZ │
│   📌 Истекают в июне  │
│   📌 Без активности  │
│   + Все сегменты…   │  ← переход на страницу управления
├──────────────────────┤
│ [Admin items...]     │
│ ...                  │
├──────────────────────┤
│ [Профиль / Выход]    │
└──────────────────────┘
```

Секция «Мои сегменты» показывает только `is_pinned=true` для текущего юзера. Максимум 8 пинов в сайдбаре (остальные через «Все сегменты…»). Раскрывается/сворачивается по клику на заголовок (state в localStorage `segments_expanded`).

### Wireframe — фильтр-бар с сегментами (напр. /leads)

```
┌────────────────────────────────────────────────────────────────────┐
│ [🔍 Поиск...]  [Воронка ▼]  [Статус ▼]  [Владелец ▼]             │
│ [bi-bookmark Применить сегмент ▼]  [bi-bookmark-plus Сохранить]   │
└────────────────────────────────────────────────────────────────────┘
```

Кнопка «Применить сегмент» — dropdown со списком сохранённых сегментов для текущей `page_key`. По клику — применяет фильтры из `filter_json`. Кнопка «Сохранить» — Modal создания нового сегмента.

### Wireframe — Modal сохранения сегмента

```
┌─────────────────────────────────────────────────────┐
│ Сохранить сегмент                          [×]       │
├─────────────────────────────────────────────────────┤
│ Название *                                           │
│ [input: Горячие лиды KZ]                             │
│                                                      │
│ [x] Закрепить в боковой панели (быстрый доступ)     │
│                                                      │
│ Текущие фильтры:                                     │
│   Воронка: Sales pipeline                            │
│   Статус: active                                     │
│   Владелец: Иванов И.                                │
│                                                      │
│ (показывает читаемое резюме применённых фильтров)    │
│                                                      │
│ [btn-ghost: Отмена]           [btn-primary: Сохранить]│
└─────────────────────────────────────────────────────┘
```

### Wireframe — страница «Все сегменты» (если нужна)

```
┌────────────────────────────────────────────────────────────────────┐
│ [PageHeader: Мои сегменты]               [+ Нет прямой кнопки создания]│
│                                                                    │
│  Tabs: Мои | Общие (admin-only)                                    │
│                                                                    │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ Название         │ Страница   │ Закреплён │ Создан │ Действия │  │
│  ├──────────────────────────────────────────────────────────────┤  │
│  │ Горячие лиды KZ  │ Лиды       │ 📌 да     │ 01.06  │ ✎ ✕     │  │
│  │ Истекают в июне  │ Реестр     │ ○ нет     │ 28.05  │ ✎ ✕     │  │
│  └──────────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────┘
```

Маршрут: `/profile/segments` (под профилем) или `/admin/segments` (под админкой для глобальных). **Решение за продуктом** — вынесено в открытые вопросы.

### Композиция и компоненты

**Новые файлы:**
```
apps/web/src/components/Sidebar.tsx              (ПРАВКА существующего)
apps/web/src/components/Segments/SavedFilterPin.tsx
apps/web/src/components/Segments/SegmentSelector.tsx
apps/web/src/components/Segments/SaveSegmentModal.tsx
apps/web/src/hooks/useSavedFilters.ts
```

**`SavedFilterPin`** — одна строка в Sidebar:
```typescript
interface SavedFilterPinProps {
  filter: SavedFilter;
  isActive: boolean;
  onRemovePin: (id: number) => void;
}
```
Рендер: `<Link href={resolveUrl(filter.page_key, filter.id)}>`. `resolveUrl` — маппинг page_key → путь:
```
leads      → /leads?segment=:id
contacts   → /contacts?segment=:id
companies  → /companies?segment=:id
counterparties → /counterparties?segment=:id
deals      → /deals?segment=:id
registry   → /registry?segment=:id
```
При hover — кнопка открепить `bi-x` справа (inline, `opacity-0 group-hover:opacity-100`).

**`SegmentSelector`** — dropdown над фильтрами:
```typescript
interface SegmentSelectorProps {
  pageKey: PageKey;
  onApply: (filter_json: FilterJson) => void;
}
```
`PageKey = "leads" | "contacts" | "companies" | "counterparties" | "deals" | "registry"`.
SWR: `useSWR(\`/saved-filters?page_key=${pageKey}\`, fetcher)`. Рендерит dropdown список, по клику — `onApply(filter.filter_json)`. Иконка `bi-bookmark`.

**`SaveSegmentModal`** — Modal сохранения:
```typescript
interface SaveSegmentModalProps {
  open: boolean;
  pageKey: PageKey;
  currentFilterJson: FilterJson;           // текущий набор фильтров
  filterSummary: string[];                 // человекочитаемое резюме, строк
  onClose: () => void;
  onSaved: () => void;
}
```
FilterJson — `Record<string, unknown>` (аналогично extra_fields — backend хранит as-is).

**`useSavedFilters` hook:**
```typescript
function useSavedFilters(pageKey: PageKey): {
  filters: SavedFilter[];
  isLoading: boolean;
  mutate: () => void;
}
```
SWR + helper.

**Правка `Sidebar.tsx`:**
- Добавить секцию «Мои сегменты» между навигацией и admin-секцией
- Загружает `useSavedFilters` с `is_pinned=true` (все page_keys)
- `isExpanded` state в `localStorage.getItem('segments_sidebar_expanded')` (строка `"true"/"false"`)
- Заголовок секции: `<button>` с `bi-bookmark-star-fill` + «Мои сегменты» + `bi-chevron-down/up`
- Если 0 пинов и collapsed — секция всё равно показывается заголовком (не скрывается)

**Интеграция в фильтр-бары:**

В каждой из 6 страниц (leads, contacts, companies, counterparties, deals, registry) добавить:
1. `<SegmentSelector pageKey="leads" onApply={applySegmentFilters} />` в панель фильтров
2. `<button className="btn-ghost text-sm" onClick={() => setSaveOpen(true)}>`
   `<i className="bi bi-bookmark-plus mr-1" /> Сохранить`
   `</button>`
3. При загрузке страницы с query `?segment=:id` — автоматически подгружать filter_json и применять

**Чтение сегмента при входе на страницу:**
```typescript
// В каждой странице со списком (например leads/page.tsx)
const searchParams = useSearchParams();
const segmentId = searchParams.get("segment");
// useSWR(`/saved-filters/${segmentId}`, fetcher) если segmentId != null
// → применить filter_json к фильтрам страницы
```

### States — SavedFilters

**Loading пинов в Sidebar:** skeleton 3 строки `animate-pulse` высотой 28px (только когда секция раскрыта).

**Empty пинов:** текст `text-xs text-gray-400 px-3 py-2` — «Нет закреплённых сегментов»

**SegmentSelector — empty:** dropdown с одной строкой «Сохранённых сегментов нет»

**SegmentSelector — loading:** «Загружаем…» внутри dropdown

**Error save:** inline `text-danger` в SaveSegmentModal под кнопками

**Применён сегмент (визуальный feedback):** кнопка «Применить сегмент» получает состояние «активен» — `bg-primary/10 text-primary border-primary` + рядом `bi-x` «Сбросить сегмент».

### Interactions

| Элемент | Действие | Результат |
|---|---|---|
| «Мои сегменты» в Sidebar | click на заголовок | toggle expanded/collapsed, сохранить в localStorage |
| Пин в Sidebar | click | redirect на страницу с `?segment=:id`, фильтры применяются |
| Пин в Sidebar | hover + click `bi-x` | PATCH `/saved-filters/:id` `{is_pinned: false}` → mutate sidebar |
| «Применить сегмент ▼» | click | dropdown со списком |
| Сегмент в dropdown | click | `onApply(filter_json)` → URL не меняется (state только) |
| «Сохранить» | click | открывает SaveSegmentModal с текущими фильтрами |
| Modal «Сохранить»: submit | click | POST `/saved-filters` → mutate Sidebar → close |
| «Сбросить сегмент» | click | сброс фильтров к дефолтам, убрать active-state |
| «Все сегменты…» в Sidebar | click | redirect `/profile/segments` |

### API контракт — SavedFilters

```
GET    /api/saved-filters?page_key=leads&is_pinned=true
       response: SavedFilter[]

GET    /api/saved-filters/:id
       response: SavedFilter

POST   /api/saved-filters
       body: { name: string; page_key: PageKey; filter_json: object; is_pinned: boolean }
       response: SavedFilter

PATCH  /api/saved-filters/:id
       body: Partial<{ name, is_pinned, filter_json }>
       response: SavedFilter

DELETE /api/saved-filters/:id
       response: 204
```

**DTO `SavedFilter`:**
```typescript
interface SavedFilter {
  id: number;
  user_id: number | null;    // null = глобальный (admin-only)
  page_key: PageKey;
  name: string;
  filter_json: Record<string, unknown>;
  is_pinned: boolean;
  created_at: string;
}
```

**Открытые вопросы — SavedFilters:**

1. Глобальные сегменты (`user_id=null`): нужны ли в этом эпике? Если да — нужна страница управления для admin. Рекомендуем: в Эпике 8 только пользовательские (`user_id = current_user`). Глобальные — отдельный тикет.
2. Если `filter_json` ссылается на `owner_id` удалённого юзера — применяем as-is, backend вернёт пустой список. Никакой специальной обработки.
3. Страница «Все сегменты»: включить в скоуп или отложить? Предлагаем: сделать `/profile/segments` в скоупе Эпика 8 (простая таблица с rename/delete/pin).
4. Требуется правка backend: таблица `saved_filters(id, user_id FK nullable, page_key, name, filter_json jsonb, is_pinned bool, created_at)`.

---

## Фича 5: Расширенный Full-Text Поиск

### Архитектурное решение: Cmd+K Modal

**Обоснование.** Наш layout — slim sidebar + main content. Встраивать search bar в header (`(app)/layout.tsx`) технически возможно, но он займёт ценное место PageHeader и будет конфликтовать с внутристраничными поисками (напр. поиском по списку лидов). Cmd+K Modal — более правильный паттерн для cross-entity поиска, знакомый команде по другим инструментам (Notion, Linear, GitHub). Клавиша `Cmd+K` (Mac) / `Ctrl+K` (Windows).

Дополнительно: иконка `bi-search` в Sidebar внизу (над профилем) — визуальный хинт + кнопка открытия модала.

### Wireframe — кнопка поиска в Sidebar

```
┌──────────────────────┐
│ ...navigation items  │
├──────────────────────┤
│  🔍 Поиск... Cmd+K   │  ← новый элемент перед профилем
├──────────────────────┤
│ [Профиль / Выход]    │
└──────────────────────┘
```

Кнопка: `bg-gray-100 rounded-md px-3 py-2 text-sm text-gray-500 flex items-center gap-2 w-full hover:bg-gray-200 cursor-text`

### Wireframe — SearchModal (Cmd+K)

```
┌──────────────────────────────────────────────────────────────────┐
│ (overlay backdrop, клик снаружи = закрыть)                        │
│                                                                    │
│  ┌────────────────────────────────────────────────────────────┐   │
│  │ [bi-search]  [Поиск по всей CRM...              ] [Esc]    │   │
│  ├────────────────────────────────────────────────────────────┤   │
│  │                                                            │   │
│  │  (пока поле пустое — подсказки)                            │   │
│  │  Недавние                                                  │   │
│  │  • ООО Ромашка   [Контрагент]                              │   │
│  │  • Иван Петров   [Контакт]                                 │   │
│  │  • Сделка #1032  [Сделка]                                  │   │
│  │                                                            │   │
│  │  (когда query >= 2 символа — результаты)                   │   │
│  ├────────────────────────────────────────────────────────────┤   │
│  │ Контрагенты (3)                                            │   │
│  │  ┌────────────────────────────────────────────────────┐   │   │
│  │  │ bi-people  ООО Ромашка            ИНН: 771234567   │   │   │
│  │  │            r@ромашка.kz · +7 701 234 5678          │   │   │
│  │  └────────────────────────────────────────────────────┘   │   │
│  │  ┌────────────────────────────────────────────────────┐   │   │
│  │  │ bi-people  ООО Ромашка-KZ         ИНН: ...         │   │   │
│  │  └────────────────────────────────────────────────────┘   │   │
│  │                                                            │   │
│  │ Контакты (1)                                               │   │
│  │  ┌────────────────────────────────────────────────────┐   │   │
│  │  │ bi-person  Ромашкин Иван А.        r@romashka.kz   │   │   │
│  │  └────────────────────────────────────────────────────┘   │   │
│  │                                                            │   │
│  │ Лиды (0)  ── скрыть секцию если count=0 ──                │   │
│  │                                                            │   │
│  │ [Показать все результаты по «ромашка»]                    │   │
│  ├────────────────────────────────────────────────────────────┤   │
│  │ ↑↓ навигация    Enter открыть    Esc закрыть               │   │
│  └────────────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────────┘
```

Размер модала: `max-w-2xl`, появляется в центре экрана (не сверху, как наш стандартный Modal), с небольшим offset от верха (`top-[15vh]`). Это поведение немного отличается от базового `Modal` — использовать кастомный Portal (не реюзать `Modal.tsx` — у него другая top-позиция).

### Композиция и компоненты

**Новые файлы:**
```
apps/web/src/components/Search/SearchModal.tsx
apps/web/src/components/Search/SearchResultGroup.tsx
apps/web/src/components/Search/SearchResultItem.tsx
apps/web/src/components/Search/useSearchHistory.ts
apps/web/src/app/(app)/layout.tsx              (ПРАВКА — добавить SearchModal + Cmd+K listener)
apps/web/src/components/Sidebar.tsx            (ПРАВКА — кнопка поиска)
```

**`SearchModal`:**
```typescript
interface SearchModalProps {
  open: boolean;
  onClose: () => void;
}
```
State: `query: string`, `results: SearchResult[] | null`, `isLoading: boolean`, `selectedIndex: number`.
Логика:
- `useEffect` на `query` с `debounce 250ms` → SWR или прямой `api()` call к `/search?q=${query}&limit=20`
- Keyboard navigation: `ArrowUp/ArrowDown` меняют `selectedIndex`, `Enter` → navigate to selected item
- `Escape` → `onClose()`
- При открытии: `input.focus()`
- Недавние: `useSearchHistory` hook (localStorage `search_history` последние 5 записей с entity_type и id)

**`SearchResultGroup`:**
```typescript
interface SearchResultGroupProps {
  entityType: SearchEntityType;
  items: SearchResultItem[];
  selectedIndex: number;
  globalOffset: number;         // offset для правильного selectedIndex
  onSelect: (item: SearchResultItem) => void;
}
```
Рендерит заголовок группы `text-xs uppercase tracking-wide text-gray-500` + список `SearchResultItem`.

**`SearchResultItem`:**
```typescript
type SearchEntityType = "lead" | "contact" | "company" | "counterparty" | "deal" | "contract";

interface SearchResultItem {
  entity_type: SearchEntityType;
  id: number;
  display_name: string;
  secondary: string | null;    // email / phone / tax_id / короткое описание
  score?: number;              // relevance (опционально, не показывать)
}

interface SearchResultItemProps {
  item: SearchResultItem;
  isSelected: boolean;
  onClick: () => void;
}
```
Маппинг entity_type → иконка и путь:
```
lead         → bi-funnel-fill     /leads/:id
contact      → bi-person          /contacts/:id
company      → bi-buildings       /companies/:id
counterparty → bi-people          /counterparties/:id
deal         → bi-kanban          /deals (пока нет [id])
contract     → bi-file-earmark-text /contracts/:id
```

**`useSearchHistory` hook:**
```typescript
function useSearchHistory(): {
  history: SearchHistoryItem[];
  push: (item: SearchHistoryItem) => void;
  clear: () => void;
}
// хранит в localStorage 'crm_search_history' последние 5 записей
```

**Правка `(app)/layout.tsx`:**
- Добавить state `searchOpen: boolean`
- `useEffect` для `keydown`: `(e.metaKey || e.ctrlKey) && e.key === 'k'` → `e.preventDefault(); setSearchOpen(true)`
- Рендерить `<SearchModal open={searchOpen} onClose={() => setSearchOpen(false)} />`

**Правка `Sidebar.tsx`:**
- Добавить кнопку «Поиск... Cmd+K» как `<button>` перед секцией профиля
- По клику — нужен способ поднять событие к layout.tsx. Рекомендуем: использовать `CustomEvent` или context. Проще — Context Provider `SearchContext` в `(app)/layout.tsx` с `openSearch: () => void`. Sidebar потребляет его.

Альтернатива проще: вынести SearchModal на уровень layout и передавать `openSearch` через `SearchContext`:
```typescript
// app/(app)/layout.tsx
const SearchContext = createContext<{ openSearch: () => void }>({ openSearch: () => {} });
export function useSearch() { return useContext(SearchContext); }
```

### States — SearchModal

**Пустой query (начальное состояние):**
- Показывать «Недавние» (из `useSearchHistory`). Если history пустая — placeholder-текст `text-sm text-gray-400 text-center py-8`: «Начни вводить для поиска»

**Loading (query >= 2 символа, запрос в процессе):**
- Skeleton: 2 группы по 2 строки `animate-pulse`
- НЕ убирать поле ввода и уже полученные результаты (если предыдущий запрос был)

**Empty (запрос пришёл, результатов 0):**
- Иконка `bi-search` 2rem `text-gray-300`
- Текст: `Ничего не найдено по запросу «${query}»`
- Подсказка мелким: `Попробуй имя, email, телефон или ИНН`

**Error:**
- Inline `text-danger text-sm`: `Не удалось выполнить поиск. Попробуй снова`
- НЕ закрывать модал

**Selected item (keyboard nav):**
- Строка с `bg-primary/5` и левой полоской `border-l-2 border-primary`

### Interactions — SearchModal

| Элемент | Действие | Результат |
|---|---|---|
| `Cmd+K` / `Ctrl+K` в любом месте | keydown | открывает SearchModal, фокус на input |
| Кнопка «🔍 Поиск...» в Sidebar | click | открывает SearchModal |
| Ввод >= 2 символов | input | debounce 250ms → запрос `/search?q=...` |
| `ArrowDown` | keydown | selectedIndex++ (циклично) |
| `ArrowUp` | keydown | selectedIndex-- (циклично) |
| `Enter` | keydown | navigate на выбранный item, закрыть Modal, push в history |
| Click на результат | click | navigate на item, закрыть Modal, push в history |
| `Esc` | keydown | закрыть Modal |
| Клик вне Modal | click | закрыть Modal |
| Недавний результат | click | navigate, как обычный результат |
| «Показать все результаты по X» | click | redirect на `/search?q=X` (опционально, если есть отдельная страница) |

### API контракт — Full-Text Search

```
GET /api/search
    ?q=ромашка                          // min 2 символа
    &entity_types=counterparty,contact  // optional, дефолт — все
    &limit=20                           // default 5 на entity_type
    response: {
      query: string;
      items: SearchResultItem[];        // отсортированы по score desc
      groups: {                         // для удобной группировки
        entity_type: SearchEntityType;
        count: number;
        items: SearchResultItem[];
      }[];
    }
```

Требуется правка backend:
1. Новый endpoint `/api/search` с PostgreSQL `to_tsvector + to_tsquery` на полях `name, email, phone, tax_id`.
2. Fallback: если tsvector query невалидна — ILIKE `%q%`.
3. `display_name` — основное имя записи (name для компаний/контрагентов, full_name для контактов, title для лидов).
4. `secondary` — второстепенное: email или phone или tax_id (первое non-null).
5. Лимит: 5 записей на entity_type по умолчанию, `limit` параметр — override.
6. Минимальная длина query: 2 символа (backend возвращает 400 если < 2).
7. Таблицы для поиска: `leads (name, contact_email, contact_phone)`, `contacts (full_name, email, phone)`, `companies (legal_name, tax_id, email)`, `counterparties (name, email, phone, tax_id)`, `deals (title)`, `contracts (contract_number)`.

**Открытые вопросы — Search:**

1. Поиск по `extra_fields` (кастомным полям) — включить в скоуп? Рекомендуем: нет, оставить только системные поля. Кастомные поля в поиск — отдельный тикет.
2. «Показать все результаты» — нужна ли отдельная страница `/search`? В рамках Эпика 8 достаточно модала с до 5 результатов на entity. Если нужна full-page выдача — отдельный тикет.
3. Поиск по договорам: по `contract_number` только, или ещё по тексту? Рекомендуем: только по `contract_number` и контрагенту в Эпике 8.

---

## Глобальные правки Sidebar / Header / Layout

### Sidebar — итоговый список изменений

1. **Секция «Мои сегменты»** (Фича 4) — между навигацией и admin-секцией
2. **Кнопка поиска** (Фича 5) — над профилем
3. **Новые admin-пункты** — добавить в список `items`:
   - `{ href: "/admin/custom-fields", icon: "bi-layout-text-window-reverse", label: "Кастомные поля", roles: ["admin", "director"] }`
   - `{ href: "/admin/duplicates", icon: "bi-people-fill", label: "Дубликаты", roles: ["admin", "director"] }`

Порядок в `items`: после существующих admin-пунктов (перед «Пользователи»).

### (app)/layout.tsx — итоговые изменения

1. `SearchContext` Provider обёртывает всё
2. `SearchModal` рендерится на уровне layout
3. `useEffect` для `Cmd+K`
4. Все существующие дочерние pages не трогаются

---

## Типы TypeScript (новые, `@/lib/types.ts`)

Добавить в конец файла:

```typescript
// ── Эпик 8: Custom Fields ──────────────────────────────────────────

export type EntityScope =
  | "lead" | "contact" | "company" | "counterparty"
  | "deal" | "contract" | "subscription";

export type CustomFieldKind =
  | "text" | "textarea" | "number" | "date"
  | "select" | "multiselect" | "url" | "checkbox";

export interface CustomFieldDef {
  id: number;
  entity_scope: EntityScope;
  code: string;
  label_ru: string;
  kind: CustomFieldKind;
  is_required: boolean;
  default_value: string | null;
  options: string[];
  sort_order: number;
  is_active: boolean;
  created_at: string;
}

// ── Эпик 8: Duplicates ──────────────────────────────────────────────

export type DuplicateEntityType = "counterparty" | "contact" | "company" | "lead";

export interface DuplicateRecord {
  id: number;
  display_name: string;
  fields: Record<string, string | null>;
}

export interface DuplicateGroup {
  id: string;
  entity: DuplicateEntityType;
  records: DuplicateRecord[];
  similarity_score: number;
  scanned_at: string;
}

// ── Эпик 8: Audit Log ──────────────────────────────────────────────

export type AuditAction = "create" | "update" | "delete" | "bulk_action";

export type AuditEntityType =
  | "lead" | "contact" | "company" | "counterparty"
  | "deal" | "contract" | "subscription";

export interface AuditDiff {
  fields?: Record<string, { old: unknown; new: unknown }>;
  bulk_action?: string;
}

export interface AuditLogEntry {
  id: number;
  entity_type: AuditEntityType;
  entity_id: number;
  user_id: number | null;
  user_name: string | null;
  action: AuditAction;
  diff_json: AuditDiff | null;
  occurred_at: string;
}

// ── Эпик 8: Saved Filters ──────────────────────────────────────────

export type PageKey =
  | "leads" | "contacts" | "companies" | "counterparties"
  | "deals" | "registry";

export interface SavedFilter {
  id: number;
  user_id: number | null;
  page_key: PageKey;
  name: string;
  filter_json: Record<string, unknown>;
  is_pinned: boolean;
  created_at: string;
}

// ── Эпик 8: Search ─────────────────────────────────────────────────

export type SearchEntityType =
  | "lead" | "contact" | "company" | "counterparty" | "deal" | "contract";

export interface SearchResultItem {
  entity_type: SearchEntityType;
  id: number;
  display_name: string;
  secondary: string | null;
  score?: number;
}

export interface SearchResponse {
  query: string;
  items: SearchResultItem[];
  groups: {
    entity_type: SearchEntityType;
    count: number;
    items: SearchResultItem[];
  }[];
}

export interface SearchHistoryItem {
  entity_type: SearchEntityType;
  id: number;
  display_name: string;
  visited_at: string;
}
```

---

## Сводная таблица новых файлов

```
apps/web/src/app/(app)/
  admin/custom-fields/page.tsx        ← новая страница
  admin/duplicates/page.tsx           ← новая страница
  profile/segments/page.tsx           ← новая страница (опционально, см. открытые вопросы)

apps/web/src/components/
  CustomFields/
    CustomFieldsBlock.tsx             ← новый
    CustomFieldDefModal.tsx           ← новый
    CustomFieldInput.tsx              ← новый
  Duplicates/
    DuplicateGroupCard.tsx            ← новый
    MergeModal.tsx                    ← новый
    DuplicateSimilarityBadge.tsx      ← новый
  AuditLog/
    AuditLogTimeline.tsx              ← новый
    AuditLogEntry.tsx                 ← новый
    AuditActionBadge.tsx              ← новый
  Segments/
    SavedFilterPin.tsx                ← новый
    SegmentSelector.tsx               ← новый
    SaveSegmentModal.tsx              ← новый
  Search/
    SearchModal.tsx                   ← новый
    SearchResultGroup.tsx             ← новый
    SearchResultItem.tsx              ← новый
    useSearchHistory.ts               ← новый

  Sidebar.tsx                         ← ПРАВКА (пп. 1, 2, 3 выше)
  apps/web/src/app/(app)/layout.tsx   ← ПРАВКА (SearchContext + Cmd+K)

apps/web/src/hooks/
  useSavedFilters.ts                  ← новый

apps/web/src/lib/
  types.ts                            ← ПРАВКА (добавить типы Эпика 8)
```

---

## Edge Cases сводный

### Custom Fields
- **0 полей для сущности:** `CustomFieldsBlock` рендерит `null` — не занимает место в карточке
- **50+ полей:** `CustomFieldsBlock` рендерит grid `grid-cols-1 md:grid-cols-2` — полоска вертикального скролла внутри card, если карточка фиксированной высоты. Рекомендуем: card без фиксированной высоты, просто длинный список
- **Поле required, не заполнено:** при PATCH extra-fields backend вернёт 422 → inline error под конкретным полем (нужен map ошибок по `code`)
- **Удалена дефиниция:** данные в extra_fields не затираются, поле просто не рендерится в `CustomFieldsBlock` (т.к. `is_active=false` → не попадёт в GET defs)
- **Код дублируется в рамках scope:** ошибка 422 от backend → inline под полем «Код»

### Duplicates
- **Merge сломан (backend error 500):** inline error в MergeModal, Modal не закрывается
- **3+ записи в группе:** MergeModal в текущем дизайне работает только для 2. При N>2 — показать предупреждение «Объединение доступно только для 2 записей. Выбери, какие два объединить» + select двух записей из группы
- **После merge запись удалена:** page refresh покажет 404 на старом ID → redirect на первичную запись. Рекомендуем: backend при merge возвращает `{ merged_id }` — frontend redirect на `/counterparties/${merged_id}`
- **Сканирование не запускалось:** показывать заглушку «Запусти первое сканирование» вместо «Нет дублей»
- **Нет прав:** страница `/admin/duplicates` доступна только admin/director — middleware или redirect в layout

### Audit Log
- **Много изменений за одно событие (bulk):** показывать `AuditActionBadge bulk_action` + `bulk_action: "изменён этап у 12 лидов"` без развёртки по полям
- **user_id = null:** показывать «Система» вместо имени юзера
- **Длинное старое/новое значение (textarea):** обрезать до 80 символов + `…` с tooltip полного значения
- **Нет audit logs для старых записей (данные до введения лога):** показывать empty state без ошибки

### Saved Filters
- **SavedFilter ссылается на удалённого юзера в filter_json:** применяем as-is, пустой список — ок
- **SavedFilter ссылается на удалённый pipeline/stage:** то же — применяем as-is, backend вернёт пустой список
- **> 8 пинов:** в Sidebar показываем 8, остальные в «Все сегменты…». Порядок — по `sort_order` или `created_at desc`
- **Нет сегментов:** SegmentSelector dropdown показывает одну строку «Нет сохранённых сегментов»
- **Переход по ссылке `?segment=99999` (несуществующий):** `GET /saved-filters/99999` вернёт 404 → применить дефолтные фильтры, игнорировать невалидный ID

### Search
- **Query < 2 символов:** не делать запрос, показывать «Недавние» (если есть) или placeholder
- **Typing быстро:** debounce 250ms + отмена предыдущего запроса (AbortController в `api()` или через SWR-ключ)
- **Нет результатов:** empty state «Ничего не найдено по запросу»
- **Очень длинный query (>100 символов):** обрезать до 100 перед отправкой
- **Специальные символы в query:** backend должен экранировать для tsvector. Frontend — отправлять as-is, не экранировать
- **Offline / ошибка сети:** inline error в Modal, не закрываем

---

## Тексты (RU, для копи-паст в JSX)

### Custom Fields — /admin/custom-fields

```
PageHeader title:           «Кастомные поля»
PageHeader description:     «Добавляй поля в карточки сущностей без изменения кода»
Кнопка создания:            «Добавить поле»
Tab labels:                 «Лид», «Контакт», «Компания», «Контрагент», «Сделка», «Договор», «Подписка»
Table col — Название:       «Название»
Table col — Код:            «Код»
Table col — Тип:            «Тип»
Table col — Обязательное:   «Обяз.»
Table col — Активно:        «Активно»
Table col — Действия:       «»  (пустой заголовок)
Empty title:                «Нет полей для «${scopeLabel}»»
Empty description:          «Добавь первое кастомное поле — оно появится во всех карточках этого типа»
Empty CTA:                  «Добавить поле»
Confirm delete:             «Удалить поле «${label}»? Данные в уже заполненных карточках останутся.»
```

### Custom Fields — Modal

```
Modal title (create):       «Новое поле»
Modal title (edit):         «Редактировать поле»
Label: Сущность:            «Сущность *»
Label: Название поля:       «Название поля *»
Label: Код:                 «Код (snake_case) *»
Hint под кодом:             «Только латиница, цифры и _. Изменить после создания нельзя.»
Label: Тип поля:            «Тип поля *»
Label: Варианты ответа:     «Варианты ответа *»
Hint под вариантами:        «По одному варианту на строке»
Label: Значение по умолч.:  «Значение по умолчанию»
Checkbox обязательное:      «Обязательное поле»
Checkbox активное:          «Активное (показывать в карточках)»
Кнопка отмена:              «Отмена»
Кнопка сохранить:           «Сохранить»
Кнопка saving:              «Сохраняем…»
Error дубль кода:           «Поле с таким кодом уже существует для этой сущности»
```

### Custom Fields — CustomFieldsBlock в карточке

```
Card title:                 «Дополнительные поля»
Кнопка редактировать:       «Редактировать поля»   (иконка bi-pencil)
Кнопка сохранить:           «Сохранить поля»
Кнопка saving:              «Сохраняем…»
Кнопка отмена:              «Отмена»
Error save:                 «Не удалось сохранить. Проверь обязательные поля.»
```

### Duplicates — /admin/duplicates

```
PageHeader title:           «Дубликаты»
PageHeader description:     «Найди и объедини повторяющиеся записи»
Кнопка сканировать:         «Сканировать»
Кнопка сканирование идёт:   «Сканируем…»
Метка скана:                «Последнее сканирование: ${date}»
Метка скана (не было):      «Сканирование ещё не запускалось»
Tab labels:                 «Контрагенты», «Контакты», «Компании», «Лиды»
Tab labels с count:         «Контрагенты (12)», «Контакты (3)» и т.д.
GroupCard заголовок:        «Группа ${n} из ${total}»
GroupCard схожесть:         «Схожесть: ${score}%»
Кнопка объединить:          «Объединить»
Кнопка не дубль:            «Не дубль»
Empty title (нет групп):    «Дублей не обнаружено»
Empty description:          «Все записи уникальны. Запусти сканирование ещё раз, если добавлял новые данные»
Empty CTA:                  «Сканировать повторно»
Empty description (не скан):«Запусти сканирование, чтобы найти возможные дубликаты»
```

### Duplicates — MergeModal

```
Modal title:                «Объединить записи»
Modal description:          «Выбери, какие данные сохранить в итоговой записи»
Заголовок таблицы:          «Поле», «Запись A», «Запись B»
Подпись записи A:           «(оригинал)»
Подпись записи B:           «(дубль)»
Preview заголовок:          «Итоговая запись»
Связанные записи:           «Связанные записи переедут с B → A: ${count} ${entityLabel}»
Кнопка отмена:              «Отмена»
Кнопка объединить:          «Объединить →»
Кнопка merging:             «Объединяем…»
Error merge:                «Не удалось объединить. Попробуй снова.»
Совпадают:                  «(совпадают)»
```

### Audit Log

```
Tab label:                  «История изменений»
Tab icon:                   bi-journal-text
Select фильтра options:     «Все действия», «Только создание», «Только изменения», «Только удаление»
Действие create:            «создал запись»
Действие update:            «обновил поле «${fieldLabel}»:»
Действие delete:            «удалил запись»   (текст в text-danger)
Действие bulk_action:       «${bulk_action_text}»
Старое значение:            «${old}»
Новое значение:             «→ ${new}»
Источник система:           «Система»
Развернуть поля:            «+ ещё ${n} ${нужный падеж}»
Кнопка показать ещё:        «Показать ещё»
Empty:                      «Изменений пока не зафиксировано»
Error:                      «Не удалось загрузить историю изменений»
Кнопка экспорт (disabled):  «Экспорт»
Tooltip экспорт:            «Скоро»
```

### Saved Filters

```
Sidebar секция заголовок:   «Мои сегменты»
Sidebar ссылка:             «Все сегменты…»
SegmentSelector кнопка:     «Применить сегмент»
SegmentSelector active:     «Сегмент: ${name}»
SegmentSelector сброс:      «Сбросить»
SegmentSelector empty:      «Нет сохранённых сегментов»
Кнопка сохранить фильтр:    «Сохранить»   (иконка bi-bookmark-plus)
```

### Saved Filters — SaveSegmentModal

```
Modal title:                «Сохранить сегмент»
Label: Название:            «Название *»
Placeholder название:       «Горячие лиды KZ»
Checkbox pin:               «Закрепить в боковой панели (быстрый доступ)»
Заголовок фильтры:          «Текущие фильтры:»
Empty фильтры:              «Фильтры не применены»
Кнопка отмена:              «Отмена»
Кнопка сохранить:           «Сохранить»
Кнопка saving:              «Сохраняем…»
Error:                      «Не удалось сохранить сегмент»
```

### Saved Filters — /profile/segments (если создаётся)

```
PageHeader title:           «Мои сегменты»
Table col:                  «Название», «Страница», «Закреплён», «Создан», «»
Toggle pin on:              «Закреплён»
Toggle pin off:             «Не закреплён»
Confirm delete:             «Удалить сегмент «${name}»?»
Empty title:                «Нет сохранённых сегментов»
Empty description:          «Сохраняй часто используемые наборы фильтров для быстрого доступа»
```

### Search — SearchModal

```
Input placeholder:          «Поиск по всей CRM…»
Подсказка пустого поля:     «Начни вводить для поиска»
Раздел недавние:            «Недавние»
Группа «Контрагенты»:       «Контрагенты»
Группа «Контакты»:          «Контакты»
Группа «Компании»:          «Компании»
Группа «Лиды»:              «Лиды»
Группа «Сделки»:            «Сделки»
Группа «Договоры»:          «Договоры»
Все результаты:             «Показать все результаты по «${query}»»
Empty title:                «Ничего не найдено»
Empty description:          «Попробуй имя, email, телефон или ИНН»
Error:                      «Не удалось выполнить поиск. Попробуй снова»
Keyboard hint:              «↑↓ навигация  ·  Enter открыть  ·  Esc закрыть»
```

### Search — Sidebar кнопка

```
Кнопка текст:               «Поиск…»
Keyboard hint рядом:        «⌘K»   (или «Ctrl+K» — определяется в runtime через navigator.platform)
```

---

## Адаптивность

Desktop-first (≥1024px) — полная функциональность.

Что проверить при верстке:
- `CustomFieldsBlock` — grid переключается в `grid-cols-1` на `<md`. OK.
- `MergeModal` — двухколоночная таблица. На `<768px` сжимается до одноколоночной с radio inline. Ширина Modal `max-w-6xl` — на узких экранах станет full-width. OK.
- `SearchModal` — `max-w-2xl` на desktop, на `<640px` `w-full mx-4`. Добавить `sm:mx-4` класс.
- `DuplicateGroupCard` — горизонтальные карточки записей. На `<768px` — `flex-col`. Добавить `flex-col sm:flex-row`.
- `SavedFilterPin` в Sidebar — не критично, Sidebar на mobile в будущем станет drawer (Эпик 10).

**Mobile — TBD (Эпик 10)**. Сейчас: убедиться что ничего не сломается критически при window.width < 1024px. Горизонтальный скролл как fallback для таблиц (`overflow-x-auto` на обёртке таблицы).

---

## Сводная таблица открытых вопросов

| # | Фича | Вопрос | Рекомендация |
|---|---|---|---|
| 1 | Custom Fields | Backend: 8 новых эндпоинтов + 7 миграций (`extra_fields jsonb`) | Требуется, без этого фронтенд не работает |
| 2 | Custom Fields | Удаление дефиниции: данные или удалять? | Soft-delete, данные сохранить |
| 3 | Custom Fields | Backend-валидация required полей при PATCH? | Да, 422 с perField errors |
| 4 | Duplicates | Сканирование sync или async? | Sync с таймаутом 5s; если дольше — async с polling |
| 5 | Duplicates | Merge 3+ записей в UI | В Эпике 8 — только 2, с предупреждением для 3+ |
| 6 | Duplicates | Перепривязка FK при merge | Критичная логика — подтвердить с backend-specialist |
| 7 | Audit Log | Карточка Deal (`/deals/[id]`) — существует? | Уточнить, если нет — Audit Log для сделок не делаем |
| 8 | Audit Log | Подписки — где карточка? | Уточнить путь у cs-specialist |
| 9 | Audit Log | Кнопка «Экспорт» в скоупе? | Нет, заглушка disabled |
| 10 | Saved Filters | Глобальные сегменты (user_id=null) в скоупе? | Нет, только пользовательские в Эпике 8 |
| 11 | Saved Filters | Страница `/profile/segments` в скоупе? | Да, простая таблица |
| 12 | Search | Поиск по extra_fields в скоупе? | Нет, только системные поля |
| 13 | Search | Full-page `/search` в скоупе? | Нет, только Modal с 5 результатов на entity |
| 14 | Search | Поиск по подпискам/CS? | Нет в Эпике 8, добавить позже |

---

ТЗ готово, передавай `frontend-specialist`. Если есть правки — кидай мне.agentId: a8145f1985f08bfb5 (use SendMessage with to: 'a8145f1985f08bfb5' to continue this agent)
<usage>subagent_tokens: 68503
tool_uses: 14
duration_ms: 424152</usage>