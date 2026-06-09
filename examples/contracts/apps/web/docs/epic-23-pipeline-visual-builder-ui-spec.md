# ТЗ: Эпик 23 — Визуальный конструктор воронок + расширение категорий клиентов

**Версия:** 1.0  
**Дата:** 2026-06-02  
**Автор:** designer  
**Исполнитель:** frontend-specialist  
**Статус:** READY FOR IMPLEMENTATION

---

## Cover

### Цель

Дать администраторам MACRO CRM полноценный визуальный редактор воронок в стиле AmoCRM:
каждый этап — отдельная колонка, автоматизации привязаны к этапу прямо в канвасе, не
разбросаны по разделу «Автоматизации». Пользователь открывает воронку, видит её
«горизонтальную карту», добавляет триггеры на нужный этап в два клика.

Параллельно — расширение карточек категорий клиентов: описание (текстовое) и структурированные
опции (JSONB ключ=значение), чтобы к категории можно было прикреплять бизнес-атрибуты
(скидка, приоритет, аккаунт-менеджер и т.д.).

### Референс
AmoCRM: вертикальные колонки этапов, inline-блоки автоматизаций справа от колонки, модал выбора
действия с цветными иконками (сетка 3×N).

### Что меняем

| Область | Что делаем |
|---|---|
| `/admin/pipelines` | Добавляем кнопку «Визуальный вид» рядом с каждой воронкой |
| `/admin/pipelines/[id]/visual` | Новая страница — visual canvas |
| `components/Pipelines/` | Новая папка с 5 компонентами |
| `/admin/client-categories` | Расширяем форму (описание + структурированные опции) |

### Что НЕ меняем

Существующая страница `/admin/pipelines` (список + drag-reorder) остаётся как есть.
Visual — дополнительный режим, не замена.

---

## Раздел 1. Точка входа: кнопка «Визуальный вид» в /admin/pipelines

### Зачем

Администратор видит воронки списком — нужна быстрая ссылка на визуальный режим каждой воронки.

### Где в коде

`apps/web/src/app/(app)/admin/pipelines/page.tsx` — редактируем существующий файл, добавляем
ссылку в строку каждого этапа.

### Wireframe

```
┌── Конструктор воронок ──────────────────────────────┐
│ [select: Воронка продаж v]                          │
│                                                      │
│ Этапы                              [+ Этап]          │
│                                                      │
│ ☰  ● Неразобранное   Визуальный вид  [✏] [🗑]       │
│ ☰  ● Квалификация    Визуальный вид  [✏] [🗑]       │
│ ☰  ● Встреча         Визуальный вид  [✏] [🗑]       │
└─────────────────────────────────────────────────────┘
```

### Детали

В строке каждого этапа (SortableItem) добавить ссылку перед кнопками редактирования:

```
<a href={`/admin/pipelines/${pid}/visual`} className="btn-ghost text-xs text-primary-light whitespace-nowrap">
  <i className="bi bi-kanban mr-1" />Визуальный вид
</a>
```

Ссылка ведёт на `/admin/pipelines/{pid}/visual` (не `/admin/pipelines/{id}/stages`).

---

## Раздел 2. Страница /admin/pipelines/[id]/visual

### Зачем

Главный экран эпика. Горизонтальный канвас с этапами воронки. На каждом этапе — inline-блоки
прикреплённых автоматизаций и кнопка «+ Добавить триггер».

### Где в коде

```
apps/web/src/app/(app)/admin/pipelines/[id]/visual/page.tsx
apps/web/src/components/Pipelines/VisualCanvas.tsx
apps/web/src/components/Pipelines/StageColumn.tsx
apps/web/src/components/Pipelines/AutomationInlineCard.tsx
apps/web/src/components/Pipelines/AddTriggerModal.tsx
apps/web/src/components/Pipelines/StageEditModal.tsx
```

### Wireframe — полная страница

```
┌────────────────────────────────────────────────────────────────────────────┐
│ [← Назад к воронкам]  Воронка продаж — Визуальный вид  [Сохранить ✓]     │
├──────────────────┬─────────────────────────────────────────────────────────┤
│  Источники       │  ◄─────────────────── горизонтальный скролл ──────────► │
│  (200px)         │                                                          │
│                  │  ┌──────────────┐  ┌──────────────┐  ┌────────────────┐│
│  Каналы:         │  │● Неразобр.   │  │● Квалиф.     │  │● Встреча   ≡  ││
│  • Форма сайта   │  │  12 сделок ▾ │  │  7 сделок ▾  │  │  3 сделки ▾   ││
│  • TG @macro_bot │  ├──────────────┤  ├──────────────┤  ├────────────────┤│
│                  │  │ ⚡ При входе  │  │ ⚡ При входе  │  │                ││
│  Неразобранное:  │  │ Создать зад. │  │ Смен.отв.: A │  │  Нет           ││
│  • Дубли         │  │ [✏] [×]      │  │ [✏] [×]      │  │  автоматизаций ││
│                  │  │              │  │              │  │                ││
│                  │  │ ⚡ При входе  │  │              │  │                ││
│                  │  │ TG: владелец │  │              │  │                ││
│                  │  │ [✏] [×]      │  │              │  │                ││
│                  │  │              │  │              │  │                ││
│                  │  │ [+Добавить   │  │ [+Добавить   │  │ [+Добавить     ││
│                  │  │  триггер]    │  │  триггер]    │  │  триггер]      ││
│                  │  └──────────────┘  └──────────────┘  └────────────────┤│
│                  │                                         [+ Добавить     │
│                  │                                           этап]         │
└──────────────────┴─────────────────────────────────────────────────────────┘
```

### Layout страницы

- **Без стандартного `PageHeader`** — страница использует собственный sticky header (fullscreen-режим канваса).
- `(app)/layout.tsx` с Sidebar остаётся — страница встроена в app layout.
- Основной контейнер: `flex flex-col h-[calc(100vh-64px)]` (64px = высота Sidebar top nav).

### Sticky header страницы

```
<div className="flex items-center justify-between px-6 py-3 border-b border-gray-200 bg-white sticky top-0 z-30">
  <div className="flex items-center gap-3">
    <a href="/admin/pipelines" className="btn-ghost text-sm">
      <i className="bi bi-arrow-left mr-1" />Назад
    </a>
    <span className="text-gray-400">|</span>
    <span className="font-semibold text-gray-800">{pipeline.name}</span>
    <span className="text-sm text-gray-500">— Визуальный вид</span>
  </div>
  <div className="flex items-center gap-2">
    {hasUnsaved && (
      <span className="text-xs text-warning">Есть несохранённые изменения</span>
    )}
    <button className="btn-primary" onClick={handleSave} disabled={!hasUnsaved || saving}>
      <i className="bi bi-check-lg mr-1" />
      {saving ? "Сохраняем…" : "Сохранить"}
    </button>
  </div>
</div>
```

`hasUnsaved` — true если пользователь переименовал этап inline или изменил порядок.

### Left sidebar (200px фиксированная)

Компонент: встроен в `VisualCanvas.tsx`, не отдельный файл.

```
<aside className="w-[200px] shrink-0 border-r border-gray-200 bg-gray-50 flex flex-col overflow-y-auto">
  <div className="px-3 py-3 border-b border-gray-100">
    <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wide">Источники сделок</h3>
  </div>
  {/* Список каналов из /api/channels (Эпик 5 integration) */}
  {/* Если каналов нет → заглушка */}
</aside>
```

Данные: `useSWR("/channels", fetcher)`. Если пусто — показать заглушку «Нет подключённых каналов. Настрой в [Каналы →](/admin/channels)».

Каждый канал в sidebar — неинтерактивный информационный элемент (только отображение).

### Canvas — горизонтальный скролл

```
<div className="flex-1 overflow-x-auto overflow-y-hidden">
  <DndContext ...>
    <SortableContext items={stageIds} strategy={horizontalListSortingStrategy}>
      <div className="flex gap-4 p-6 min-h-full" style={{ minWidth: "max-content" }}>
        {stages.map(stage => <StageColumn key={stage.id} ... />)}
        <AddStageCard pipelineId={pipelineId} onCreated={handleStageCreated} />
      </div>
    </SortableContext>
  </DndContext>
</div>
```

Drag-and-drop: `@dnd-kit/core` + `@dnd-kit/sortable` (уже используется в admin/pipelines).
Стратегия: `horizontalListSortingStrategy`. При `onDragEnd` → PATCH `/api/pipelines/{id}/stages/reorder` (уже есть).

### SWR ключи страницы

```
useSWR<Pipeline>(`/pipelines/${id}`, fetcher)       // метаданные воронки
useSWR<PipelineStage[]>(`/pipelines/${id}/stages`, fetcher)  // этапы
useSWR<Automation[]>(`/automations?pipeline_id=${id}`, fetcher)  // автоматизации
useSWR<Channel[]>(`/channels`, fetcher)              // источники
```

**Важно:** у `Automation` уже есть поле `stage_id` — фильтруем на клиенте по `stage_id` для
каждой колонки. Не нужен отдельный endpoint.

---

## Раздел 3. Компонент StageColumn

### Файл

`apps/web/src/components/Pipelines/StageColumn.tsx`

### Props

```ts
interface StageColumnProps {
  stage: PipelineStage;
  automations: Automation[];          // уже профильтрованные по stage_id
  pipelineId: number;
  onAutomationCreated: () => void;    // revalidate SWR automations
  onAutomationDeleted: () => void;
  onStageRenamed: (id: number, name: string) => void;  // локальный unsaved
}
```

### Wireframe колонки

```
┌────────────────────────────────┐  ← w-80 (320px), shrink-0
│ ● [input: Неразобранное] [✏]  │  ← header: цветная точка + inline edit + шестерня
│   12 сделок                    │  ← subtitle: count из Automation.runs_count (нет у нас) 
│   или просто количество авт.   │     → пока не показываем count сделок, это requires /api/board
├────────────────────────────────┤
│ ⚡ При входе на этап           │  ← AutomationInlineCard × N
│    Создать задачу: Позвонить   │
│    [✏] [×]                     │
├────────────────────────────────┤
│ ⚡ Через 7 дней                │
│    TG: Владельцу               │
│    [✏] [×]                     │
├────────────────────────────────┤
│ [+ Добавить триггер]           │  ← btn-ghost, полная ширина
└────────────────────────────────┘
```

### Детали header

- Цветная точка: `<span className="w-3 h-3 rounded-full shrink-0" style={{ backgroundColor: stage.color || "#6B7A99" }} />`
- Название: inline-редактирование по клику (см. ниже)
- Шестерня `bi-gear` по ховеру → открывает `StageEditModal`
- `is_won` → маленький бейдж `text-success bi-trophy` справа
- `is_lost` → маленький бейдж `text-danger bi-x-circle` справа

### Inline-редактирование названия этапа

При клике на название:
1. `isEditingName` → true
2. Вместо `<span>` показать `<input className="input text-sm font-medium" />`
3. `onBlur` или `Enter` → PATCH `/api/pipelines/{pipelineId}/stages/{stage.id}` с `{ name: newName }`
4. При ошибке — вернуть старое значение + inline `text-danger` под колонкой

**Не через `StageEditModal`** — быстрое inline, без модала.

### Пустое состояние (нет автоматизаций)

```
<div className="px-3 py-4 text-center">
  <i className="bi bi-lightning text-2xl text-gray-300" />
  <p className="text-xs text-gray-400 mt-1">Нет автоматизаций</p>
</div>
```

---

## Раздел 4. Компонент AutomationInlineCard

### Файл

`apps/web/src/components/Pipelines/AutomationInlineCard.tsx`

### Props

```ts
interface AutomationInlineCardProps {
  automation: Automation;
  onEdit: () => void;    // переход на /admin/automations/{id}
  onDelete: () => void;  // DELETE + mutate
}
```

### Wireframe карточки

```
┌─────────────────────────────────────────────┐
│ [⚡icon]  При входе на этап         [✏] [×] │  ← hover buttons
│           Создать задачу: Позвонить          │
└─────────────────────────────────────────────┘
```

### Структура

```
<div className="group flex items-start gap-2 p-2.5 rounded-md border border-gray-100 bg-white hover:border-gray-300 transition-colors">
  {/* Иконка триггера */}
  <span className={`mt-0.5 shrink-0 text-sm ${triggerColor}`}>
    <i className={`bi ${triggerIcon}`} />
  </span>
  
  {/* Текст */}
  <div className="flex-1 min-w-0">
    <div className="text-xs font-medium text-gray-700">{triggerLabel}</div>
    <div className="text-xs text-gray-500 truncate">{actionLabel}: {actionSummary}</div>
  </div>
  
  {/* Hover-кнопки */}
  <div className="opacity-0 group-hover:opacity-100 flex items-center gap-1 transition-opacity">
    <a href={`/admin/automations/${automation.id}`} className="btn-ghost text-xs p-1">
      <i className="bi bi-pencil" />
    </a>
    <button onClick={handleDelete} className="btn-ghost text-xs p-1 text-danger">
      <i className="bi bi-x-lg" />
    </button>
  </div>
</div>
```

### Формирование `actionSummary` (строка-резюме действия)

| action_kind | Пример summary |
|---|---|
| tg_notify | «TG: Владельцу» / «TG: @user» |
| create_task | automation.action_config.title или «(без заголовка)» |
| set_field | `${field}: ${value}` |
| change_owner | `${rule}` |
| webhook | hostname из URL |
| email | automation.action_config.subject |
| start_sequence | `sequence_id` |
| generate_document | automation.action_config.template_code |

Функцию `getActionSummary(automation: Automation): string` вынести в
`apps/web/src/lib/pipelineVisual.ts` (новый вспомогательный файл).

### Иконки и цвета триггеров

Реюзаем данные из `TriggerBadge.tsx` — импортировать ICONS/COLORS оттуда или вынести в
`automationConfig.ts`.

### Confirm удаления

Не нативный `confirm()`. При клике `[×]` → показать маленький inline-попап под карточкой:

```
<div className="mt-1 p-2 bg-danger/10 border border-danger/30 rounded text-xs flex items-center justify-between gap-2">
  <span className="text-danger">Удалить автоматизацию?</span>
  <div className="flex gap-1">
    <button onClick={cancelDelete} className="btn-ghost text-xs py-0.5">Нет</button>
    <button onClick={confirmDelete} className="btn-ghost text-xs py-0.5 text-danger">Да, удалить</button>
  </div>
</div>
```

Состояние `deletePending: boolean` в компоненте.

---

## Раздел 5. Компонент AddTriggerModal (3-шаговый мастер)

### Файл

`apps/web/src/components/Pipelines/AddTriggerModal.tsx`

### Открывается

По клику «+ Добавить триггер» на колонке этапа. Передаёт `pipelineId` + `stageId`.

### 3 шага мастера

```
Шаг 1: Выбор действия → Шаг 2: Настройка действия → Шаг 3: Выбор триггера → Сохранить
```

Прогресс-индикатор сверху в модале:

```
[1 Действие] ──── [2 Параметры] ──── [3 Триггер]
```

Tailwind: `flex items-center gap-2 mb-6`. Активный шаг — `text-primary font-semibold`,
пройденный — `text-success`, будущий — `text-gray-400`.

---

### Шаг 1: Выбор действия

**Wireframe:**

```
┌─── Добавить автоматизацию на этап «Квалификация» ──┐
│                                                      │
│ [🔍 Поиск действий...]                               │
│                                                      │
│ ┌────────────┐  ┌────────────┐  ┌────────────┐      │
│ │ 📋          │  │ 📬          │  │ 📣          │     │
│ │ Создать     │  │ Отправить  │  │ TG-         │     │
│ │ задачу      │  │ письмо     │  │ уведомление │     │
│ └────────────┘  └────────────┘  └────────────┘      │
│                                                      │
│ ┌────────────┐  ┌────────────┐  ┌────────────┐      │
│ │ 👤          │  │ ✏️          │  │ 🏷           │    │
│ │ Сменить     │  │ Изменить   │  │ Редактир.  │     │
│ │ ответств.   │  │ поле       │  │ теги        │     │
│ └────────────┘  └────────────┘  └────────────┘      │
│                                                      │
│                               [Отмена]               │
└─────────────────────────────────────────────────────┘
```

**Сетка действий:** `grid grid-cols-3 gap-3`

**Карточка действия:**

```
<button
  onClick={() => selectAction(action.kind)}
  className="flex flex-col items-center gap-2 p-4 rounded-lg border border-gray-200 hover:border-primary hover:bg-primary/5 transition-colors cursor-pointer text-center"
>
  <span className={`text-2xl ${action.color}`}>
    <i className={`bi ${action.icon}`} />
  </span>
  <span className="text-sm font-medium text-gray-700 leading-tight">{action.label}</span>
</button>
```

**Поиск:** `<input className="input mb-4" placeholder="Поиск действий…" />` — фильтр по `action.label` (клиентский, без запроса).

**Полный список карточек действий:**

| action_kind | Иконка | Цвет иконки | Лейбл | Backend |
|---|---|---|---|---|
| create_task | bi-clipboard-plus | text-success | Создать задачу | есть |
| email | bi-envelope-fill | text-info | Отправить письмо | есть |
| tg_notify | bi-telegram | text-info | TG-уведомление | есть |
| change_owner | bi-person-fill-gear | text-primary-light | Сменить ответственного | есть |
| set_field | bi-pencil-square | text-warning | Изменить поле | есть |
| set_tags | bi-tags | text-warning | Редактировать теги | НОВОЕ |
| complete_tasks | bi-check-circle-fill | text-success | Завершить задачи | НОВОЕ |
| webhook | bi-broadcast | text-primary | Webhook | есть |
| change_stage | bi-arrow-right-circle-fill | text-primary-light | Сменить этап | НОВОЕ |
| generate_document | bi-file-earmark-text | text-primary | Сгенерировать документ | есть |
| start_sequence | bi-collection-play-fill | text-primary | Запустить последов. | есть |
| create_deal | bi-currency-dollar | text-success | Создать сделку | НОВОЕ |

---

### Шаг 2: Настройка параметров действия

**Заголовок:** «Настройки: {actionLabel}»

**Реюз существующих Config-компонентов:**

| action_kind | Компонент |
|---|---|
| create_task | `CreateTaskConfig` из `@/components/Automations/actions/CreateTaskConfig` |
| email | `EmailConfig` |
| tg_notify | `TgNotifyConfig` |
| change_owner | `ChangeOwnerConfig` |
| set_field | `SetFieldConfig` (targetType передаём как "deal" по умолчанию для sales-воронки) |
| webhook | `WebhookConfig` |
| generate_document | `GenerateDocumentConfig` |
| start_sequence | `StartSequenceConfig` |
| set_tags | `SetTagsConfig` — НОВЫЙ компонент (см. ниже) |
| complete_tasks | `CompleteTasksConfig` — НОВЫЙ компонент (см. ниже) |
| change_stage | `ChangeStageConfig` — НОВЫЙ компонент (см. ниже) |
| create_deal | простая форма прямо в модале (title + pipeline_id select) |

**Кнопки шага 2:**

```
[← Назад]  ...пространство...  [Далее →]
```

---

### Шаг 3: Выбор триггера

**Заголовок:** «Когда запускать?»

**Список триггеров** — вертикальный, не сетка:

```
<div className="space-y-2">
  {TRIGGER_OPTIONS.map(trigger => (
    <button
      key={trigger.value}
      onClick={() => setTriggerKind(trigger.value)}
      className={`w-full text-left p-3 rounded-lg border transition-colors ${
        selected === trigger.value
          ? "border-primary bg-primary/5"
          : "border-gray-200 hover:border-gray-300"
      }`}
    >
      <div className="flex items-center gap-3">
        <TriggerBadge kind={trigger.value} />
        <div>
          <div className="text-sm font-medium text-gray-800">{trigger.label}</div>
          <div className="text-xs text-gray-500">{trigger.description}</div>
        </div>
      </div>
    </button>
  ))}
</div>
```

Для `on_enter_stage` — триггер не требует дополнительного конфига.
Для `idle_in_stage_days` — под выбором появляется `<IdleInStageDaysConfig ... />`.
Для `date_field_approaching` — `<DateFieldApproachingConfig ... />`.

**По умолчанию выбран `on_enter_stage`** (самый частый кейс).

**Кнопки шага 3:**

```
[← Назад]  ...пространство...  [Сохранить и добавить]  ← btn-primary, disabled пока не выбран триггер
```

**При «Сохранить и добавить»:**

```
POST /api/automations
{
  name: `${actionLabel} при ${triggerLabel}`,  // авто-имя, пользователь потом может поменять
  pipeline_id: pipelineId,
  stage_id: stageId,
  trigger_kind: selectedTrigger,
  trigger_config: triggerConfig,
  action_kind: selectedAction,
  action_config: actionConfig,
  is_active: true
}
```

После успеха: закрыть модал + `onAutomationCreated()` (revalidate SWR).

---

### Новые Config-компоненты (заглушки для новых action_kind)

Эти компоненты нужны потому что backend ещё не поддерживает новые action_kind. Компоненты
реализуем с UI, но при сохранении backend вернёт 422 — это ожидаемо. В TODO-комментарии
указать «backend: добавить action_kind X в automation_executor.py».

#### SetTagsConfig

Файл: `apps/web/src/components/Automations/actions/SetTagsConfig.tsx`

Props: `{ config, onChange }` — стандартный интерфейс Action Config.

UI: `<input className="input" placeholder="Теги через запятую: лид, горячий, Q2" />`.
Сохраняет `{ tags: string[] }` в config.

#### CompleteTasksConfig

Файл: `apps/web/src/components/Automations/actions/CompleteTasksConfig.tsx`

UI: радио-группа:
- «Все открытые задачи» (значение `all`)
- «Задачи типа…» + select типов задач из `TASK_TYPES`

Сохраняет `{ scope: "all" | "by_type", task_types: string[] }`.

#### ChangeStageConfig

Файл: `apps/web/src/components/Automations/actions/ChangeStageConfig.tsx`

UI: select воронки + select этапа (зависимый SWR).

```
useSWR<Pipeline[]>("/pipelines", fetcher)
useSWR<PipelineStage[]>(targetPipelineId ? `/pipelines/${targetPipelineId}/stages` : null, fetcher)
```

Сохраняет `{ pipeline_id: number, stage_id: number }`.

---

## Раздел 6. Компонент StageEditModal

### Файл

`apps/web/src/components/Pipelines/StageEditModal.tsx`

### Открывается

По клику иконки `bi-gear` в хедере колонки этапа.

### Wireframe

```
┌─── Настройки этапа: Квалификация ──────────────────┐
│                                                      │
│ Название *                                           │
│ [Квалификация                             ]          │
│                                                      │
│ Цвет                   Код (для ЖЦ-воронки)         │
│ [#2B4987            ]  [A1              ]            │
│                                                      │
│ Описание этапа                                       │
│ [Textarea: что должно произойти на этом этапе...   ] │
│                                                      │
│ Флаги                                                │
│ [ ] Финальный успешный этап (is_won)                 │
│ [ ] Финальный проигрышный этап (is_lost)             │
│ [x] Этап активен (is_active)                         │
│                                                      │
│ SLA: предельное время на этапе (ч)                   │
│ [72                 ] часов (0 = без SLA)            │
│                                                      │
│ Типы задач на этапе                                  │
│ [✓ Звонок] [  Встреча] [  Задача]                    │
│                                                      │
│ [Отмена]                         [Сохранить]         │
└─────────────────────────────────────────────────────┘
```

### Props

```ts
interface StageEditModalProps {
  stage: PipelineStage;
  pipelineId: number;
  onClose: () => void;
  onSaved: () => void;  // revalidate SWR stages
}
```

### Поля формы

| Поле | HTML | Тип |
|---|---|---|
| name | input className="input" | string, required |
| color | input type="color" / text | string |
| code | input className="input" placeholder="B0/A1/C0" | string\|null |
| description | textarea className="input" rows={4} | string (новое поле!) |
| is_won | checkbox | bool |
| is_lost | checkbox | bool |
| is_active | checkbox | bool |
| sla_hours | input type="number" min={0} | number\|null (новое поле!) |
| task_types | multi-checkbox (TASK_TYPES) | string[] |

**Новые поля `description` и `sla_hours` требуют правки backend** (см. Раздел 8).

### API

```
PATCH /api/pipelines/{pipelineId}/stages/{stageId}
{
  name, color, code, description, is_won, is_lost, is_active,
  sla_hours, task_types, responsible_user_ids, visible_department_ids, visible_user_ids
}
```

`responsible_user_ids` и `visible_*` — оставляем в модале с теми же чекбоксами что в текущем
admin/pipelines (скопировать UI-блок), только убрать из хедера в отдельную секцию «Доступ».

---

## Раздел 7. Добавление нового этапа (inline в канвасе)

### Компонент AddStageCard

Встроен в `VisualCanvas.tsx` — не отдельный файл.

### Wireframe

```
┌─────────────────────┐
│  + Добавить этап    │  ← card пунктирная рамка
│                     │
│  [Название нового   │  ← появляется при клике на карточку
│   этапа...        ] │
│                     │
│  Enter = сохранить  │
│  Esc = отмена       │
└─────────────────────┘
```

### Поведение

1. Начальное состояние: карточка `w-48 border-2 border-dashed border-gray-300 rounded-lg flex items-center justify-center cursor-pointer text-gray-400 hover:border-primary hover:text-primary`
2. По клику → `isAdding = true` → показать input автофокусом
3. `onKeyDown Enter` → POST `/api/pipelines/{id}/stages` с `{ name: inputValue.trim(), sort_order: stages.length }`
4. При успехе → `onStageCreated(newStage)` → новая колонка добавляется в конец
5. `onKeyDown Escape` / `onBlur` без значения → `isAdding = false`
6. Loading state: input `disabled` + текст «Создаём…»

---

## Раздел 8. Расширение /admin/client-categories

### Зачем

Категории клиентов сейчас имеют `options: string[]` (текстовые строки). Нужны структурированные
опции (ключ=значение) и текстовое описание категории для документирования бизнес-правил.

### Что меняем

В `apps/web/src/app/(app)/admin/client-categories/page.tsx`:

1. В карточке каждой категории (строка `border border-gray-200`) — добавить блок описания если `description` заполнен
2. В модале редактирования — заменить `TextareaField` для `optionsText` на новый UI (см. ниже)
3. Добавить поле Description

### Wireframe модала категории (новый вид)

```
┌─── Категория B2 ─────────────────────────────────────┐
│                                                        │
│ Код *         Название *                               │
│ [B2      ]    [Средний бизнес              ]           │
│                                                        │
│ Мин. оборот ₽   Макс. ₽ (пусто=∞)   Группа            │
│ [5000000   ]    [50000000          ]  [M    ]           │
│                                                        │
│ Описание                                               │
│ [Компании с оборотом 5–50 млн. Стандартный            ]│
│ [пакет поддержки. АМ не обязателен.                   ]│
│                                                        │
│ Атрибуты категории ────────────────────────────────── │
│                                                        │
│ Ключ                   Значение              Тип       │
│ [discount_pct      ]   [10               ]  [Число v]  │
│ [priority_support  ]   [false            ]  [Булев v]   │
│ [account_manager   ]   [false            ]  [Булев v]   │
│ [+ Добавить атрибут]                                   │
│                                                        │
│ Цвет (hex)       Активна                              │
│ [#172747    ]    [x]                                   │
│                                                        │
│ [Отмена]                            [Сохранить]        │
└───────────────────────────────────────────────────────┘
```

### UI «Атрибуты категории»

Компонент: `CategoryOptionsEditor` — встроен прямо в страницу (не отдельный файл, объём небольшой).

Состояние:

```ts
type OptionEntry = { key: string; value: string; type: "string" | "number" | "boolean" };
const [options, setOptions] = useState<OptionEntry[]>(
  Object.entries(cat.options_map ?? {}).map(([k, v]) => ({
    key: k,
    value: String(v),
    type: typeof v === "number" ? "number" : typeof v === "boolean" ? "boolean" : "string",
  }))
);
```

При сохранении конвертируем в объект:

```ts
const options_map = Object.fromEntries(
  options.filter(o => o.key.trim()).map(o => {
    if (o.type === "number") return [o.key, Number(o.value)];
    if (o.type === "boolean") return [o.key, o.value === "true"];
    return [o.key, o.value];
  })
);
```

Строка атрибута:

```
<div className="grid grid-cols-[1fr_1fr_auto_auto] gap-2 items-center">
  <input className="input" placeholder="ключ" value={opt.key} onChange={...} />
  <input className="input" placeholder="значение" value={opt.value} onChange={...} />
  <select className="input" value={opt.type} onChange={...}>
    <option value="string">Текст</option>
    <option value="number">Число</option>
    <option value="boolean">Да/Нет</option>
  </select>
  <button className="btn-ghost text-danger p-1" onClick={() => removeOption(i)}>
    <i className="bi bi-x-lg" />
  </button>
</div>
```

### Поле description в карточке категории

В строке списка добавить под name/amount:

```tsx
{c.description && (
  <p className="text-xs text-gray-400 mt-0.5 truncate max-w-xs">{c.description}</p>
)}
```

### API изменения

```
PATCH /api/client-categories/{id}
{
  code, name, group, min_amount, max_amount, color,
  description: string | null,       // НОВОЕ
  options_map: Record<string, unknown>,  // НОВОЕ (заменяет options: string[])
  sort_order, is_active
}
```

**Важно:** `options: string[]` (старое поле) оставить для обратной совместимости или мигрировать —
решение на backend-specialist. Фронт при чтении проверяет наличие `options_map` (новое) vs
`options` (старое) и отображает соответственно.

---

## Раздел 9. Список новых файлов и изменённых файлов

### Новые файлы

| Файл | Назначение |
|---|---|
| `apps/web/src/app/(app)/admin/pipelines/[id]/visual/page.tsx` | Страница visual builder |
| `apps/web/src/components/Pipelines/VisualCanvas.tsx` | Канвас с DnD + sidebar |
| `apps/web/src/components/Pipelines/StageColumn.tsx` | Колонка этапа |
| `apps/web/src/components/Pipelines/AutomationInlineCard.tsx` | Инлайн-блок автоматизации |
| `apps/web/src/components/Pipelines/AddTriggerModal.tsx` | 3-шаговый мастер |
| `apps/web/src/components/Pipelines/StageEditModal.tsx` | Модал расширенных настроек этапа |
| `apps/web/src/lib/pipelineVisual.ts` | Утилиты: getActionSummary, getStageAutomations |
| `apps/web/src/components/Automations/actions/SetTagsConfig.tsx` | Конфиг нового action |
| `apps/web/src/components/Automations/actions/CompleteTasksConfig.tsx` | Конфиг нового action |
| `apps/web/src/components/Automations/actions/ChangeStageConfig.tsx` | Конфиг нового action |

### Изменяемые файлы

| Файл | Что добавляем |
|---|---|
| `apps/web/src/app/(app)/admin/pipelines/page.tsx` | Ссылка «Визуальный вид» в строке воронки |
| `apps/web/src/app/(app)/admin/client-categories/page.tsx` | Description + options_map UI |
| `apps/web/src/lib/types.ts` | `description?: string`, `sla_hours?: number` в PipelineStage; `description?: string`, `options_map?: Record<string, unknown>` в ClientCategory; новые action_kind в AutomationActionKind |
| `apps/web/src/lib/automationConfig.ts` | Новые ACTION_OPTIONS, ACTION_LABELS, ACTION_ICONS для set_tags / complete_tasks / change_stage / create_deal |
| `apps/web/src/components/Automations/ActionBadge.tsx` | Иконки/цвета для новых action_kind |

---

## Раздел 10. States (загрузка / пусто / ошибка)

### Страница /admin/pipelines/[id]/visual

| Ситуация | UI |
|---|---|
| Загрузка pipeline | Sticky header: «Загружаем…», canvas серый placeholder 3 колонки animate-pulse |
| Загрузка stages | 3 колонки-skeleton `w-80 h-48 bg-gray-100 animate-pulse rounded-lg` |
| Загрузка automations | В каждой колонке: 1-2 строки `h-12 bg-gray-100 animate-pulse rounded` |
| Pipeline не найден (404) | Центрированный блок в main: `bi-exclamation-triangle text-4xl text-danger` + «Воронка не найдена» + ссылка «Вернуться» |
| Ошибка загрузки stages | inline `text-danger` под sticky header + кнопка «Повторить» |
| Этапов нет | Канвас пустой с `AddStageCard` — пользователь создаёт первый этап |
| Ошибка сохранения имени этапа | `text-danger text-xs` под колонкой, 3 секунды, потом исчезает |
| Ошибка удаления автоматизации | Карточка остаётся, под ней `text-danger text-xs` |
| Ошибка POST automation | В AddTriggerModal на шаге 3 под кнопкой «Сохранить» |

### AddTriggerModal

| Ситуация | UI |
|---|---|
| Загрузка users/pipelines в Config | Inline «Загружаем…» серый текст |
| Ошибка POST | `text-danger bg-danger/10 px-3 py-2 rounded` над footer кнопками |
| Новый action_kind без backend | Предупреждение в шаге 2: `bg-warning/10 border border-warning/30 text-warning text-xs px-3 py-2 rounded` — «Это действие требует обновления backend. Сохранение вернёт ошибку 422.» |

### /admin/client-categories (расширение)

| Ситуация | UI |
|---|---|
| Ошибка PATCH с options_map | Inline под формой в модале `text-danger` |

---

## Раздел 11. Interactions

| Элемент | Действие | Результат |
|---|---|---|
| «Визуальный вид» в /admin/pipelines | click | navigate `/admin/pipelines/{id}/visual` |
| `[← Назад]` в visual | click | navigate `/admin/pipelines` |
| Название этапа в колонке | click | inline input, фокус |
| Inline input (название) | Enter / blur | PATCH `/api/pipelines/{id}/stages/{sid}` |
| Иконка `bi-gear` в хедере колонки | hover + click | открыть `StageEditModal` |
| `[+ Добавить триггер]` в колонке | click | открыть `AddTriggerModal` с stageId |
| Карточка действия в шаге 1 | click | перейти к шагу 2 |
| Поле поиска в шаге 1 | input | фильтр карточек (клиентский) |
| `[← Назад]` в мастере | click | предыдущий шаг (состояние сохраняется) |
| `[Далее →]` в мастере | click | следующий шаг |
| `[Сохранить и добавить]` | click | POST automation → закрыть → mutate |
| `[×]` на AutomationInlineCard | click | показать inline confirm |
| `[Да, удалить]` в confirm | click | DELETE automation → mutate |
| `[✏]` на AutomationInlineCard | click | navigate `/admin/automations/{id}` |
| AddStageCard (заглушка) | click | показать input |
| Input нового этапа | Enter | POST stage → добавить колонку |
| Input нового этапа | Escape | скрыть input |
| Drag колонки этапа | dragend | PATCH reorder (уже есть endpoint) |
| `[Сохранить]` в StageEditModal | click | PATCH stage → onSaved() |
| `[+ Добавить атрибут]` в категории | click | добавить строку OptionEntry |
| `[×]` в строке атрибута | click | удалить OptionEntry |
| `[Сохранить]` в модале категории | click | PATCH `/api/client-categories/{id}` с description + options_map |

---

## Раздел 12. Адаптивность

Desktop-first (1280px+).
Mobile — TBD (эпик 10), горизонтальный канвас на телефоне не приоритет сейчас.
На экранах < 1024px канвас скроллируется горизонтально — это нормально.

---

## Раздел 13. Тексты (RU, без i18n — копипаст в JSX)

### Страница /admin/pipelines — кнопка

- `Визуальный вид` — ссылка рядом с каждой воронкой

### Sticky header страницы visual

- `← Назад` — кнопка возврата
- `— Визуальный вид` — подзаголовок рядом с названием воронки
- `Есть несохранённые изменения` — предупреждение (warning)
- `Сохранить` — кнопка (btn-primary)
- `Сохраняем…` — loading state кнопки

### Left sidebar

- `Источники сделок` — заголовок секции
- `Нет подключённых каналов.` — empty state первая строка
- `Настрой в` + ссылка `Каналы →` — ссылка на /admin/channels

### StageColumn

- `Нет автоматизаций` — пустая колонка (под иконкой bi-lightning)
- `+ Добавить триггер` — кнопка btn-ghost полная ширина

### AddStageCard

- `+ Добавить этап` — текст заглушки
- `Название нового этапа…` — placeholder input
- `Создаём…` — loading state

### AddTriggerModal

- `Добавить автоматизацию` — заголовок модала
- `на этап «{stageName}»` — подзаголовок через `description` prop у Modal
- `1 Действие` / `2 Параметры` / `3 Триггер` — шаги прогресса
- `Поиск действий…` — placeholder input поиска
- `Создать задачу` / `Отправить письмо` / `TG-уведомление` / `Сменить ответственного` / `Изменить поле` / `Редактировать теги` / `Завершить задачи` / `Webhook` / `Сменить этап` / `Сгенерировать документ` / `Запустить последовательность` / `Создать сделку` — лейблы карточек
- `Настройки: {actionLabel}` — заголовок шага 2
- `Когда запускать?` — заголовок шага 3
- `← Назад` — кнопка назад (btn-secondary)
- `Далее →` — кнопка вперёд (btn-primary)
- `Сохранить и добавить` — финальная кнопка (btn-primary)
- `Отмена` — кнопка отмены (btn-ghost)
- `Это действие требует обновления backend. Сохранение вернёт ошибку 422.` — warning для новых action_kind

### AutomationInlineCard

- `Удалить автоматизацию?` — inline confirm
- `Нет, оставить` — кнопка отмены confirm (btn-ghost)
- `Да, удалить` — кнопка подтверждения (btn-ghost text-danger)

### StageEditModal

- `Настройки этапа: {stageName}` — заголовок
- `Название` + `*` — лейбл поля
- `Цвет` — лейбл
- `Код этапа (B0/A1/C0 — воронка ЖЦ)` — лейбл
- `Описание этапа` — лейбл
- `Что должно произойти на этом этапе, чтобы сделка продвинулась дальше` — placeholder textarea
- `Финальный успешный этап` — лейбл чекбокса is_won
- `Финальный проигрышный этап` — лейбл чекбокса is_lost
- `Этап активен` — лейбл чекбокса is_active
- `Предельное время на этапе (часов)` — лейбл SLA
- `0 = без SLA` — hint под SLA
- `Типы задач на этапе` — лейбл чекбоксов
- `Доступ к этапу` — лейбл секции visibility
- `Пусто = виден всем` — hint под секцией доступа
- `Отмена` — btn-ghost
- `Сохранить` — btn-primary
- `Сохранение…` — loading state кнопки

### /admin/client-categories — модал

- `Описание` — лейбл нового textarea
- `Кратко: кто попадает в эту категорию и какие условия работы` — placeholder textarea
- `Атрибуты категории` — заголовок секции
- `Ключ` — placeholder input ключа
- `Значение` — placeholder input значения
- `Текст` / `Число` / `Да/Нет` — options select типа
- `+ Добавить атрибут` — кнопка (btn-ghost)

---

## Раздел 14. Backend-требования (для backend-specialist)

### 14.1 Изменения в PipelineStage

Новые поля в таблице `pipeline_stages`:

| Поле | Тип | Default | Описание |
|---|---|---|---|
| `description` | `TEXT` | `NULL` | Описание этапа — что должно произойти |
| `sla_hours` | `INTEGER` | `NULL` | Максимальное время на этапе (0 = без SLA) |

PATCH endpoint уже существует: `/api/pipelines/{id}/stages/{stage_id}` — нужно расширить схему
входного Pydantic-модели.

GET endpoint `/api/pipelines/{id}/stages` — нужно вернуть новые поля в ответе.

### 14.2 Изменения в ClientCategory

Новые поля:

| Поле | Тип | Default | Описание |
|---|---|---|---|
| `description` | `TEXT` | `NULL` | Описание категории |
| `options_map` | `JSONB` | `{}` | Структурированные атрибуты |

Старое поле `options` (TEXT ARRAY) — оставить для обратной совместимости или мигрировать. Решение
принять в backend-specialist с учётом существующих данных в проде.

PATCH `/api/client-categories/{id}` — расширить схему.
GET `/api/client-categories` — вернуть `options_map` + `description` в ответе.

### 14.3 Новые AutomationActionKind

Добавить в `automation_executor.py` в AUTOMATION_ACTIONS:

| action_kind | Описание | Приоритет |
|---|---|---|
| `set_tags` | Добавить/удалить теги у объекта (когда появятся теги в модели) | MEDIUM |
| `complete_tasks` | Завершить открытые Activity.type="task" по target | HIGH |
| `change_stage` | Перевести объект на указанный stage_id | HIGH |
| `create_deal` | Создать новую сделку (pipeline_id обязателен) | LOW |

До реализации на backend — фронт показывает warning в модале.

### 14.4 Автоматизации: фильтрация по pipeline_id

Проверить, что GET `/api/automations?pipeline_id={id}` работает и возвращает список автоматизаций
для конкретной воронки. Если query-параметр не реализован — добавить в router.

Ответ должен включать поля `pipeline_name` и `stage_name` (уже есть в `Automation` типе на
фронте) для отображения в канвасе без дополнительных запросов.

---

## Открытые вопросы

1. **Количество сделок в заголовке колонки.** AmoCRM показывает «12 сделок» в каждой колонке.
   У нас для этого нужен `/api/board/{pipeline_id}` запрос или `stage_deal_count`. Это тяжело
   (полный board-запрос). Предложение: не показывать count сделок в visual-режиме на v1. Вместо
   этого — количество автоматизаций: «3 авт.».
   _Нужно решение продукта._

2. **Drag-and-drop этапов: unsaved state.** Reorder через DnD уже делает PATCH немедленно (см.
   `/pipelines/page.tsx` handleStagesDragEnd). В visual-режиме то же поведение (immediate PATCH)
   или добавить в «unsaved» буфер и сохранять по кнопке «Сохранить»?
   _Рекомендация: immediate PATCH, как сейчас — проще и привычнее._

3. **options / options_map в ClientCategory.** Текущий тип: `options: string[]`. Предложение
   вводит `options_map: Record<string, unknown>`. Нужна backend-позиция: мигрировать данные или
   держать оба поля параллельно. Миграция данных в JSONB — задача для backend-specialist.

4. **Новые action_kind (set_tags, complete_tasks, change_stage, create_deal) и Tags-модель.**
   `set_tags` требует модели тегов в БД (которой пока нет). Порядок реализации — решение
   backend-specialist. Пока на фронте показываем warning.

5. **Ссылка «Визуальный вид» в sidebar.** Добавить ли в навигацию Sidebar отдельный пункт
   «Конструктор воронок» с подпунктами per-pipeline? Или только через /admin/pipelines?
   _Рекомендация: не добавлять в Sidebar — visual builder это инструмент администратора, не
   операционный экран._

6. **`stageId` = null при создании автоматизации через AddTriggerModal.** В ТЗ `stageId` всегда
   присутствует (передаётся из колонки). Но технически автоматизация может быть без stage_id
   (на всю воронку). В AddTriggerModal — нет возможности выбрать «для всей воронки». Это
   намеренное ограничение visual builder'а (детальная настройка — через /admin/automations).
   _Подтвердить у продукта._
