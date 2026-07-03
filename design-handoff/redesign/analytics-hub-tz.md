# ТЗ: DashboardPage → хаб аналитики с табами (спринт «Планы и отчёты», Ф0)

> Компонентное ТЗ для frontend-specialist. Формат — по `docs/designer-charter.md` §5.
> Дизайн-система: navy DS, repo-токены (`$space-*`/`$surface-*`/`--p-*`), закон dark-селекторов (§4 charter),
> PrimeVue 4.5 + ECharts (vue-echarts, НЕ Chart.js), `PctTag`, деньги `1 200 000 ₽`.
> **Vizion — только структура кода.** Источник смысла — `docs/audit/SpaceCRM-reports-analysis-2026-07.md`
> (переносим паттерн, не UI-код). Опорные референсы в репо:
> `MkPlanTable.vue` (inline-грид InputNumber), `MkSaveBar.vue` (sticky save-bar + dirty-guard),
> `MkCurrencyEditor.vue` + `MkKpiStrip.vue` (мультивалюта Popover), `DashboardToolbar.vue` (фильтры),
> `ManagerCabinetPage/index.vue` (паттерн `?tab=`).

**Зачем:** превратить дашборд в единый хаб аналитики продаж (SpaceCRM-паритет): обзор, ввод планов,
операционный реестр/дожим, календарный график НП, рейтинг менеджеров — в одном месте, без отдельного раздела.

**Где в коде:** `front/src/pages/DashboardPage/` (расширяем существующую страницу — она становится табом «Обзор»).

**Reuse (существующие, переиспользуем как есть или с адаптацией):**
`PageHeader`, `PctTag`, `EntityAvatar`, PrimeVue `DataTable`/`Column`/`InputNumber`/`Select`/`SelectButton`/`Popover`/`Tag`/`Button`/`Message`/`Skeleton`/`ProgressSpinner`, `vue-echarts`.
Опорные (копируем паттерн, не импортируем 1-в-1): `MkPlanTable` → грид планов, `MkSaveBar` → save-bar,
`MkCurrencyEditor`/`MkKpiStrip` → мультивалюта, `DashboardToolbar` → сквозные фильтры.

**Новые компоненты (обоснование ниже, §«Новые компоненты»):** 5 контейнеров табов + `AnalyticsFilterBar` +
`PlanMatrix` + `PlanMatrixCurrencyCell` + `RegistryTable` + `NpCalendar` + `LeaderCard` + `RatingTable`.

---

## 0. Общая архитектура страницы

```
DashboardPage/
├── index.vue                         (шелл: PageHeader + AnalyticsFilterBar + таб-стрип + <keep-alive> активного таба)
├── components/
│   ├── AnalyticsFilterBar.vue        (сквозные фильтры: период/слой · воронка · менеджер)
│   ├── tabs/
│   │   ├── TabOverview.vue           (= текущий контент index.vue, вынести без изменений)
│   │   ├── TabPlans.vue              (суб-вкладки метрик + PlanMatrix + save-bar)
│   │   ├── TabRegistry.vue           (RegistryTable ×2: ожидаемые + дожим)
│   │   ├── TabSchedule.vue           (NpCalendar + ECharts-кумулятив)
│   │   └── TabRating.vue             (LeaderCard + RatingTable)
│   ├── plans/
│   │   ├── PlanMatrix.vue            (сотрудник × 12 мес, InputNumber-ячейки)
│   │   ├── PlanMatrixCurrencyCell.vue(Select валюты + Popover per-currency)
│   │   └── PlanSaveBar.vue           (sticky, dirty-guard, copy-prev)
│   ├── registry/RegistryTable.vue
│   ├── schedule/NpCalendar.vue
│   └── rating/{LeaderCard.vue, RatingTable.vue}
├── composables/
│   ├── useDashboardPage.ts           (существует — обзор)
│   ├── useAnalyticsHub.ts            (НОВ: активный таб, сквозные фильтры, ?tab=/?layer=)
│   ├── usePlansTab.ts                (НОВ: матрица, dirty-set, bulk-upsert, copy-prev)
│   ├── useRegistryTab.ts / useScheduleTab.ts / useRatingTab.ts (НОВ)
```

Шелл `index.vue` — тонкий: `PageHeader` (заголовок + Excel-экспорт справа) → `AnalyticsFilterBar` →
`SelectButton` таб-стрип → `<component :is>` активного таба под `<keep-alive>` (чтобы не терять
скролл/ввод при переключении). `overflow-y:auto` на контенте, `margin` — как в текущем `.dashboard-page`.

---

## 1. Таб-структура (шелл)

### Wireframe (шелл)
```
┌──────────────────────────────────────────────────────────────────────────┐
│  📊 Аналитика продаж                            [⬇ Экспорт в Excel]        │  ← PageHeader (Excel виден только на отчётных табах)
├──────────────────────────────────────────────────────────────────────────┤
│  [Период ▾] [◀ Июль 2026 ▶]   [Слой: Оперативный|Годовой]                  │  ← AnalyticsFilterBar
│  [Воронка: MACRO Global ▾]    [Менеджер: Все ▾]                             │
├──────────────────────────────────────────────────────────────────────────┤
│  ( Обзор ) ( Планы ) ( Реестр ) ( График ) ( Рейтинг )                     │  ← SelectButton таб-стрип
├──────────────────────────────────────────────────────────────────────────┤
│                                                                            │
│                     <активный таб>                                         │
│                                                                            │
└──────────────────────────────────────────────────────────────────────────┘
```

### Зоны и компоненты (шелл)
| Зона | Компонент / элемент | Props / атрибуты |
|------|---------------------|-----------------|
| Шапка | `PageHeader` | `title="dashboard.hub.title"` `icon="pi pi-chart-bar"`; `#actions` → Excel-кнопка (v-if отчётный таб) |
| Excel | PrimeVue `Button` | `icon="pi pi-file-excel"` `:label="t('dashboard.hub.export')"` `severity="secondary" outlined` `:loading="exporting"` `:disabled="loading"` |
| Фильтры | `AnalyticsFilterBar` | см. §1.1 |
| Таб-стрип | `SelectButton` | `:options="tabOptions"` `option-label="label"` `option-value="value"` `:allow-empty="false"` — паттерн `ManagerCabinetPage` |
| Тело | `<component :is>` + `<keep-alive>` | активный таб по `?tab=` |

### Право-гейты табов
- **Обзор / Реестр / График / Рейтинг** — RBAC-видимость (`VisibilityResolver`: менеджер — своё, директор — подчинённые, admin — всё). Таб виден всем, данные фильтруются на бэке по scope.
- **Планы** — таб виден и редактируем только при `plans.manage` (admin/director). У менеджера таб «Планы» **скрыт** из `tabOptions` (не «disabled», а отсутствует). Гейт: `useAuthStore().can('plans.manage')`.

### `tabOptions` (computed)
```
[
  { value: 'overview',  label: t('dashboard.hub.tab_overview') },
  { value: 'plans',     label: t('dashboard.hub.tab_plans'),   gate: 'plans.manage' },  // фильтруется если нет права
  { value: 'registry',  label: t('dashboard.hub.tab_registry') },
  { value: 'schedule',  label: t('dashboard.hub.tab_schedule') },
  { value: 'rating',    label: t('dashboard.hub.tab_rating') },
]
```
`?tab=` дефолт — `overview` (пустой query = overview, как в кабинете: не пишем `tab=overview` в URL).
Если пользователь без права руками введёт `?tab=plans` → `useAnalyticsHub` редиректит на `overview`.

### 1.1 `AnalyticsFilterBar` — сквозная панель фильтров (НОВ)

Опора — `DashboardToolbar.vue` (Card + `d-flex flex-wrap gap-3`), расширенная.

```
┌──────────────────────────────────────────────────────────────────────────┐
│ [Период ▾]  [◀  Июль 2026  ▶]        [ Оперативный | Годовой ]            │
│ [Воронка: MACRO Global ▾]  [Менеджер: Все ▾]                              │
└──────────────────────────────────────────────────────────────────────────┘
```

| Элемент | Компонент | Props / поведение |
|---------|-----------|-------------------|
| Переключатель гранулярности | `SelectButton` | опции `Месяц`/`Год`; в режиме «Год» стрелки-степпер меняют год, в «Месяц» — месяц |
| Степпер периода | 2× `Button text` (◀ ▶) + label | по образцу `ManagerCabinetPage/MonthStepper.vue` (reuse-кандидат: обобщить его в `PeriodStepper`) |
| Слой | `SelectButton` | опции `Оперативный`/`Годовой` (`layer`), пишется в `?layer=`. Влияет ТОЛЬКО на таб «Планы» и колонку «План» в отчётах — на остальных табах контрол dimmed/скрыт (см. ОВ-3) |
| Воронка | `Select show-clear` | `option-label="name" option-value="id"`, `:placeholder="t('dashboard.filters.allPipelines')"` (MACRO Global / MACRO AI Global) |
| Менеджер | `Select filter show-clear` | v-if `canSeeAllManagers`; `option-label="full_name"` |

Разница «Период» vs «Слой»:
- **Период** (месяц/год + степпер) = какой отрезок времени смотрим (данные факта/реестра).
- **Слой** = какой слой планов (operative пересматриваемый / annual фиксированный) участвует в план-колонках.
Оба идут в `?tab`-независимый query, `useAnalyticsHub` — единый источник, все табы читают из него.

Адаптив: `flex-wrap`, при ≤1280 переносится в 2 ряда (уже так за счёт `flex-wrap`). При ≤768 — контролы в столбец (`w-100`).

---

## 2. Таб «Планы» — матрицы с inline-вводом

**Зачем:** руководитель вводит планы (сотрудник × месяц) прямо в отчёте, без отдельного мастера — паттерн SpaceCRM. Планы питают факт-сравнение и live-прогноз МК.

### Wireframe
```
┌──────────────────────────────────────────────────────────────────────────┐
│  Метрика:  ( Поступления )( Задачи )( Конверсии )        [Слой: Оперативный]│  ← суб-вкладки метрик (SelectButton)
│  ├─ (для Задач) Тип задачи: [Встреча ▾]                                     │  ← вторичный Select (только для Задач/Конверсий)
├──────────────────────────────────────────────────────────────────────────┤
│  Сотрудник        Вал.  Всего   Янв   Фев   Мар  …  Дек                    │  ← header (sticky top)
│ ┌────────────┬─────┬────────┬───────┬───────┬────────────────────────────┐ │
│ │ Иванов И.   │[₽▾]│2 400 000│[план ]│[план ]│ …                          │ │  ← InputNumber в каждой месячной ячейке
│ │             │ ⓘ  │        │ факт↓ │ факт↓ │                            │ │  ← под инпутом read-only факт + PctTag
│ │             │    │        │ 82% ● │ 45% ● │                            │ │
│ ├────────────┼─────┼────────┼───────┼───────┼────────────────────────────┤ │
│ │ Петров П.   │[$ ▾]│  …     │       │       │                            │ │
│ ├────────────┼─────┼────────┼───────┼───────┼────────────────────────────┤ │
│ │ ИТОГО (base)│  —  │…в base │…в base│…в base│  (footer, read-only, base) │ │
│ └────────────┴─────┴────────┴───────┴───────┴────────────────────────────┘ │
│                                                                            │
│ ┌── PlanSaveBar (sticky bottom) ────────────────────────────────────────┐ │
│ │ Изменено ячеек: 7    [⎘ Копировать пред. период]   [💾 Сохранить (7)]  │ │
│ └────────────────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────────┘
```

### 2.1 Суб-вкладки метрик
`SelectButton` `metricTab` со значениями: `income` (Поступления) · `tasks` (Задачи) · `conversions` (Конверсии).
Пишется в `?metric=` (вложенный query, дефолт `income`).
- **Поступления** (`new_income`): деньги, строка = сотрудник (scope=user). Ячейка = денежный план в валюте строки.
  > Прим.: SpaceCRM в поступлениях планирует по продуктовым линейкам, а не по менеджеру. У нас scope
  > гибкий (`plan_targets.scope`). **Дефолт нашей версии — по сотруднику** (единый контур с МК, ОВ-1);
  > разрез «по продуктовой линейке» — переключатель «Разрез: Сотрудник | Продукт» справа в шапке метрики (ОВ-1).
- **Задачи** (`tasks_completed{kind}`): штуки. Требует вторичный `Select` «Тип задачи» (встреча/КП/договор/…). Ячейка = целевое число задач.
- **Конверсии** (`conversion{pair}`): проценты, план **производный** (не вводится напрямую — read-only, считается из планов задач-числителя/знаменателя). На этом табе InputNumber-ячейки **disabled**, показываем план% + факт% + PctTag; вверху — блок «Пары конверсий» с конструктором (кнопка «Добавить конверсию» открывает диалог; выходит за рамки Ф0-UI — заглушка + ОВ-2).

### 2.2 `PlanMatrix` (НОВ) — грид сотрудник × 12 месяцев
Основа — `DataTable size="small" show-gridlines` (как `MkPlanTable`), но с 12+3 колонками и горизонтальным скроллом.

Колонки:
| Колонка | Тип | Поведение |
|---------|-----|-----------|
| Сотрудник | frozen left (`frozen` + `alignFrozen="left"`) | `EntityAvatar :pixel-size="22"` + имя |
| Валюта | `PlanMatrixCurrencyCell` | Select валюты строки/метрики + `pi pi-info-circle` Popover разбивки (только для денежных метрик) |
| Всего | read-only | сумма 12 месяцев в base-валюте, `font-variant-numeric: tabular-nums` |
| Янв…Дек (×12) | ячейка-план | `InputNumber` (план) + под ним read-only факт/% |

Ячейка месяца (композиция внутри `#body`):
```
InputNumber (план)         ← locale="ru-RU", :min="0", text-align:right, для денег без дробных
─────────────
факт: 1 200 000 ₽          ← мелкий read-only ($font-size-xs, $surface-600)  ИЛИ через v-tooltip (ОВ-4)
PctTag :value=факт/план    ← size="sm" (severity по порогам)
```
- Денежная метрика: InputNumber денег (рубли/валюта строки), факт форматируется `formatMkMoney`.
- Штучная метрика (Задачи): InputNumber целых, факт — число.
- Пустой план (null) → инпут пустой, PctTag muted `—`.

Строки: `enabledRows` = сотрудники в scope (VisibilityResolver). Footer-строка `ИТОГО` (base-валюта, read-only) —
через `#footer` DataTable или `ColumnGroup`.

Плотность: держим `DataTable`-паттерн `MkPlanTable` (`padding: $space-2 $space-3` в ячейках). Горизонтальный
скролл — `scrollable scrollHeight` + `frozen` первая колонка. НЕ изобретаем свой грид (риск из §7 плана).

### 2.3 `PlanMatrixCurrencyCell` (НОВ) — мультивалюта
Опора — `MkCurrencyEditor` + `MkKpiStrip` Popover.
- `Select` валюты (₽/$/€/…) — `option-label="label" option-value="value"`, ширина ~90px.
- Рядом `pi pi-info-circle` кнопка → `Popover` с per-currency разбивкой: «в валюте плана: X · курс на 1-е число: Y · в base: Z».
- Показываем только для денежных метрик (Поступления). Для Задач/Конверсий колонка «Валюта» = `—` (dimmed).
- Курс — на 1-е число месяца (консистентно с МК, `ExchangeRateService`). Значение «Всего» и «ИТОГО» — всегда в base.

### 2.4 `PlanSaveBar` (НОВ) — sticky bulk-save + dirty-guard
Опора — `MkSaveBar.vue` (sticky bottom, dirty-guard паттерн Settings Ф4).
```
┌──────────────────────────────────────────────────────────────────────────┐
│  Изменено ячеек: 7          [⎘ Копировать пред. период]  [💾 Сохранить (7)]│
└──────────────────────────────────────────────────────────────────────────┘
```
| Элемент | Компонент | Поведение |
|---------|-----------|-----------|
| Счётчик dirty | текст | `t('dashboard.plans.dirty_count', { n })`; скрыт при 0 |
| Копировать пред. период | `Button severity="secondary" icon="pi pi-copy"` | заполняет план ячеек значениями пред. месяца/года (не сохраняет — только заполняет матрицу как dirty). Confirm если есть несохранённое |
| Сохранить | `Button icon="pi pi-save" :badge="dirtyCount"` | bulk-upsert только dirty-ячеек; `:disabled="dirtyCount===0"`; `:loading="saving"` |

Dirty-guard: `useBeforeUnload` + router `beforeRouteLeave` — если `dirtyCount>0`, `ConfirmDialog` «Несохранённые изменения планов. Уйти?». Паттерн Settings Ф4 (см. mgcrm-frontend-gotchas — unified settings dirty-guard).

Save-bar показываем только на табе «Планы» (внутри `TabPlans`, sticky к низу его контента).

---

## 3. Таб «Реестр» — сделки месяца + дожим

**Зачем:** операционный реестр «денег в пути» за период + очередь просроченных оплат (дожим). SpaceCRM `expected-deals`.

### Wireframe
```
┌──────────────────────────────────────────────────────────────────────────┐
│  Ожидаемые поступления · Июль 2026                     Итого: 4 200 000 ₽  │  ← заголовок секции + итог-бейдж
│ ┌───────┬──────────┬─────────┬──────────┬──────────┬─────────┬───────────┐ │
│ │ Дата  │ Сделка   │ Компания│ Продукты │ К оплате │Отв-ный  │Посл.задача│ │
│ │оплаты │(ссылка)  │         │ (теги)   │  (₽)     │(avatar) │ (текст+⏱)│ │
│ ├───────┼──────────┼─────────┼──────────┼──────────┼─────────┼───────────┤ │
│ │15.07  │ ООО …    │ …       │[CRM][ERP]│ 800 000 ₽│ 🧑 Иван │ Ждём КП…  │ │
│ └───────┴──────────┴─────────┴──────────┴──────────┴─────────┴───────────┘ │
│                                                                            │
│  🔴 Дожим (просроченные оплаты)                       Итого: 3 943 765 ₽  │  ← вторая секция, danger-акцент бейджа
│ ┌──────────────────────────────────────────────────────────────────────┐ │
│ │  … те же колонки; строки без expected_payment_date подсвечены warning  │ │
│ └──────────────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────────┘
```

### Зоны и компоненты
| Зона | Компонент | Props / поведение |
|------|-----------|-------------------|
| Секция «Ожидаемые» | `RegistryTable` (НОВ) | `:rows` `:total-kopecks` `title-key` `severity="info"` |
| Секция «Дожим» | `RegistryTable` | `severity="danger"`; строки где `expected_payment_date` пуст → класс `registry__row--no-date` (тонкая warning-подсветка + `pi pi-exclamation-triangle` в ячейке даты + tooltip «Нет даты оплаты») |
| Итог-бейдж | `Tag` | `severity="info"`/`"danger"`, `:value="formatMoney(total)"` |

`RegistryTable` — `DataTable size="small"` со скроллом. Колонки:
| Колонка | Рендер |
|---------|--------|
| Дата оплаты | `DD.MM.YYYY`; пусто → warning-иконка + tooltip |
| Сделка | ссылка `router.push('/deals/:id')`, `text-decoration:underline hover` |
| Компания | текст (+ `EntityAvatar :square :pixel-size="22"` опц.) |
| Продукты | `Tag` × позиции (severity `secondary`), при >3 → «+N» |
| К оплате | деньги, `tabular-nums`, right-align |
| Ответственный | `EntityAvatar :pixel-size="22"` + имя |
| Посл. задача | текст результата + `⏱ relative-time` мелким |

Итоговая строка — `#footer` или `ColumnGroup` (Итого по «К оплате»).

---

## 4. Таб «График» — календарный кумулятив НП

**Зачем:** визуализировать распределение ожидаемых/фактических поступлений по дням месяца + кумулятив план/факт/дожим. SpaceCRM `income-forecast-schedule`.

### Wireframe
```
┌──────────────────────────────────────────────────────────────────────────┐
│  График НП · Июль 2026     Легенда: ▮план  ▮дожим  ▯факт  ░выходной        │
│ ┌────────────────────────────────────────────────────────────────────────┐│
│ │   ▲ ₽                                                        накопительно││  ← ECharts: line/area
│ │   │                                        ┌────── факт (area)           ││     кумулятив план/факт/дожим
│ │   │                        ┌───────────────┘                             ││
│ │   │        ┌───────────────┘  ← план (line)                             ││
│ │   └──┬──┬──┬──┬──┬──┬──┬──┬──┬──┬──┬──┬──┬──┬──┬──►  дни 1…31            ││
│ └────────────────────────────────────────────────────────────────────────┘│
│ ┌────────────────────────────────────────────────────────────────────────┐│
│ │  1   2  [3]  4   5  [6][7]  8 …  31   (календарные ячейки: сумма дня;    ││  ← календарный грид дней
│ │ 120к 80к  0  … выходные [6][7] приглушены; дни с дожимом — danger-точка) ││     (heat-подобные плитки)
│ └────────────────────────────────────────────────────────────────────────┘│
└──────────────────────────────────────────────────────────────────────────┘
```

### Зоны и компоненты
| Зона | Компонент | Реализация |
|------|-----------|-----------|
| Кумулятив-чарт | `vue-echarts` (VChart) | line/area: 3 серии (план накопительно, факт накопительно, дожим). Тема — `useMacroCrmEchartsTheme()` (dark-aware, уже подключён в DashboardPage). Ось X = дни 1…N |
| Календарный грид | `NpCalendar` (НОВ) | грид дней месяца (CSS grid 7 колонок). Плитка дня: число + сумма поступлений дня; выходные — приглушённый фон (`$surface-100`, opacity); дни с дожимом — `danger`-точка/бордер; сегодня — `primary`-обводка |
| Легенда | ряд `Tag`/цветных точек | план / дожим / факт / выходной. Цвета — semantic-токены (см. §«Токены»), НЕ литералы |

`NpCalendar` — не ECharts, а лёгкий CSS-grid (7×N), плитка = `$radius-sm`, приглушение выходных через `$surface-100`/opacity. Кумулятив — ECharts (плавные линии). Так календарь остаётся кликабельным/доступным, а тренд — векторным.

> Прим.: SpaceCRM рисует это heat-таблицей по дням (строки Прямая/СБС/Всего/Накопительно). Мы упрощаем до
> «календарь-плитки (день) + ECharts-кумулятив (тренд)» — читаемее и на нашем стеке. Разбивку Прямая/СБС — в tooltip плитки (ОВ-5).

---

## 5. Таб «Рейтинг» — лучший менеджер

**Зачем:** годовой геймифицированный рейтинг менеджеров с очками и hero-карточкой лидера. SpaceCRM `best-manager`.

### Wireframe
```
┌──────────────────────────────────────────────────────────────────────────┐
│  [Год: 2026 ▾]              [ Стандартный | Абсолютный зачёт ]             │  ← год-селектор + тумблер зачёта
│ ┌────────────── LeaderCard (hero) ──────────────────────────────────────┐ │
│ │  🏆  ┌────┐   Иванов Иван            Дивизион: Global                   │ │
│ │      │ 🧑 │   Очки: 1 240   ·   Сделок: 42   ·   НП: 12 400 000 ₽       │ │  ← hero-число очков крупно
│ │      └────┘                                                             │ │
│ └────────────────────────────────────────────────────────────────────────┘ │
│ ┌── RatingTable ─────────────────────────────────────────────────────────┐ │
│ │ #  Менеджер     Сделки  Сопров.  НП (₽)     Ср.чек   Очки НП  Очки итог │ │
│ │ 🥇 Иванов        42      12    12 400 000 ₽  295 000    820     1 240   │ │
│ │ 🥈 Петров        …                                                      │ │
│ │ 🥉 Сидоров       …                                                      │ │
│ │ 4  …             …                                                      │ │
│ │ —  Admin         —        —        —          —         —       — (вне) │ │  ← «вне зачёта» → тире
│ └────────────────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────────┘
```

### Зоны и компоненты
| Зона | Компонент | Props / поведение |
|------|-----------|-------------------|
| Год-селектор | `Select` | список лет (текущий − N…текущий); пишет в `?year=` |
| Тумблер зачёта | `SelectButton` | `Стандартный`/`Абсолютный`; вне зачёта (`—`) в абсолютном режиме |
| Hero лидера | `LeaderCard` (НОВ) | `EntityAvatar :pixel-size="72"` + `pi pi-trophy` акцент; hero-число очков 28px/700/navy (dark: `--mg-primary-300`, как МК hero); дивизион, сделки, НП |
| Таблица | `RatingTable` (НОВ) | `DataTable size="small"`; топ-3 — медальки (иконки `pi pi-star-fill` с золото/серебро/бронза-акцентом ИЛИ порядковый #). Колонки: #, Менеджер (avatar+имя), Сделки, Сопровождения, НП, Ср.чек, Очки НП, Очки итог, Дивизион |

Медальки топ-3: `pi pi-star-fill` в цвете (золото/серебро/бронза — эти 3 hex допускаются как semantic-акценты рейтинга, добавить в `_colors.scss` как `$rank-gold/$rank-silver/$rank-bronze`, ОВ-6). «Вне зачёта» → строка с `—` во всех числовых, `$surface-400`.

`LeaderCard` — hero-паттерн по духу МК `MkTotalCard`/`EntityInfoHeader`: navy-акцент, крупное число очков. НЕ отдельная navy-панель (это не карточка сущности) — обычная `Card` с primary-акцентной левой полосой/иконкой трофея.

---

## 6. Состояния (для всех табов)

| Состояние | Реализация |
|-----------|-----------|
| **loading** | По форме контента: **Планы** — `Skeleton` строк матрицы (N строк × ширина); **Реестр/Рейтинг** — `Skeleton` строк DataTable (использовать `DataTable` `#loading` или наложить `Skeleton`); **График** — `Skeleton` прямоугольник под чарт + сетку. Существующий «Обзор» — как есть. Оверлей при повторной подгрузке — `ProgressSpinner` поверх контейнера |
| **empty** | Иконка `$font-size-icon-xl` + заголовок + hint + опц. CTA. Планы: `pi pi-users` «Нет сотрудников в вашей зоне видимости». Реестр: `pi pi-inbox` «Нет ожидаемых поступлений в этом месяце». Дожим-пусто (хорошо!): `pi pi-check-circle` success-акцент «Нет просроченных оплат — дожимать нечего». График: `pi pi-calendar` «Нет данных за период». Рейтинг: `pi pi-trophy` «Недостаточно данных за год» |
| **error** | `Message severity="error"` inline в теле таба + `Button` retry; сетевые ошибки записи планов — `useToast().add({severity:'error'})`. `multi_currency_warning` (как в DashboardPage) — `Message severity="warn"` над матрицей/реестром |

Мультивалютное предупреждение и «нет воронки» — переиспользовать паттерн `Message` из текущего `DashboardPage/index.vue` (строки 32–51).

---

## 7. Адаптив (≤1280)

- **Фильтр-бар:** `flex-wrap` — при ≤1280 в 2 ряда (уже так); при ≤768 контролы `w-100` в столбец.
- **PlanMatrix:** горизонтальный скролл (`scrollable`), первая колонка `frozen`. При ≤768 — та же таблица со скроллом (не переверстываем в карточки — сетка план/факт критична; но save-bar остаётся sticky и полноширинным).
- **Реестр/Рейтинг DataTable:** горизонтальный скролл на узких экранах (`scrollable`), ключевые колонки первыми.
- **График:** ECharts `autoresize`; календарный грид — 7 колонок всегда, плитки ужимаются.
- **Save-bar:** `flex-wrap`, кнопки переносятся под счётчик на узком (паттерн `MkSaveBar`).

---

## 8. Токены и компоненты (сводно)

**Отступы:** шапка таба `$space-4 $space-6`; тело `$space-6`; ячейки DataTable `$space-2 $space-3`; save-bar `$space-3 $space-5`; gap фильтров `$space-3`.
**Радиусы:** карточки/панели `$radius-lg`; инпуты/плитки календаря `$radius-sm`; save-bar `$radius-lg`.
**Типографика:** hero-число очков лидера ~28px/`$font-weight-bold`; факт под инпутом `$font-size-xs`; метки `$font-size-sm`; `font-variant-numeric: tabular-nums` на всех числах/деньгах.
**Цвета/поверхности (обе темы через theme-reactive токены — dark-ветка не нужна):**
- Фон карточек/таблиц: `$surface-card`.
- Текст вторичный: `$surface-600` (в dark читается автоматически; где нужен явный override — `.app-dark & { color: var(--p-surface-600) }` на собственном scoped-элементе, НЕ внутри `:deep()`).
- Hover строк: `var(--mg-surface-hover)`.
- Акцент primary: `$primary-color` / dark `var(--p-primary-color)`.
- Hero-число dark: `--mg-primary-300` (`#9fb0d4`) — как МК hero (не белый).
**Семантика (Tag/severity/легенда):** план — `info`; факт — `success`; дожим/просрочка — `danger`; ожидание/выходной — `warning`/`$surface-100`. PctTag — свои пороги (≥100 success / 80–99 warning / <80 danger / null muted).
**Деньги:** `formatMkMoney` / `Intl.NumberFormat('ru-RU',{style:'currency',currency})` → `1 200 000 ₽`.
**Dark-закон:** только theme-reactive токены ИЛИ non-scoped namespaced `<style>` (charter §4). Ни одного из 3 мёртвых `.app-dark`-в-`:deep()` вариантов.

**PrimeVue-компоненты:** `SelectButton` (табы, слой, зачёт), `Select`/`MultiSelect` (фильтры, валюта), `DataTable`/`Column`/`ColumnGroup` (все таблицы/матрицы), `InputNumber` (планы), `Popover` (мультивалюта), `Tag` (статусы/итоги/легенда), `Button` (действия/Excel/степпер), `Message` (warn/error/no-pipeline), `Skeleton`/`ProgressSpinner` (loading), `ConfirmDialog` (dirty-guard/copy-prev), `Tooltip` (директива, подсказки).
**ECharts:** `vue-echarts` (VChart) — кумулятив на «Графике», при желании bar в рейтинге. Тема — `useMacroCrmEchartsTheme()`.

---

## 9. Interactions (сводная таблица)

| Элемент | Действие | Результат | Endpoint |
|---------|----------|-----------|---------|
| Таб-стрип | select | `router.replace({query:{...,tab}})`; `<keep-alive>` сохраняет состояние | — |
| Период-степпер ◀▶ | click | меняет месяц/год в query; все табы перезапрашивают | — |
| Слой (Опер/Годовой) | select | `?layer=`; влияет на план-колонки | — |
| Воронка/Менеджер | select | обновляет query; перезапрос активного таба | — |
| Excel-кнопка | click | скачивает xlsx активного отчётного таба | `GET /api/v1/analytics/{report}/export?…` (PhpSpreadsheet) |
| **Планы:** ячейка InputNumber | ввод/blur | помечает ячейку dirty (в `dirtySet`), обновляет счётчик и «Всего» | — |
| **Планы:** Select валюты | change | меняет валюту строки/метрики → пересчёт base | — |
| **Планы:** `pi-info-circle` в валюте | click | `Popover` per-currency разбивки (курс на 1-е число) | — |
| **Планы:** Копировать пред. период | click | заполняет матрицу планом пред. периода (dirty, без сохранения); confirm если есть dirty | `GET /api/v1/plan-targets?…&period=prev` |
| **Планы:** Сохранить | click | bulk-upsert dirty-ячеек; toast success; чистит dirtySet | `POST /api/v1/plan-targets/bulk` |
| **Планы:** уход со страницы при dirty | route-leave | `ConfirmDialog` «Несохранённые изменения» | — |
| **Реестр:** строка сделки | click по ссылке | `router.push('/deals/:id')` | — |
| **Реестр:** иконка «нет даты» | hover | tooltip «Не задана дата оплаты» | — |
| **График:** hover по точке/плитке | hover | ECharts tooltip / tooltip плитки (сумма дня, разбивка) | — |
| **Рейтинг:** год-селектор | change | `?year=`; перезапрос | `GET /api/v1/analytics/best-manager?year=` |
| **Рейтинг:** тумблер зачёта | change | пересчёт рейтинга (стандартный/абсолютный) | тот же endpoint, `?mode=` |

> Все endpoint-пути — **предположительные** (Ф0, контракт пишет `backend-architect` в `docs/contracts/plan-targets-api-contract.md`). Финальные shape'ы Resource'ов — из контракта.

---

## 10. i18n-ключи (RU обязательно, EN — задел)

```json
{
  "ru": {
    "dashboard.hub.title": "Аналитика продаж",
    "dashboard.hub.export": "Экспорт в Excel",
    "dashboard.hub.tab_overview": "Обзор",
    "dashboard.hub.tab_plans": "Планы",
    "dashboard.hub.tab_registry": "Реестр",
    "dashboard.hub.tab_schedule": "График",
    "dashboard.hub.tab_rating": "Рейтинг",

    "dashboard.filters.period": "Период",
    "dashboard.filters.granularity_month": "Месяц",
    "dashboard.filters.granularity_year": "Год",
    "dashboard.filters.layer_operative": "Оперативный",
    "dashboard.filters.layer_annual": "Годовой",
    "dashboard.filters.allPipelines": "Все воронки",
    "dashboard.filters.allManagers": "Все менеджеры",

    "dashboard.plans.metric_income": "Поступления",
    "dashboard.plans.metric_tasks": "Задачи",
    "dashboard.plans.metric_conversions": "Конверсии",
    "dashboard.plans.task_kind": "Тип задачи",
    "dashboard.plans.breakdown_by_user": "Сотрудник",
    "dashboard.plans.breakdown_by_product": "Продукт",
    "dashboard.plans.col_employee": "Сотрудник",
    "dashboard.plans.col_currency": "Валюта",
    "dashboard.plans.col_total": "Всего",
    "dashboard.plans.col_fact": "факт",
    "dashboard.plans.row_total": "ИТОГО",
    "dashboard.plans.dirty_count": "Изменено ячеек: {n}",
    "dashboard.plans.copy_prev": "Копировать пред. период",
    "dashboard.plans.save": "Сохранить",
    "dashboard.plans.saved": "Планы сохранены",
    "dashboard.plans.leave_confirm": "Есть несохранённые изменения планов. Уйти без сохранения?",
    "dashboard.plans.rate_on_first": "Курс на 1-е число месяца",
    "dashboard.plans.in_base": "В базовой валюте",
    "dashboard.plans.add_conversion": "Добавить конверсию",
    "dashboard.plans.empty_title": "Нет сотрудников",
    "dashboard.plans.empty_hint": "В вашей зоне видимости нет сотрудников для планирования",

    "dashboard.registry.expected_title": "Ожидаемые поступления",
    "dashboard.registry.slippage_title": "Дожим",
    "dashboard.registry.col_pay_date": "Дата оплаты",
    "dashboard.registry.col_deal": "Сделка",
    "dashboard.registry.col_company": "Компания",
    "dashboard.registry.col_products": "Продукты",
    "dashboard.registry.col_amount": "К оплате",
    "dashboard.registry.col_responsible": "Ответственный",
    "dashboard.registry.col_last_task": "Последняя задача",
    "dashboard.registry.total": "Итого",
    "dashboard.registry.no_pay_date": "Не задана дата оплаты",
    "dashboard.registry.empty_expected": "Нет ожидаемых поступлений в этом месяце",
    "dashboard.registry.empty_slippage": "Нет просроченных оплат — дожимать нечего",

    "dashboard.schedule.title": "График НП",
    "dashboard.schedule.legend_plan": "План",
    "dashboard.schedule.legend_slippage": "Дожим",
    "dashboard.schedule.legend_fact": "Факт",
    "dashboard.schedule.legend_weekend": "Выходной",
    "dashboard.schedule.cumulative": "Накопительно",
    "dashboard.schedule.empty": "Нет данных за период",

    "dashboard.rating.year": "Год",
    "dashboard.rating.mode_standard": "Стандартный зачёт",
    "dashboard.rating.mode_absolute": "Абсолютный зачёт",
    "dashboard.rating.leader": "Лидер",
    "dashboard.rating.col_rank": "#",
    "dashboard.rating.col_manager": "Менеджер",
    "dashboard.rating.col_deals": "Сделки",
    "dashboard.rating.col_escort": "Сопровождения",
    "dashboard.rating.col_income": "Новые поступления",
    "dashboard.rating.col_avg_check": "Средний чек",
    "dashboard.rating.col_points_income": "Очки НП",
    "dashboard.rating.col_points_total": "Очки итог",
    "dashboard.rating.col_division": "Дивизион",
    "dashboard.rating.out_of_ranking": "вне зачёта",
    "dashboard.rating.empty": "Недостаточно данных за год"
  },
  "en": {
    "dashboard.hub.title": "Sales analytics",
    "dashboard.hub.export": "Export to Excel",
    "dashboard.hub.tab_overview": "Overview",
    "dashboard.hub.tab_plans": "Plans",
    "dashboard.hub.tab_registry": "Registry",
    "dashboard.hub.tab_schedule": "Schedule",
    "dashboard.hub.tab_rating": "Rating",
    "dashboard.plans.metric_income": "Income",
    "dashboard.plans.metric_tasks": "Tasks",
    "dashboard.plans.metric_conversions": "Conversions",
    "dashboard.plans.save": "Save",
    "dashboard.plans.copy_prev": "Copy previous period",
    "dashboard.registry.expected_title": "Expected income",
    "dashboard.registry.slippage_title": "Slippage",
    "dashboard.schedule.title": "Income schedule",
    "dashboard.rating.leader": "Leader"
  }
}
```

---

## 11. Новые компоненты — обоснование (reuse-first гейт)

| Компонент | Почему нельзя переиспользовать существующий |
|-----------|--------------------------------------------|
| `AnalyticsFilterBar` | `DashboardToolbar` близок, но фикс на 3 фильтра без слоя/степпера/гранулярности. Расширяем его паттерн; **рекомендация — эволюционировать `DashboardToolbar` в `AnalyticsFilterBar`** (переименовать + добавить контролы), а не плодить второй. |
| `PlanMatrix` + `PlanMatrixCurrencyCell` | `MkPlanTable` — 5 фикс-колонок под позиции МК, не сотрудник×12мес. Матрица планов — новая структура (12 месяцев, frozen, факт-под-инпутом). Переиспользуем visual-язык/токены `MkPlanTable`, не сам компонент. |
| `PlanSaveBar` | `MkSaveBar` — с preview ЗП/статусами МК. Наш save-bar проще (dirty-счётчик + copy + save). Копируем паттерн sticky+dirty-guard, но props иные. Кандидат: обобщить в shared `StickySaveBar` (ОВ-7). |
| `RegistryTable` | Нет табличного компонента реестра сделок. Обычный `DataTable`-обёртка со специфичными колонками. |
| `NpCalendar` | Нет календарного грида. Лёгкий CSS-grid. |
| `LeaderCard` / `RatingTable` | Нет рейтинг-компонентов. `LeaderCard` — hero по духу `MkTotalCard`. |

Всё, что решается `PctTag`/`EntityAvatar`/`Tag`/`Select`/`DataTable`/`Popover`/`Message`/`Skeleton` — **переиспользуем как есть**.

---

## 12. Открытые вопросы (с дефолтами)

**ОВ-1 [продукт].** Разрез планирования Поступлений: SpaceCRM планирует по продуктовым линейкам, у нас
`plan_targets.scope` гибкий. **Мой дефолт:** основной разрез — **по сотруднику** (единый контур с МК),
плюс переключатель «Разрез: Сотрудник | Продукт» в шапке метрики. Решение — за PO/`backend-architect` (влияет на форму матрицы и контракт).

**ОВ-2 [scope Ф0].** Конструктор пар конверсий (числитель/знаменатель + «Добавить конверсию») — SpaceCRM
имеет диалог создания метрики. **Мой дефолт:** в Ф0-UI — только матрица конверсий read-only с
дефолтными парами; полный конструктор пар — отдельное ТЗ на Ф4 (когда бэкенд даст `conversion_defs`). Заглушка «Добавить конверсию» disabled + tooltip «Скоро».

**ОВ-3 [UX].** Контрол «Слой» (Оперативный/Годовой) релевантен Планам и план-колонкам отчётов, но не
Рейтингу/Реестру-факту. **Мой дефолт:** показываем всегда в фильтр-баре, но на табах где не влияет — dimmed
с tooltip «Влияет на планы». Альтернатива — прятать. Оставляю dimmed (меньше «прыжков» лейаута).

**ОВ-4 [UX плотность].** Факт+% под каждым InputNumber в матрице (сотрудник×12мес) — очень плотно.
**Мой дефолт:** факт+PctTag показываем **под инпутом** для текущего/выбранного среза, но при >6 месяцев на
экране — факт в `v-tooltip` ячейки (hover), под инпутом только PctTag-точка. Порог плотности — на усмотрение
фронта при реализации, вернуть мне если неоднозначно.

**ОВ-5 [scope].** Разбивка Прямая/СБС на «Графике» (SpaceCRM разделяет). **Мой дефолт:** в Ф0 — суммарный
кумулятив; разбивка по типу сделки — в tooltip плитки/точки. Отдельные серии Прямая/СБС — если PO попросит.

**ОВ-6 [токены].** Цвета медалек рейтинга (золото/серебро/бронза). **Мой дефолт:** добавить
`$rank-gold/$rank-silver/$rank-bronze` в `_colors.scss` как semantic-акценты (единственный способ не
нарушить токен-дисциплину; это не «новые произвольные hex», а именованные бренд-акценты). Подтвердить у PO.

**ОВ-7 [реюз].** `PlanSaveBar` vs обобщённый `StickySaveBar`. **Мой дефолт:** сначала `PlanSaveBar` локально;
если следующий экран потребует такой же — вынести в `components/shared/StickySaveBar.vue` и отметить в charter §2.

**Требуется backend (Ф0-контракт, `backend-architect`):**
- `plan_targets` схема + `POST /api/v1/plan-targets/bulk` (bulk-upsert dirty-ячеек) + matrix-GET по метрике/скоупу/периоду/слою.
- `GET /api/v1/plan-targets?period=prev` (copy-previous источник).
- Реестр: `GET /api/v1/analytics/registry?month=&pipeline_id=&manager_id=` → 2 набора (expected / slippage) + итоги.
- График: `GET /api/v1/analytics/np-schedule?month=…` → дни (план/факт/дожим) + кумулятив + флаг выходной.
- Рейтинг: `GET /api/v1/analytics/best-manager?year=&mode=` → строки + лидер + «вне зачёта».
- Excel-экспорт по табам (PhpSpreadsheet).
- `plans.manage` permission (сидер, прогнать `RolePermissionSeeder` на проде — как МК).
- Все агрегаторы — через `VisibilityResolver` (менеджер — своё, директор — подчинённые, admin — всё).

---

*Ф0. Автор: agent `designer`. Идёт параллельно контракту `backend-architect`. После апрува PO — реализация по фазам Ф1…Ф5 (см. план спринта §5).*
</content>
</invoke>
