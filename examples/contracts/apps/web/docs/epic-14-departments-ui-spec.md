# ТЗ: Эпик 14 — Departments + Visibility ACL (UI)

**Зачем:** Дать администратору инструмент для создания иерархии отделов, назначения ответственных на сущности и настройки видимости данных по ролям — фундаментальная фича, от которой зависят Эпик 17 и Эпик 10.5.

**Дата:** 2026-06-02  
**Статус:** готово для frontend-specialist  
**Backend-зависимости:** миграция departments + поля users.department_id/manager_id, endpoints `/api/departments`, `/api/admin/visibility-settings` — всё делается backend-specialist параллельно или до.

---

## Ключевые факты о существующем коде

- `Department` тип уже есть в `@/lib/types.ts`: `{ id: number; name: string; sort_order: number }` — требует расширения (добавить `parent_id`, `head_user_id`, `is_active`, `children?`)
- `User` тип уже имеет `department_id?: number | null`, но `manager_id` ещё нет
- `/admin/departments/page.tsx` уже существует — плоский список без иерархии и без Modal. Его нужно **полностью переписать** по этому ТЗ
- `/admin/users/page.tsx` — Modal с Field/SelectField, нужно добавить секцию «Отдел и иерархия»
- `/admin/visibility` — страница не существует, создать с нуля
- `Sidebar.tsx` — пункт «Отделы» (`/admin/departments`) уже есть в `ADMIN_ITEMS`. Нужно добавить «Видимость»
- `Modal`, `UserSelect`, `Field`, `SelectField`, `PageHeader` — переиспользовать как есть
- `@dnd-kit/sortable` — проверить наличие в package.json перед использованием (если нет — исключить drag-and-drop из Фазы 1, добавить в открытые вопросы)

---

## Раздел 1: `/admin/departments` — дерево отделов

### Wireframe

```
┌────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ Отделы и команды                [+ Добавить отдел]│
│            ├────────────────────────────────────────────────────│
│            │                                                    │
│            │  ┌──────────────────────────────────────────────┐ │
│            │  │ ▼ Отдел продаж                        [✏][🗑]│ │
│            │  │   Руководитель: Иванов И.И.  · 4 сотрудника  │ │
│            │  │                                              │ │
│            │  │   ↳ Команда KZ                       [✏][🗑]│ │
│            │  │     Руководитель: Петров А.  · 2 сотрудника  │ │
│            │  │                                              │ │
│            │  │   ↳ Команда UZ                       [✏][🗑]│ │
│            │  │     Руководитель: —          · 1 сотрудник   │ │
│            │  └──────────────────────────────────────────────┘ │
│            │                                                    │
│            │  ┌──────────────────────────────────────────────┐ │
│            │  │ ▼ Customer Success                    [✏][🗑]│ │
│            │  │   Руководитель: Сидорова Е.  · 3 сотрудника  │ │
│            │  └──────────────────────────────────────────────┘ │
│            │                                                    │
└────────────────────────────────────────────────────────────────┘
```

### Где в коде

- Страница: `apps/web/src/app/(app)/admin/departments/page.tsx` (переписать)
- Компоненты:
  - `apps/web/src/components/Departments/DepartmentTree.tsx` — рекурсивный рендер дерева
  - `apps/web/src/components/Departments/DepartmentCard.tsx` — карточка одного отдела
  - `apps/web/src/components/Departments/DepartmentFormModal.tsx` — создать/редактировать
  - `apps/web/src/components/Departments/DepartmentSelect.tsx` — dropdown выбора отдела (реюз в форме пользователя и в самой форме отдела для parent)

### Типы (расширить `@/lib/types.ts`)

```typescript
// Расширить существующий Department:
export interface Department {
  id: number;
  name: string;
  parent_id: number | null;
  head_user_id: number | null;
  sort_order: number;
  is_active: boolean;
  // вычисляется на клиенте из flat-списка:
  children?: Department[];
  members_count?: number;  // из /api/departments — если backend шлёт, иначе считаем из users
}

// Новый тип для формы:
export type DepartmentScope = "all" | "personal" | "department" | "department_and_children";

// Для visibility settings:
export interface VisibilitySetting {
  id?: number;
  entity_type: "lead" | "deal" | "contract" | "subscription" | "counterparty" | "company";
  applies_to_role: "director" | "lawyer" | "manager";
  scope: DepartmentScope;
}
```

### Данные и API

- `GET /api/departments` — возвращает `Department[]` (flat-список). Клиент строит дерево через `parent_id`
- `POST /api/departments` — создать
- `PATCH /api/departments/{id}` — обновить
- `DELETE /api/departments/{id}` — удалить (backend возвращает 400 если есть users)

### Алгоритм построения дерева на клиенте

Страница получает flat `Department[]`. Функция-утилита `buildDepartmentTree(flat: Department[]): Department[]` строит вложенность через `parent_id` — корни = `parent_id === null`, остальные вкладываются рекурсивно. Сортировка по `sort_order` на каждом уровне.

### UI компоненты — DepartmentCard

Карточка корневого отдела (и дочернего с отступом):

```
┌────────────────────────────────────────────────────────────┐
│ [bi-chevron-down] Название отдела              [✏] [🗑]  │
│  badge: Руководитель: ФИО  ·  bi-people: N сотрудников   │
│                                                            │
│  [+/−] дочерние отделы (отступ 24px + bi-arrow-return-right) │
└────────────────────────────────────────────────────────────┘
```

**Tailwind-классы:**
- Корневая карточка: `card p-4 mb-3`
- Заголовок строки: `flex items-center justify-between`
- Название: `text-base font-semibold text-primary`
- Expand toggle: `bi-chevron-down` / `bi-chevron-right` (кнопка `btn-ghost p-1`)
- Бейдж руководителя: `inline-flex items-center gap-1 text-xs bg-info/10 text-info px-2 py-0.5 rounded-full`
- Счётчик сотрудников: `text-xs text-gray-500 ml-2` + `bi-people mr-1`
- Кнопка редактировать: `btn-ghost p-1.5 text-gray-500 hover:text-primary` + `bi-pencil`
- Кнопка удалить: `btn-ghost p-1.5 text-danger` + `bi-trash`
- Дочерний отдел (вложение): `ml-6 mt-2 border-l border-gray-200 pl-4` — чуть менее выражен чем корень
- Иконка вложения: `bi-arrow-return-right text-gray-400 mr-1 text-xs`

Дочерние отделы раскрываются/скрываются по клику на chevron (локальное состояние `expanded: boolean`, по умолчанию `true`). Вторая вложенность (внуки) рендерится аналогично с дополнительным `ml-6`.

### DepartmentFormModal

Компонент `DepartmentFormModal` открывается при «Добавить отдел» (create) или клике на `bi-pencil` (edit).

**Props:**
```typescript
interface DepartmentFormModalProps {
  open: boolean;
  onClose: () => void;
  onSaved: () => void;
  department?: Department;      // undefined = создание
  allDepartments: Department[]; // для выбора parent
  allUsers: User[];             // для выбора head_user
}
```

**Поля формы:**

| Поле | Компонент | Обязательное | Примечание |
|---|---|---|---|
| Название | `<Field label="Название" required />` | да | `name` |
| Родительский отдел | `<DepartmentSelect />` | нет | `parent_id`; при создании можно передать pre-selected; exclude self при edit |
| Руководитель отдела | `<UserSelect />` | нет | `head_user_id` |

**Footer Modal:**
```
[btn-ghost: Отмена]  ...  [btn-primary: Сохранить]
```

Кнопка «Сохранить» — `disabled` пока `name.trim() === ""` или идёт сохранение (`saving`). Текст при сохранении: «Сохраняем…».

Ошибка от API — inline под полями: `text-sm text-danger bg-danger/10 px-3 py-2 rounded`.

### DepartmentSelect компонент

Новый реюзабельный компонент для выбора отдела. Используется в DepartmentFormModal (выбор родителя) и в форме пользователя (выбор отдела).

```typescript
// apps/web/src/components/Departments/DepartmentSelect.tsx
interface DepartmentSelectProps {
  value: string;                    // id как строка, "" = не выбран
  onChange: (value: string) => void;
  departments: Department[];        // flat-список
  placeholder?: string;
  excludeId?: number;               // исключить себя (при редактировании)
  className?: string;
}
```

Рендерит `<select className={className ?? "input"}>`. Опции отрисовываются рекурсивно с отступами через ` ` (неразрывный пробел):
- Корень: `Отдел продаж`
- Дочерний: `  ↳ Команда KZ`
- Внук: `    ↳ Подгруппа`

Опции строятся из flat-списка через `buildDepartmentTree` — обход в глубину с передачей уровня.

### DeleteConfirmModal поведение

При клике `bi-trash` открывается `<Modal>` (переиспользуем существующий) с:
- Title: «Удалить отдел?»
- Body: «Удалить отдел «{name}»? Это действие нельзя отменить.»
- Если `members_count > 0`: добавить предупреждение `text-warning` + иконка `bi-exclamation-triangle`: «В отделе {N} сотрудников. Сначала переведи их в другой отдел.»
- Footer:
  - Если `members_count > 0`: `[btn-ghost: Отмена]` — кнопка «Удалить» `disabled` + tooltip `title="Сначала переведи сотрудников"`
  - Если `members_count === 0`: `[btn-ghost: Отмена]` `[btn-secondary text-danger: bi-trash Удалить]`

### States — страница /admin/departments

**Loading:** пока SWR загружает — отображать 3 skeleton-карточки:
```
card p-4 mb-3 animate-pulse:
  div h-5 w-48 bg-gray-200 rounded mb-2
  div h-4 w-32 bg-gray-100 rounded
```

**Empty:** если `deps === []`:
```
card p-12 flex flex-col items-center text-center:
  bi-diagram-3 text-5xl text-gray-300 mb-4
  h3 "Пока нет ни одного отдела"
  p text-gray-500 "Создай первый, чтобы начать организовывать команду"
  button btn-primary mt-4 "Добавить отдел"
```

**Error:** если SWR вернул ошибку — inline над деревом: `text-sm text-danger bg-danger/10 px-3 py-2 rounded`

### Interactions — /admin/departments

| Элемент | Действие | Результат |
|---|---|---|
| `+ Добавить отдел` (PageHeader) | click | открыть `DepartmentFormModal` (create mode) |
| Chevron карточки | click | toggle expand/collapse дочерних |
| `bi-pencil` | click | открыть `DepartmentFormModal` (edit mode, pre-fill) |
| `bi-trash` | click | открыть DeleteConfirmModal |
| Confirm удалить | click | `DELETE /api/departments/{id}` → `mutate()` → закрыть modal |
| Сохранить в форме (create) | click | `POST /api/departments` → `mutate()` → закрыть |
| Сохранить в форме (edit) | click | `PATCH /api/departments/{id}` → `mutate()` → закрыть |

### Drag-and-drop (sort_order)

**Открытый вопрос**: нужно проверить наличие `@dnd-kit/sortable` в `apps/web/package.json`. Если нет — drag-and-drop для изменения `sort_order` **откладывается на Фазу 2** эпика. В Фазе 1 порядок отделов меняется через поле `sort_order` в форме редактирования (числовое поле).

Если `@dnd-kit/sortable` есть: использовать `DndContext` + `SortableContext` только для корневых отделов одного уровня. При drop — `PATCH /api/departments/{id}` с новым `sort_order` для всех затронутых. Drag-handle: `bi-grip-vertical text-gray-400 cursor-grab` слева от chevron.

---

## Раздел 2: `/admin/users` — секция «Отдел и иерархия» в форме пользователя

### Где в коде

- Файл: `apps/web/src/app/(app)/admin/users/page.tsx` (добавить секцию в существующий Modal)
- Компоненты: `DepartmentSelect` (из раздела 1)

### Что добавить

В `Modal` редактирования/создания пользователя (`admin/users/page.tsx`) добавить новую секцию после поля «Telegram User ID»:

```
─────────────────────────────────────────────
Отдел и иерархия                             ← секция-разделитель
─────────────────────────────────────────────
[Отдел: DepartmentSelect]
[Прямой руководитель: UserSelect]
hint: «Прямой руководитель может отличаться от руководителя отдела...»
```

**Разделитель секции:**
```html
<div class="pt-4 border-t border-gray-200">
  <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">
    Отдел и иерархия
  </p>
  ...поля...
</div>
```

**Поля:**

| Поле | Компонент | props |
|---|---|---|
| Отдел | `DepartmentSelect` | `value={form.department_id}` `onChange` → `setForm({...form, department_id: v ? Number(v) : null})` |
| Прямой руководитель | `UserSelect` | `value={form.manager_id}` — выбор любого пользователя; label «Прямой руководитель» |

**Hint под UserSelect:**
```
text-xs text-gray-500 mt-1:
"Прямой руководитель может отличаться от руководителя отдела — например, в матричной структуре."
```

**Данные:** добавить `useSWR<Department[]>("/departments", fetcher)` рядом с существующим `useSWR<User[]>("/users", fetcher)` в компоненте страницы. Передавать `departments` в `DepartmentSelect`.

**Тип FormUser:** добавить поля:
```typescript
type FormUser = Partial<User> & {
  password?: string;
  manager_id?: number | null;  // добавить
};
```

**Тип User** (`@/lib/types.ts`): добавить `manager_id?: number | null`.

**PATCH body:** существующий `save()` уже шлёт весь `form` через `PATCH /users/{id}` — новые поля подхватятся автоматически при включении `department_id` и `manager_id` в тип.

### States

- Поле «Отдел»: если `departments` грузится — `<select disabled className="input opacity-60">` с опцией «Загружаем…»
- Поле «Прямой руководитель»: аналогично через существующий `UserSelect` (он сам загружает `/users`)

---

## Раздел 3: `/admin/visibility` — настройки видимости

### Wireframe

```
┌────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ Настройки видимости                               │
│            │ Определи, какие записи видит каждая роль          │
│            ├────────────────────────────────────────────────────│
│            │                                                    │
│            │  ┌──────────────────────────────────────────────┐ │
│            │  │           │ Директор │ Юрист │ Менеджер      │ │
│            │  ├───────────┼──────────┼───────┼───────────────┤ │
│            │  │ Лиды      │ [select] │[sel.] │ [select]      │ │
│            │  │ Сделки    │ [select] │[sel.] │ [select]      │ │
│            │  │ Контраг.  │ [select] │[sel.] │ [select]      │ │
│            │  │ Компании  │ [select] │[sel.] │ [select]      │ │
│            │  │ Подписки  │ [select] │[sel.] │ [select]      │ │
│            │  │ Контракты │ [select] │[sel.] │ [select]      │ │
│            │  └──────────────────────────────────────────────┘ │
│            │                                                    │
│            │  [unsaved indicator]                              │
│            │                   [Сбросить к «Все»] [Сохранить] │
│            │                                                    │
└────────────────────────────────────────────────────────────────┘
```

### Где в коде

- Страница: `apps/web/src/app/(app)/admin/visibility/page.tsx` (создать)
- Компоненты:
  - `apps/web/src/components/Visibility/VisibilityMatrix.tsx` — таблица-матрица
  - `apps/web/src/components/Visibility/VisibilityScopeSelect.tsx` — одна ячейка dropdown

### Константы (определить в файле страницы или отдельном `visibility.config.ts`)

```typescript
const ENTITY_LABELS: Record<string, string> = {
  lead: "Лиды",
  deal: "Сделки",
  counterparty: "Контрагенты",
  company: "Компании",
  subscription: "Подписки",
  contract: "Контракты",
};

const ENTITY_TYPES = ["lead", "deal", "counterparty", "company", "subscription", "contract"] as const;

// admin column не рендерится (у admin всегда "all")
const ROLES_TO_CONFIGURE = [
  { role: "director", label: "Директор" },
  { role: "lawyer",   label: "Юрист" },
  { role: "manager",  label: "Менеджер" },
] as const;

const SCOPE_OPTIONS: { value: DepartmentScope; label: string; icon: string }[] = [
  { value: "all",                      label: "Все",                      icon: "bi-globe" },
  { value: "personal",                 label: "Только свои",              icon: "bi-person" },
  { value: "department",               label: "Свой отдел",               icon: "bi-people" },
  { value: "department_and_children",  label: "Отдел и дочерние",         icon: "bi-diagram-3" },
];
```

### State машина

```typescript
// Ключ матрицы: "entity_type:role"
type MatrixKey = `${string}:${string}`;
type VisibilityMatrix = Record<MatrixKey, DepartmentScope>;

// Состояние страницы:
const [matrix, setMatrix] = useState<VisibilityMatrix>({});   // текущие значения (редактируемые)
const [savedMatrix, setSavedMatrix] = useState<VisibilityMatrix>({});  // последнее сохранённое
const [saving, setSaving] = useState(false);
const [error, setError] = useState<string | null>(null);

const isDirty = JSON.stringify(matrix) !== JSON.stringify(savedMatrix);
```

**Инициализация:** `GET /api/admin/visibility-settings` возвращает `VisibilitySetting[]`. На клиенте маппим в `MatrixKey`:
```typescript
settings.forEach(s => {
  matrix[`${s.entity_type}:${s.applies_to_role}`] = s.scope;
});
// отсутствующие ключи = "all" (default)
```

**Сохранение:** `PATCH /api/admin/visibility-settings` с массивом всех ячеек матрицы. После успеха: `setSavedMatrix({...matrix})`, `isDirty` → `false`.

**Сброс к «Все»:** установить все ячейки в `"all"` (не сохранять сразу, просто обновить `matrix` — кнопка «Сохранить» подсветится).

### VisibilityScopeSelect компонент

```typescript
interface VisibilityScopeSelectProps {
  value: DepartmentScope;
  onChange: (v: DepartmentScope) => void;
  disabled?: boolean;  // для admin-колонки
}
```

Рендерит нативный `<select className="input text-sm py-1.5 px-2">`. Каждая опция с prefix-иконкой через text: «glob Все», «person Только свои», etc. (иконки Bootstrap в native select не отображаются, используем текст с emoji-заменой или просто текст — **без Bootstrap Icons в option-тегах**).

Альтернатива — кастомный dropdown через `<details>/<summary>` если нужны иконки в опциях (открытый вопрос).

**Подсветка ячеек по значению:**
- `"all"` → без акцента (default input)
- `"personal"` → `border-warning focus:border-warning` (желтоватый)
- `"department"` → `border-info focus:border-info` (синеватый)
- `"department_and_children"` → `border-primary focus:border-primary` (синий бренд)

### VisibilityMatrix компонент

**Структура таблицы:**

```html
<div class="card overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-gray-50">
      <tr>
        <th class="text-left px-4 py-3 font-semibold text-gray-700 w-40">Сущность</th>
        <!-- для каждой роли из ROLES_TO_CONFIGURE -->
        <th class="text-center px-4 py-3 font-semibold text-gray-700">Директор</th>
        <th class="text-center px-4 py-3 font-semibold text-gray-700">Юрист</th>
        <th class="text-center px-4 py-3 font-semibold text-gray-700">Менеджер</th>
      </tr>
    </thead>
    <tbody>
      <!-- для каждого entity_type -->
      <tr class="border-t border-gray-200 hover:bg-gray-50/50">
        <td class="px-4 py-3 font-medium text-gray-900">Лиды</td>
        <!-- для каждой роли -->
        <td class="px-4 py-3 text-center">
          <VisibilityScopeSelect value={...} onChange={...} />
        </td>
      </tr>
    </tbody>
  </table>
</div>
```

**Tooltip при hover на ячейку:** реализуется через нативный `title` атрибут на `<td>`. Тексты:
- `personal`: «Увидит только свои записи (owner = он сам)»
- `department`: «Увидит записи своего отдела»
- `department_and_children`: «Увидит записи своего отдела и дочерних подразделений»
- `all`: «Без ограничений — видит всё»

### Индикатор несохранённых изменений

Отображается между таблицей и кнопками только при `isDirty === true`:
```html
<div class="flex items-center gap-2 text-sm text-warning mt-4">
  <i class="bi bi-exclamation-circle" />
  Есть несохранённые изменения
</div>
```

### Footer кнопки

```html
<div class="flex items-center justify-end gap-3 mt-6">
  <button class="btn-secondary" onClick={resetToAll}>
    <i class="bi bi-arrow-counterclockwise mr-1" />
    Сбросить к «Все»
  </button>
  <button
    class="btn-primary"
    disabled={!isDirty || saving}
    onClick={handleSave}
  >
    {saving ? "Сохраняем…" : "Сохранить настройки"}
  </button>
</div>
```

Кнопка «Сохранить» — `disabled` при `!isDirty || saving`. Визуально: при `isDirty === true` — обычная `btn-primary`; при `isDirty === false` — `btn-primary opacity-50 cursor-not-allowed`.

### States — страница /admin/visibility

**Loading:** пока `GET /api/admin/visibility-settings` грузится — таблица с skeleton-ячейками:
```
animate-pulse: div h-8 w-full bg-gray-100 rounded
```

**Empty (нет настроек):** не бывает — при отсутствии записи в visibility_settings по умолчанию `"all"`. Страница всегда показывает матрицу.

**Error:** inline над таблицей: `text-sm text-danger bg-danger/10 px-3 py-2 rounded`

**Save success:** нет toast-системы. После сохранения: `isDirty` сбрасывается в `false`, индикатор исчезает — этого достаточно.

### Interactions — /admin/visibility

| Элемент | Действие | Результат |
|---|---|---|
| Ячейка select | change | обновить `matrix` state → `isDirty = true` → подсветить кнопку «Сохранить» |
| `Сбросить к «Все»` | click | установить все ячейки в `"all"` → `isDirty = true` |
| `Сохранить настройки` | click | `PATCH /api/admin/visibility-settings` → при успехе `setSavedMatrix` → `isDirty = false` |
| Уйти со страницы при `isDirty` | navigate | нет блокировки (поведение как есть, не реализуем `beforeunload` в Фазе 1) |

### API контракт

```
GET  /api/admin/visibility-settings
→ VisibilitySetting[]

PATCH /api/admin/visibility-settings
body: VisibilitySetting[]  // полный список всех ячеек
→ VisibilitySetting[]

// Если backend требует — отдельный endpoint:
// PUT /api/admin/visibility-settings/{entity_type}/{role}
// Уточнить у backend-specialist (открытый вопрос)
```

---

## Раздел 4: Sidebar — обновление

### Текущее состояние

В `Sidebar.tsx` в `ADMIN_ITEMS` уже есть:
```typescript
{ href: "/admin/departments", icon: "bi-diagram-3-fill", label: "Отделы", roles: ["admin", "director"] },
```

### Что добавить

Добавить пункт «Видимость» **сразу после «Отделы»**:
```typescript
{ href: "/admin/visibility", icon: "bi-eye", label: "Видимость", roles: ["admin"] },
```

Роль `["admin"]` — только администратор настраивает видимость.

Порядок в `ADMIN_ITEMS` после изменения (фрагмент):
```
...
"/admin/departments"   bi-diagram-3-fill  Отделы       [admin, director]
"/admin/visibility"    bi-eye             Видимость    [admin]
"/admin/cs-config"     ...
...
```

**Группировка в UI:** текущий Sidebar не имеет подгрупп внутри «Настройки» — просто единый список. Группировку «Команда» **не добавляем** (изменение визуальной структуры sidebar — отдельный UX-эпик). Два пункта рядом по позиции достаточно для контекста.

---

## Раздел 5: Right Rail — блок «Принадлежность»

### Контекст

Right Rail существует только в карточке контрагента (`CounterpartyRightRail.tsx`). Для Lead, Deal, Company, Subscription — отдельные right rail компоненты либо отсутствуют. Реализация блока «Принадлежность» — **только для Counterparty** в Фазе 1 эпика 14. Для остальных сущностей — в соответствующих правках карточек (отдельные задачи).

### Что добавить в CounterpartyRightRail

Уже есть секция «Ответственный» с `UserSelect` (строка 87–94 компонента). Нужно:

1. Под `UserSelect` добавить поле «Отдел» — отображение (не редактирование inline):
   ```html
   <div class="mt-2">
     <label class="label text-xs mb-1">Отдел</label>
     <!-- если department_id задан: -->
     <a
       href="/admin/departments"
       class="text-sm text-primary-light hover:underline flex items-center gap-1"
     >
       <i class="bi bi-diagram-3 text-xs" />
       {department_name}
     </a>
     <!-- если не задан: -->
     <span class="text-sm text-gray-400">Не указан</span>
   </div>
   ```

2. Кнопка «Назначить ответственного» (когда и owner, и department не заданы):
   ```html
   <!-- вместо UserSelect если responsible_user_id == null: -->
   <button class="btn-ghost text-sm w-full justify-start text-gray-500">
     <i class="bi bi-person-plus mr-1" />
     Назначить ответственного
   </button>
   ```
   При клике — открывает inline UserSelect (тот же, что сейчас, но через кнопку-триггер).

**Props изменения `CounterpartyRightRailProps`:**
- Добавить `departments?: Department[]` — для отображения названия отдела по `department_id` (ищем по `cp.department_id` если backend добавит это поле в `Counterparty`)
- Добавить `onDepartmentChange?: (deptId: number | null) => void` — если нужна смена отдела из right rail (опционально)

**Важно:** `Counterparty` type в `@/lib/types.ts` не имеет `department_id` — нужно добавить поле после backend-миграции. Указать в открытых вопросах.

---

## Список новых компонентов

| Компонент | Путь | Статус |
|---|---|---|
| `DepartmentTree` | `components/Departments/DepartmentTree.tsx` | НОВЫЙ |
| `DepartmentCard` | `components/Departments/DepartmentCard.tsx` | НОВЫЙ |
| `DepartmentFormModal` | `components/Departments/DepartmentFormModal.tsx` | НОВЫЙ |
| `DepartmentSelect` | `components/Departments/DepartmentSelect.tsx` | НОВЫЙ (реюз везде) |
| `VisibilityMatrix` | `components/Visibility/VisibilityMatrix.tsx` | НОВЫЙ |
| `VisibilityScopeSelect` | `components/Visibility/VisibilityScopeSelect.tsx` | НОВЫЙ |

## Список изменённых файлов

| Файл | Что меняем |
|---|---|
| `app/(app)/admin/departments/page.tsx` | Полная переработка: добавить DepartmentTree, DepartmentFormModal, delete confirm |
| `app/(app)/admin/users/page.tsx` | Добавить секцию «Отдел и иерархия» в Modal |
| `app/(app)/admin/visibility/page.tsx` | НОВЫЙ файл |
| `components/Sidebar.tsx` | Добавить пункт «Видимость» в ADMIN_ITEMS |
| `components/Counterparty/CounterpartyRightRail.tsx` | Добавить поле «Отдел» под «Ответственный» |
| `lib/types.ts` | Расширить `Department`, `User`; добавить `DepartmentScope`, `VisibilitySetting` |

---

## Тексты (RU, для копипаст в JSX)

### /admin/departments

- Заголовок страницы: `Отделы и команды`
- Описание (PageHeader): `Организуй команду по отделам для управления видимостью и аналитики`
- Кнопка создания: `Добавить отдел`
- Бейдж руководителя (prefix): `Руководитель:`
- Счётчик сотрудников: `{N} сотрудников` / `1 сотрудник`
- Expand tooltip: `Развернуть` / `Свернуть`
- Empty state title: `Пока нет ни одного отдела`
- Empty state body: `Создай первый, чтобы начать организовывать команду`
- Empty state CTA: `Добавить отдел`
- Error generic: `Не удалось загрузить отделы. Попробуй обновить страницу.`

### DepartmentFormModal

- Title (create): `Новый отдел`
- Title (edit): `Редактировать отдел`
- Label «Название»: `Название` (required *)
- Label «Родительский отдел»: `Родительский отдел`
- Placeholder «Родительский отдел»: `Корневой (без родителя)`
- Label «Руководитель отдела»: `Руководитель отдела`
- Placeholder «Руководитель»: `Не назначен`
- Кнопка сохранить: `Сохранить`
- Кнопка сохранить (процесс): `Сохраняем…`
- Кнопка отмена: `Отмена`

### DeleteConfirmModal

- Title: `Удалить отдел?`
- Body (без сотрудников): `Удалить отдел «{name}»? Это действие нельзя отменить.`
- Body (есть сотрудники — предупреждение): `В отделе {N} сотрудников. Сначала переведи их в другой отдел, затем удали отдел.`
- Кнопка удалить: `Удалить`
- Кнопка удалить (disabled tooltip): `Сначала переведи сотрудников`
- Кнопка отмена: `Отмена`

### /admin/users — секция

- Заголовок секции: `Отдел и иерархия`
- Label «Отдел»: `Отдел`
- Placeholder «Отдел»: `Не выбрано`
- Label «Прямой руководитель»: `Прямой руководитель`
- Placeholder «Руководитель»: `Не назначен`
- Hint: `Прямой руководитель может отличаться от руководителя отдела — например, в матричной структуре.`

### /admin/visibility

- Заголовок страницы: `Настройки видимости`
- Описание (PageHeader): `Настрой, какие записи видит каждая роль: только свои, свой отдел или все`
- Заголовок таблицы строка: `Сущность`
- Колонки таблицы: `Директор` / `Юрист` / `Менеджер`
- Строки таблицы: `Лиды` / `Сделки` / `Контрагенты` / `Компании` / `Подписки` / `Контракты`
- Опции select: `Все` / `Только свои` / `Свой отдел` / `Отдел и дочерние`
- Индикатор: `Есть несохранённые изменения`
- Кнопка сброс: `Сбросить к «Все»`
- Кнопка сохранить: `Сохранить настройки`
- Кнопка сохранить (процесс): `Сохраняем…`
- Error: `Не удалось сохранить настройки. Попробуй ещё раз.`
- Tooltip all: `Без ограничений — роль видит все записи`
- Tooltip personal: `Роль видит только свои записи (owner = он сам)`
- Tooltip department: `Роль видит записи своего отдела`
- Tooltip department_and_children: `Роль видит записи своего отдела и дочерних подразделений`

### Right Rail (CounterpartyRightRail)

- Label «Отдел»: `Отдел`
- Нет отдела: `Не указан`
- Кнопка назначить: `Назначить ответственного`

---

## API контракты (сводка)

| Метод | URL | Тело / Ответ | Примечание |
|---|---|---|---|
| `GET` | `/api/departments` | `Department[]` (flat) | client строит дерево |
| `POST` | `/api/departments` | `{ name, parent_id?, head_user_id?, sort_order? }` → `Department` | |
| `PATCH` | `/api/departments/{id}` | partial `Department` → `Department` | |
| `DELETE` | `/api/departments/{id}` | 204 / 400 если есть users | |
| `GET` | `/api/admin/visibility-settings` | `VisibilitySetting[]` | |
| `PATCH` | `/api/admin/visibility-settings` | `VisibilitySetting[]` → `VisibilitySetting[]` | полная замена |
| `PATCH` | `/api/users/{id}` | `{ department_id?, manager_id? }` | уже есть, расширить |

Все запросы через `api` / `fetcher` из `@/lib/api` с `credentials: "same-origin"`.

---

## Адаптивность

Desktop-first. Mobile — TBD (эпик 10). Таблица visibility при узком экране — горизонтальный скролл (`overflow-x-auto`).

---

## Открытые вопросы

1. **@dnd-kit/sortable**: есть ли в `apps/web/package.json`? Если нет — drag-and-drop откладывается. frontend-specialist проверяет перед реализацией DepartmentCard.

2. **Backend endpoint visibility-settings**: один `PATCH /api/admin/visibility-settings` с полным массивом или отдельные `PUT /api/admin/visibility-settings/{entity_type}/{role}`? Уточнить у backend-specialist перед реализацией `handleSave()`.

3. **Department.members_count**: backend шлёт `members_count` в `GET /api/departments` или считать на клиенте из `users[].department_id`? Если на клиенте — нужен `useSWR<User[]>("/users", fetcher)` в DepartmentsPage (есть в текущей имплементации, можно сохранить).

4. **Counterparty.department_id**: поле отсутствует в текущем типе `Counterparty` и (вероятно) в таблице. Требуется правка backend: миграция + поле в DTO Counterparty. До готовности backend секция «Отдел» в CounterpartyRightRail рендерит `"Не указан"` статично.

5. **Видимость для director**: в UI директор configurable (не disabled). Это намеренно? Проверить с продуктом — если директор всегда должен видеть всё, его колонку стоит disabled-ить как admin.

6. **DepartmentSelect в иконках опций**: нативный `<select>` не поддерживает Bootstrap Icons в `<option>`. Если нужны иконки scope-опций — использовать кастомный dropdown. Решение: в VisibilityScopeSelect добавить текстовые prefix-символы (`◎ Все`, `● Только свои` и т.д.) или реализовать кастомный `<details>/<summary>` dropdown.

7. **`head_user_id` vs `members_count`**: если backend в ответе `GET /api/departments` не шлёт `head_user` объект (а только ID), нужно джойнить вручную из `users[]`. Бейдж «Руководитель: ФИО» требует `users` в DepartmentCard — передать через пропс или SWR.
