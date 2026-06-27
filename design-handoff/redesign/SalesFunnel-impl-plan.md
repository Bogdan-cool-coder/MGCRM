# Имплемент-план редизайна воронки продаж

**Эталон:** `design-handoff/redesign/sales-funnel.html` + `SalesFunnel-spec.md`
**Файлы реализации:** `front/src/pages/DealsPage/` и её `components/`
**Исключения:** карточка сделки (`DealPage`, `/deals/:id`) — не трогаем.
**Дата составления:** 2026-06-22

---

## Что уже хорошо и переиспользуется

| Компонент | Что годно |
|---|---|
| `DealsKanbanBoard.vue` | flex-ряд, `align-items:flex-start`, скролл, skeleton/empty, vuedraggable |
| `DealsKanbanCard.vue` | структура тела (title, amount-row, meta-row, health-strip), логика health/rotting, форматирование дат, inline-редактирование title, bulk-чекбокс |
| `DealsKanbanColumn.vue` | grid заголовка (count · name · _), форматирование суммы, Popover мульти-валюты, Skeleton, draggable |
| `DealsFilterOverlay.vue` | шесть пресетов (open/mine/won/lost/noTask/overdue), логика onApply/onReset/onBackdropClick, поля q/stage_ids/owner_ids/region/city/budget, теги |
| `DealsListView.vue` | DataTable, empty-state, ротинг-классы, форматирование дат, rowMenu |
| `index.vue` | стейт-машина view/filter/pipeline, composables, bulk-диалоги, D&D, экспорт |

---

## Дельты по секциям спеки

### Дельта 1 — `index.vue` + глобальный layout страницы

**Текущее:** страница рендерит `<PageHeader>` (отдельная строка шапки) + `<DealsToolbar>` (отдельная строка тулбара). Между ними — семантический разрыв. Фон страницы `$surface-card`; нет токена `--c-board` как фона всего холста.

**Нужно по спеке:**
- Удалить `<PageHeader>` — его содержимое переезжает внутрь `DealsToolbar` (левая часть).
- Задать фон всей `.deals-page` равным `var(--app-surface-50)` (= `--c-board` в light) — не белый, а чуть серее.
- `.deals-page__board-wrap`: убрать внешний `padding` (борд сам управляет отступами через `padding:16px 20px`).
- Добавить state `pipelineMenuOpen` (ref<boolean>) и `activePipelineName` (computed из `currentPipeline`) — нужны для нового тулбара.
- FilterOverlay остаётся на `Teleport(body)` — не трогаем механику.

**Файл:** `front/src/pages/DealsPage/index.vue`

**Конкретные изменения:**

```
// Удалить:
import PageHeader from '@/components/AppShell/PageHeader.vue'
<PageHeader ... />

// Добавить в <script setup>:
const pipelineMenuOpen = ref(false)
const activePipelineName = computed(() => {
  const pid = currentPipelineId.value
  return pipelines.value.find(p => p.id === pid)?.name ?? t('sales.deals.page.toolbar.pipeline')
})

// Emit из DealsToolbar расширить:
@open-pipeline-menu="pipelineMenuOpen = !pipelineMenuOpen"
@close-pipeline-menu="pipelineMenuOpen = false"
@set-pipeline="onSetPipeline"
```

**SCSS `.deals-page`:**
```scss
.deals-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
  background: var(--app-surface-50); // c-board light
  
  .app-dark & {          // ← идиома .app-dark &, НЕ :global
    background: var(--p-surface-950); // c-board dark
  }
}
```

---

### Дельта 2 — `DealsToolbar.vue` — полная пересборка в одну строку

**Текущее:** тулбар — «Filter | summary | spacer | [kanban/list] | ⋮ menu | + Создать». Нет иконки-портфеля, нет заголовка h1, нет подписи, нет переключателя воронки. Кнопка ⋮ — `pi-ellipsis-h` (горизонтальная). MoreMenu содержит «Сортировку» (не нужна в меню по спеке — сортировка только на th в списке).

**Нужно по спеке §2:**

Порядок (слева направо):

1. **Иконка-плитка 38×38** (`background: $primary-100; border-radius: $radius-md`) с `pi-briefcase 17px` цвета `$primary-900`.
2. **Заголовок-блок:** `<h1>` «Сделки» (`19px / 600 / $surface-900`) + `<div>` «{pipelineName} · {N} сделок · ≈ {sum}» (`12px / $surface-500`). Принимает пропсы `totalDeals`, `totalSum`, `pipelineName`.
3. **Спейсер** `flex:1`.
4. **Кнопка «Поиск и фильтр»** с бейджем счётчика. Состояние: неактивна — `outlined secondary height:38`; активна — `background:$primary-100; color:$primary-900; border-color:$primary-900`. Бейдж `filterCount` — абсолютный кружок `$color-warning-badge` (= `#E8821E`). Принимает проп `filterActive: boolean`, `filterCount: number`.
5. **Переключатель воронки** `height:38 outlined secondary` — иконка `pi-sitemap` + `pipelineName` + `pi-chevron-down`; активен (меню открыто) — `border-color:$primary-900`. `@click` — emit `openPipelineMenu`.
6. **Сегмент вида** — `inline-flex gap:2px background:$surface-100 border-radius:7px padding:3px`. Два `Button` compact `height:31` иконки `pi-th-large`/`pi-list`. Активный: `background:$primary-100 color:$primary-900`. **Реализовать не через PrimeVue `severity` (он не даёт background), а кастовыми классами `.deals-toolbar__view-btn--active`** (уже есть — доработать).
7. **Кнопка «⋮»** — `pi-ellipsis-v` (вертикальный!, сейчас горизонтальный), `outlined`, compact `height:31`, открывает MoreMenu.
8. **Кнопка «Создать сделку»** — primary `height:38 pi-plus`.

**MoreMenu (§2.3) — пересборка:**
Убрать из PrimeVue `Menu :model` секцию «Сортировки» (сейчас есть `sort` с 4 вложенными пунктами). Итоговый порядок:
```
Массовые действия  (pi-check-square)  → emit('enterBulk')
Поиск дублей       (pi-clone)         → заглушка
─────────────────────────────────────
Импорт             (pi-download)      → заглушка
Экспорт            (pi-upload)        → emit('export')
```

**PipelineMenu (§2.4) — новый подкомпонент `DealsPipelineMenu.vue`:**
Позиция `absolute top:42 right:0 z-index:30`. Пункты: `pi-sitemap Продажи / воронка продаж`, `pi-flag Онбординг / внедрение и запуск`, `pi-users Партнёрская / реферальные сделки` + разделитель + `pi-cog Настроить воронки`. Активный пункт — `background:$primary-100` + `pi-check` справа. Данные берутся из пропа `pipelines: PipelineDto[]`. Клик — emit `setPipeline(id)`.

**Файл:** `front/src/pages/DealsPage/components/DealsToolbar.vue`
**Новый файл:** `front/src/pages/DealsPage/components/DealsPipelineMenu.vue`

**Добавить/изменить пропсы:**
```ts
defineProps<{
  activeView: DealsView
  totalDeals: number
  totalSum: string
  pipelineName: string      // NEW — заменяет отдельную подпись из PageHeader
  filterActive: boolean     // NEW
  filterCount: number       // NEW
  pipelines: PipelineDto[]  // NEW
  pipelineMenuOpen: boolean // NEW
}>()
```

**SCSS DealsToolbar — ключевые правки:**
```scss
.deals-toolbar {
  gap: $space-3;       // было $space-2; нужно 12px
  padding: 14px $space-5; // 14px 20px
  border-bottom: 1px solid $surface-200;
  background: $surface-card;
  flex-shrink: 0;
  flex-wrap: wrap;
  position: relative; // для позиционирования PipelineMenu

  .app-dark & {
    background: var(--p-surface-900);
    border-bottom-color: var(--p-surface-700);
  }
}

// Иконка-плитка
.deals-toolbar__section-icon {
  width: 38px; height: 38px; flex-shrink: 0;
  background: $primary-100;
  border-radius: $radius-md;
  display: inline-flex; align-items: center; justify-content: center;

  .app-dark & {
    background: rgba(23, 39, 71, 0.35);
  }
  
  i { font-size: 17px; color: $primary-900; }
}

// Заголовочный блок
.deals-toolbar__title-block { display: flex; flex-direction: column; }

.deals-toolbar__h1 {
  font-size: 19px; font-weight: 600; color: $surface-900;
  margin: 0; line-height: 1.1;

  .app-dark & { color: var(--p-surface-50); }
}

.deals-toolbar__subtitle {
  font-size: 12px; color: $surface-500; margin-top: 2px;

  .app-dark & { color: var(--p-surface-400); }
}

// Кнопка фильтра
.deals-toolbar__filter-wrap { position: relative; display: inline-flex; }

.deals-toolbar__filter-btn {
  height: 38px; // override PrimeVue default
}

.deals-toolbar__filter-btn--active {
  background: $primary-100 !important;
  color: $primary-900 !important;
  border-color: $primary-900 !important;

  .app-dark & {
    background: rgba(23, 39, 71, 0.4) !important;
    border-color: $primary-300 !important;
    color: $primary-300 !important;
  }
}

// Бейдж фильтра
.deals-toolbar__filter-badge {
  position: absolute; top: -7px; right: -7px;
  min-width: 18px; height: 18px; border-radius: 9px;
  background: $color-warning-badge; color: #fff;
  font-size: 10px; font-weight: 700;
  display: inline-flex; align-items: center; justify-content: center; padding: 0 4px;
}

// Переключатель воронки
.deals-toolbar__pipeline-btn {
  height: 38px;
  gap: $space-1;
  // outlined secondary — базовый стиль из PrimeVue
}

// Сегмент вида
.deals-toolbar__views {
  display: inline-flex; gap: 2px;
  background: $surface-100; border-radius: 7px; padding: 3px;

  .app-dark & {
    background: var(--p-surface-800);
  }
}

.deals-toolbar__view-btn { height: 31px; }

.deals-toolbar__view-btn--active {
  background: $primary-100 !important; color: $primary-900 !important;

  .app-dark & {
    background: rgba(23, 39, 71, 0.45) !important;
    color: var(--p-primary-200) !important;
  }
}

// Кнопка ⋮
.deals-toolbar__more-btn { height: 31px; }
```

---

### Дельта 3 — `DealsFilterOverlay.vue` — переместить и пересобрать

**Текущее:** FilterOverlay — `position:fixed top:0 left:0` через `Teleport(body)`. По спеке — FilterPanel раскрывается ПРЯМО ПОД TopBar (не как оверлей поверх всего), `border-bottom`, `background:var(--app-surface-50)`.

**Решение — два варианта (рекомендую Вариант А):**

**Вариант А (предпочтительный):** Не переводить в Teleport. Рендерить `<DealsFilterPanel>` прямо в `index.vue` между тулбаром и бордом через `v-if="filterOverlayVisible"`. Это даёт поведение «раскрывается под шапкой», борд смещается вниз — именно как в спеке. Backdrop убрать.

```html
<!-- в index.vue, между DealsToolbar и board-wrap -->
<DealsFilterPanel
  v-if="filterOverlayVisible"
  :stages="currentStages"
  :users="usersForFilter"
  :filters="toOverlayFilters()"
  @close="filterOverlayVisible = false"
  @apply="onFilterApply"
  @reset="onFilterReset"
/>
```

Переименовать файл не обязательно — можно оставить `DealsFilterOverlay.vue`, но убрать Teleport и позиционирование fixed.

**Изменения в компоненте:**

1. Удалить `<Teleport to="body">`, `v-if="visible"` на backdrop и панели.
2. Изменить SCSS: убрать `position:fixed`, `z-index`, `box-shadow:$shadow-overlay-sm`. Вместо этого:
   ```scss
   .filter-overlay {
     border-bottom: 1px solid $surface-200;
     background: var(--app-surface-50);
     padding: $space-4 $space-5;  // 16px 20px
     flex-shrink: 0;
     
     .app-dark & {
       background: var(--p-surface-950);
       border-bottom-color: var(--p-surface-700);
     }
   }
   ```
3. **Поле «Поиск»** — добавить поверх пресетов, `max-width:460px`, иконка `pi-search` абс. left:12 + `InputText height:38 padding-left:34px border:1px solid $surface-200 border-radius:$radius-md`. Это поле уже есть в компоненте (`localFilters.q`), просто перенести из колонки «Свойства» наверх, до пресетов.
4. **Пресеты** — переделать из `ToggleButton` в **кастомные pill-чипы** (`.filter-overlay__chip`), потому что `ToggleButton` не даёт `border-radius:999px` и `padding:6px 13px` нативно. Каждый чип: inactive `border:1px $surface-200 color:$surface-600`; active — цвет severity (brand/success/danger/warning) + `pi-check` слева.
5. **Сетка полей** — переделать с Bootstrap `row/col-md-4` на CSS grid `grid-template-columns: repeat(4,1fr); gap:14px 18px`. **Убрать «Компания»** — её нет в спеке. Порядок полей строго по §3: Ответственный / Этап / Продукт / Регион-страна / Город / Бюджет (два инпута) / Теги / Период создания.
6. **Блок «Скрытые статусы»** — новый раздел `max-width:50%`, раскрывающийся аккордеон с тумблерами. Получает проп `hiddenStages: BoardColumnDto[]` (список скрытых колонок с `stage.id`, `stage.name`, `stage.color`, `total`). Emit: `@toggle-hidden-stage="id"` → родитель вызывает `toggleHiddenStage(id)`. Тумблер: кастовый элемент (не PrimeVue ToggleSwitch, который имеет другой размер) — `34×19 border-radius:999px; background: on ? $primary-900 : $surface-200; кружок 15×15`.
7. **Футер** — `justify-content:flex-end gap:$space-2 margin-top:$space-4`. «Сбросить» `text secondary`, «Применить» `primary pi-check`.

**Добавить пропс:**
```ts
defineProps<{
  // ... существующие
  hiddenStages: BoardColumnDto[]  // NEW
}>()

// Добавить emit:
emit('toggleHiddenStage', id: number)
```

---

### Дельта 4 — `DealsKanbanColumn.vue` — шапка колонки

**Текущее:** шапка имеет `background: stageColor` (полная заливка цветом) для «ярких» цветов и `background: var(--p-surface-700)` + `borderLeft` для «мягких» в dark. Плюс — кнопка «+» в шапке справа.

**Нужно по спеке §4.1:**
- Шапка: `background: color-mix(in srgb, {stage.color} 13%, --c-card)` (мягкий тинт, не полная заливка).
- `border-top: 3px solid {stage.color}` (цветная полоска сверху, не border-left).
- Удалить кнопку «+» из шапки. По спеке её нет — создание только через «Создать сделку» в тулбаре.
- Ширина колонки: `width:284px` (сейчас `280px` — +4px).
- `align-self:flex-start` — уже есть через `align-items:flex-start` на контейнере.
- `max-height:100%` — добавить на колонку.
- Бейдж-счётчик: `background:$surface-card; border:1px solid $surface-200; border-radius:999px; padding:1px 0; min-width:26px; text-align:center` (сейчас просто цвет).
- Название: `14px/700 letter-spacing:0.04em text-transform:uppercase` (сейчас `$font-size-md`=16px; нужно именно 14px; text-transform нет).
- Правая ячейка grid — пустой спейсер `<span />` (вместо кнопки «+»).
- Сумма: `color:$surface-500` (сейчас `$primary-color` — слишком активный).

**Конкретные правки в SCSS:**

```scss
.kanban-col {
  width: 284px;
  min-width: 284px;
  // ... остальное без изменений
}

.kanban-col__header {
  padding: 11px 13px 9px;
  border-top: 3px solid var(--stage-color, transparent); // CSS custom prop, задаётся :style
  // border-bottom остаётся
  background: var(--stage-color-tint); // задаётся :style через color-mix
  
  .app-dark & {
    // не override фон — color-mix работает относительно --c-card,
    // который в тёмной теме = var(--p-surface-900); результат тинта автоматически тёмный
  }
}

// Title row: grid 34px 1fr 34px
.kanban-col__title-row {
  display: grid;
  grid-template-columns: 34px 1fr 34px;
  align-items: center;
  margin-bottom: $space-1;
}

.kanban-col__count {
  font-size: 12px; font-weight: 700; color: $surface-600;
  background: $surface-card; border: 1px solid $surface-200;
  border-radius: 999px; padding: 1px 0; min-width: 26px;
  text-align: center; justify-self: start;
  
  .app-dark & {
    background: var(--p-surface-800);
    border-color: var(--p-surface-600);
    color: var(--p-surface-200);
  }
}

.kanban-col__name {
  text-align: center;
  font-size: 14px; // было $font-size-md = 16px
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: $surface-800;
  
  .app-dark & { color: var(--p-surface-50); }
}

// Убрать .kanban-col__add-btn (кнопка «+» уходит)

.kanban-col__sum {
  font-size: 12px; font-weight: normal;
  color: $surface-500;
  cursor: default; // убрать pointer, убрать underline hover
  
  .app-dark & { color: var(--p-surface-400); }
}
```

**Конкретные правки в `<script setup>`:**

```ts
// headerStyle — упрощённая версия:
const headerStyle = computed(() => {
  const color = stageColor.value
  if (!color) return {}
  return {
    '--stage-color': color,
    '--stage-color-tint': `color-mix(in srgb, ${color} 13%, var(--app-surface-card))`,
    borderTop: `3px solid ${color}`,
    backgroundColor: `color-mix(in srgb, ${color} 13%, var(--app-surface-card))`,
  }
})

// headerTextColor / headerTextColorMuted — больше не нужны (текст всегда $surface-*)
// Удалить BRIGHT_COLORS, SOFT_TEXT_MAP, SOFT_DARK_BORDER_MAP
// (они были нужны только для яркого фона)
```

**`<template>` — убрать кнопку «+»:**
```html
<!-- Было: -->
<Button icon="pi pi-plus" ... @click="emit('addDeal', column.stage.id)" />
<!-- Заменить на: -->
<span />  <!-- пустой правый элемент grid -->
```

**Убрать emit `addDeal`** из компонента (он теперь не нужен). Убрать `@add-deal="emit('addDealToStage', $event)"` из `DealsKanbanBoard.vue`. Убрать `onAddDealToStage` из `index.vue` — `createDrawerStageId` всегда `null` при создании через тулбар.

**«Загрузить ещё»** — убрать кнопку «Ещё N» по спеке §4.1 («Все карточки показаны»). Удалить блок `.kanban-col__load-more` и `emit('loadMore')`. Борд грузит все карточки сразу.

> **Требуется backend:** API `GET /api/sales/boards/{pipelineId}` должен возвращать ВСЕ карточки колонки без пагинации (сейчас есть `has_more` и пагинация через `loadMore`). Если бэкенд не готов — оставить кнопку временно, но скрыть через `v-if="false"` до готовности бэка.

---

### Дельта 5 — `DealsKanbanCard.vue` — точечные правки

**Текущее:** карточка в целом соответствует спеке, но имеет несколько отклонений.

**Отклонения и правки:**

| Что | Текущее | Нужно | Правка |
|---|---|---|---|
| Кнопка быстрой задачи «+» | Есть `.kanban-card__quick-add` (появляется при hover) | Нет по спеке §4.2 | Убрать `<button class="kanban-card__quick-add">` из шаблона и весь CSS `.kanban-card__quick-add`. Функцию `onQuickAdd` и импорт `activityStore` можно оставить — откроются из health-strip по «+ Задача». |
| Размер тела | `padding: $space-3` = 12px | `padding: 11px 12px` | Минимальная правка: ок, разница 1px — принять текущее |
| Дни в стадии — цвет | `$color-danger` при `≥dangerDays`, `var(--p-orange-500)` при warn | `--mg-red-600` при `≥14`, `--mg-orange-700` при `≥7` | Зафиксировать как 14/7 по умолчанию, если `stage.danger_days` нет |
| health-strip «no-task» | Текст «Нет задачи» цвет `$color-warning-text` (#9b4029) | Orange-900 (`$orange-900`) + ссылка «+ Задача» | Проверить совпадение токенов. `$color-warning-text` = #9b4029 = `--mg-orange-900` — совпадает |
| health-strip «ok» фон | `var(--p-surface-50)` | `var(--app-surface-50)` | Привести к app-токену |

**Удалить из `DealsKanbanCard.vue`:**
```html
<!-- Удалить всю секцию quick-add button: -->
<button class="kanban-card__quick-add" ...>
  <i class="pi pi-plus" />
</button>
```

```scss
// Удалить из SCSS:
.kanban-card__quick-add { ... }
.kanban-card:hover .kanban-card__quick-add { ... }
```

---

### Дельта 6 — `DealsListView.vue` — пересборка на `<table>` + KPI-чипы

**Текущее:** список реализован через PrimeVue `DataTable`. Колонки отличаются от спеки (есть `#id`, `Company`, `Created`, `Actions`; нет `Country`, `В статусе`, `Посл. контакт`). Нет KPI-чипов над таблицей. Сортировка через `sortable` на Column, не через кастомные `pi-sort-alt`.

**Нужно по спеке §5:**

**Вариант реализации:** Оставить `DataTable` как контейнер (он управляет пагинацией, striped-rows, empty-state), но заменить набор колонок и добавить KPI-слот над таблицей.

**KPI-блок** (добавить над `DataTable` внутри `.deals-list`):
```html
<div class="deals-list__kpi">
  <DealsKpiChips :deals="deals" />
</div>
```

Создать новый компонент `DealsKpiChips.vue` (обоснование: KPI-блок — самостоятельный переиспользуемый элемент, вычисляет 5 метрик из пропа `deals`):

```
deals-list__kpi:
  background: $surface-card
  border: 1px solid $surface-200
  border-radius: $radius-lg
  box-shadow: $shadow-card
  padding: 12px 14px
  margin-bottom: 14px
  display: flex
  flex-wrap: wrap
  gap: $space-2

Чипы (.deals-kpi-chip):
  display: inline-flex align-items:center gap:7px
  padding: 6px 13px border-radius:999px font-size:13px

  --brand:   bg=$primary-100   color=$primary-900
  --info:    bg=$blue-100      color=$blue-900    (var(--app-blue-100)/var(--app-blue-900))
  --success: bg=$green-100     color=$green-900
  --warning: bg=$orange-100    color=$orange-900
  --danger:  bg=$red-50        color=$red-700
```

Состав и расчёт:
- «В работе: N компаний» — `brand`; N = уникальные `deal.company_id` среди сделок не в `stage.is_won`.
- «Категории L/M/S: nL / nM / nS» — `info`; нужен проп или computed из `deal.category` (если поле есть в `DealDto`). `требуется backend: поле category (L/M/S) в DealDto`.
- «Успешных: N» — `success`; N = deals где `stage.is_won === true`.
- «Без задачи: N» — `warning`; N = deals где `next_task === null`.
- «Просрочено: N» — `danger`; N = deals где `next_task?.is_overdue`.

**Колонки DataTable — новый порядок (строго §5.2):**

| # | Было | Стало |
|---|---|---|
| 1 | `#` id | Название (ссылка, `$primary-color / 500`) |
| 2 | Название | Страна (`color:$surface-500`) |
| 3 | Компания | Сумма (right, bold, `$primary-color`) |
| 4 | Статус | Статус (чип: `color-mix(in srgb, {color} 22%, $surface-card)` + точка 7px + «N. Этап») |
| 5 | Задача | В статусе (`$surface-500`, «N дней» со склонением) |
| 6 | Дней в этапе | Посл. контакт (freshness, цвет) |
| 7 | Сумма | Задача (пилюля/иконка+дата) |
| 8 | Ответственный | Ответственный (аватар 22px + полное имя) |

Удалить колонки: `#` (id), Company, Created, Actions (rowMenu через `pi-ellipsis-v`).

**Добавить в `DealDto` (или вычислять на фронте):**
- `country: string` — `требуется backend: поле country (страна компании) в DealDto / BoardColumnDto`
- `last_contact_at: string | null` — `требуется backend: дата последнего контакта (активности) в DealDto`
- `category: 'L' | 'M' | 'S' | null` — `требуется backend: поле категории сделки в DealDto`

**Freshness-логика (§5.3):**
```ts
function freshnessColor(deal: DealDto): string {
  if (!deal.last_contact_at) return $surface-500 // 'n'
  const days = Math.floor((Date.now() - new Date(deal.last_contact_at).getTime()) / 86400000)
  const isOverdue = deal.next_task?.is_overdue ?? false
  if (isOverdue || days >= 21) return '#e61c14'  // $mg-red-600 → $red-700 токен
  if (days >= 7) return '#e6643a'                 // $mg-orange-700 → нет прямого scss-токена, используй var(--mg-orange-700)
  return '#36a04b'                                // $mg-green-700 → нет прямого токена, var(--mg-green-700)
}
function freshnessText(days: number | null): string {
  if (days === null) return '—'
  if (days <= 1) return t('sales.deals.list.today')
  return t('sales.deals.list.daysAgo', { n: days })
}
```

**Статус-чип в таблице:**
```html
<span class="deals-list__stage-chip" :style="stageChipStyle(data.stage)">
  <span class="deals-list__stage-dot" :style="{ background: data.stage.color }" />
  {{ stageIndex(data.stage) }}. {{ data.stage.name }}
</span>
```
```scss
.deals-list__stage-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 3px 10px; border-radius: $radius-sm;
  font-size: 12px; font-weight: 600; color: $surface-800;
  // background задаётся :style = color-mix(in srgb, {color} 22%, var(--app-surface-card))
  
  .app-dark & { color: var(--p-surface-100); }
}

.deals-list__stage-dot {
  width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
}
```

**Сортировка:** PrimeVue `Column sortable` добавляет свой toggle-иконку. По спеке — `pi-sort-alt` на всех th (без функциональной сортировки в заголовках, только визуал). Рекомендую оставить PrimeVue `sortable` — оно работает, только заменить иконку через `:sort-icon="'pi pi-sort-alt'"` (проп DataTable `sortIcon`).

**Зебра-строки:** `striped-rows` уже есть в DataTable — сохранить.

---

### Дельта 7 — `DealsKanbanBoard.vue` — HiddenColumnsToggle заменить

**Текущее:** есть отдельный компонент `HiddenColumnsToggle.vue` — показывает кнопки для каждой скрытой колонки. По спеке — управление скрытыми колонками перенесено в `FilterPanel` (блок «Скрытые статусы» с тумблерами).

**Правка:** убрать `<HiddenColumnsToggle>` из `DealsKanbanBoard.vue`. Логика `toggleHiddenStage` остаётся в `index.vue` — вызывается через emit из FilterPanel (`@toggle-hidden-stage`). Скрытые колонки отображаются в борде автоматически через `hiddenColumns` (как сейчас — `visibleColumns` + видимые из `hiddenColumns`).

---

## Новые компоненты (обоснование)

| Компонент | Обоснование |
|---|---|
| `DealsPipelineMenu.vue` | Переключатель воронки — изолированный дропдаун, рендерится внутри тулбара. Слишком специфичен для тулбара, не переиспользуется нигде. Обоснование выноса: тулбар и так растёт; изолировать разметку меню. |
| `DealsKpiChips.vue` | KPI над таблицей — самостоятельный блок с вычислениями. Потенциально переиспользуется в дашборде. |

---

## Фазы реализации

### Фаза A — Тулбар-монолит (2–3 ч)
- `DealsToolbar.vue`: удалить `<PageHeader>` из `index.vue`, перестроить тулбар с иконкой, h1, subtitle, кнопкой фильтра с бейджем, сегментом вида (31px), ⋮ вертикальный.
- Создать `DealsPipelineMenu.vue`.
- Обновить MoreMenu (убрать «Сортировки», привести порядок).
- Обновить пропсы тулбара.

### Фаза B — Фон страницы + layout (30 мин)
- `index.vue`: удалить PageHeader, добавить `background:var(--app-surface-50)` на `.deals-page`.
- Убрать padding у `.deals-page__board-wrap` (борд сам отступает).

### Фаза C — FilterPanel рефактор (2–3 ч)
- Убрать Teleport + fixed из `DealsFilterOverlay.vue`.
- Переместить поле поиска вверх.
- Пересобрать пресеты как кастомные pill-чипы.
- Пересобрать grid полей (4 колонки, без «Компании»).
- Добавить блок «Скрытые статусы» с тумблерами.
- Пропс `hiddenStages`, emit `toggleHiddenStage`.
- Обновить `index.vue`: убрать `<DealsFilterOverlay>` с Teleport, добавить `<DealsFilterPanel v-if="filterOverlayVisible">` inline.

### Фаза D — Колонка канбана (1–2 ч)
- `DealsKanbanColumn.vue`: шапка — тинт 13% + border-top 3px, удалить кнопку «+», ширина 284px, буллет-счётчик в pill, name uppercase 14px, сумма $surface-500.
- Убрать «Загрузить ещё» (или спрятать `v-if="false"` до бэкенда).
- Удалить `addDeal` emit из колонки и борда.

### Фаза E — Карточка канбана (30 мин)
- `DealsKanbanCard.vue`: удалить `kanban-card__quick-add`.
- Проверить dark-тема health-strip: `background: var(--p-surface-50)` → `var(--app-surface-50)`.

### Фаза F — Список + KPI-чипы (3–4 ч)
- Создать `DealsKpiChips.vue`.
- `DealsListView.vue`: переупорядочить колонки DataTable по §5.2, добавить freshness, stage-chip, аватар-ответственного, склонение «дней/день/дня», KPI-чипы над таблицей.
- Добавить `DealsKpiChips` над DataTable.
- Убрать ненужные колонки (#, Company, Created, Actions rowMenu).

### Фаза G — HiddenColumnsToggle + финальная чистка (30 мин)
- Убрать `HiddenColumnsToggle.vue` из `DealsKanbanBoard.vue`.
- Проверить: нет `pi-ellipsis-h` (горизонтальный), везде `pi-ellipsis-v`.
- Прогнать `npm run lint:ds`.

---

## i18n-ключи (добавить в `ru.json`)

```json
"sales": {
  "deals": {
    "page": {
      "toolbar": {
        "pipeline": "Воронка",
        "pipelineSettings": "Настроить воронки",
        "searchAndFilter": "Поиск и фильтр"
      },
      "pipeline": {
        "sales": "Продажи",
        "salesSubtitle": "воронка продаж",
        "onboarding": "Онбординг",
        "onboardingSubtitle": "внедрение и запуск",
        "partner": "Партнёрская",
        "partnerSubtitle": "реферальные сделки"
      },
      "filters": {
        "hiddenStages": "Скрытые статусы",
        "hiddenStagesHint": "По умолчанию эти статусы скрыты из воронки. Включите тригер, чтобы отобразить колонку.",
        "hiddenStagesCount": "{n} вкл.",
        "hiddenStagesSettings": "Настроить, какие статусы скрывать",
        "presetNoTask": "Без задач",
        "presetOverdue": "С просроченными"
      },
      "kpi": {
        "inWork": "В работе",
        "inWorkValue": "{n} компаний",
        "categories": "Категории L/M/S",
        "won": "Успешных",
        "noTask": "Без задачи",
        "overdue": "Просрочено"
      },
      "list": {
        "today": "Сегодня",
        "daysAgo": "{n} дн назад",
        "columns": {
          "country": "Страна",
          "inStage": "В статусе",
          "lastContact": "Посл. контакт"
        }
      }
    }
  }
}
```

EN-задел (добавить в `en.json`):
```json
"sales.deals.page.toolbar.pipeline": "Pipeline",
"sales.deals.page.toolbar.pipelineSettings": "Configure pipelines",
"sales.deals.page.kpi.inWork": "Active",
"sales.deals.page.kpi.inWorkValue": "{n} companies",
"sales.deals.page.list.today": "Today",
"sales.deals.page.list.daysAgo": "{n}d ago"
```

---

## Dark-тема — критичные идиомы

Все dark-оверрайды в SCSS используют **`.app-dark &`** (НЕ `:global(.app-dark) &`).

| Элемент | Light | Dark | Идиома |
|---|---|---|---|
| Фон страницы | `var(--app-surface-50)` | `var(--p-surface-950)` | `.app-dark & { background: ... }` |
| Фон тулбара / фильтра | `$surface-card` | `var(--p-surface-900)` | `.app-dark & { background: ... }` |
| h1 тулбара | `$surface-900` | `var(--p-surface-50)` | `.app-dark & { color: ... }` |
| Subtitle тулбара | `$surface-500` | `var(--p-surface-400)` | `.app-dark & { color: ... }` |
| Иконка-плитка bg | `$primary-100` | `rgba(23,39,71,0.35)` | `.app-dark & { background: ... }` |
| Шапка колонки тинт | `color-mix(in srgb,{color} 13%,#fff)` | `color-mix(in srgb,{color} 13%,#3a3b3d)` | CSS `color-mix` уже адаптируется автоматически если `--c-card` задан как CSS-var, но здесь мы передаём `var(--app-surface-card)` в color-mix через `:style` — это работает корректно в dark т.к. `--app-surface-card` реактивен |
| Кнопка активного вида | `$primary-100` | `rgba(23,39,71,0.45)` | `.app-dark & { background: ... }` |
| Бейдж счётчика | `$color-warning-badge` (#E8821E) | без изменений (константа) | — |
| KPI-чипы bg | `$primary-100 / $green-100 / ...` | PrimeVue surface-адаптация | реактивные токены работают автоматически |
| stage-chip | `color-mix(22%, $surface-card)` | автоматически — `$surface-card` тёмный | |

---

## Backend-пробелы

| # | Что нужно фронту | Где нужно | Статус |
|---|---|---|---|
| B1 | `country: string` в `DealDto` (страна компании) | Колонка «Страна» в таблице | Требуется backend |
| B2 | `last_contact_at: string | null` в `DealDto` (дата последней активности) | Колонка «Посл. контакт» + freshness | Требуется backend |
| B3 | `category: 'L' | 'M' | 'S' | null` в `DealDto` | KPI-чип «Категории L/M/S» | Требуется backend |
| B4 | API `GET /api/sales/boards/{id}` без пагинации (все карточки сразу) | Убрать «Загрузить ещё» | Требуется backend. Временно: скрыть кнопку `v-if="false"` |
| B5 | `stageIndex` (порядковый номер этапа в воронке) в `BoardColumnDto` или `PipelineStageDto` | Чип статуса в таблице «N. Этап» | Computed на фронте через `currentStages.findIndex` — backend не нужен |

---

## Саммари: ключевые дельты current → target

| Зона | Current | Target |
|---|---|---|
| **Layout страницы** | PageHeader (отд. строка) + Toolbar (отд. строка); белый фон | Единая строка TopBar (h1 + подпись + контролы); серый фон `$surface-50` |
| **Тулбар** | Нет иконки-портфеля, нет h1, нет voronka-picker; ⋮ горизонтальный; MoreMenu содержит сортировку | Полная строка §2; ⋮ вертикальный; MoreMenu без сортировки; бейдж фильтров |
| **FilterPanel** | Фиксированный оверлей поверх страницы (Teleport+fixed) | Inline-блок под тулбаром; поиск вверху; pill-пресеты; grid 4col без «Компании»; блок «Скрытые статусы» с тумблерами |
| **Колонка канбана** | Шапка = яркий фон (полная заливка); кнопка «+» в шапке; 280px; сумма = primary; загрузить ещё | Шапка = тинт 13% + border-top 3px; без «+»; 284px; сумма = muted; без «загрузить ещё» |
| **Карточка канбана** | Кнопка быстрой задачи «+» при hover | Убрана |
| **Список** | DataTable с id/#/Company/Created/Actions; нет freshness, нет KPI-чипов, stage-чип = яркий | Порядок §5.2 (8 колонок); KPI-чипы над таблицей; freshness; stage-chip = тинт 22% |

**Backend-блокеры:** B1 country, B2 last_contact_at, B3 category (все три — для ListVIew полного). B4 (no-paging) — низкий приоритет, временно скрыть кнопку. Канбан и базовый список работают уже сейчас без бэка.
