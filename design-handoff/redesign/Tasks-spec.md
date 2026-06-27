# Раздел «Мои задачи» (задачник) — ТЗ для реализации (Claude Code)

Пересборка страницы задач: канбан по дедлайн-бакетам + табличный вид, с быстрым созданием,
фильтрами, массовыми действиями. Эталон — `redesign/tasks.html` (в хендоффе — `design-handoff/tasks.html`).
Документ описывает разметку, размеры, токены и поведение пиксель-в-пиксель.

- **Стек:** Vue 3 + PrimeVue 4. Страница — `pages/MyTasksPage/index.vue`; канбан —
  `components/TasksKanbanBoard.vue` + `TaskCard.vue`; список — `MyTasksTable.vue`,
  табы — `MyTasksPresetTabs.vue`, фильтр — `MyTasksFilterPanel.vue`; быстрое создание —
  `components/tasks/TaskQuickForm.vue`; данные — `composables/useTaskBoard.ts` / `useMyTasks.ts`.
  Массовые действия — взять паттерн из `pages/DealsPage/components/DealsBulkToolbar.vue` и
  `salesStore.bulkMode/bulkSelection`.
- **Шрифт:** `--mg-font-sans` (SF UI Display → web-фолбэк Inter). Иконки — **PrimeIcons 7**.
- **Темы:** светлая и тёмная (класс `.surface` / `.surface.dark`).

> ### As-built deltas after task-mgmt pass (2026-06-27)
> Эталон визуала не изменился — ниже то, что реально отшипперено в коде и расходится с исходным
> текстом ТЗ (подробности — в инлайн-пометках по разделам):
> 1. **Таб «Выполненные»** (5-й пресет) — `MyTasksPresetTabs.vue` + `counts.completed` +
>    `GET /api/activities/presets/completed`. См. §6.1.
> 2. **Статус — transition-gated dropdown** (не статичная пилюля): inline-`Select` ограничен
>    валидными переходами (`ACTIVITY_STATUS_TRANSITIONS`), патч через `/status`. См. §6.2.
> 3. **Бакеты — серверные, Asia/Dubai** (`useTaskBoard.ts`, без клиентского пере-бакетинга);
>    **`rejected` считается закрытой** наравне с `done` (не только `done`). См. §5.
> 4. **Выполненная задача покидает open-список** в «Мои задачи» (`useMyTasks.removeLocal`, не
>    `updateLocal`) — после completion строка уходит из активной выборки.
> 5. **Реальные 4-state лейблы статуса** (`new` / `in_progress` / `done` / `rejected`) из
>    `activity.statuses.*`, не бинарный «открыта/выполнена».
> 6. **Защита от двойного сабмита по Ctrl+Enter** (in-flight guard, F4) — горячая клавиша не
>    обходит `:loading`-кнопку и не создаёт дубль.

> **Где брать визуальные значения (источник правды — дизайн-система):**
> токены лежат в `styles.css` + `tokens/*.css` дизайн-системы
> (`tokens/colors.css`, `typography.css`, `spacing.css`, `semantic.css`, `fonts.css`).
> В репозитории CRM она подключена как скилл: `.claude/skills/macroglobal-design/`
> (тот же `styles.css` + `tokens/*.css`). **Никаких хардкод-цветов/радиусов/размеров — только
> токены `--mg-*` / `--c-*`.** Общая «хромка» (TopBar, FilterPanel, тема) совпадает с воронкой —
> сверяйся с `SalesFunnel-spec.md`, чтобы кнопки/панели были идентичны.

---

## 0. Токены поверхности (light / dark)

Объявляются на корневом `.surface` — идентично воронке (`SalesFunnel-spec.md` §0):

| Переменная | Light | Dark |
|---|---|---|
| `--c-page` | `#F1F2F3` | `#272829` |
| `--c-board` | `#ECEDEF` | `#1f2021` |
| `--c-card` | `#FFFFFF` | `#3a3b3d` |
| `--c-border` | `#E3E4E6` | `#54595E` |
| `--c-border2` | `#D5D6D8` | `#54595E` |
| `--c-text` | `#272829` | `#F9FAFB` |
| `--c-text2` | `#616263` | `#E3E4E6` |
| `--c-muted` | `#7E7F82` | `#9B9C9F` |
| `--c-hover` | `#F9FAFB` | `#444547` |

Бренд/радиусы/тени — из дизайн-системы: `--mg-primary-900` (акцент, по умолч. `#172747`),
`--mg-primary-800` (hover), `--mg-primary-100` (светлая плашка), `--mg-radius-sm/md/lg`,
`--mg-shadow-sm/card/lg`. Палитра статусов — `--mg-green-*`, `--mg-red-*`, `--mg-orange-*`, `--mg-blue-*`.

### Цвета дедлайн-бакетов (kanban-колонки)
| Бакет | key | Цвет |
|---|---|---|
| Просрочено | `overdue` | `#FF5A44` |
| Сегодня | `today` | `#EF9F27` |
| Завтра | `tomorrow` | `#378ADD` |
| На этой неделе | `this_week` | `#7F77DD` |
| Следующая неделя | `next_week` | `#1D9E75` |

### Цвета тегов типа задачи (`kind`)
| Тип | Иконка | bg / text |
|---|---|---|
| `call` (Звонок) | `pi-phone` | `--mg-orange-50` / `--mg-orange-900` |
| `meeting` (Встреча) | `pi-calendar` | `--mg-blue-100` / `--mg-blue-700` |
| `task` (Задача) | `pi-check-square` | `--mg-gray-100` / `--mg-gray-700` |
| `note` (Заметка) | `pi-file-edit` | `color-mix(in srgb, #7F77DD 14%, --c-card)` / `#4b45a0` |
| `follow_up` (Follow-up) | `pi-arrow-right-arrow-left` | `color-mix(in srgb, #1D9E75 14%, --c-card)` / `#15795a` |

### Цвета статуса задачи (`status`) и приоритета
- Статус-пилюли: `new`→`--mg-blue-100`/`--mg-blue-700` (Новая); `in_progress`→`--mg-orange-50`/`--mg-orange-900`
  (В работе); `done`→`--mg-green-100`/`--mg-green-900` (Выполнена); `rejected`→`--c-hover`/`--c-muted` (Отклонена).
- Приоритет (показывать только `high`/`critical`): иконка `pi-flag-fill` 9px + подпись; `high`→`--mg-orange-700`
  («Высокий»), `critical`→`--mg-red-600` («Срочный»).

---

## 1. Каркас страницы

```
.surface (height:100vh; flex column; background: var(--c-board))
├─ TopBar (шапка + тулбар в одной строке)            §2
├─ QuickCreate (раскрывается под шапкой)             §3
├─ FilterPanel (раскрывается под шапкой)             §4
├─ BulkBar (строка массовых действий)               §2.2
└─ Board (kanban)  |  ListView (таблица)            §5 / §6
```

Состояние: `view ∈ {'kanban','list'}` (по умолч. `kanban`), `scope ∈ {'day','week','month'}`
(по умолч. `month`), `quickOpen`, `filterOpen`, `selectMode`, `selected[]`, `pinned[]`,
`removed[]`, `done[]`.

---

## 2. TopBar — единая верхняя строка

Flex-ряд: `align-items:center; gap:12px; padding:14px 20px; border-bottom:1px --c-border;
background:--c-card; flex-wrap:wrap`. Слева направо:

1. **Иконка раздела** — плитка 38×38, `background:--mg-primary-100; radius:--mg-radius-md`,
   внутри `pi-check-square` 17px цвета `--mg-primary-900`.
2. **Заголовок-блок:** `<h1>` «Мои задачи» (19px/600/`--c-text`) + подпись «MACRO Global ·
   {N} задач · {M} просрочено» (12px/`--c-muted`; число просроченных — `--mg-red-700`).
3. **Спейсер** `flex:1`.
4. **«Поиск и фильтр»** — как в воронке (§2 п.4): `height:38`, неактив — border `--c-border2`/текст
   `--c-text2`; актив — `--mg-primary-100` + border/текст `--mg-primary-900`. Бейдж счётчика
   активных фильтров справа-сверху (`--mg-orange-700`, #fff, 10px/700).
5. **Сегмент scope** (только в `view==='kanban'`): контейнер `inline-flex; gap:2px;
   background:--mg-gray-100; radius:7px; padding:3px`; три кнопки **День / Неделя / Месяц**
   (height:25, 12px/600). Активная — `background:--c-card; color:--mg-primary-900; shadow:--mg-shadow-sm`.
   Управляет видимыми бакетами (см. §5).
6. **Сегмент вида** — `pi-th-large` (kanban) · `pi-list` (список), compact (height:31), активная —
   `--mg-primary-100`/`--mg-primary-900`.
7. **Кнопка «⋮»** (`pi-ellipsis-v`) — icon-only, outlined, compact (height:31). Открывает MoreMenu.
8. **«Создать задачу»** — primary (`height:38`, `--mg-primary-900`, hover `--mg-primary-800`,
   #fff), иконка `pi-plus`. Тогглит панель быстрого создания (§3).

### 2.1 MoreMenu (по «⋮»)
Дропдаун `min-width:250` (`background:--c-card; border:1px --c-border; radius:--mg-radius-md;
shadow:--mg-shadow-lg; padding:5px`), пункты 13px/500, иконка 14px `--c-muted` слева, hover
`--c-hover`. **Порядок:** `Выбрать` (pi-check-square — включает режим массовых действий, §2.2)
· — · `Синхронизация с календарём` (pi-calendar) · `Экспорт в CSV` (pi-upload).

### 2.2 BulkBar — строка массовых действий (паттерн `DealsBulkToolbar.vue`)
Появляется, когда `selectMode === true` — **в обоих видах** (канбан и список). Полоса:
`display:flex; align-items:center; gap:10px; padding:9px 20px; flex-wrap:wrap;
background:--mg-primary-100; border-bottom:1px --mg-primary-900` (подсветка акцентом). Слева направо:
- **«✕ Отмена»** (text, height:31) — выход из режима, очистка `selected`.
- **Чекбокс «Выбрать все»** — квадрат 17px, `radius:4`; off — border `--c-border2`; «частично» —
  border `--mg-primary-900` + полоска-минус; «все» — заливка `--mg-primary-900` + `pi-check` #fff.
  Кликом выделяет/снимает все **видимые** задачи (в канбане — по текущему scope; см. §5).
- **«Выбрано: {N}»** — 13px/600/`--mg-primary-900`.
- **Разделитель** — 1×24, `--c-border2`.
- **Действия** (outlined, height:31, `disabled` при `N===0`, `opacity .45`): **Редактировать**
  (pi-pencil) · **Закрепить** (pi-bookmark-fill) · **Открыть заново** (pi-refresh) · **Удалить**
  (pi-trash, danger: текст `--mg-red-700`, border `--mg-red-200`).

Действия применяются к выделенным; **точечно** — те же действия доступны на одной задаче
(в карточке/строке). «Закрепить» добавляет `pin` (значок-закладка), «Удалить» убирает задачу,
«Открыть заново» снимает `done`.

---

## 3. QuickCreate — инлайн быстрого создания (по `TaskQuickForm.vue`)

Раскрывается под TopBar по «Создать задачу»: `border-bottom:1px --c-border; background:--c-hover;
padding:14px 20px; animation: slide .18s`. Один ряд контролов (flex-wrap, gap:10), **строго в этом
порядке** — задача создаётся ДЛЯ сущности:

1. **Сущность (компания/контакт)** — автоподбор `EntityPicker` (min-width:250):
   инпут (height:38, padding-left:32 под иконку) с плейсхолдером «Компания или контакт…».
   Слева иконка: `pi-search` (пусто) → после выбора `pi-building` (компания) / `pi-user` (контакт),
   цвет `--mg-primary-900`; рамка выбранного — `--mg-primary-900`. **Выпадающий список появляется,
   как только пользователь начинает печатать**, фильтрация по подстроке (без учёта регистра),
   до 6 совпадений. Дропдаун: `top:42; --c-card; border:1px --c-border; radius:--mg-radius-md;
   shadow:--mg-shadow-lg; padding:4`. Пункт: плитка 26px (`--mg-primary-100`, компания — квадрат
   `radius:sm`, контакт — круг) с `pi-building`/`pi-user` `--mg-primary-900` + две строки (имя 13px/500
   + подпись 11px/`--c-muted`: «Компания» / «Контакт · {компания}»). Нет совпадений — строка
   «Ничего не найдено · создать «{ввод}»» (ссылка `--mg-primary-900`).
2. **Что нужно сделать** — текстовый инпут `flex:1; min-width:200; height:38`, плейсхолдер «Что нужно сделать?».
3. **Тип задачи** — поле-селект (QField: height:38, border `--c-border2`, иконка `pi-check-square` +
   значение + `pi-chevron-down`), по умолчанию «Задача».
4. **Дата выполнения** — QField `pi-calendar`, по умолч. «Сегодня 18:00».
5. **Ответственный** — QField `pi-user`, по умолч. имя сотрудника.
6. **«Создать»** (primary, `pi-check`) + **«Отмена»** (text).

> Никаких декоративных иконок «+» в строке. Поля идут именно в порядке 1→5.

---

## 4. FilterPanel (по «Поиск и фильтр»)

`border-bottom:1px --c-border; background:--c-hover; padding:16px 20px`. Идентично воронке по
оформлению (`SalesFunnel-spec.md` §3), состав под задачи:
- **Строка поиска** (`max-width:460`): `pi-search` + инпут (height:38), плейсхолдер «Поиск по
  задачам, сделкам, компаниям…».
- **Пресеты** (мультивыбор с подсветкой, pill `radius:999; padding:6px 13px; 13px/600`; активный —
  заливка severity + `pi-check` слева; неактивный — border `--c-border2`): `Мои задачи` (brand,
  по умолч.) · `Сегодня` (brand) · `Просрочено` (danger) · `Без срока` (warning) · `Выполненные`
  (success) · `Все` (brand). Справа `pi-times` — закрыть.
- **Сетка полей** (`grid; repeat(4,1fr); gap:14px 18px`), поле = лейбл (12px/500/`--c-text2`) +
  контрол (height:36, border `--c-border2`, шеврон): `Тип задачи` · `Статус` · `Приоритет` ·
  `Ответственный` · `Срок от` · `Срок до` · `Сделка / компания` · `Тип объекта`.
- **Низ:** `Сбросить` (text) + `Применить` (primary, `pi-check`).

---

## 5. Канбан (view = kanban)

Контейнер: `flex; align-items:flex-start; gap:12px; padding:16px 20px; overflow:auto`.
**`align-items:flex-start`** обязательно (колонки обнимают контент).

**Видимые бакеты по scope:** `day` → overdue, today, tomorrow; `week` → + this_week;
`month` → + next_week.

**Авто-скрытие «Просрочено»:** колонка `overdue` показывается, **только если есть незакрытые
просроченные задачи**. Нет просроченных — колонка скрыта; появляется автоматически, как только
просрочка возникла. Закрыли все просроченные — снова исчезает.

> *(as-built 2026-06-27: бакеты **считает сервер** в операционной таймзоне **Asia/Dubai** —
> `useTaskBoard.ts` рендерит `getMyBoard()` напрямую (`serverBuckets`) и **не пере-бакетит на
> клиенте** в браузерной tz. Поэтому клиентского предиката `status!=='done'` для overdue здесь
> НЕТ — попадание в `overdue` определяет бэкенд. «Закрытость» (open/overdue) считает **rejected
> финальным наравне с done** (бэкенд `scopeOpen` / `isFinal`; на стороне `MyTasksTable`
> оптимистично `is_closed = done || rejected`) — отклонённая задача в open-/overdue-выборки не
> попадает.)*

### 5.1 Колонка (по `DealsKanbanColumn`, общий вид с воронкой)
Белая подложка: `width:284; flex-shrink:0; align-self:flex-start; background:--c-card; border:1px
--c-border; radius:--mg-radius-lg; shadow:--mg-shadow-sm; overflow:hidden; max-height:100%`.
- **Шапка:** `border-top:3px solid {bucket.color}`; `background: color-mix(in srgb, {bucket.color}
  13%, --c-card)`; `border-bottom:1px --c-border`; `padding:11px 13px 9px`.
  - Верхний ряд `grid: 34px 1fr 34px`: слева — бейдж-счётчик карточек (`12px/700 --c-text2;
    background:--c-card; border:1px --c-border; radius:999; padding:1px 0; min-width:26;
    justify-self:start`); по центру — НАЗВАНИЕ бакета `14px/700; letter-spacing:0.04em; uppercase;
    --c-text`; справа — пустой спейсер. Без «+».
  - **Мета-строка** (вместо суммы): `12px/--c-muted; text-align:center; margin-top:6px` —
    короткий контекст периода: overdue→«требуют внимания», today→«24 июня, вт», tomorrow→«25 июня,
    ср», this_week→«до 29 июня», next_week→«30 июня – 6 июля».
- **Список:** `padding:10px; gap:8px; --c-card; overflow-y:auto`. Пустой — строка «Нет задач»
  (12px/`--c-muted`, min-height:60).

### 5.2 Карточка задачи (по `TaskCard.vue`, в стиле `DealsKanbanCard`)
`position:relative; background:--c-card; border:1px --c-border; radius:--mg-radius-md;
shadow:--mg-shadow-sm; overflow:hidden; cursor:pointer`. Выполненная — `opacity:0.7`.
Сигнал-полоса слева (inset): `overdue` → `inset 4px 0 0 --mg-danger` + border `--mg-danger`;
`critical`-приоритет → `inset 4px 0 0 --mg-orange-700`.

Тело (`padding:11px 12px`):
- **Сделка** (если есть): `pi-briefcase` 10px `--c-muted` + название (12px/`--c-muted`, эллипсис).
- **Заголовок задачи** — 13px/600/`--c-text`, 2 строки c эллипсисом (`-webkit-line-clamp:2`);
  выполненная — `line-through`.
- **Тип + приоритет** (`gap:6`): тег типа (см. §0; `radius:sm; padding:2px 8px; 11px/500`, иконка
  10px); приоритет (только high/critical) — `pi-flag-fill` 9px + подпись 11px/600 в цвет приоритета.
- **Ответственный:** аватар-кружок 20px (`--mg-primary-900`, инициал, #fff) + имя «Имя Ф.» (12px/`--c-muted`).
- **Health-полоса** (низ карточки, `padding:7px 12px; border-top:1px --c-border; min-height:30;
  11px`): иконка + текст + кнопка справа.
  - выполнена → `bg:--c-hover; col:--mg-green-700; pi-check-circle; «Выполнено»`, без кнопки;
  - overdue → `bg:--mg-red-50; col:--mg-red-700; pi-clock; «Просрочено: {когда}»` (500);
  - иначе → `bg:--c-hover; col:--c-text2; pi-clock; {срок}`.
  - Для невыполненных — справа кнопка **«Выполнить»** (`pi-check`, `--mg-green-700`, 11px/600,
    `stopPropagation`).

### 5.3 Выбор на канбане (режим массовых действий)
В `selectMode` у каждой карточки в правом-верхнем углу — **чекбокс** (`absolute; top:8; right:8;
18px; radius:4`; off border `--c-border2` на `--c-card`; on — `--mg-primary-900` + `pi-check` #fff).
Клик по карточке выделяет/снимает; выделенная — border `--mg-primary-900` + `box-shadow: 0 0 0 1px
--mg-primary-900`. Работает вместе с BulkBar (§2.2).

---

## 6. Табличный вид (view = list, по `MyTasksTable.vue` + `MyTasksPresetTabs.vue`)

Контейнер `flex:1; overflow:auto; padding:16px 20px`.

### 6.1 Табы-пресеты (над таблицей)
Ряд табов с нижней линией `border-bottom:2px --c-border`. Таб: 13px/600, активный — текст
`--mg-primary-900` + нижняя линия `2px --mg-primary-900` (`margin-bottom:-2px`). Бейдж счётчика
(min-width:20; h:20; radius:999; 11px/700; активный — `--mg-primary-100`/`--mg-primary-900`, иначе
`--c-hover`/`--c-muted`). Состав: `Мои задачи` · `Сегодня` · `Просрочено` · `Все` · **`Выполненные`**.
**KPI-чипов на этой странице нет** (дублируют табы — убраны намеренно).

> *(as-built 2026-06-27: `MyTasksPresetTabs.vue` рендерит **5 табов**, последним —
> «Выполненные», провязанный на `counts.completed` и отдельный эндпоинт
> `GET /api/activities/presets/completed` (бэкенд `ActivityService::PRESETS` включает
> `completed`). Загрузку «Выполненных» ведёт `useMyTasks.fetchPage` через
> `getPresetActivities('completed', …)`.)*

### 6.2 Таблица
Карточка `--c-card; border:1px --c-border; radius:--mg-radius-lg; shadow:--mg-shadow-sm;
overflow:hidden`. `table{width:100%; border-collapse:collapse}`.
- **`<th>`:** `padding:11px 14px; 12px/600; --c-text2; border-bottom:1px --c-border; white-space:nowrap;
  background:--c-card`; значок сортировки `pi-sort-alt` (11px, `--c-muted`) на всех колонках.
- **`<td>`:** `padding:10px 14px; 13px; border-bottom:1px --c-border; vertical-align:middle`. Зебра:
  чётный `--c-hover`, нечётный `--c-card`. Выделенная строка (selectMode) — `--mg-primary-100`.
- **Первый столбец-чекбокс — только в `selectMode`** (по умолчанию скрыт): шапка — «выбрать все»
  (17px), ячейки — выбор строки (17px, on — `--mg-primary-900`+`pi-check`). Столбца «⋮» нет —
  его заменили массовые/точечные действия.

**Порядок колонок строго (по правке Богдана):**

| # | Колонка | Рендер |
|---|---|---|
| 1 | **Срок** | вертикально: время (overdue→`--mg-red-600`/600), под ним пилюля «Просрочено» (`--mg-red-700` на `--mg-red-50`) |
| 2 | **Сделка / компания** | ссылка `--mg-primary-900`/500; нет — «—» (`--c-muted`) |
| 3 | **Этап сделки** | точка 7px {цвет этапа} + название этапа (`--c-text2`); нет сделки — «—» |
| 4 | **Тип** | тег типа (см. §0): иконка + подпись, `radius:sm; padding:2px 9px; 12px/500` |
| 5 | **Текст задачи** | заголовок (выполненная — `line-through`/`--c-muted`); +`pi-bookmark-fill` если закреплена; +`pi-flag-fill` (`--mg-red-600`) если `critical` |
| 6 | **Статус** | пилюля статуса (см. §0; `radius:sm; padding:3px 10px; 12px/600; white-space:nowrap`). **Клик по пилюле открывает inline-Select смены статуса** (as-built ниже) |
| 7 | **Ответственный** | аватар 22px (`--mg-primary-900`, инициал) + полное имя |

Пустой список / «всё выполнено» — состояние с иконкой и текстом (как `MyTasksTable` empty-states).

> *(as-built 2026-06-27: колонка **Статус** — НЕ статичная read-only пилюля. По клику
> `MyTasksTable.vue` раскрывает inline-`Select`, опции которого **ограничены валидными
> переходами** из текущего статуса через `ACTIVITY_STATUS_TRANSITIONS`
> (`front/src/entities/activity.ts`) — зеркало `ActivityStatus::allowedTransitions()` на бэкенде.
> Текущий статус всегда в списке (идемпотентный no-op). Сохранение идёт через выделенный
> эндпоинт `/status` (`activityApi.changeStatus`), оптимистично, с откатом при ошибке. Карты
> переходов: `new → in_progress | rejected`; `in_progress → done | rejected | new`;
> `done → in_progress`; `rejected → new | in_progress`.)*

---

## 7. Tweaks
Панель `TweaksPanel` (как в воронке): секция **Бренд** → `Акцент` (`TweakColor`, опции
`#172747 / #2b2f36 / #312e81 / #0f4d3a` — переопределяет `--mg-primary-900/800`); секция
**Полотно** → `Тёмная тема` (`TweakToggle` — класс `.surface.dark`).

---

## 8. Чек-лист соответствия
- [ ] Шапка и тулбар — одной строкой; плитка `pi-check-square` + «Мои задачи» + подпись слева,
      контролы справа. Кнопки 38px, «⋮» и сегменты — 31px.
- [ ] Сегмент scope (День/Неделя/Месяц) виден только в канбане и управляет видимыми бакетами.
- [ ] Канбан: белые колонки-подложки, спокойная шапка (тинт 13% + полоска цвета 3px), название
      бакета КАПСОМ по центру, счётчик слева, мета-строка периода по центру.
- [ ] Колонка «Просрочено» скрыта, когда нет незакрытых просроченных; появляется автоматически.
- [ ] Карточка: сделка → заголовок → тип+приоритет → ответственный → health-полоса; inset-сигнал
      (overdue/critical); кнопка «Выполнить».
- [ ] Быстрое создание: порядок «сущность(автоподбор) → что сделать → тип → дата → ответственный»;
      без декоративного «+»; список сущностей выпадает при вводе с фильтром по буквам.
- [ ] Массовые действия: «Выбрать» в «⋮» включает режим в текущем виде; BulkBar подсвечен акцентом;
      чекбоксы на карточках канбана и первый столбец в списке (скрыт по умолчанию); действия
      Редактировать/Закрепить/Открыть заново/Удалить работают массово и точечно.
- [ ] Список: табы-пресеты (без KPI-чипов); порядок колонок §6.2 (Срок · Сделка · Этап · Тип ·
      Текст · Статус · Ответственный); сортировка на всех th.
- [ ] Только токены `--mg-*` / `--c-*`; светлая и тёмная темы корректны; скроллбары скрыты.
