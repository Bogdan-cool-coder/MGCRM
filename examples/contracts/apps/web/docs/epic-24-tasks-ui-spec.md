# ТЗ: Эпик 24 — Полноценный Задачник (Activity v2)

**MARATHON-3 · XL · 3–4 недели**

**Зачем:** менеджеры получают единое место для всех задач (сейчас задачи разбросаны по
карточкам сделок/лидов/контрагентов). Добавляем дисциплину через статусную цепочку,
делегирование через роли участников, шаблоны через категории, группировку через
parent-child и повторение через recurrence.

**Архитектура:** расширяем существующую модель `Activity`, НЕ создаём отдельную `Task`.
Activity уже polymorphic к 7 target_type (lead/contact/company/counterparty/deal/contract/
subscription) — это закрывает сценарий «задача привязана к сделке/лиду/договору».

**Новые поля Activity (после миграции 0056):**
- `category_id` — FK → task_categories
- `parent_activity_id` — self-FK (group/subtask)
- `priority` — `low | normal | high | critical`
- `status` — `new | in_progress | done | rejected` + `is_closed BOOL`
- `progress_pct` — 0–100
- `planned_hours`, `actual_hours` — decimal
- `result_text` — TEXT nullable (обязателен если category.restrict_close_without_result)
- `tags TEXT[]` — GIN индекс
- `recurrence_rule` — `daily | weekly | monthly | null`
- `recurrence_until` — DATE
- `recurrence_parent_id` — FK (первый экземпляр серии)
- Junction `activity_collaborators(activity_id, user_id, role ENUM co_executor|auditor|observer)`
- Junction `activity_checklist_items(id, activity_id, text, is_done, sort_order)`
- Table `activity_attachments(id, activity_id, filename, url, uploaded_by_id, created_at)`
- Table `activity_related_links(id, activity_id, related_activity_id)`
- Table `task_categories` — шаблоны (описано в разделе 4)

---

## Где в коде

| Артефакт | Путь |
|---|---|
| Главная страница | `apps/web/src/app/(app)/tasks/page.tsx` |
| Детальная карточка | `apps/web/src/app/(app)/tasks/[id]/page.tsx` |
| Admin категории | `apps/web/src/app/(app)/admin/task-categories/page.tsx` |
| Компоненты | `apps/web/src/components/Tasks/` (25–30 файлов) |

---

## Обзор разделов

| № | Раздел | Файл |
|---|---|---|
| 1 | `/tasks` — главная страница | `Tasks/TasksPage` |
| 2 | `/tasks/[id]` — карточка задачи | `Tasks/TaskDetailPage` |
| 3 | `TaskCreateDrawer` | `Tasks/TaskCreateDrawer` |
| 4 | `/admin/task-categories` | `Tasks/TaskCategoryAdmin` |
| 5 | `RecurrenceSelector` | `Tasks/RecurrenceSelector` |
| 6 | `TaskGroupView` | `Tasks/TaskGroupView` |
| 7 | Bulk operations | `Tasks/BulkActionsBar` |
| 8 | FTM-расширение в новом modal | в `Tasks/TaskCreateDrawer` |

---

## Раздел 1 — `/tasks` Главная страница задачника

### Wireframe (desktop 1440px)

```
┌───────────────────────────────────────────────────────────────────────────────────────┐
│ [Sidebar 240px]   │ [PageHeader: Задачи]                    [N открытых] [+ Создать] │
│                   ├───────────────────────────────────────────────────────────────────┤
│ - Дашборд         │                                                                   │
│ - Лиды            │ ┌─── Левая sidebar 240px ───┐ ┌── Main canvas (flex-1) ──────┐  │
│ - Сделки          │ │ 📌 Закреплённые     (3) ● │ │ ┌─ Toolbar ────────────────┐ │  │
│ - Контрагенты     │ │ ⏰ Просроченные     (7) ● │ │ │ [☐] [Сортировка ▼] ...  │ │  │
│ > Задачи          │ │ ✅ Готово, не закрыто (2) │ │ └──────────────────────────┘ │  │
│ - Реестр          │ │ 📅 Сегодня           (5) │ │ ┌─ TaskListItem ────────────┐ │  │
│ - ...             │ │ 📆 Эта неделя       (12) │ │ │ ● [☐] ★ [title...]       │ │  │
│                   │ │ 🔜 Следующая неделя  (4) │ │ │ Срок: 03 июн · Просрочено│ │  │
│                   │ │ 🔭 Будущие          (23) │ │ │ [badge: new] 30% [====  ]│ │  │
│                   │ │ 👤 Мои задачи       (18) │ │ │ Иванов И. → Создал: Я   │ │  │
│                   │ │ 📤 Мои поручения    (11) │ │ └──────────────────────────┘ │  │
│                   │ │ ─────────────────── │    │ │ ┌─ TaskListItem ────────────┐ │  │
│                   │ │ [Все задачи]        │    │ │ │ ● [☐] ...                 │ │  │
│                   │ └────────────────────────┘  │ └──────────────────────────┘ │  │
│                   │                              └─────────────────────────────┘  │  │
│                   │                                                                   │
│                   │         [Правая панель фильтров — слайдер 320px, по кнопке]      │
└───────────────────────────────────────────────────────────────────────────────────────┘
```

### Расширенный wireframe главной (с деталями)

```
HEADER
┌──────────────────────────────────────────────────────────────────────┐
│ Задачи                          [45 открытых]  [Фильтры ▼] [+ Создать задачу] │
└──────────────────────────────────────────────────────────────────────┘

BODY: три-колонночный grid
┌────────────────┬────────────────────────────────────────┬──────────────────────┐
│ LEFT SIDEBAR   │ MAIN CANVAS                            │ RIGHT FILTERS PANEL  │
│ 240px, sticky  │ flex-1                                 │ 320px, slide-in      │
│                │                                        │ (по кнопке «Фильтры»)│
│ [Пресеты]      │ [Toolbar]                              │                      │
│ ─ Закреплённые │  [☐ всё] Сортировка: Срок ▼           │ Категории (multi)    │
│   (3) ● orange │  Вид: ≡ список                        │ Статусы (multi)      │
│ ─ Просроченные │                                        │ Приоритеты (multi)   │
│   (7) ● red    │ ─────────────────────────────────────  │ Теги (autocomplete)  │
│ ─ Готово, не   │ [TaskListItem]                         │ Исполнитель          │
│   закрыто (2)  │   ● [☐] ★ Подготовить КП для MACRO   │ Постановщик          │
│   ● green      │   #1042 · Категория: Продажи           │ Отдел                │
│ ─ Сегодня (5)  │   Срок: сегодня 15:00 ⚡ просрочено   │ Период (date range)  │
│ ─ Эта неделя   │   [badge new] ████░░░░ 40%             │ ─────────────────    │
│   (12)         │   Иванов И. · Создал: Я · 03 июн      │ [☐] Только мои       │
│ ─ Следующая    │ ─────────────────────────────────────  │ [☐] Только поручения │
│   неделя (4)   │ [TaskListItem]                         │ [☐] Включить закрытые│
│ ─ Будущие (23) │   ○ [☐] Позвонить клиенту Х           │ ─────────────────    │
│ ─────────────  │   #1043 · Категория: Звонки            │ [Сбросить фильтры]   │
│ ─ Мои задачи   │   Срок: 05 июн                        │                      │
│   (18)         │   [badge in_progress] ████████░░ 80%   │                      │
│ ─ Мои поручения│   Петров С. · Создал: Я               │                      │
│   (11)         │ ─────────────────────────────────────  │                      │
│ ─────────────  │ [Если выбраны задачи — BulkActionsBar] │                      │
│                │ ┌─────────────────────────────────────┐ │                    │
│                │ │ Выбрано: 3  [Дедлайн] [Назначить]  │ │                    │
│                │ │ [Закрыть] [Удалить]  Отмена выбора │ │                    │
│                │ └─────────────────────────────────────┘ │                    │
└────────────────┴────────────────────────────────────────┴──────────────────────┘
```

### Левая sidebar (пресеты) — компонент `TaskPresetSidebar`

**Описание:** фиксированный список пресетов-фильтров, кликабельные строки. Активный пресет
выделен `bg-primary text-white`. Рядом с каждым пресетом badge с количеством задач (живые
данные из `GET /api/activities/counts-by-preset`).

**Пресеты:**

| Пресет | Значок | Dot-цвет | Описание фильтра |
|---|---|---|---|
| Закреплённые | `bi-pin-fill` | orange | `is_pinned=true AND is_closed=false` |
| Просроченные | `bi-clock-history` | red `text-danger` | `due_at < now AND is_closed=false AND status != done` |
| Готово, не закрыто | `bi-check2-all` | green `text-success` | `status=done AND is_closed=false` |
| Сегодня | `bi-calendar-day` | — | `due_at в сегодняшнем дне` |
| Эта неделя | `bi-calendar-week` | — | `due_at в текущей ISO неделе` |
| Следующая неделя | `bi-calendar-range` | — | `due_at в следующей ISO неделе` |
| Будущие | `bi-calendar2-plus` | — | `due_at > конец следующей недели` |
| Мои задачи | `bi-person-check` | — | `responsible_id=me OR collaborator_id=me` |
| Мои поручения | `bi-person-up` | — | `created_by_id=me AND responsible_id != me` |
| Все задачи | `bi-list-task` | — | без доп. фильтра |

**HTML-структура каждой строки:**
```
<button class="flex items-center gap-2 w-full px-3 py-2 rounded-md text-sm
               text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700
               [&.active]:bg-primary [&.active]:text-white transition-colors">
  <i class="bi bi-[icon] text-sm shrink-0" />   // иконка
  <span class="flex-1 text-left">Просроченные</span>
  <span class="text-[10px] font-bold">7</span>  // count badge
  <span class="w-2 h-2 rounded-full bg-danger shrink-0" />  // dot только если overdue/pinned/done
</button>
```

### Main canvas

**Toolbar (`TaskListToolbar`):**
```
┌──────────────────────────────────────────────────────────────────────────┐
│ [☐ checkbox all]  [Сортировка: Срок ▼]  Вид: [≡ список]  [○ Фильтры (N)]│
└──────────────────────────────────────────────────────────────────────────┘
```

- checkbox «Всё» — `checked` если все выбраны, `indeterminate` если часть
- `Сортировка` — `<select class="input text-sm py-1 pl-2 pr-6">`:
  - Срок: ближайший
  - Срок: дальний
  - Приоритет: высокий
  - Создание: новые
  - Создание: старые
  - Обновление
- `Вид` — пока только список (`bi-list`), в будущем доска (`bi-kanban`)
- `Фильтры (N)` — кнопка `btn-secondary` с `bi-funnel`. В скобках N = количество активных
  фильтров. При клике — правая панель фильтров slide-in

**TaskListItem — один элемент списка:**

```
┌──────────────────────────────────────────────────────────────────────────┐
│ [●] [☐] [★] [COLOR] [title link──────────────] [badge status] [⋯ menu]  │
│        #1042  Категория: Продажи  |  [target: Сделка «Самокат»]          │
│        Срок: 03 июн 15:00  ·  ⚡ просрочено 2 дня  ·  Приоритет: high   │
│        ████████░░ 80%  ·  Исполнитель: Иванов И.  ·  Создал: Я 01.06   │
│        Факт: 2/5ч  ·  Теги: [CRM] [Онбординг]                           │
└──────────────────────────────────────────────────────────────────────────┘
```

**Детальный breakdown каждого поля:**

1. **Priority indicator** — вертикальная полоска 4×28px слева:
   - `low` → `bg-gray-300`
   - `normal` → `bg-info`
   - `high` → `bg-warning`
   - `critical` → `bg-danger animate-pulse`

2. **Checkbox** — нативный `<input type="checkbox">` `w-4 h-4`

3. **Star (избранное)** — `bi-star` / `bi-star-fill text-warning` `cursor-pointer`

4. **Color label** — кастомная плашка `w-3 h-3 rounded-sm` inline с title (если category.color задан)

5. **Title** — `<a href="/tasks/[id]" class="text-sm font-medium hover:underline text-gray-900 dark:text-gray-100">`
   - если is_closed: `line-through text-gray-400`
   - если rejected: `line-through text-danger`

6. **Status badge** — `<span class="badge text-[10px] px-1.5 py-0.5 rounded-full ...">`:
   - `new` → `bg-info/10 text-info`
   - `in_progress` → `bg-warning/10 text-warning`
   - `done` → `bg-success/10 text-success`
   - `rejected` → `bg-danger/10 text-danger`
   - `closed` (`is_closed=true`) → `bg-gray-200 text-gray-500`

7. **Kebab-menu** `bi-three-dots-vertical` (появляется при hover) → dropdown:
   - Открыть
   - Редактировать
   - Закрепить / Открепить (`bi-pin` / `bi-pin-fill`)
   - В избранное / Убрать из избранного
   - Дублировать
   - Перенести дедлайн (+1д / +3д / +1н)
   - Отклонить (с reason input)
   - Удалить (confirm `Modal`)

8. **Вторая строка:** ID `#NNN` · Категория · Привязка к (если target)

9. **Третья строка:** дата дедлайна:
   - если просрочено: `text-danger font-medium` + `bi-clock-history` + «просрочено N дн»
   - если сегодня: `text-warning font-medium`
   - иначе: `text-gray-500`
   - Приоритет (если не normal)

10. **Четвёртая строка:** progress bar + процент + Исполнитель + Создал + дата

11. **Пятая строка:** план/факт часов (если заданы) · теги как chips

**Tailwind-классы строки:**
```
<div class="group flex items-start gap-3 px-4 py-3 border-b border-gray-100
            dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50
            transition-colors [&.selected]:bg-primary/5">
```

### Правая панель фильтров (`TaskFiltersPanel`)

Overlay справа, ширина `320px`, `fixed top-0 right-0 h-full z-20`, `translate-x-full` →
`translate-x-0` при открытии (CSS transition 200ms). При открытии — полупрозрачный
оверлей `bg-black/20` на основном контенте.

**Содержимое:**

```
[X Закрыть]  Фильтры

Категории задач
[multi-select chips с autocomplete]

Статус
[☐] Новая  [☐] В работе  [☐] Выполнена  [☐] Отклонена  [☐] Закрыта

Приоритет
[☐] Критический  [☐] Высокий  [☐] Нормальный  [☐] Низкий

Теги
[multi-select input с autocomplete]

Исполнитель
[UserSelect component]

Постановщик
[UserSelect component]

Отдел
[DepartmentSelect — select из /api/departments]

Период дедлайна
От [date input]  До [date input]

────────────────────────────────
[☐] Только мои задачи
[☐] Только мои поручения
[☐] Включить закрытые

────────────────────────────────
[Сбросить фильтры]   [Применить]
```

- `DepartmentSelect` — `<select class="input">` из `useSWR<Department[]>("/departments", fetcher)`
- Все фильтры читают/пишут в `URLSearchParams` (sharable URL)
- Кнопка «Сбросить» — `btn-ghost` сбрасывает все params
- Кнопка «Применить» — `btn-primary` закрывает панель

### States (главная)

**Loading:** skeleton 5 рядов
```jsx
{[1,2,3,4,5].map(i => (
  <div key={i} class="h-20 bg-gray-100 dark:bg-gray-700 animate-pulse rounded mx-4 my-2" />
))}
```

**Empty (пресет = Все задачи):**
```
<EmptyState
  icon="bi-clipboard-check"
  title="Пока нет задач"
  description="Создай первую задачу, чтобы начать"
  cta={<button class="btn-primary">+ Создать задачу</button>}
/>
```

**Empty (пресет с фильтром, нет совпадений):**
```
<EmptyState
  icon="bi-funnel"
  title="Нет задач по выбранным фильтрам"
  description="Попробуй изменить критерии"
  cta={<button class="btn-ghost">Сбросить фильтры</button>}
/>
```

**Error:** `<div class="text-danger text-sm px-4 py-3">Не удалось загрузить задачи. Обновить страницу.</div>`

### SWR-ключ и API

```
GET /api/activities?kind=task
  &status[]=new&status[]=in_progress
  &priority[]=high&priority[]=critical
  &category_ids[]=1&category_ids[]=2
  &tags[]=CRM
  &responsible_id=5
  &created_by_id=3
  &department_id=2
  &due_from=2026-06-01&due_to=2026-06-30
  &my_tasks=true
  &my_orders=true
  &include_closed=true
  &parent_only=true
  &sort=due_at_asc
  &limit=50&offset=0
```

Пресеты транслируются во внутренние query params:
- «Просроченные» → `overdue=true&is_closed=false`
- «Сегодня» → `due_from=YYYY-MM-DD&due_to=YYYY-MM-DD` (same day)
- «Мои задачи» → `my_tasks=true`

Счётчики пресетов: `GET /api/activities/counts-by-preset` → `{ pinned: 3, overdue: 7, ... }`

---

## Раздел 2 — `/tasks/[id]` Детальная карточка задачи

### Wireframe

```
┌───────────────────────────────────────────────────────────────────────┐
│ [← Задачи]                                                            │
│                                                                       │
│ ┌── Inline header ─────────────────────────────────────────────────┐ │
│ │ [Priority●] [Редактируемый title]  [Status dropdown] [• • •]     │ │
│ │ #1042  ·  Категория: Продажи  ·  [badge критический]             │ │
│ └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│ ┌── Tabs ────────────────────────────────────────────────────────┐   │
│ │ [Сводка] [Чек-лист (5/8)] [Файлы (2)] [Подзадачи (3)] [Связ.] [Чат] [История] │
│ └────────────────────────────────────────────────────────────────┘   │
│                                                                       │
│ ┌── Main panel (2/3 width) ──────────┬── Right sidebar (1/3) ──────┐ │
│ │                                    │                              │ │
│ │ [TAB CONTENT]                      │ Создал: Я · 01.06           │ │
│ │                                    │ ─────────────────────────── │ │
│ │                                    │ Ответственный: [UserSelect] │ │
│ │ СВОДКА:                           │ Соисполнители: [+Добавить]  │ │
│ │ Описание (inline rich text)       │ Аудиторы: [+Добавить]       │ │
│ │ Дедлайн: 03 июн 15:00 [edit]     │ Наблюдатели: [+Добавить]   │ │
│ │ План: 5ч · Факт: 2ч · -3ч       │ ─────────────────────────── │ │
│ │ Прогресс: [====░░░░] 40% [edit]  │ Привязка: Сделка Х          │ │
│ │                                    │ Контакт: Иванов И.          │ │
│ │                                    │ Повторение: каждую неделю  │ │
│ │                                    │ ─────────────────────────── │ │
│ │                                    │ Теги: [CRM] [+ добавить]    │ │
│ │                                    │ ─────────────────────────── │ │
│ │                                    │ [Закрепить] [Дублировать]   │ │
│ │                                    │ [+1д] [+3д] [+1н]           │ │
│ │                                    │ [Отклонить]                 │ │
│ └────────────────────────────────────┴──────────────────────────────┘ │
└───────────────────────────────────────────────────────────────────────┘
```

### Header задачи (`TaskDetailHeader`)

```jsx
<div class="flex items-start gap-3 px-6 py-4 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
  {/* Priority indicator */}
  <div class="w-1.5 rounded-full self-stretch mt-1 bg-[priority-color]" />
  
  {/* Title inline edit */}
  <div class="flex-1">
    <input
      class="text-h4 font-semibold bg-transparent border-none outline-none
             hover:bg-gray-50 dark:hover:bg-gray-700 focus:bg-gray-50 dark:focus:bg-gray-700
             rounded px-1 -ml-1 w-full"
      value={title}
      onBlur={saveTitle}
    />
    <div class="flex items-center gap-2 mt-1 text-sm text-gray-500">
      <span>#1042</span>
      <span>·</span>
      <span>Продажи</span>  {/* category */}
      <span>·</span>
      <span class="badge bg-danger/10 text-danger">критический</span>
    </div>
  </div>
  
  {/* Status dropdown */}
  <div class="shrink-0">
    <select class="input text-sm py-1 rounded-full">
      <option>Новая</option>
      <option>В работе</option>
      <option>Выполнена</option>
    </select>
  </div>
  
  {/* Actions */}
  <div class="flex items-center gap-1">
    <button class="btn-secondary text-sm"><i class="bi bi-pencil mr-1"/>Редактировать</button>
    {status === 'done' && <button class="btn-primary text-sm">Закрыть задачу</button>}
    {status !== 'rejected' && <button class="btn-secondary text-sm text-danger">Отклонить</button>}
  </div>
</div>
```

**Состояния кнопок по статусу:**

| Текущий статус | Доступные кнопки |
|---|---|
| `new` | «В работу», «Отклонить» |
| `in_progress` | «Выполнена», «Отклонить» |
| `done` | «Закрыть задачу», «Вернуть в работу», «Отклонить» |
| `rejected` | «Восстановить» (→ new) |
| `is_closed=true` | только просмотр, кнопок нет |

Смена статуса: `PATCH /api/activities/{id}/status` с `body: { status: "in_progress" }`.
Закрытие: `PATCH /api/activities/{id}/close` — проверит `result_text` если нужен.

### Tabs (7 вкладок)

Компонент `TaskDetailTabs` — simple tab switcher:
```
[Сводка] [Чек-лист (5/8)] [Файлы (2)] [Подзадачи (3/5)] [Связанные (1)] [Чат (4)] [История]
```
Активная вкладка: `border-b-2 border-primary text-primary font-medium`
Цифры в скобках: live count из данных задачи.

#### Вкладка «Сводка» (`TaskSummaryTab`)

```
Описание
┌──────────────────────────────────────────────────────┐
│ [Кликабельная область inline edit]                    │
│ Сейчас: placeholder «Добавить описание...»            │
│ При клике → textarea с auto-resize                    │
│ Blur → сохранить PATCH /api/activities/{id}          │
└──────────────────────────────────────────────────────┘

Дедлайн
┌─────────────────────────────────────┐
│ 03 июн 2026, 15:00  [✎]            │
│ (красный если просрочено)           │
└─────────────────────────────────────┘
→ при клике на ✎: datetime-local input в той же строке

Время
┌────────────────────────────────────────────────┐
│ Плановое: [5ч input]  Фактическое: [2ч input] │
│ Разница: -3ч (text-danger) / +1ч (text-success)│
└────────────────────────────────────────────────┘

Прогресс (только если status=in_progress или done)
┌────────────────────────────────────────────────┐
│ [0%──────────────── 100%] 40%                  │
│ <input type="range" min=0 max=100> + число     │
│ Сохранение onMouseUp/onTouchEnd                │
└────────────────────────────────────────────────┘

Результат (если status=done OR is_closed + category.restrict_close_without_result)
┌────────────────────────────────────────────────┐
│ Результат работы *                              │
│ <textarea class="input min-h-[80px]"           │
│ placeholder="Опиши результат...">              │
│ Обязательное поле для закрытия задачи          │
└────────────────────────────────────────────────┘
```

#### Вкладка «Чек-лист» (`TaskChecklistTab`)

```
Прогресс: 5 из 8 пунктов ■■■■■░░░ 62%

┌─────────────────────────────────────────────────┐
│ [☑] Провести созвон с клиентом                  │
│ [☑] Подготовить презентацию                     │
│ [☑] Согласовать бюджет                          │
│ [☐] Отправить КП                                │ ← drag handle ⠿ слева
│ [☐] Получить обратную связь                     │
└─────────────────────────────────────────────────┘

[+ Добавить пункт]
<input placeholder="Название пункта..." onEnter=addItem>
```

- Checkbox click → `PATCH /api/activities/{id}/checklist/{item_id}` `{is_done: true}`
- Drag-and-drop reorder (без библиотеки, через `draggable` HTML5 или `@dnd-kit/core`)
- Delete пункта: иконка `bi-x` появляется при hover, `DELETE /api/activities/{id}/checklist/{item_id}`
- Inline edit: клик на текст пункта → `<input>` прямо там

#### Вкладка «Файлы» (`TaskFilesTab`)

```
┌── Drag-drop zone ────────────────────────────────┐
│ bi-cloud-upload text-4xl text-gray-300           │
│ «Перетащи файлы или нажми для выбора»            │
│ [Выбрать файлы]  (accept="*")                    │
└──────────────────────────────────────────────────┘

Прикреплённые файлы (2):
┌─────────────────────────────────────────────────┐
│ bi-file-pdf text-danger  presentation.pdf  2Мб  │  [↓] [bi-trash text-danger]
│ bi-image text-success  screenshot.png  340Кб   │  [↓] [bi-trash text-danger]
└─────────────────────────────────────────────────┘
```

Upload: `POST /api/activities/{id}/attachments` multipart/form-data
Delete: `DELETE /api/activities/{id}/attachments/{attachment_id}` (только creator/admin)
Download: `GET /api/activities/{id}/attachments/{attachment_id}/download`

#### Вкладка «Подзадачи» (`TaskSubtasksTab`)

Показывается только если задача является parent или имеет subtasks.

```
Roll-up: 3 выполнено · 2 в работе · 0 отклонено = 5 всего
Плановое время: 12ч · Фактическое: 7ч

[TaskSubtaskRow: компактная версия TaskListItem]
● Провести аудит базы данных         [in_progress] 50%
● Написать отчёт                      [new]         —
● Согласовать с руководителем         [done]       100%

[+ Добавить подзадачу]  → открывает TaskCreateDrawer с parent_activity_id pre-filled
```

Roll-up статистика: computed из subtask statuses.

#### Вкладка «Связанные» (`TaskRelatedTab`)

```
Связанные задачи (1):
┌─────────────────────────────────────────────────────┐
│ bi-link  #1041  Согласовать договор с партнёром     │  [x развязать]
└─────────────────────────────────────────────────────┘

[+ Связать задачу]  → autocomplete input /api/activities?kind=task&search=...
```

Linked: `POST /api/activities/{id}/related` `{related_activity_id: 1041}`
Unlink: `DELETE /api/activities/{id}/related/{related_id}`

#### Вкладка «Чат» (`TaskChatTab`)

Комментарии в хронологическом порядке + reply. Если в будущем будет
`ActivityComment` модель — использовать её. MVP: вкладка показывает
`body`-историю Activity + inline form для добавления комментариев через
`PATCH /api/activities/{id}` расширение или отдельный `POST /api/activities/{id}/comments`.

Структура каждого комментария:
```
[Avatar] Иванов И. · 01 июн 14:30
         Текст комментария...
         [Ответить]
```

#### Вкладка «История» (`TaskHistoryTab`)

Audit log из `EntityAuditLog` (если есть) или inline-лог:
```
01 июн 15:00  Иванов И. изменил статус: new → in_progress
02 июн 10:00  Петров С. добавил чек-пункт «Согласовать бюджет»
02 июн 11:00  Иванов И. изменил дедлайн: 01 июн → 03 июн
```

Каждая строка: дата + автор + действие. `GET /api/activities/{id}/history`

### Right sidebar (`TaskDetailSidebar`)

**Блок «Участники»:**
```
Создал
[Avatar 24px] Иванов И. · 01 июн 2026

Ответственный
[UserSelect value=responsible_id onChange=PATCH /responsible_id]
(редактируемый если текущий юзер — создатель или admin/director)

Соисполнители
[Avatar] Петров С.  [x]
[+ Добавить соисполнителя]  → UserSelect → POST /api/activities/{id}/collaborators {role:"co_executor"}

Аудиторы
[+ Добавить аудитора]

Наблюдатели
[+ Добавить наблюдателя]
```

**Блок «Привязка»:**
```
Привязка
[badge: Сделка] Самокат Pro 2026  [→ /deals/123]

Контакт
[badge: Контакт] Сергей Иванов  [→ /contacts/45]
```

**Блок «Повторение»:** (если recurrence_rule задан)
```
Повторение
bi-arrow-repeat  Каждую неделю до 31 дек 2026
[Изменить]  [Отключить]
```

**Блок «Теги»:**
```
Теги
[CRM] [Онбординг] [+ добавить тег]
→ input с autocomplete GET /api/activities/tags?q=...
```

**Блок «Быстрые действия»:**
```
[bi-pin  Закрепить]         btn-ghost text-sm
[bi-star  В избранное]      btn-ghost text-sm
[bi-copy  Дублировать]      btn-ghost text-sm
[bi-link  Связать]          btn-ghost text-sm

Перенести дедлайн
[+1 день] [+3 дня] [+1 неделя]   3 кнопки btn-secondary text-xs
→ POST /api/activities/{id}/extend-deadline {days: 1, reason: "..."}

[bi-x-circle text-danger  Отклонить]  btn-ghost text-sm text-danger
→ Modal с textarea «Причина отклонения» (required)
→ PATCH /api/activities/{id}/status {status: "rejected", reject_reason: "..."}
```

### States (детальная)

**Loading:** skeleton layout — серые прямоугольники
```jsx
<div class="animate-pulse space-y-4 p-6">
  <div class="h-8 bg-gray-200 dark:bg-gray-700 rounded w-3/4" />
  <div class="h-4 bg-gray-100 dark:bg-gray-700/50 rounded w-1/2" />
  <div class="h-32 bg-gray-100 dark:bg-gray-700/50 rounded" />
</div>
```

**Not found:** `<EmptyState icon="bi-question-circle" title="Задача не найдена" description="Возможно, она была удалена" cta={<Link href="/tasks" class="btn-primary">К списку задач</Link>} />`

**Error:** inline `text-danger` в верхней части страницы

---

## Раздел 3 — `TaskCreateDrawer` (Drawer справа, wide)

### Wireframe

```
┌─── Drawer (640px wide, fixed right) ──────────────────────────────────┐
│ Новая задача                                              [X закрыть]  │
├───────────────────────────────────────────────────────────────────────┤
│ Тип                                                                   │
│ [Задача] [Звонок] [Встреча] [Заметка]  ← сегментный switcher         │
│                                                                       │
│ Название *                                                            │
│ [input placeholder="Что нужно сделать..."]                            │
│                                                                       │
│ Дедлайн *                  Категория                                  │
│ [datetime-local input]     [select categories]                        │
│                                                                       │
│ Ответственный                                                         │
│ [UserSelect default=me]                                               │
│                                                                       │
│ ▶ Больше параметров                                                   │
│                                                                       │
│ (при раскрытии):                                                      │
│ Приоритет                                                             │
│ ○ Низкий  ● Нормальный  ○ Высокий  ○ Критический                     │
│                                                                       │
│ Соисполнители           Аудиторы            Наблюдатели               │
│ [multi UserSelect]      [multi UserSelect]  [multi UserSelect]        │
│                                                                       │
│ Плановое время          Описание                                      │
│ [input type=number]ч    [textarea]                                    │
│                                                                       │
│ Чек-лист                                                              │
│ [+ Добавить пункт] → inline items list                                │
│                                                                       │
│ Привязать к                                                           │
│ [select: Лид/Сделка/...] [autocomplete input]                         │
│                                                                       │
│ Теги                                                                  │
│ [multi chips input с autocomplete]                                    │
│                                                                       │
│ Повторение                                                            │
│ [RecurrenceSelector component]                                        │
│                                                                       │
│ Файлы                                                                 │
│ [drag-drop zone compact]                                              │
│                                                                       │
├───────────────────────────────────────────────────────────────────────┤
│ [Отмена]      [Создать и открыть]           [Создать]                 │
└───────────────────────────────────────────────────────────────────────┘
```

### Реализация Drawer

Технически — тот же паттерн что `LessonEditorDrawer`: `createPortal` в `document.body`,
`fixed inset-y-0 right-0 z-40`, ширина `w-[640px]`, backdrop `bg-black/40`.

```jsx
// Drawer wrapper
<div class="fixed inset-0 z-40">
  <div class="absolute inset-0 bg-black/40" onClick={tryClose} />
  <div class="absolute inset-y-0 right-0 w-[640px] bg-white dark:bg-gray-800
              shadow-xl flex flex-col overflow-hidden">
    {/* header */}
    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
      <h2 class="text-h4">Новая задача</h2>
      <button onClick={tryClose}><i class="bi bi-x-lg text-xl"/></button>
    </div>
    {/* scrollable body */}
    <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
      {/* fields */}
    </div>
    {/* sticky footer */}
    <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700
                bg-gray-50 dark:bg-gray-900 flex items-center justify-end gap-2">
      <button class="btn-ghost">Отмена</button>
      <button class="btn-secondary" onClick={createAndOpen}>Создать и открыть</button>
      <button class="btn-primary" disabled={submitting}>
        {submitting ? "Создаём…" : "Создать"}
      </button>
    </div>
  </div>
</div>
```

### Поля формы (`TaskCreateForm`)

**Тип задачи** — 4 кнопки как в `ActivityForm`:
```jsx
<div class="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden text-sm">
  {["task","call","meeting","note"].map(k => (
    <button
      class={kind === k
        ? "px-4 py-2 bg-primary text-white"
        : "px-4 py-2 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700"}
    >
      {KIND_LABELS[k]}
    </button>
  ))}
</div>
```

**Название** — `<input class="input" placeholder="Что нужно сделать..." />` required

**Дедлайн** — `<input type="datetime-local" class="input" />` required для kind=task|meeting|call

**Категория** — `<select class="input">` из `useSWR("/task-categories", fetcher)`.
При выборе категории — автозаполнение:
- Ответственный ← `category.default_executor_id`
- Соисполнители ← `category.default_co_executor_ids`
- Аудиторы ← `category.default_auditor_ids`
- Наблюдатели ← `category.default_observer_ids`
- Чек-лист items ← `category.checklist_template_items`
- Описание ← `category.description_template`
Автозаполнение можно вручную переопределить.

**Ответственный** — `<UserSelect>` default=me.id

**«Больше параметров»** — `<button class="flex items-center gap-1 text-sm text-gray-500 hover:text-primary">`:
- `bi-chevron-right` → при клике ставит `bi-chevron-down` + раскрывает блок с `transition-all`

**Приоритет** — 4 radio-кнопки:
```jsx
{["low","normal","high","critical"].map(p => (
  <label class="flex items-center gap-1.5 cursor-pointer">
    <input type="radio" name="priority" value={p} />
    <span class={PRIORITY_DOT[p]} />
    {PRIORITY_LABELS[p]}
  </label>
))}
```

**Повторение** — компонент `RecurrenceSelector` (раздел 5)

**Привязать к сущности:**
```jsx
<div class="grid grid-cols-2 gap-2">
  <select class="input">  {/* Лид / Сделка / Контакт / Компания / Договор / Подписка */}
  <input class="input" placeholder="Поиск..." autocomplete />
</div>
```

**FTM-секция** (если kind=meeting):
```jsx
<div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 space-y-3">
  <label class="flex items-center gap-2 cursor-pointer">
    <input type="checkbox" checked={isFtm} />
    <span class="text-sm">Это первая встреча с клиентом (FTM)</span>
  </label>
  {isFtm && (
    <>
      <label class="flex items-center gap-2"><input type="checkbox" /> Decision maker присутствовал</label>
      <label class="flex items-center gap-2"><input type="checkbox" /> Презентация показана</label>
      <div>
        <label class="label">Отчёт (ссылка)</label>
        <input class="input" placeholder="https://..." />
      </div>
    </>
  )}
</div>
```

### States (drawer)

- **Submitting:** кнопка «Создать» disabled + «Создаём…»
- **Error:** inline `<div class="text-danger text-sm bg-danger/10 rounded px-3 py-2">` над footer
- **isDirty guard:** при попытке закрыть с незаполненной формой → тот же `confirmOpen` что в `Modal`

### API

`POST /api/activities` с расширенным телом:
```json
{
  "kind": "task",
  "title": "Подготовить КП",
  "due_at": "2026-06-05T15:00:00Z",
  "category_id": 3,
  "responsible_id": 5,
  "priority": "high",
  "planned_hours": 5.0,
  "body": "Описание задачи",
  "tags": ["CRM", "Онбординг"],
  "recurrence_rule": "weekly",
  "recurrence_until": "2026-12-31",
  "target_type": "deal",
  "target_id": 123,
  "collaborators": [
    {"user_id": 6, "role": "co_executor"},
    {"user_id": 7, "role": "auditor"}
  ],
  "checklist_items": [
    {"text": "Написать текст", "sort_order": 0},
    {"text": "Согласовать", "sort_order": 1}
  ],
  "is_first_time_meeting": false
}
```

---

## Раздел 4 — `/admin/task-categories` Управление категориями

### Wireframe

```
┌────────────────────────────────────────────────────────────────────────┐
│ [Sidebar] │ [PageHeader: Категории задач]      [+ Создать категорию]  │
│           ├────────────────────────────────────────────────────────────┤
│           │ [Search input placeholder="Поиск по названию..."]  [Фильтр: Активные ▼] │
│           │                                                            │
│           │ ┌─ CategoryCard ───────────────────────────────────────┐  │
│           │ │ ⠿ [COLOR dot] Продажи                  [Активна ▼]   │  │
│           │ │ Описание: Задачи для отдела продаж                   │  │
│           │ │ Ответственный: Иванов И.                             │  │
│           │ │ Чек-лист по умолчанию: 3 пункта                     │  │
│           │ │ [✎ Редактировать]  [bi-trash Удалить]               │  │
│           │ └──────────────────────────────────────────────────────┘  │
│           │ ┌─ CategoryCard ───────────────────────────────────────┐  │
│           │ │ ⠿ [COLOR dot] Звонки                   [Активна ▼]   │  │
│           │ └──────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────┘
```

### Компонент `TaskCategoryCard`

Карточка-аккордеон: свёрнутая показывает имя + brief, развёрнутая — полную форму редактирования.

**Свёрнутый вид:**
```jsx
<div class="card p-4 flex items-center gap-3 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50">
  <i class="bi bi-grip-vertical text-gray-400 cursor-grab" />  {/* drag handle */}
  <span class="w-3 h-3 rounded-full" style={{background: category.color}} />
  <span class="flex-1 font-medium">{category.name}</span>
  <span class="text-sm text-gray-500">{category.checklist_items_count} чек-пунктов</span>
  <span class={`badge ${category.is_active ? "bg-success/10 text-success" : "bg-gray-200 text-gray-500"}`}>
    {category.is_active ? "Активна" : "Неактивна"}
  </span>
  <button class="btn-ghost text-sm" onClick={openEdit}>✎ Редактировать</button>
  <button class="btn-ghost text-sm text-danger" onClick={confirmDelete}>bi-trash</button>
</div>
```

**Форма редактирования** (открывается в `Modal` width="lg"):

```
Название *
[input]

Описание-шаблон
[textarea placeholder="Шаблон описания новых задач этой категории..."]

Цвет категории
[input type="color" w-10 h-10]

─────────────────────────────────────────────────────────────
Участники по умолчанию

Ответственный               Администратор категории
[UserSelect]                [UserSelect]

Соисполнители
[multi UserSelect — список user chips с [x]]
[+ Добавить]

Аудиторы
[multi UserSelect]
[+ Добавить]

Наблюдатели
[multi UserSelect]
[+ Добавить]

─────────────────────────────────────────────────────────────
Чек-лист по умолчанию

[⠿] [Написать текст КП...        ] [x]
[⠿] [Согласовать с руководителем] [x]
[⠿] [Отправить клиенту          ] [x]

[+ Добавить пункт]

─────────────────────────────────────────────────────────────
Ограничения

Минимум файлов для закрытия: [input type=number min=0]
[☐] Запрещать закрытие без указания результата
[☐] Автоматически заполнять название из шаблона

Порядок сортировки: [input type=number]
[☐] Активна

─────────────────────────────────────────────────────────────
                              [Отмена]  [Сохранить]
```

**Multi UserSelect для соисполнителей:**
Список из `<UserSelect>` chips. При добавлении — новый `<UserSelect>`. При удалении — `[x]` рядом.
```jsx
<div class="flex flex-wrap gap-2">
  {coExecutors.map(u => (
    <span class="flex items-center gap-1 bg-gray-100 dark:bg-gray-700 rounded-full px-3 py-1 text-sm">
      {u.full_name}
      <button onClick={() => removeCoExec(u.id)}><i class="bi bi-x text-xs"/></button>
    </span>
  ))}
  <UserSelect value="" onChange={addCoExec} placeholder="+ Добавить" className="input text-sm py-1 w-40" />
</div>
```

**Drag-and-drop сортировки категорий** — `@dnd-kit/core` (уже в проекте для Sidebar).

### States (admin)

**Loading:** 3 skeleton cards `animate-pulse`
**Empty:**
```jsx
<EmptyState
  icon="bi-tags"
  title="Нет категорий задач"
  description="Создай первую категорию, чтобы стандартизировать задачи команды"
  cta={<button class="btn-primary">+ Создать категорию</button>}
/>
```
**Delete confirm:** `Modal` с текстом «Удалить категорию «Продажи»? Все задачи этой категории сохранятся, но потеряют привязку к ней.»

### API

```
GET    /api/task-categories                 → list
POST   /api/task-categories                 → create
PATCH  /api/task-categories/{id}            → update
DELETE /api/task-categories/{id}            → delete (403 если есть активные задачи)
PATCH  /api/task-categories/reorder         → [{id, sort_order}]
```

Доступ: только `admin | director`

---

## Раздел 5 — `RecurrenceSelector` Reusable компонент

**Использование:** в `TaskCreateDrawer` и в `TaskDetailSidebar` для редактирования

### Wireframe

```
Повторение
[select: Нет повторений ▼]

При выборе не "Нет":

Повторять  [Ежедневно ▼]   до  [date input]

ℹ️ При повторении создаются отдельные независимые копии задачи.
   Изменение одной не затрагивает другие.

⚠️ (если date > конец следующего года): Максимальный период — до конца следующего года.
```

### Props типизация

```typescript
interface RecurrenceSelectorProps {
  rule: "none" | "daily" | "weekly" | "monthly";
  until: string | null;  // ISO date YYYY-MM-DD
  onChange: (rule: "none" | "daily" | "weekly" | "monthly", until: string | null) => void;
}
```

### Реализация

```jsx
export function RecurrenceSelector({ rule, until, onChange }: RecurrenceSelectorProps) {
  const maxDate = endOfNextYear(); // YYYY-12-31

  return (
    <div class="space-y-3">
      <div>
        <label class="label">Повторение</label>
        <select
          class="input"
          value={rule}
          onChange={e => onChange(e.target.value as Rule, until)}
        >
          <option value="none">Нет повторений</option>
          <option value="daily">Ежедневно</option>
          <option value="weekly">Еженедельно</option>
          <option value="monthly">Ежемесячно</option>
        </select>
      </div>

      {rule !== "none" && (
        <div class="flex items-center gap-3">
          <span class="text-sm text-gray-600 dark:text-gray-400">до</span>
          <input
            type="date"
            class="input w-auto"
            value={until ?? ""}
            max={maxDate}
            onChange={e => onChange(rule, e.target.value || null)}
          />
        </div>
      )}

      {rule !== "none" && (
        <p class="text-xs text-gray-500 dark:text-gray-400">
          При повторении создаются отдельные независимые копии задачи.
          Изменение одной не затрагивает другие.
        </p>
      )}

      {rule !== "none" && until && new Date(until) > new Date(maxDate) && (
        <p class="text-xs text-warning">
          <i class="bi bi-exclamation-triangle mr-1"/>
          Максимальный период — до конца следующего года.
        </p>
      )}
    </div>
  );
}
```

---

## Раздел 6 — `TaskGroupView` Группировка parent + subtasks

### Логика навигации

- Открытие `/tasks/[id]` где задача **является подзадачей** (`parent_activity_id != null`) →
  страница показывает задачу нормально, но в right sidebar добавляется блок:
  ```
  Часть группы
  → [Parent task title]  bi-arrow-up-right
  ```

- Открытие `/tasks/[id]` где задача **является родителем** (`has_subtasks=true`) →
  вкладка «Подзадачи» становится активной по умолчанию

### Roll-up статистика (`TaskGroupRollup`)

```jsx
<div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 space-y-3">
  {/* Статусы */}
  <div class="flex flex-wrap gap-3 text-sm">
    <span class="flex items-center gap-1.5">
      <span class="w-2 h-2 rounded-full bg-success"/>
      <span>{stats.done} выполнено</span>
    </span>
    <span class="flex items-center gap-1.5">
      <span class="w-2 h-2 rounded-full bg-warning"/>
      <span>{stats.in_progress} в работе</span>
    </span>
    <span class="flex items-center gap-1.5">
      <span class="w-2 h-2 rounded-full bg-danger"/>
      <span>{stats.rejected} отклонено</span>
    </span>
    <span class="text-gray-500">= {stats.total} всего</span>
  </div>

  {/* Общий прогресс */}
  <div class="flex items-center gap-3">
    <div class="flex-1 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
      <div class="h-full bg-primary" style={{width: `${stats.completion_pct}%`}} />
    </div>
    <span class="text-sm font-medium tabular-nums">{stats.completion_pct}%</span>
  </div>

  {/* Часы */}
  {(stats.total_planned_hours > 0 || stats.total_actual_hours > 0) && (
    <div class="flex gap-4 text-sm text-gray-600 dark:text-gray-400">
      <span>Плановое время: <b>{stats.total_planned_hours}ч</b></span>
      <span>Фактическое: <b>{stats.total_actual_hours}ч</b></span>
    </div>
  )}
</div>
```

### Список подзадач (`TaskSubtaskList`)

Компактная версия `TaskListItem` без Priority bar и некоторых метаданных:
```jsx
<div class="space-y-0.5">
  {subtasks.map(t => (
    <div class="flex items-center gap-2 py-2 px-3 rounded-md hover:bg-gray-50 dark:hover:bg-gray-800/50">
      <StatusDot status={t.status} />
      <Link href={`/tasks/${t.id}`} class="flex-1 text-sm hover:underline truncate">
        {t.title}
      </Link>
      <span class="text-xs text-gray-500 tabular-nums w-8 text-right">{t.progress_pct}%</span>
      {t.responsible_name && (
        <span class="text-xs text-gray-500 truncate max-w-[80px]">{t.responsible_name}</span>
      )}
      <StatusBadge status={t.status} />
    </div>
  ))}
</div>
```

**«+ Добавить подзадачу»** → открывает `TaskCreateDrawer` с pre-filled:
```
parent_activity_id = currentTask.id
target_type = currentTask.target_type  // наследует от parent
target_id = currentTask.target_id
```

---

## Раздел 7 — Bulk Operations

### `BulkActionsBar` компонент

Sticky внизу main canvas, появляется когда `selectedIds.length > 0`:

```jsx
<div class={`
  fixed bottom-0 left-[240px] right-0 z-30
  bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700
  px-6 py-3 flex items-center gap-3 shadow-lg
  transition-transform duration-200
  ${selectedIds.length > 0 ? "translate-y-0" : "translate-y-full"}
`}>
  <span class="text-sm font-medium">
    Выбрано: {selectedIds.length}
  </span>
  <div class="h-4 w-px bg-gray-300 dark:bg-gray-600"/>

  <button class="btn-secondary text-sm" onClick={openBulkDeadline}>
    <i class="bi bi-calendar2-check mr-1.5"/>
    Изменить дедлайн
  </button>

  <button class="btn-secondary text-sm" onClick={openBulkAssign}>
    <i class="bi bi-person-check mr-1.5"/>
    Переназначить
  </button>

  <button class="btn-secondary text-sm" onClick={confirmBulkClose}>
    <i class="bi bi-check2-all mr-1.5"/>
    Закрыть все
  </button>

  <button class="btn-secondary text-sm text-danger" onClick={confirmBulkDelete}>
    <i class="bi bi-trash mr-1.5"/>
    Удалить все
  </button>

  <button class="btn-ghost text-sm ml-auto" onClick={clearSelection}>
    Отмена выбора
  </button>
</div>
```

### Bulk modals

**«Изменить дедлайн»:**
```
Modal title="Изменить дедлайн для N задач"
Новый дедлайн *
[datetime-local input]
[Отмена] [Сохранить]
```

**«Переназначить»:**
```
Modal title="Переназначить N задач"
Новый ответственный *
[UserSelect]
[Отмена] [Сохранить]
```

**«Закрыть все»:**
```
Modal title="Закрыть N задач?"
Внимание: если среди задач есть с обязательным результатом, они будут пропущены.
[Отмена] [Закрыть задачи]
```

**«Удалить все»:**
```
Modal title="Удалить N задач?"
Это действие нельзя отменить. Задачи и их подзадачи будут удалены.
[Отмена] [Удалить всё]  ← btn-primary text-danger
```

### API

```
POST /api/activities/bulk
{
  "action": "change_deadline" | "reassign" | "close" | "delete",
  "ids": [1, 2, 3],
  "payload": {
    "due_at": "2026-06-10T15:00:00Z",  // для change_deadline
    "responsible_id": 5,                // для reassign
  }
}
```

Response: `{ success_count, failed_count, skipped_ids }`

---

## Раздел 8 — FTM-расширение в `TaskCreateDrawer`

Это расширение существующей FTM-логики (Эпик 10.5) для нового drawer:

**Условие отображения:** только если `kind === "meeting"`.

**Секция FTM:**
```jsx
<div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
  <label class="flex items-center gap-2 cursor-pointer select-none">
    <input
      type="checkbox"
      checked={form.is_first_time_meeting}
      onChange={e => setForm({...form, is_first_time_meeting: e.target.checked})}
    />
    <span class="text-sm font-medium">Это первая встреча с клиентом (FTM)</span>
    <span class="badge bg-info/10 text-info text-[10px]">FTM</span>
  </label>

  {form.is_first_time_meeting && (
    <div class="pl-6 space-y-2">
      <label class="flex items-center gap-2 cursor-pointer text-sm">
        <input type="checkbox" checked={form.ftm_decision_maker_attended}
          onChange={...} />
        Decision maker присутствовал
      </label>
      <label class="flex items-center gap-2 cursor-pointer text-sm">
        <input type="checkbox" checked={form.ftm_presentation_shown}
          onChange={...} />
        Презентация показана
      </label>
      <div>
        <label class="label">Отчёт (ссылка)</label>
        <input
          class="input"
          placeholder="https://..."
          value={form.ftm_report_url}
          onChange={e => setForm({...form, ftm_report_url: e.target.value})}
        />
        <p class="text-xs text-gray-500 mt-1">
          Ссылка на отчёт в AmoCRM или внутренней системе
        </p>
      </div>
    </div>
  )}
</div>
```

**Уже реализованные поля** в `Activity` модели (Эпик 10.5):
- `is_first_time_meeting`
- `ftm_decision_maker_attended`
- `ftm_presentation_shown`
- `ftm_report_url`
- `ftm_telegram_announced`

Все поля уже есть в `ActivityOut` Pydantic-схеме и `ActivityUpdate`. Frontend просто
добавляет их в payload `POST /api/activities`.

---

## Sidebar — изменения в `Sidebar.tsx`

### Добавить в `SALES_ITEMS` перед или после «Лидов»:

```typescript
// Вставить после { href: "/leads", ... }:
{ href: "/tasks", icon: "bi-clipboard-check", label: "Задачи" },
```

Вместе с badge «N открытых» аналогично `NavItemWithBadge`:
```typescript
// hook в Sidebar:
function useOpenTasksCount(): number {
  const { data } = useSWR<{ count: number }>(
    "/activities/my-open-count",  // GET /api/activities/my-open-count
    fetcher,
    { refreshInterval: 60_000 }
  );
  return data?.count ?? 0;
}
```

Строка в navigation:
```jsx
<NavItemWithBadge
  item={{ href: "/tasks", icon: "bi-clipboard-check", label: "Задачи" }}
  pathname={pathname}
  badgeCount={openTasksCount > 0 ? openTasksCount : undefined}
  badgeColor={openTasksCount > 0 ? "bg-warning" : "bg-info"}
/>
```

### Добавить в `ADMIN_ITEMS`:

```typescript
{ href: "/admin/task-categories", icon: "bi-tags", label: "Категории задач",
  roles: ["admin", "director"] },
```

Вставить после `bi-archive-fill Bulk-задачи`.

---

## Адаптивность

**Desktop-first** (основная аудитория — менеджеры на ноутбуках 1440px).

**Mobile breakpoints (TBD — будущий эпик 10 mobile):**
- Левая sidebar пресетов (`TaskPresetSidebar`) — на mobile сворачивается в `<select>` или
  drawer снизу `bottom-sheet`
- Правая панель фильтров (`TaskFiltersPanel`) — на mobile занимает 100% ширины
- `TaskListItem` — на mobile упрощается до 2 строк (title + due + status)
- `BulkActionsBar` — на mobile `left-0` (без Sidebar offset)
- `TaskCreateDrawer` — на mobile 100vw
- `TaskDetailPage` — main panel full-width, right sidebar → tab «Детали» или collapsible

**Сейчас реализовывать mobile-режим не нужно.** Отмечаем только где будет `md:` breakpoint.

---

## Список новых и изменённых файлов (28 файлов)

### Новые страницы

| Файл | Описание |
|---|---|
| `apps/web/src/app/(app)/tasks/page.tsx` | Главная страница задач |
| `apps/web/src/app/(app)/tasks/[id]/page.tsx` | Детальная карточка задачи |
| `apps/web/src/app/(app)/admin/task-categories/page.tsx` | Управление категориями |

### Новые компоненты (`apps/web/src/components/Tasks/`)

| Файл | Описание |
|---|---|
| `TaskPresetSidebar.tsx` | Левая sidebar с пресетами + счётчиками |
| `TaskListToolbar.tsx` | Toolbar над списком (checkbox all + sort + filter btn) |
| `TaskListItem.tsx` | Одна строка задачи в списке (priority bar, checkbox, star, etc) |
| `TaskFiltersPanel.tsx` | Правая sliding-панель с фильтрами (14 критериев) |
| `TaskCreateDrawer.tsx` | Drawer создания задачи (всё в одном) |
| `TaskCreateForm.tsx` | Внутренняя форма drawer (поля + валидация) |
| `TaskDetailHeader.tsx` | Header на странице задачи (inline title edit, status dropdown) |
| `TaskDetailTabs.tsx` | Tab switcher (7 вкладок) |
| `TaskSummaryTab.tsx` | Вкладка Сводка (описание, дедлайн, часы, прогресс, результат) |
| `TaskChecklistTab.tsx` | Вкладка Чек-лист (items с drag-reorder) |
| `TaskFilesTab.tsx` | Вкладка Файлы (drag-drop upload + список) |
| `TaskSubtasksTab.tsx` | Вкладка Подзадачи (roll-up + compact list) |
| `TaskRelatedTab.tsx` | Вкладка Связанные задачи |
| `TaskChatTab.tsx` | Вкладка Чат / Комментарии |
| `TaskHistoryTab.tsx` | Вкладка История / Audit log |
| `TaskDetailSidebar.tsx` | Правый sidebar карточки (участники, привязка, теги, действия) |
| `TaskGroupRollup.tsx` | Roll-up статистика группы задач |
| `TaskSubtaskList.tsx` | Компактный список подзадач |
| `TaskCategoryCard.tsx` | Карточка категории в admin (свёрнутая + expanded форма) |
| `TaskCategoryForm.tsx` | Форма редактирования/создания категории (в Modal) |
| `RecurrenceSelector.tsx` | Выбор повторяемости задачи (reusable) |
| `BulkActionsBar.tsx` | Sticky toolbar bulk-операций |
| `BulkDeadlineModal.tsx` | Modal для bulk смены дедлайна |
| `BulkAssignModal.tsx` | Modal для bulk переназначения |
| `TaskStatusBadge.tsx` | Badge статуса задачи (reusable, используется в нескольких местах) |
| `TaskPriorityIndicator.tsx` | Цветная полоска / dot приоритета (reusable) |

### Изменённые файлы

| Файл | Что меняется |
|---|---|
| `apps/web/src/components/Sidebar.tsx` | Добавить `/tasks` в SALES_ITEMS + badge + `/admin/task-categories` в ADMIN_ITEMS |
| `apps/web/src/lib/types.ts` | Расширить `Activity` interface новыми полями (status, priority, progress_pct и др.) + новые типы `TaskCategory`, `ActivityCollaborator`, `ChecklistItem`, `ActivityAttachment` |
| `apps/web/src/components/Timeline/ActivityForm.tsx` | Добавить FTM-поля (уже есть частично из Эпик 10.5, дополнить is_first_time_meeting секцией) |

---

## Новые типы TypeScript в `types.ts`

```typescript
// Расширение Activity (поля добавляются к существующему interface Activity)
export type ActivityStatus = "new" | "in_progress" | "done" | "rejected";
export type ActivityPriority = "low" | "normal" | "high" | "critical";
export type CollaboratorRole = "co_executor" | "auditor" | "observer";
export type RecurrenceRule = "daily" | "weekly" | "monthly";

// Добавить в interface Activity:
//   status?: ActivityStatus | null
//   is_closed?: boolean
//   priority?: ActivityPriority | null
//   category_id?: number | null
//   category_name?: string | null
//   parent_activity_id?: number | null
//   progress_pct?: number | null
//   planned_hours?: number | null
//   actual_hours?: number | null
//   result_text?: string | null
//   tags?: string[]
//   recurrence_rule?: RecurrenceRule | null
//   recurrence_until?: string | null
//   recurrence_parent_id?: number | null
//   is_pinned?: boolean
//   is_favorite?: boolean
//   collaborators?: ActivityCollaborator[]
//   checklist_items?: ChecklistItem[]

export interface ActivityCollaborator {
  id: number;
  user_id: number;
  user_name: string;
  role: CollaboratorRole;
}

export interface ChecklistItem {
  id: number;
  activity_id: number;
  text: string;
  is_done: boolean;
  sort_order: number;
}

export interface ActivityAttachment {
  id: number;
  activity_id: number;
  filename: string;
  url: string;
  size_bytes: number | null;
  uploaded_by_name: string | null;
  created_at: string;
}

export interface TaskCategory {
  id: number;
  name: string;
  description_template: string | null;
  color: string | null;
  default_executor_id: number | null;
  default_executor_name: string | null;
  default_co_executor_ids: number[];
  default_auditor_ids: number[];
  default_observer_ids: number[];
  checklist_template_items: { text: string; sort_order: number }[];
  required_files_count: number;
  restrict_close_without_result: boolean;
  auto_title_from_category: boolean;
  sort_order: number;
  is_active: boolean;
  checklist_items_count: number;
}

export const ACTIVITY_STATUS_LABELS: Record<ActivityStatus, string> = {
  new: "Новая",
  in_progress: "В работе",
  done: "Выполнена",
  rejected: "Отклонена",
};

export const ACTIVITY_STATUS_COLORS: Record<ActivityStatus, string> = {
  new: "bg-info/10 text-info",
  in_progress: "bg-warning/10 text-warning",
  done: "bg-success/10 text-success",
  rejected: "bg-danger/10 text-danger",
};

export const ACTIVITY_PRIORITY_LABELS: Record<ActivityPriority, string> = {
  low: "Низкий",
  normal: "Нормальный",
  high: "Высокий",
  critical: "Критический",
};

export const ACTIVITY_PRIORITY_COLORS: Record<ActivityPriority, string> = {
  low: "bg-gray-300",
  normal: "bg-info",
  high: "bg-warning",
  critical: "bg-danger",
};
```

---

## Все тексты (RU, готовые для копи-паст в JSX)

### Общие

- Заголовок страницы `/tasks`: `Задачи`
- Counter в header: `{N} открытых`
- Кнопка создания: `+ Создать задачу`

### Пресеты (левая sidebar)

- `Закреплённые`
- `Просроченные`
- `Готово, не закрыто`
- `Сегодня`
- `Эта неделя`
- `Следующая неделя`
- `Будущие`
- `Мои задачи`
- `Мои поручения`
- `Все задачи`

### Сортировка

- `Срок: ближайший`
- `Срок: дальний`
- `Приоритет: высокий`
- `Создание: новые`
- `Создание: старые`
- `Обновление`

### Статусы задачи

- `Новая`
- `В работе`
- `Выполнена`
- `Отклонена`
- `Закрыта`

### Приоритеты

- `Низкий`
- `Нормальный`
- `Высокий`
- `Критический`

### Тип задачи

- `Задача`
- `Звонок`
- `Встреча`
- `Заметка`

### TaskListItem (строка списка)

- Просроченный дедлайн: `просрочено N дн`
- Сегодня: `сегодня`
- Без срока: `без срока`

### Фильтры

- Заголовок панели: `Фильтры`
- `Категории задач`
- `Статус`
- `Приоритет`
- `Теги`
- `Исполнитель`
- `Постановщик`
- `Отдел`
- `Период дедлайна`
- `Только мои задачи`
- `Только мои поручения`
- `Включить закрытые`
- Кнопка: `Сбросить фильтры`
- Кнопка: `Применить`

### Kebab-menu

- `Открыть`
- `Редактировать`
- `Закрепить`
- `Открепить`
- `В избранное`
- `Убрать из избранного`
- `Дублировать`
- `Перенести дедлайн`
- `+1 день`
- `+3 дня`
- `+1 неделю`
- `Отклонить`
- `Удалить`

### Кнопки действий на карточке

- `Редактировать`
- `В работу`
- `Выполнена`
- `Закрыть задачу`
- `Вернуть в работу`
- `Восстановить`
- `Отклонить`

### Tabs вкладок

- `Сводка`
- `Чек-лист`
- `Файлы`
- `Подзадачи`
- `Связанные`
- `Чат`
- `История`

### Поля формы создания

- `Тип`
- `Название *`
- `Дедлайн *`
- `Категория`
- `Ответственный`
- `Больше параметров`
- `Приоритет`
- `Соисполнители`
- `Аудиторы`
- `Наблюдатели`
- `Плановое время`
- `Описание`
- `Чек-лист`
- `Привязать к`
- `Теги`
- `Повторение`
- `Файлы`

### Placeholders

- Название: `Что нужно сделать...`
- Описание: `Добавить описание...`
- Пункт чек-листа: `Название пункта...`
- Теги: `Добавить тег...`
- Поиск задачи для связи: `Найти задачу...`
- Отчёт FTM: `https://...`

### Кнопки drawer

- `Отмена`
- `Создать и открыть`
- `Создаём…` (loading state)
- `Создать`

### Сводка (tab)

- `Описание`
- `Дедлайн`
- `Плановое время`
- `Фактическое время`
- `Прогресс`
- `Результат работы *`
- `Опиши результат...` (placeholder textarea)
- `Обязательное поле для закрытия задачи`

### Правый sidebar

- `Создал`
- `Ответственный`
- `Соисполнители`
- `Аудиторы`
- `Наблюдатели`
- `Привязка`
- `Контакт`
- `Повторение`
- `Теги`
- `Закрепить`
- `В избранное`
- `Дублировать`
- `Связать`
- `Перенести дедлайн`
- `Отклонить`
- `Причина отклонения *` (placeholder в modal)
- `Часть группы`

### RecurrenceSelector

- `Нет повторений`
- `Ежедневно`
- `Еженедельно`
- `Ежемесячно`
- `до`
- Help text: `При повторении создаются отдельные независимые копии задачи. Изменение одной не затрагивает другие.`
- Warning: `Максимальный период — до конца следующего года.`

### Bulk actions

- `Выбрано: N`
- `Изменить дедлайн`
- `Переназначить`
- `Закрыть все`
- `Удалить все`
- `Отмена выбора`
- Modal bulk deadline title: `Изменить дедлайн для N задач`
- Modal bulk assign title: `Переназначить N задач`
- Modal bulk close title: `Закрыть N задач?`
- Modal bulk close warning: `Внимание: если среди задач есть с обязательным результатом, они будут пропущены.`
- Modal bulk delete title: `Удалить N задач?`
- Modal bulk delete warning: `Это действие нельзя отменить. Задачи и их подзадачи будут удалены.`

### Admin категории

- Заголовок страницы: `Категории задач`
- Кнопка: `+ Создать категорию`
- Search placeholder: `Поиск по названию...`
- Empty title: `Нет категорий задач`
- Empty description: `Создай первую категорию, чтобы стандартизировать задачи команды`
- Поля формы: `Название`, `Описание-шаблон`, `Цвет`, `Ответственный`, `Администратор`, `Соисполнители`, `Аудиторы`, `Наблюдатели`, `Чек-лист по умолчанию`, `Минимум файлов для закрытия`, `Запрещать закрытие без указания результата`, `Автоматически заполнять название из шаблона`, `Порядок сортировки`, `Активна`
- Delete confirm: `Удалить категорию «{name}»? Все задачи этой категории сохранятся, но потеряют привязку к ней.`
- Delete confirm если есть активные задачи: `Нельзя удалить: есть N активных задач этой категории.`

### FTM-секция

- `Это первая встреча с клиентом (FTM)`
- `Decision maker присутствовал`
- `Презентация показана`
- `Отчёт (ссылка)`
- Helper text: `Ссылка на отчёт в AmoCRM или внутренней системе`

### Empty states (разные пресеты)

- Все задачи пустые: `Пока нет задач`
- Подзаголовок: `Создай первую задачу, чтобы начать`
- Фильтр без результата: `Нет задач по выбранным фильтрам`
- Подзаголовок: `Попробуй изменить критерии`
- Задача не найдена: `Задача не найдена`
- Подзаголовок: `Возможно, она была удалена`
- Нет подзадач: `Нет подзадач`
- Подзаголовок: `Добавь первую, чтобы разбить задачу на шаги`
- Нет файлов: `Файлы не прикреплены`
- Подзаголовок: `Перетащи сюда или нажми «Выбрать файлы»`
- Нет связанных: `Нет связанных задач`
- Нет комментариев: `Пока нет сообщений`
- Подзаголовок: `Напиши первый комментарий`

### Error states

- Загрузка задач: `Не удалось загрузить задачи. Обновить страницу.`
- Сохранение: `Не удалось сохранить`
- Категории: `Не удалось загрузить категории`

---

## Связь с backend

### Существующие endpoints (расширить)

| Method | URL | Что добавить |
|---|---|---|
| `GET` | `/api/activities` | Новые query params: `status[]`, `priority[]`, `category_ids[]`, `tags[]`, `my_tasks`, `my_orders`, `include_closed`, `parent_only`, `due_from`, `due_to` |
| `POST` | `/api/activities` | Новые поля в body: `category_id`, `priority`, `status`, `planned_hours`, `tags`, `recurrence_rule`, `recurrence_until`, `collaborators[]`, `checklist_items[]`, `parent_activity_id` |
| `PATCH` | `/api/activities/{id}` | Новые поля: `status`, `priority`, `progress_pct`, `planned_hours`, `actual_hours`, `result_text`, `tags`, `is_pinned`, `is_favorite`, `category_id` |

### Новые endpoints (требуются от backend)

| Method | URL | Описание |
|---|---|---|
| `GET` | `/api/activities/counts-by-preset` | Счётчики для пресетов {pinned, overdue, done_unclosed, today, this_week, next_week, future, mine, my_orders} |
| `GET` | `/api/activities/my-open-count` | Число открытых задач me (для Sidebar badge) |
| `PATCH` | `/api/activities/{id}/status` | Смена статуса {status, reject_reason?} |
| `PATCH` | `/api/activities/{id}/close` | Финальное закрытие (проверка result_text) |
| `POST` | `/api/activities/{id}/collaborators` | {user_id, role} |
| `DELETE` | `/api/activities/{id}/collaborators/{user_id}` | Убрать участника |
| `GET` | `/api/activities/{id}/checklist` | Список пунктов |
| `POST` | `/api/activities/{id}/checklist` | {text, sort_order} |
| `PATCH` | `/api/activities/{id}/checklist/{item_id}` | {is_done?, text?, sort_order?} |
| `DELETE` | `/api/activities/{id}/checklist/{item_id}` | Удалить пункт |
| `PATCH` | `/api/activities/{id}/checklist/reorder` | [{id, sort_order}] |
| `POST` | `/api/activities/{id}/attachments` | multipart upload |
| `DELETE` | `/api/activities/{id}/attachments/{attachment_id}` | |
| `GET` | `/api/activities/{id}/attachments/{attachment_id}/download` | |
| `POST` | `/api/activities/{id}/related` | {related_activity_id} |
| `DELETE` | `/api/activities/{id}/related/{related_id}` | |
| `POST` | `/api/activities/{id}/extend-deadline` | {days, reason} → notification → PATCH due_at |
| `GET` | `/api/activities/{id}/history` | Audit log записей |
| `POST` | `/api/activities/bulk` | {action, ids[], payload} |
| `GET` | `/api/activities/tags` | ?q=... autocomplete тегов |
| `GET` | `/api/task-categories` | Список категорий |
| `POST` | `/api/task-categories` | Создать категорию |
| `PATCH` | `/api/task-categories/{id}` | Обновить |
| `DELETE` | `/api/task-categories/{id}` | Удалить |
| `PATCH` | `/api/task-categories/reorder` | [{id, sort_order}] |

### Миграции (3, планируются в backend)

```
0055_task_categories    — task_categories + junction tables
0056_activity_task_fields — расширение activities + activity_collaborators
0057_activity_files_and_links — activity_attachments + activity_related_links
```

---

## Координация с другими эпиками

| Эпик | Зависимость |
|---|---|
| **Эпик 14 Departments** | `DepartmentSelect` в `TaskFiltersPanel` — `GET /api/departments` уже реализован |
| **Эпик 21 Notifications** | `task_assigned` kind + `task_deadline_extended_request` kind — добавить в `NotificationKind` type после реализации `services/task_notifications.py` |
| **Эпик 24.2 GCal sync** | Meeting/Call Activities из нового drawer будут синкаться — интерфейс `recurrence_rule` уже учитывает GCal RRULE формат |
| **Эпик 24.3 TG NL parsing** | `POST /api/activities` принимает `created_via: "tg_bot"` — frontend не затрагивается |
| **Эпик 7 TG-бот** | Команды `reply` бота (Готово/Закрыть/Отклонить) оперируют теми же `/status` endpoint-ами |
| **Эпик 23 Pipeline Builder** | `default_task_categories` на этапе — создаются через `POST /api/activities` с `category_id` pre-filled |
| **Dashboard MyTasksWidget** | `MyTasksWidget` → обновить ссылку `href="/tasks"` (сейчас `/dashboard`) |
| **Me page TodayTasksWidget** | Ссылка `href="/tasks?preset=today"` вместо `/me?tab=activity` |

---

## Открытые вопросы

1. **Права доступа на смену статуса.** Кто может менять статус задачи?
   - Только исполнитель и создатель?
   - Или любой участник (co-executor тоже)?
   - Или admin/director без ограничений?
   - Рекомендация: исполнитель + создатель + admin/director. Аудиторы и наблюдатели — только чтение.

2. **Закрытие задачи (is_closed).** Кто может финально закрыть (is_closed=true)?
   - Только создатель (постановщик)?
   - Или admin тоже?
   - Рекомендация: только создатель (постановщик) закрывает после статуса `done`.

3. **Удаление задачи.** Та же политика что у Activity сейчас (создатель ИЛИ admin/director)?
   - Если задача с подзадачами — удалять каскадно или запрещать?

4. **Bulk delete прав.** Только admin или менеджер может удалять свои?

5. **Прикреплённые файлы.** Где физически хранятся? Локально (volumes docker)?
   - Сейчас в проекте нет файлового хранилища — нужно решить: локальный volume, S3, или другое.
   - **Требуется решение пользователя (Богдана) перед реализацией `activity_attachments`.**

6. **Комментарии (вкладка Чат).** Нужна отдельная модель `ActivityComment` или
   комментарии пишутся через расширение `body` поля?
   - Рекомендация: отдельная `activity_comments` таблица (id, activity_id, user_id, body, parent_id, created_at).
   - **Требуется правка backend: новая модель + миграция.**

7. **История / Audit log.** Есть ли уже `EntityAuditLog` модель в проекте?
   - grep по `audit_log` в models.py покажет. Если нет — нужно создать или упрощённый
     инлайн-лог через `activity_history` таблицу.

8. **Recurrence cron.** Сервис `task_recurrence.py` создаёт копии — когда именно?
   - Утром в 00:00 UTC по `recurrence_rule`?
   - **Требуется правка backend: новый cron-сервис.**

9. **Теги (tags TEXT[]).** Autocomplete тегов — брать из существующих тегов задач всех
   пользователей или только моих?
   - Рекомендация: `GET /api/activities/tags?q=...` возвращает теги всех задач (shared vocabulary).

10. **Интеграция в Timeline.** Задачи с `parent_activity_id` и `status/priority` полями —
    показывать ли их в Timeline-вкладке карточки сущности (deal/lead/etc) с новыми badges?
    - Рекомендация: да, расширить `ActivityKindBadge` и `Timeline` компоненты новыми badges.

11. **Extend-deadline notification.** `POST /api/activities/{id}/extend-deadline` —
    отправляет уведомление создателю задачи. Это работает через Эпик 21 notification центр.
    Пока notification center есть только in-app — ок на старте.

12. **Права видимости задач (Visibility ACL Эпик 14).** Задачи с `target_type=deal`
    наследуют visibility от сделки (если у менеджера нет доступа к сделке — нет доступа
    к задаче)? Или задачи всегда видны участникам (collaborators)?
    - **Требуется позиция пользователя.**

---

**ТЗ готово, передавай `frontend-specialist`. Если есть правки — кидай мне.**
