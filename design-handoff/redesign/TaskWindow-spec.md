# ТЗ: Окно разворота задачи в «Мои задачи» (Волна 6, пп. 10.4/10.5)

**Зачем:** пользователь кликает по карточке задачи в канбане и видит полный контекст (исполнитель,
тип, сроки, текст) + может заполнить итог и выполнить задачу — без перехода на отдельную страницу.

**Где в коде:**
- `front/src/pages/MyTasksPage/components/TaskCard.vue` — точка входа (клик по карточке)
- `front/src/pages/MyTasksPage/components/TasksKanbanBoard.vue` — место монтирования Dialog
- `front/src/components/crm/activity/TaskExpandedPanel.vue` — **новый компонент** (единый для
  всех контекстов)
- `front/src/components/crm/entity/OpenTasksList.vue` — донор логики и шаблона expanded-блока

**Источник фич:** `Tasks-spec.md` §5.2 (карточка канбана), `DealCard-spec.md` §11 (expanded-блок
`open-tasks__expanded-wrap` в `OpenTasksList.vue`)

---

## 1. Форма разворота — решение и обоснование

### Выбор: центральный Dialog (PrimeVue `<Dialog>`)

Четыре варианта и почему Dialog:

| Вариант | За | Против | Решение |
|---|---|---|---|
| (а) Inline-раскрытие внутри карточки | Не прерывает контекст | 284px слишком узко для textarea итога + мета-поля; drag-and-drop карточки ломается при overflow; при нескольких открытых одновременно — хаос | Нет |
| (б) Overlay/Popover рядом с карточкой | Плавный | Выходит за пределы экрана у правых колонок; нет места для `DatePicker inline`; overflow:visible на всём дереве | Нет |
| (в) Правый Drawer | Привычный CRM-паттерн | В канбане уже есть горизонтальный скролл и 3–5 колонок — Drawer накрывает соседние карточки; неуместен когда задача не «сущность», а «действие» | Нет |
| **(г) Центральный Dialog** | **Просторный (540px); фокус на содержимом; не конфликтует с drag-and-drop; один компонент `TaskExpandedPanel` подходит во все 4 контекста через `mode` проп** | Прерывает контекст | **Принят** |

**Обоснование Dialog:** контент задачи — поле textarea с обязательным заполнением — требует фокуса
и достаточной ширины. 540px позволяют разместить все поля в 1–2 колонки без скролла. Dialog
закрывается по клику вне или Escape — тот же «нативный» UX, что и в MergeDialog / EntityCreate.
Drag-and-drop карточки (`draggable`) не запускается, если клик обработан в `@click` и Dialog
открыт.

---

## 2. Wireframe

```
┌─────────────────────── Dialog 540px ───────────────────────────────────┐
│  [pi-check-square]  Текст задачи — двухстрочный заголовок              │
│  [tag: тип]  [chip: дата, overdue=red]                    [pi-times] ✕ │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  СВЯЗАННЫЙ ОБЪЕКТ                                                       │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │  [pi-briefcase / pi-building / pi-user]  Название  [pi-external-link] │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                                                         │
│  ПОЛЯ  (2 колонки, gap-3)                                               │
│  ┌──────────────────────┐  ┌──────────────────────┐                    │
│  │ Ответственный        │  │ Тип задачи            │                    │
│  │ [avatar] Имя Ф.      │  │ [tag тип]             │                    │
│  └──────────────────────┘  └──────────────────────┘                    │
│  ┌──────────────────────┐  ┌──────────────────────┐                    │
│  │ Срок выполнения      │  │ Статус                │                    │
│  │ [pi-clock] дата      │  │ [pill статуса]        │                    │
│  │ (overdue=red/bold)   │  │                       │                    │
│  └──────────────────────┘  └──────────────────────┘                    │
│                                                                         │
│  ТЕКСТ ЗАДАЧИ (read-only, если заполнен)                                │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │ Полный текст задачи...                                             │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                                                         │
│  ИТОГ ВЫПОЛНЕНИЯ  *  (required)                                         │
│  ┌────────────────────────────────────────────────────────────────────┐ │
│  │ Textarea 3 строки, placeholder «Опишите результат...»              │ │
│  └────────────────────────────────────────────────────────────────────┘ │
│  [!] Заполните итог выполнения      ← только при ошибке валидации      │
│                                                                         │
├─────────────────────────────────────────────────────────────────────────┤
│  [Удалить]  (danger, outlined, left)       [Отмена]  [Выполнить ✓]     │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Зоны клика — разграничение (критично для drag-and-drop)

### TaskCard.vue — три явные зоны

```
┌─────────────────────────────────────────────────────────────────────┐
│  [pi-briefcase]  Название сделки ← RouterLink @click.stop           │
│                                   переход /deals/:id                │
├─────────────────────────────────────────────────────────────────────┤
│  Текст задачи                                                        │
│  [kind-tag]  [priority]                    ← body: @click → Dialog  │
│  [avatar] Имя Ф.                                                     │
├─────────────────────────────────────────────────────────────────────┤
│  [pi-clock] срок    [«Выполнить»]  ← health-strip: @click.stop      │
│                      кнопка        быстрое выполнение (гейт итога)   │
└─────────────────────────────────────────────────────────────────────┘
```

**Правило зон:**

| Элемент | Событие | Результат |
|---|---|---|
| `.task-card__deal-link` (RouterLink) | `@click.stop` | Переход `/deals/:id` (уже реализован) |
| `.task-card__body` (весь блок без deal-link) | `@click` | `emit('open', task.id)` → Dialog |
| `.task-card__health .task-card__complete-btn` | `@click.stop` | Быстрое «Выполнить» с гейтом — открывает Dialog и фокусирует textarea (тот же флоу, что в `OpenTasksList.onCompleteClick`) |
| `.task-card__checkbox` (selectMode) | `@click.stop` | Toggle выбора (уже реализован) |
| Drag-handle (`draggable`) | `dragstart` | DnD; перехватывается только если `selectMode === false` и `@click` НЕ был обработан |

**Важно:** `@click` на `.task-card` (корневом div) в `onCardClick` сейчас работает ТОЛЬКО в
`selectMode`. Нужно добавить ветку `else emit('open', task.id)` — см. §6 «Изменения в TaskCard».

---

## 4. Единый компонент TaskExpandedPanel — 4 контекста

```
TaskExpandedPanel.vue
  props:
    task: ActivityDto | MyBoardActivityDto
    mode: 'dialog' | 'inline'   // dialog = в Dialog канбана/списка; inline = в OpenTasksList
    usersList?: { id, name }[]
  emits:
    completed(activity)
    deleted(id)
    updated(activity)
    close()
```

| Контекст | Где монтируется | mode | Как открывается |
|---|---|---|---|
| Канбан «Мои задачи» | `TasksKanbanBoard.vue` — `<Dialog>` | `dialog` | `@open` от `TaskCard` |
| Список «Мои задачи» | `MyTasksPage/index.vue` — `<Dialog>` | `dialog` | `@click` на строке таблицы |
| Лента сделки / контакта / компании | `OpenTasksList.vue` — инлайн внутри `.open-tasks__row` | `inline` | клик по `.open-tasks__compact` |

**В режиме `inline`** компонент рендерит `.open-tasks__expanded-wrap` (текущая разметка
`OpenTasksList`, перенесённая без изменений). **В режиме `dialog`** оборачивается в `<Dialog>`
(шапка, footer, 540px) — разметка полей аналогичная, но просторнее.

Это позволяет:
- Не дублировать логику (resultDraft, 3-step delete, onCompleteSubmit, resultRequired-гейт).
- `OpenTasksList` становится тонкой оберткой: рендерит compact-строку и монтирует
  `TaskExpandedPanel mode="inline"` вместо inline `open-tasks__expanded-wrap`.
- `TasksKanbanBoard` монтирует один `<Dialog>` снаружи списка карточек и передаёт `activeTask`
  по `emit('open')` от `TaskCard`.

---

## 5. Компоненты и пропы

### Dialog-оболочка (в TasksKanbanBoard / MyTasksPage)

```html
<Dialog
  v-model:visible="taskDialogVisible"
  :style="{ width: '540px' }"
  :modal="true"
  :draggable="false"
  :show-close-icon="false"
  class="task-window-dialog"
>
  <TaskExpandedPanel
    v-if="activeTask"
    :task="activeTask"
    mode="dialog"
    :users-list="usersList"
    @completed="onTaskCompleted"
    @deleted="onTaskDeleted"
    @updated="onTaskUpdated"
    @close="taskDialogVisible = false"
  />
</Dialog>
```

`show-close-icon="false"` — крестик рисует сам `TaskExpandedPanel` (паттерн из `EntityCard-spec`).

### TaskExpandedPanel — внутренние компоненты

| Элемент | PrimeVue / HTML | Props / замечания |
|---|---|---|
| Шапка-заголовок | `<h3>` + kind-tag (custom span) | 15px/600, max 2 строки, ellipsis |
| Связанный объект | `RouterLink` + `<a target="_blank">` | `@click.stop`; иконка `pi-external-link` 10px |
| Ответственный | custom avatar-chip | 20px круг primary-900, инициал, имя сокр. |
| Тип задачи | kind-tag span | `typeChipStyle()` из OpenTasksList |
| Срок | span + `pi-clock` | overdue: `var(--p-red-500)`, 500 weight |
| Статус | `Tag` | severity по enum: `info/warning/success/secondary` |
| Текст задачи | `<p>` read-only | `font-size: $font-size-sm` |
| Итог | `Textarea` (PrimeVue) | `rows="3"`, `autoResize`, обязательное; red-border при `resultRequired` |
| Выполнить | `Button severity="success"` | `icon="pi pi-check"`, `:loading` |
| Удалить | `Button severity="danger" outlined` | 3-step через `deleteClickCounts` |
| Отмена / Закрыть | `Button text` | `emit('close')` |

---

## 6. Изменения в существующих файлах

### TaskCard.vue

1. Добавить `emit('open', task.id)` в `defineEmits`.
2. `onCardClick()` — добавить `else emit('open', props.task.id)` (сейчас ничего не делает вне
   selectMode).
3. `task-card__complete-btn` (`@click.stop`) — изменить: `emit('open', task.id)` вместо
   `emit('complete', id)` напрямую; Dialog сам запустит гейт фокуса на textarea. Причина: без итога
   выполнить нельзя; кнопка в health-strip теперь = «открыть Dialog с фокусом на итоге».

### TasksKanbanBoard.vue

1. Добавить `emit('open', id)` в TaskCard, обработать в Board: `activeTask = bucket.tasks.find(t => t.id === id)`, `taskDialogVisible = true`.
2. Монтировать `<Dialog>` + `TaskExpandedPanel` один раз за пределами `.task-board__columns`.

### OpenTasksList.vue

1. Заменить inline `open-tasks__expanded-wrap` на `<TaskExpandedPanel mode="inline" ...>`.
2. Передать все нужные пропы + слушать эмиты completed/deleted/updated/close (close = `expandedId = null`).
3. Логику (resultDraft, deleteClickCounts, resultRequired, onCompleteSubmit, handleDeleteClick) перенести в `TaskExpandedPanel`.

---

## 7. States

| Состояние | Что показывает |
|---|---|
| **compact (default)** | TaskCard без изменений — сделка, title, kind+priority, assignee, health |
| **dialog открыт** | `<Dialog>` с TaskExpandedPanel; backdrop; фокус в textarea итога если открыт через «Выполнить» |
| **textarea пустая + нажато «Выполнить»** | textarea красная рамка (`border-color: var(--p-red-500)`), текст-ошибка под полем, Dialog остаётся открытым |
| **выполнение (loading)** | `Button :loading="true"` на кнопке «Выполнить»; остальные поля disabled |
| **успешное выполнение** | Dialog закрывается; Toast `severity="success"` `t('tasks.board.card.completed')`; карточка исчезает из колонки (optimistic) |
| **ошибка API** | Toast `severity="error"`, Dialog остаётся открытым |
| **удаление (3-step)** | кнопка «Удалить» → warn (1 клик) → danger+scale (2 клика) → удаление (3 клик); Dialog закрывается после 3-го |
| **selectMode** | Dialog НЕ открывается; клик по карточке = toggle-select |
| **task.status === 'done'** | Dialog открывается, но textarea итога скрыта (результат уже есть), кнопка «Выполнить» скрыта; только «Закрыть» |

---

## 8. Темы

| Токен | Light | Dark |
|---|---|---|
| Dialog background | `var(--p-surface-0)` | `var(--p-surface-100)` — инвертированная шкала PrimeVue |
| Dialog border | `1px solid var(--p-surface-200)` | `1px solid var(--p-surface-700)` |
| Заголовок задачи | `$surface-700` | `var(--p-surface-200)` |
| Мета-текст (дата, ответственный) | `$surface-400` | `var(--p-surface-400)` |
| Overdue дата | `var(--p-red-500)` | `var(--p-red-400)` |
| textarea background | `var(--p-surface-0)` | `var(--p-surface-200)` |
| textarea border (normal) | `var(--p-surface-300)` | `var(--p-surface-600)` |
| textarea border (required) | `var(--p-red-500)` | `var(--p-red-500)` |
| Секция «Связанный объект» | `var(--p-surface-50)` | `var(--p-surface-100)` |

Никаких литеральных hex-значений. Семантика через `var(--p-*)` + `$`-токены репо.

---

## 9. Interactions — полная таблица

| Элемент | Действие | Результат | Endpoint |
|---|---|---|---|
| `.task-card__body` (кроме deal-link) | `@click` | `taskDialogVisible=true`, `activeTask=task` | — |
| `.task-card__deal-link` | `@click.stop` | `router.push('/deals/:id')` | — |
| `.task-card__complete-btn` | `@click.stop` | `taskDialogVisible=true`, `activeTask=task`, focus на textarea после `nextTick` | — |
| Крестик `pi-times` в Dialog | `@click` | `taskDialogVisible=false` | — |
| Escape / клик вне Dialog | PrimeVue Modal | `taskDialogVisible=false` | — |
| Ссылка на связанный объект | `RouterLink` / `<a target="_blank">` | навигация в /deals/:id или /contacts/:id | — |
| «Выполнить» (textarea пуста) | `@click` | red border + error-текст + focus textarea | — |
| «Выполнить» (textarea заполнена) | `@click` | `POST /api/activities/:id/complete` body `{result}` | `activityApi.completeActivity` |
| «Удалить» (1-й клик) | `@click` | кнопка → warn-стиль + tooltip | — |
| «Удалить» (2-й клик) | `@click` | кнопка → danger-стиль | — |
| «Удалить» (3-й клик) | `@click` | `DELETE /api/activities/:id`; Dialog закрыть; emit deleted | `activityApi.deleteActivity` |
| «Отмена» | `@click` | `taskDialogVisible=false` | — |
| Таймаут 3-step (3 сек) | автоматически | счётчик сбрасывается в 0 | — |

---

## 10. i18n-ключи

```json
// RU (tasks.window.*)
{
  "tasks": {
    "window": {
      "title": "Задача",
      "fields": {
        "responsible": "Ответственный",
        "kind": "Тип задачи",
        "dueAt": "Срок выполнения",
        "status": "Статус",
        "description": "Текст задачи",
        "result": "Итог выполнения",
        "resultPlaceholder": "Опишите результат выполнения...",
        "resultRequired": "Заполните итог выполнения"
      },
      "actions": {
        "complete": "Выполнить",
        "cancel": "Отмена",
        "delete": "Удалить"
      },
      "relatedEntity": {
        "deal": "Сделка",
        "contact": "Контакт",
        "company": "Компания"
      }
    }
  }
}
```

```json
// EN (tasks.window.*)
{
  "tasks": {
    "window": {
      "title": "Task",
      "fields": {
        "responsible": "Assignee",
        "kind": "Task type",
        "dueAt": "Due date",
        "status": "Status",
        "description": "Task description",
        "result": "Completion result",
        "resultPlaceholder": "Describe the completion result...",
        "resultRequired": "Please fill in the completion result"
      },
      "actions": {
        "complete": "Complete",
        "cancel": "Cancel",
        "delete": "Delete"
      },
      "relatedEntity": {
        "deal": "Deal",
        "contact": "Contact",
        "company": "Company"
      }
    }
  }
}
```

---

## 11. Новый компонент — обоснование

`TaskExpandedPanel.vue` создаётся новым, потому что:
- Является переиспользуемым примитивом для 4 независимых контекстов с разными оборачивателями.
- Существующий `OpenTasksList.vue` содержит и список, и expanded-вид вперемешку — выделение
  упрощает оба компонента.
- Ничего подходящего в `components/crm/entity/` или `ui_kits/crm/` нет (там — `OpenTasksList`,
  `EntityLogList`, `EntityComposer`, не переиспользуемая панель задачи).

---

## 12. Backend-блокеры

Новых endpoint'ов не требуется. Все нужные уже есть:
- `activityApi.completeActivity(id, result)` — POST `/api/activities/:id/complete`
- `activityApi.deleteActivity(id)` — DELETE `/api/activities/:id`
- `activityApi.updateActivity(id, patch)` — PATCH `/api/activities/:id` (если нужен inline-edit)

---

## 13. Открытые вопросы

**ОВ-1:** Нужен ли inline-edit текста задачи в Dialog (double-click = редактировать заголовок,
как в `OpenTasksList`)? Сейчас в ТЗ — read-only. Дать ответ до реализации.

**ОВ-2:** Нужны ли в Dialog пикеры для смены ответственного / даты / типа (как в
`OpenTasksList` compact-режиме)? Если да — Dialog расширяется до 580px и пикеры встраиваются.
Сейчас ТЗ описывает поля как read-only display; смена — через «Открыть в карточке сделки».

**ОВ-3:** Связанный объект — только сделка (`task.deal`) или также прямой контакт / компания
(если задача создана вне сделки)? Нужно уточнить тип `MyBoardActivityDto.target` — что именно
приходит с бэкенда.
