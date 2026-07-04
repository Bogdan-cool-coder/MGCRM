# ТЗ: Рестайл хаба аналитики по DS2 (визуальный слой)

**Зачем:** привести все табы хаба аналитики (`DashboardPage`) к единому визуальному языку
DS2. Вкладка «Обзор» уже пересобрана по `design-handoff/redesign/dashboard.html` (Э11) и
служит **эталоном стиля** — остальные табы (Планы / Реестр / График / Рейтинг) построены до
DS2 и выбиваются: плоская серая фильтр-полоса, «голые» таблицы и карточки без единой
карточной подложки, самодельные KPI-плитки. Задача — **только визуальный рестайл**: данные,
эндпоинты, композаблы, dirty-guard, export-мост, поведение — **не трогаем**.

**Где в коде:** `front/src/pages/DashboardPage/`
**Источник стиля (в репо):**
`design-handoff/redesign/dashboard.html` (эталон: `WidgetCard`, шапка-тулбар, сегментед,
KPI-карты, стек-бары) · `front/src/pages/ManagerCabinetPage/components/CabinetToolbar.vue`
(реализованный toolbar-паттерн — реюз) · `.claude/skills/macroglobal-design/templates/data-table-page`
+ `crm-page` (тулбары/таблицы) · `docs/designer-charter.md` (токен-дисциплина, закон
dark-селекторов).
**Эталон-экран (реальный код):** `front/src/pages/DashboardPage/components/tabs/TabOverview.vue`
+ `WidgetFunnelTable.vue` / `WidgetForecast.vue` (карточная подложка `Card.widget-card`,
DataTable `striped-rows row-hover`, семантические точки/Tag).

> **Правило номер один (весь документ):** ноль изменений в `.ts`-композаблах,
> props/emit-контрактах, эндпоинтах, dirty-guard, export-мосте. Всё, что ниже — правки
> `<template>` и `<style>` в `.vue`. Если фронтендер видит, что визуальная правка тянет за
> собой изменение логики — это **открытый вопрос ко мне**, не «решаю сам».

---

## 0. Ключевая диагностика (что именно выбивается)

| Зона | Сейчас | Эталон DS2 |
|------|--------|-----------|
| Шапка хаба | `PageHeader` (56px) + отдельная `AnalyticsFilterBar` (серый `Card`) + отдельный `SelectButton` таб-стрип = **3 разорванных ряда** | Один тулбар-ряд: icon-tile + заголовок + сегментед-табы + Excel (как `CabinetToolbar` + `dashboard.html` Header) |
| Фильтр-полоса | `Card` с `p-card-body` padding — плоская серая плита, «Месяц/Год · ‹ Июль 2026 › · Оперативный/Годовой · 2 дропдауна» ощущается чужеродно | Компактная фильтр-строка на карточной подложке `widget-card`, единый ритм с виджетами |
| «Планы» матрицы | `DataTable show-gridlines` без карточной обёртки, самодельный `SelectButton` метрик | Матрица в `widget-card`-подложке; метрик-переключатель — pill-сегментед в шапке карточки |
| «Реестр» | `<section>` c самодельным header + `DataTable` без обёртки, `Tag`-бейдж суммы | `widget-card` на секцию + StatCard-полоса сумм сверху |
| «График» | Есть `Card` (уже ближе), но самодельная KPI-плитка-грид сверху не в едином стиле | KPI-полоса → единый `widget-card`-стат-стиль; календарь и график — та же карточная подложка |
| «Рейтинг» | самодельная `leader-card` + «голый» `DataTable`, controls вне тулбара | Лидер-карта на `widget-card`-подложке; год+режим — в фильтр-строку |

---

## 1. Общий каркас хаба — единый тулбар + карточная фильтр-строка

### 1.1 Wireframe (target)

```
┌───────────────────────────────────────────────────────────────────────────────┐
│ ▓ [icon] Аналитика продаж          [Обзор][Планы][Реестр][График][Рейтинг]  ⤓Excel│  ← HubToolbar (1 ряд, $surface-card, border-bottom)
├───────────────────────────────────────────────────────────────────────────────┤
│ ╭───────────────────────────────────────────────────────────────────────────╮ │
│ │ [Месяц│Год]  ‹  Июль 2026  ›   [Оператив.│Годовой]   ⌄Воронка   ⌄Менеджер   │ │  ← AnalyticsFilterBar (widget-card подложка, компакт)
│ ╰───────────────────────────────────────────────────────────────────────────╯ │
│                                                                                 │
│  <активный таб>                                                                 │
└───────────────────────────────────────────────────────────────────────────────┘
```

### 1.2 Изменения в `index.vue`

**Что меняем:** сейчас шапка — `PageHeader` (title+icon+Excel в `#actions`) + `dashboard-page__tabs`
(`SelectButton` таб-стрип) — это два отдельных ряда. Сливаем их в один тулбар-ряд
**по образцу `CabinetToolbar`**: icon-tile + заголовок слева, сегментед-табы + Excel справа.

Реализация — **новый презентационный компонент** `components/HubToolbar.vue` (обоснование см.
§7), в который переезжают: icon-tile, заголовок, `SelectButton` таб-стрип, кнопка Excel.
`index.vue` отдаёт в него props (`activeTab`, `tabOptions`, `showExport`, `exporting`) и ловит
emit (`update:activeTab`, `export`) — **вся логика (`onTabSelect`, `tabStripKey`, veto-снап,
`onExport`) остаётся в `index.vue`**, HubToolbar только рендерит.

> Причина не оставлять `PageHeader`: `PageHeader` — паттерн листовых страниц (одна строка
> title+actions). Хаб аналитики по DS2 (dashboard.html) — тулбар с интегрированным
> сегментед-переключателем в той же строке, ближе к `CabinetToolbar`. Оставлять и `PageHeader`,
> и отдельный таб-стрип = тот самый «разорванный» вид.

**Зоны HubToolbar:**

| Зона | Элемент | Токены / props |
|------|---------|----------------|
| icon-tile | `<span>` 38×38, `pi pi-chart-bar` | `$radius-md`, bg `var(--p-primary-100)`, icon `var(--p-primary-color)`; dark: bg `var(--p-primary-900)`, icon `var(--p-primary-100)` — **1:1 как `CabinetToolbar__icon-tile`** |
| Заголовок | `<h1>` `t('dashboard.hub.title')` | `$font-size-lg`, `$font-weight-semibold`, `$surface-900` |
| spacer | `<span>` flex:1 | — |
| Таб-стрип | `SelectButton` (тот же, что сейчас, с `:key="tabStripKey"`) | сегментед по `_segmented.scss`; см. §1.4 |
| Excel | `Button icon="pi pi-file-excel" :label severity="secondary" outlined :loading` | как сейчас; `v-if="showExport"` |

**Раскладка (scoped на HubToolbar):**
```scss
.hub-toolbar {
  display: flex; align-items: center; flex-wrap: wrap;
  gap: $space-3; padding: $space-3 $space-5;   // = CabinetToolbar
  background: $surface-card;
  border-bottom: 1px solid $surface-200;
  .app-dark & { border-bottom-color: var(--p-surface-200); }
}
```
Отступ страницы: `.dashboard-page` уже имеет отрицательные margin'ы, чтобы тулбар доезжал до
краёв контента (как сейчас `PageHeader`). Сохранить; HubToolbar встаёт на место `PageHeader`.

### 1.3 AnalyticsFilterBar — из «серой плиты» в компактную фильтр-строку

**Что меняем — только `<template>` обёртка + `<style>`.** Props/emit/`periodLabel` не трогаем.

- Сейчас: `<Card class="analytics-filter-bar mb-4">` с `:deep(.p-card-body){padding:$space-3}` и
  **двумя рядами** (`__row` × 2). Это визуально читается как отдельная серая панель.
- Target: **одна карточная подложка `widget-card`-стиля** (тот же фон/бордер/радиус/тень, что у
  виджетов Обзора), но **в один горизонтальный ряд с wrap** — фильтры выстраиваются как компактная
  строка над контентом, а не как форма из двух строк.

**Раскладка (target):**
```scss
.analytics-filter-bar {
  display: flex; flex-wrap: wrap; align-items: center;
  gap: $space-3;
  padding: $space-3 $space-4;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;              // = widget-card
  box-shadow: $shadow-sm;
  margin-bottom: $space-4;
  .app-dark & { border-color: var(--p-surface-200); }
}
```
Внутри — убрать деление на `__row` × 2, оставить один flex-ряд: `[granularity segmented]`
`[stepper ‹ label ›]` `[layer segmented]` `[Select воронка]` `[Select менеджер]`. На ≤768px
(медиа уже есть) — колонка, элементы на всю ширину. **Убрать** `:deep(.p-card-body)` (Card больше
нет). `periodLabel` тот же (`$surface-900` / dark `var(--p-surface-800)` — уже корректно).

**Divider-акцент (опционально, для читаемости группировки):** между «layer segmented» и «Select
воронка» допускается тонкий вертикальный разделитель `1px` высотой ~20px цветом `$surface-200`
(dark: `var(--p-surface-200)`) — визуально отделяет period-контролы от scope-фильтров. Не
обязателен; если добавляем — только этот токен.

### 1.4 Сегментед-контролы (таб-стрип + granularity + layer + метрики + режим рейтинга) — единый вид

Все `SelectButton` в хабе должны выглядеть одинаково — как pill-сегментед из dashboard.html
(«Продукты | Менеджеры» в Топ-10) и `_segmented.scss`. Это **уже даёт глобальный preset
PrimeVue**, отдельных правок обычно не нужно — но проверить, что нигде нет локальных
`:deep(.p-selectbutton)`-оверрайдов, ломающих единство. Если таб-стрип в HubToolbar визуально
крупнее внутренних сегментедов (метрики/layer) — это ок (главный переключатель), размер задаётся
глобальным preset'ом, локальные размеры не хардкодим.

---

## 2. Таб «Планы» — матрица в карточной подложке, метрики pill-сегментед

**Файлы:** `components/tabs/TabPlans.vue`, `components/plans/MetricIncome.vue` (+ Product/Tasks/
Conversions — тот же паттерн), `components/plans/PlanMatrix.vue`, `PlanSaveBar.vue`.
**Объём: M** (обёртка карточкой + шапка метрик; логика не трогается).

### 2.1 Метрик-переключатель — в шапку карточки

Сейчас `tab-plans__metrics` — голый `SelectButton` над контентом. Target: обернуть весь таб в
`widget-card`-подложку, а метрик-сегментед посадить в **шапку карточки** (как «Продукты |
Менеджеры» в правом верхнем углу Топ-10 виджета dashboard.html).

**Wireframe:**
```
╭─────────────────────────────────────────────────────────────────────────╮
│ План: новые продажи          [Поступления│Продукты│Задачи│Конверсии]      │ ← card header
├─────────────────────────────────────────────────────────────────────────┤
│ ┌── DataTable матрицы (frozen «Сотрудник», зебра/hover, sticky ИТОГО) ──┐ │
│ │ Сотрудник │ Валюта │ Всего │ Янв │ Фев │ … │ Год │                    │ │
│ └───────────────────────────────────────────────────────────────────────┘ │
│ [ save-bar: N изменений · Скопировать прошлый · Сохранить ]                │
╰─────────────────────────────────────────────────────────────────────────╯
```

**Зоны:**

| Зона | Компонент | Props / токены |
|------|-----------|----------------|
| Обёртка таба | PrimeVue `Card` (`widget-card`, как в Обзоре) | `#title` слот — заголовок метрики + сегментед; `#content` — матрица + save-bar |
| Заголовок метрики | `<span>` — динамический (`Поступления` / `Продукты` / `Задачи` / `Конверсии`) | `$font-size-md $font-weight-semibold` (= `funnel-header__title`) |
| Метрик-сегментед | `SelectButton` (тот же `metricTab`, `:key="metricStripKey"`) | справа в шапке карточки (`justify-content: space-between`) |
| Матрица | `PlanMatrix` (без изменений разметки) | см. §2.2 |
| Save-bar | `PlanSaveBar` (без изменений) | остаётся под матрицей внутри `#content` |

> **Важно:** обёртка `Card` **не должна** ломать `scroll-height="flex"` матрицы и sticky-футер.
> `Card` даёт padding через `p-card-content` — матрице нужен полный контроль высоты. Если
> `Card`-обёртка мешает `scroll-height="flex"` (частый конфликт flex-контейнера) — использовать
> **не `Card`, а div-подложку** `widget-card`-стилем (`background:$surface-card; border;
> border-radius:$radius-lg; box-shadow:$shadow-sm; padding:$space-4`) с `display:flex;
> flex-direction:column; min-height:0`, чтобы DataTable сохранил flex-скролл. Это [ОВ-1].

### 2.2 PlanMatrix — таблица к data-table-page стилю

Сейчас: `DataTable size="small" show-gridlines`. Target — привести к DS2 data-table-стилю
(как `funnel-table` / DataTable в templates):

- **Убрать `show-gridlines`** → `striped-rows` + `row-hover` (единый вид с Обзором и списками).
  Границы ячеек-сетки заменяются зеброй — это DS2-паттерн. **[ОВ-2]:** для матрицы плотных
  числовых ячеек grid-lines иногда читаемее; если PO хочет сохранить сетку в матрице планов —
  оставить `show-gridlines` только здесь как обоснованное исключение. По умолчанию — зебра.
- **frozen «Сотрудник»** (уже есть) — оставить, добавить визуальную тень-разделитель как в
  sticky-паттерне (PrimeVue frozen сам рисует; проверить что фон frozen-ячейки = `$surface-card`,
  не прозрачный, чтобы прокручиваемые колонки не просвечивали в dark).
- Шапка `thead` — `white-space:nowrap` (есть). Ячейки padding `$space-2 $space-3` (есть).
- Dirty-инпут бордер `var(--p-primary-color)` (есть, корректно) — не трогать.
- `PctTag` и `plan-matrix__fact` — оставить.
- Футер ИТОГО (`tfoot`) — оставить bold; убедиться что при зебре он визуально отделён (PrimeVue
  даёт свой фон tfoot).

Аналогично **MetricProductIncome / MetricTasks / MetricConversions** — те же обёртка `Card`/
подложка + зебра. `ConversionPairsTable` / `StageConversionFunnel` внутри Conversions — обернуть
в ту же подложку, если сейчас голые.

### 2.3 `PlansEmpty` — сверить с DS empty-паттерном

`PlansEmpty` (icon + title + hint) — убедиться, что icon `$font-size-icon-xl`, `$surface-400`,
title `$surface-800`, hint `$surface-500` (как в других табах). Если так — не трогаем.

---

## 3. Таб «Реестр» — секции в widget-card + StatCard-полоса сумм

**Файлы:** `components/tabs/TabRegistry.vue`, `components/registry/RegistryTable.vue`.
**Объём: M.**

### 3.1 Wireframe (target)

```
[Message: multi-currency / no-date banner — как есть]
┌─────────────────────┐  ┌─────────────────────┐
│ ▓ К поступлению      │  │ ▓ Дожим (просрочка) │   ← StatCard-полоса сумм сверху (§3.2)
│   1 240 000 ₽        │  │   380 000 ₽         │
└─────────────────────┘  └─────────────────────┘
╭─────────────────────────────────────────────────────────────────────────╮
│ Ожидаемые поступления · Июль 2026                                         │ ← widget-card header
├─────────────────────────────────────────────────────────────────────────┤
│ DataTable (striped/hover): Дата · Сделка · Компания · Продукты · Сумма …  │
│ ИТОГО: 1 240 000 ₽                                                        │
╰─────────────────────────────────────────────────────────────────────────╯
╭─────────────────────────────────────────────────────────────────────────╮
│ ▼ Дожим · Июль 2026                                              danger   │
│ DataTable …                                                               │
╰─────────────────────────────────────────────────────────────────────────╯
```

### 3.2 StatCard-полоса сумм сверху

Реестр возвращает `report.expected.total_base_kopecks` и `report.squeeze.total_base_kopecks` —
это готовые суммы. Вынести их в **полосу из 2 StatCard** над таблицами (по образцу
KPI-полосы Обзора / `EntityKpiStrip`), чтобы итоги считывались сразу, а не только в бейджах/футерах.

| StatCard | Значение | Акцент |
|----------|----------|--------|
| «К поступлению» | `formatMkMoney(expected.total_base_kopecks)` | `info` (иконка `pi pi-wallet` / `pi pi-arrow-circle-down`) |
| «Дожим» | `formatMkMoney(squeeze.total_base_kopecks)` | `danger` (иконка `pi pi-exclamation-triangle`) |

Стиль плитки — **как `tab-schedule__kpi`** (уже DS2-корректная плитка: `padding:$space-3 $space-4;
border-radius:$radius-lg; border:1px solid $surface-200; background:$surface-card`), чтобы не
плодить новый вид. Значение `danger` → `var(--p-red-500)` / dark `var(--p-red-400)`; `info`-сумма
— `$surface-900`. Grid: 2 колонки, ≤768px — 1. Это переиспользование существующего DS-примитива
(плитка `tab-schedule__kpi`) — **вынести общий класс** или продублировать стилем (не логикой).
**[ОВ-3]:** если суммы уже достаточно видны в бейдже секции — PO может счесть StatCard-полосу
избыточной. По умолчанию добавляем (юзер просил «StatCard-полоса сверху если есть суммы» — суммы есть).

### 3.3 RegistryTable — секцию в widget-card

- Обернуть каждую `<section class="registry-table">` в `widget-card`-подложку (`$surface-card`,
  border `$surface-200`, `$radius-lg`, `$shadow-sm`, padding `$space-4`). Сейчас секция «голая».
- Заголовок секции (`registry-table__title` + `__period`) остаётся как есть — он уже
  DS-корректен (`$font-size-md $font-weight-semibold $surface-800`); **бейдж суммы** (`Tag
  severity`) можно оставить в шапке секции ИЛИ убрать, раз сумма теперь в StatCard-полосе §3.2
  **[ОВ-4]**. По умолчанию: оставить бейдж (дублирование per-секция полезно при скролле).
- `DataTable` — добавить `striped-rows row-hover` (сейчас нет), padding ячеек оставить.
  No-date row-tint (`color-mix yellow`) — оставить, он корректен для обеих тем.
- Empty-state секции (`registry-table__empty`) — уже DS-корректен, не трогаем.

---

## 4. Таб «График» — карточная подложка + KPI-полоса в едином стиле

**Файлы:** `components/tabs/TabSchedule.vue`, `components/schedule/NpCalendar.vue`,
`ScheduleLegend.vue`. **Объём: S** (уже ближе к DS — есть `Card`; правки косметические).

### 4.1 Что уже хорошо / что правим

- KPI-полоса (`tab-schedule__kpi`) — **уже DS2-корректная плитка** (эталон для §3.2). Не трогаем,
  наоборот — переиспользуем её стиль в Реестре.
- График и календарь — уже в `Card`. Убедиться, что `Card` = тот же `widget-card`-вид, что в
  Обзоре (`$surface-card` фон, `$radius-lg`, тень). Сейчас `.tab-schedule__card{background:
  $surface-card}` + `:deep(.p-card-title)` — ок; добавить border `$surface-200` и `$shadow-sm`
  если Card их не даёт по preset'у, чтобы совпало с виджетами Обзора.
- **Легенда** (`ScheduleLegend`) — семантические свотчи plan(blue)/fact(green)/slippage(red)/
  weekend(surface) уже по DS (dark-aware). Не трогаем. Убедиться, что легенда в шапке карточки
  графика выровнена (`justify-content:space-between` — есть).

### 4.2 NpCalendar — легенда по DS + сверка

- Плитки дня (`np-calendar__tile`) — `$surface-card` фон, `$surface-200` бордер, weekend-dim с
  корректным dark-override (есть), today-обводка `--p-primary-color` в dark (есть),
  squeeze-dot `--p-red` (есть). Всё DS-корректно — **не трогаем**.
- Единственное: убедиться, что карточка-обёртка календаря (`tab-schedule__card`) даёт достаточный
  padding вокруг сетки (`p-card-content`), чтобы плитки не липли к бордеру карточки.
- Легенда цветов календаря: сейчас у календаря нет своей легенды (squeeze-dot без подписи). Если
  юзер хочет «легенда по DS» и для календаря — добавить мини-легенду под сеткой: «● дожим» цветом
  `var(--p-red-500)` (dark `--p-red-400`), стилем `schedule-legend__item`. **[ОВ-5]** — нужна ли
  отдельная легенда календаря или достаточно tooltip'ов. По умолчанию добавляем строку «● дожим».

---

## 5. Таб «Рейтинг» — лидер-карта на widget-card, controls в фильтр-строку

**Файлы:** `components/tabs/TabRating.vue`, `components/rating/LeaderCard.vue`,
`RatingTable.vue`. **Объём: M.**

### 5.1 Год + режим — из «controls» в фильтр-строку

Сейчас `tab-rating__controls` (Год `Select` + режим `SelectButton`) — отдельный блок над
контентом, вне общей фильтр-строки хаба. Визуально это ещё один разорванный ряд.

Target: **режим** («Стандартный | Абсолютный») — это по сути scope-фильтр рейтинга. Год у рейтинга
годовой (управляет `hub year` через `update:year`). Оставить контролы **в самом табе, но
переоформить как компактную фильтр-строку** того же вида, что `AnalyticsFilterBar` (карточная
подложка `widget-card`, один ряд, `$space-3` gap), а не голый блок с label'ами сверху.

> Не переносим год/режим физически в `AnalyticsFilterBar` (это hub-level компонент, а режим —
> tab-local): вместо этого локальная фильтр-строка рейтинга **визуально идентична** hub-баре.
> Label «Год» можно убрать (Select самодостаточен) — режим-сегментед и год-Select в один ряд.
> **[ОВ-6]:** альтернатива — вынести год-Select в hub-бар (period-зона), раз рейтинг годовой.
> По умолчанию: локальная строка-двойник.

### 5.2 LeaderCard — на widget-card-подложку

Сейчас `leader-card` — самодельная карточка (border-left 3px primary). Она **почти DS-корректна**
(`$surface-card`, `$surface-200`, `$radius-lg`, primary-акцент dark-aware). Правки:

- Оставить композицию (трофей+аватар / имя+дивизион / hero-очки+метрики) — она хорошая.
- Свести фон/бордер/тень к точному `widget-card`-виду (добавить `$shadow-sm`, если нет).
- Border-left 3px `$primary-color` (dark `--p-primary-color`) — **бренд-акцент лидера, оставить**
  (это осмысленный акцент, аналог hero-border KPI-карты `active` в dashboard.html).
- Hero-очки `$font-size-3xl` primary (dark `--p-primary-color`) — оставить.
- Трофей `$orange-500` (dark `--p-orange-400`) — оставить.

Опционально DS2-усиление: аватар лидера в кластере + supporting-метрики можно оформить как
`AvatarGroup`-паттерн, если PO хочет показать топ-3 лиц. Не в этом рестайле (это функциональное
изменение) — **[ОВ-7]**, только если явно попросят. По умолчанию — лидер-карта как есть, только
подложка/тень.

### 5.3 RatingTable — зебра/hover + widget-card обёртка

- Обернуть `DataTable` рейтинга в `widget-card`-подложку (сейчас голый).
- Добавить `striped-rows row-hover` (сейчас нет — только `row-class` для out-of-standings).
- Медали (gold/silver/bronze) — токен-чистые, dark-aware (есть). Не трогаем.
- Out-of-standings row (`--out`, `$surface-400`) — оставить.
- frozen ранг+менеджер — проверить фон frozen-ячейки = `$surface-card` (не прозрачный) для dark.
- Total-points `--p-primary-color` в dark — есть.

---

## 6. Кнопка Excel — единый вид во всех табах

Уже единая (живёт в `index.vue` → переезжает в `HubToolbar`): `Button icon="pi pi-file-excel"
:label="t('dashboard.hub.export')" severity="secondary" outlined :loading="exporting"`.
**Требование:** во всех табах, где `showExport === true`, кнопка выглядит идентично и стоит в
правом краю тулбара — **как «Экспорт Excel» в dashboard.html** (`pi pi-download` там; у нас
`pi pi-file-excel` — оставляем file-excel, он точнее по семантике). `secondary outlined` —
единый secondary-стиль. Никаких per-таб вариаций. Логика `onExport`/`exportParams` — не трогаем.

---

## 7. Новый компонент — обоснование (reuse-first)

| Компонент | Новый? | Обоснование |
|-----------|--------|-------------|
| `HubToolbar.vue` | **ДА** (1 новый) | Нет готового тулбара «icon+title+сегментед-табы+action» под хаб. `CabinetToolbar` — близкий, но заточен под кабинет (user-picker, month-select, свои emit'ы). `PageHeader` не держит интегрированный таб-стрип. HubToolbar — тонкая презентационная обёртка (props вниз, emit вверх), вся логика в `index.vue`. Композиция копируется 1:1 с `CabinetToolbar` (icon-tile + heading + spacer + segmented + action). |
| Стат-плитка Реестра | **НЕТ** | Переиспользуем стиль `tab-schedule__kpi` (существующая DS2-плитка). Вынести общий класс `.analytics-stat-tile` или продублировать стилем. |
| Карточная подложка `widget-card` | **НЕТ** | Существующий стиль из Обзора (`Card.widget-card`). В табах, где `Card` конфликтует с flex-скроллом таблицы, — div-подложка тем же набором токенов. |

Всё остальное — правки `<template>`/`<style>` существующих компонентов. Ноль новых цветов/
радиусов/теней вне токенов.

---

## 8. Токены и правила (для всех табов)

- **Карточная подложка (widget-card):** `background:$surface-card; border:1px solid $surface-200;
  border-radius:$radius-lg; box-shadow:$shadow-sm`. Dark border → `var(--p-surface-200)` через
  `.app-dark &` на собственном scoped-элементе (live-паттерн). Всё остальное surface —
  theme-reactive из базового правила, dark-ветка не нужна.
- **Отступы:** тулбар `$space-3 $space-5`; фильтр-строка `$space-3 $space-4`; тело карточки
  `$space-4`; ячейки таблиц `$space-2 $space-3`; gap рядов `$space-3`/`$space-4`.
- **Заголовки секций/карточек:** `$font-size-md $font-weight-semibold $surface-800/900`.
- **Icon-tile:** `var(--p-primary-100)` bg + `var(--p-primary-color)` icon; dark инверсия как
  `CabinetToolbar`.
- **Акценты статусов:** info=`--p-blue`, success=`--p-green`, danger=`--p-red`, warn=`--p-yellow`
  — всегда dark-aware (`-500` light / `-400` dark).
- **Закон dark-селекторов (charter §«Обе темы»):** dark-override только `.app-dark &` на
  собственном scoped-элементе; **никогда** внутри `:deep()`; для внутренностей PrimeVue (frozen-
  фон, tfoot) — non-scoped namespaced-блок `.app-dark .dashboard-page__... .target{}` если базовый
  токен не покрывает. Предпочтение — theme-reactive токены (dark-ветка не нужна).
- **Деньги:** `formatMkMoney` (уже везде) — `1 200 000 ₽`.
- **Иконки:** только `pi pi-*`.
- **Никаких** литеральных hex/px, градиентов, цветных теней, Tailwind-классов.

---

## 9. i18n

Новых ключей практически нет (рестайл). Уже существуют: `dashboard.hub.title`,
`dashboard.hub.export`, все `dashboard.plans.*` / `dashboard.registry.*` / `dashboard.schedule.*`
/ `dashboard.rating.*`. Добавить только если реализуем опциональные пункты:

```json
{
  "ru": {
    "dashboard.registry.stat_expected": "К поступлению",
    "dashboard.registry.stat_squeeze": "Дожим",
    "dashboard.schedule.legend_squeeze_dot": "Дожим"
  },
  "en": {
    "dashboard.registry.stat_expected": "Expected",
    "dashboard.registry.stat_squeeze": "Overdue",
    "dashboard.schedule.legend_squeeze_dot": "Overdue"
  }
}
```
(`stat_expected`/`stat_squeeze` — только если добавляем StatCard-полосу §3.2 с собственными
лейблами; можно переиспользовать существующие `expected_title`/`slippage_title` — тогда новых
ключей ноль. `legend_squeeze_dot` — только если §4.2 добавляет легенду календаря.)

---

## 10. Открытые вопросы

1. **[ОВ-1]** «Планы»: `Card`-обёртка vs div-подложка `widget-card` — зависит от того, ломает ли
   `p-card-content` `scroll-height="flex"` матрицы. Рекомендация: div-подложка (безопаснее для
   flex-скролла). Финальное решение — за фронтендером на реализации (визуал идентичен).
2. **[ОВ-2]** Матрица планов: зебра vs `show-gridlines`. Плотные числовые ячейки иногда читаемее с
   сеткой. По умолчанию — зебра (DS2), но PO может оставить grid-lines как обоснованное
   исключение для матрицы. **Нужен апрув PO.**
3. **[ОВ-3]** Реестр: нужна ли StatCard-полоса сумм сверху, если суммы уже в бейджах секций и
   футерах. По умолчанию — да (юзер просил). **Апрув PO желателен.**
4. **[ОВ-4]** Реестр: оставлять ли Tag-бейдж суммы в шапке секции при наличии StatCard-полосы
   (дублирование). По умолчанию — оставить (полезно при скролле).
5. **[ОВ-5]** График: добавлять ли отдельную легенду календаря («● дожим») или достаточно
   tooltip'ов. По умолчанию — добавить строку легенды.
6. **[ОВ-6]** Рейтинг: год-Select оставить в локальной строке-двойнике или вынести в hub period-
   зону (рейтинг годовой). По умолчанию — локальная строка.
7. **[ОВ-7]** Рейтинг: AvatarGroup топ-3 в лидер-карте — **функциональное** изменение, вне этого
   рестайла. Только по явной просьбе.

> Все ОВ — **визуальные**, ни один не требует backend. Ни один эндпоинт/контракт не меняется.

---

## 11. Acceptance-чеклист (для qa-tester)

**Общий каркас**
- [ ] Шапка хаба — **один** тулбар-ряд (icon-tile + «Аналитика продаж» + сегментед-табы +
      Excel), без отдельного `PageHeader`-ряда и отдельного таб-стрипа под ним.
- [ ] icon-tile: light bg `--p-primary-100` + icon `--p-primary-color`; dark bg `--p-primary-900`
      + icon `--p-primary-100` (computed-styles, обе темы) — 1:1 с `CabinetToolbar`.
- [ ] `AnalyticsFilterBar` — карточная подложка `widget-card`-стиля (не плоская серая плита),
      **один ряд** фильтров с wrap; на ≤768px — колонка на всю ширину.
- [ ] period-label (`Июль 2026`) — `tabular-nums`, `$surface-900` light / `--p-surface-800` dark.
- [ ] Все сегментед-контролы (табы/granularity/layer/метрики/режим) визуально едины (pill-preset).

**Планы**
- [ ] Матрица в карточной подложке; метрик-сегментед в шапке карточки (справа).
- [ ] DataTable — зебра+hover (или grid-lines по решению ОВ-2), frozen «Сотрудник» с
      непрозрачным `$surface-card`-фоном в dark (прокручиваемые колонки не просвечивают).
- [ ] dirty-инпут — бордер `--p-primary-color` в обеих темах; save-bar внутри карточки.
- [ ] Скролл матрицы (`scroll-height="flex"`) и sticky-футер ИТОГО работают после обёртки.
- [ ] Все 4 метрики (Поступления/Продукты/Задачи/Конверсии) — единый вид.

**Реестр**
- [ ] StatCard-полоса сумм сверху (2 плитки, «К поступлению» info / «Дожим» danger), стиль =
      `tab-schedule__kpi`; danger-значение `--p-red-500`/dark `--p-red-400`.
- [ ] Каждая секция (Ожидаемые / Дожим) — в `widget-card`-подложке.
- [ ] DataTable — зебра+hover; no-date row-tint читаем в обеих темах; footer ИТОГО bold.
- [ ] Баннеры (multi-currency, no-date, endpoint-missing) — не сломаны.

**График**
- [ ] KPI-полоса, карточка графика, карточка календаря — единый `widget-card`-вид (фон/бордер/
      тень/радиус совпадают с виджетами Обзора в обеих темах).
- [ ] ECharts-серии plan/fact/squeeze — dark-aware цвета (уже есть); легенда выровнена в шапке.
- [ ] NpCalendar: today-обводка `--p-primary-color` в dark, weekend-dim, squeeze-dot `--p-red` —
      корректны; (если ОВ-5=да) строка легенды «● дожим» присутствует.

**Рейтинг**
- [ ] Год+режим — компактная фильтр-строка `widget-card`-вида (не голый блок с label'ами).
- [ ] LeaderCard — `widget-card`-подложка + border-left primary (dark `--p-primary-color`), hero-
      очки `--p-primary-color` в dark, трофей `--p-orange-400` в dark.
- [ ] RatingTable в подложке, зебра+hover, медали dark-aware, frozen ранг+менеджер непрозрачны в
      dark, out-of-standings muted.

**Сквозное**
- [ ] Кнопка Excel — идентична во всех табах (`file-excel` + `secondary outlined`), правый край
      тулбара, `:loading` работает.
- [ ] Обе темы (light `body` / dark `.app-dark`) для КАЖДОГО таба: computed-styles, ноль
      литеральных hex, инвертированная navy-шкала не даёт dark-on-dark текст.
- [ ] Скрытые скроллбары сохранены; функциональность (dirty-guard планов, veto-снап таба/метрики,
      export, фильтры, endpoint-missing-состояния) **не изменилась** — регресс чист.
- [ ] `npm run lint:ds` зелёный; `vue-tsc` зелёный.

---

*Автор: `designer`. Реализация: `frontend-specialist` строго по этому ТЗ. Правки UX — ко мне,
не в код. Визуал перебивает: `front/src/theme` (токены) → `design-handoff/redesign/dashboard.html`
(лейаут) → skill `macroglobal-design` (бренд).*
