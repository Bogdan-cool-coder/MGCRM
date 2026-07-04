# ТЗ Э11 — Редизайн вкладки «Обзор» Dashboard-хаба (DS2)

**Статус:** **РЕАЛИЗОВАНО 2026-07-04 (Фаза 1), коммит `68788cf`** (Э11, reviewer PASS). As-built отклонения: реализован **вариант Б** grid — порядок + видимость виджетов (без drag-resize); клик по KPI фильтрует только по `pipeline_id` (не по стадии); семантика «сквозной конверсии» = честная win-rate (won / всего вошедших в воронку); в inline-форме `NoTaskWidget` поле `due_at` **обязательно** (создание задачи требует срока). Фаза 2 (валютный `CcyPopover`) — отложена за расширением `DashboardResponse` (§7 аудита).

> **Скоуп Э11 = ФАЗА 1, БЕЗ изменений backend.** Всё, что требует расширения API,
> вынесено в раздел «ГЭПы» с дефолтами и/или помечено «вне скоупа Э11». Не выдумывать API.

**Зачем:** привести вкладку «Обзор» аналитического хаба к DS2-мокапу — компактная 12-колоночная
виджет-сетка с настраиваемой раскладкой, KPI с trend-pills, обновлённые Funnel/Forecast/NoTask,
dismissible-баннер мультивалюты. User story: РОП открывает дашборд и за один экран видит
статус-группы, сквозную конверсию, взвешенный прогноз с составом и очаг риска (сделки без задач),
а также может подстроить раскладку под себя.

**Где в коде:** `front/src/pages/DashboardPage/components/tabs/TabOverview.vue` (корневой контейнер вкладки)
+ рестайл существующих виджетов в `front/src/pages/DashboardPage/components/*.vue`
+ 1 новый компонент-обёртка сетки (см. «Рекомендация по grid»).

**Источник фич (мокап):** `design-handoff/redesign/dashboard.html` (React-прототип — литералы reference-only, стек не переносится).
**Источник бизнес-логики (old):** `./examples/contracts/apps/web` — состав дашборда продаж (KPI/воронка/прогноз/топ/без задач).
**Данные:** `GET /api/sales/dashboard` → `DashboardResponse` (`front/src/entities/salesDashboard.ts`), composable `useDashboardPage.ts` — БЕЗ изменений контракта в Э11.

---

## 0. Что меняем, что оставляем (дельта current → target)

| Зона | Current (`TabOverview.vue`) | Target (Э11) |
|------|------------------------------|--------------|
| Раскладка | Bootstrap-grid `row/col-lg-*`, 3 фиксированных ряда | 12-колоночная виджет-сетка с настраиваемой раскладкой (localStorage), режим «Редактировать/Готово», «Сбросить» |
| KPI | `WidgetStatusGroups` — 4 карты, trend через `Tag severity` | Карты с **trend-pill** (стрелка + «N% к прошлому периоду»), hero-акцент для «active» (левый бордер + primary-рамка) |
| Воронка | `WidgetFunnelTable` — DataTable | **+ «Сквозная конверсия N%» в правой части шапки** (вычислимо из stage-строк). Тело таблицы — без изменений в Э11 (см. ОВ-1 про tapered-bar) |
| Прогноз | `WidgetForecast` — 4 KPI-карты 2×2 | **Рестайл:** hero-число (взвешенный) + stacked composition bar (HOT/Warm/Trial) + 3 legend-строки |
| Без задач | `WidgetDealsWithoutTasks` — только счётчик + кнопка | **Превью-СПИСОК** сделок без задач (до 4–5 строк из deals index `only_no_task=1`) + inline «+ Задача» + бейдж «N требуют внимания» → полный список |
| Мультивалюта | `<Message severity="warn">` не закрываемый | **Dismissible CurrencyBanner** (крестик, скрытие в сессии) |
| Топ-10 | `WidgetTopBar` — ECharts bar + сегмент Продукты/Менеджеры | Остаётся; переносится внутрь сетки как виджет (визуал баров — без изменений в Э11) |
| Тулбар | `DashboardToolbar` (period/pipeline/manager) | Остаётся; **+ кнопка «Редактировать / Готово»** для режима раскладки. Экспорт Excel — как есть |

**НЕ трогаем в Э11:** контракт `GET /api/sales/dashboard`; фильтры period/pipeline/manager
(«прежнее поведение сохранить» — см. коммент в `TabOverview.vue`); ECharts-бары `WidgetTopBar`;
логику `useDashboardPage.ts` (кроме подключения нового ресурса для превью NoTask — точечно).

---

## 1. Wireframe (ASCII) — целевой обзор

```
┌───────────────────────────────────────────────────────────────────────────────┐
│ [Период: Месяц|Квартал|Год]  [Воронка ▾]  [Менеджер ▾]   [✎ Редактировать] [⭳ Excel] │  ← DashboardToolbar (+ кнопка режима)
├───────────────────────────────────────────────────────────────────────────────┤
│ ⓘ Часть сделок в других валютах не включена…  Каталог → Курсы валют       [✕]   │  ← CurrencyBanner (dismissible)
├───────────────────────────────────────────────────────────────────────────────┤
│ [режим правки]  ⟵ Перетаскивайте виджеты… ⟶                    [↻ Сбросить]     │  ← EditModeBanner (только edit=true)
├───────────────────────────────────────────────────────────────────────────────┤
│                            12-колоночная виджет-сетка                            │
│ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐                                     │
│ │▎Активн.│ │ Выигр. │ │ Проигр.│ │ Итого  │   ← 4 KPI-карты (по 3 колонки)      │
│ │ 42 ≈148│ │ 18 ≈96 │ │  7 ≈21 │ │ 67 ≈265│                                     │
│ │▲12% ...│ │▲8% ... │ │▲3% ... │ │без сравн│                                     │
│ └────────┘ └────────┘ └────────┘ └────────┘                                     │
│ ┌─────────────────────────────┐ ┌───────────────────────────┐                   │
│ │ Конверсия по стадиям         │ │ Прогноз по выручке         │                  │
│ │        Сквозная конверсия 43%│ │ Взвешенный прогноз         │                  │
│ │ ● Новая     ▓▓▓ 42  →74% 2.1д│ │  ≈ 82,4 млн ₸ (hero)       │                  │
│ │ ● Квалиф.   ▓▓  31  →68% 4.4д│ │  ▓▓▓▓▓▓▓▓░░░░  (bar)       │                  │
│ │ ● …                          │ │  🔥 HOT-deals    46%  38,1 │                  │
│ │ ● Успешно  WON 18   —   1.4д │ │  ☀ Warm          36%  29,3 │                  │
│ │      (funnel, ~7 кол)        │ │  ⏱ Trial/Перег.  18%  15,0 │                  │
│ └─────────────────────────────┘ └───────────────────────────┘  (forecast, ~5)   │
│ ┌─────────────────────────────┐ ┌───────────────────────────┐                   │
│ │ Топ-10 по выручке [Прод|Мен] │ │ Сделки без задач [9 треб.▸]│                  │
│ │  ▓▓▓▓▓▓▓ echarts bar         │ │ • CRM «МедПлюс» 2,1млн Нов.│                  │
│ │                              │ │   · 11 дн. без задачи [+Задача]                │
│ │      (top, ~7 кол)           │ │ • BI «Финрезерв»…  [+Задача]│                  │
│ └─────────────────────────────┘ └───────────────────────────┘  (notask, ~5)     │
└───────────────────────────────────────────────────────────────────────────────┘
```

Дефолтная раскладка (12 колонок; из мокапа `DEFAULT_LAYOUT`):

| Виджет | id | x | y | w | h |
|--------|----|---|---|---|---|
| KPI Активные | `kpi-active` | 0 | 0 | 3 | 5 |
| KPI Выиграно | `kpi-won` | 3 | 0 | 3 | 5 |
| KPI Проиграно | `kpi-lost` | 6 | 0 | 3 | 5 |
| KPI Итого | `kpi-total` | 9 | 0 | 3 | 5 |
| Воронка | `funnel` | 0 | 5 | 7 | 12 |
| Прогноз | `forecast` | 7 | 5 | 5 | 12 |
| Топ-10 | `top` | 0 | 17 | 7 | 10 |
| Без задач | `notask` | 7 | 17 | 5 | 10 |

`localStorage` ключ раскладки: **`mg-dash-layout-v2`** (как в мокапе). Валидация при загрузке:
массив длины `DEFAULT_LAYOUT.length` — иначе fallback на дефолт.

---

## 2. Рекомендация по grid-библиотеке

**Факт:** PLAN §3.2 явно называет **`grid-layout-plus`** одобренным пакетом для кастом-дашбордов,
НО его **нет в `front/package.json`** (проверено: deps не содержат ни `grid-layout-plus`, ни аналога).
Значит фактически пакет не установлен.

**Два варианта для frontend-specialist:**

### Вариант (Б) — РЕКОМЕНДУЕМЫЙ для Э11 (без новой зависимости) ✅
Не тянуть drag/resize-библиотеку. Реализовать настраиваемую раскладку минимально:
- **12-колоночный CSS-grid** через существующий Bootstrap-grid / `display:grid` на токенах
  (виджеты в фиксированных пресетах ширины: KPI = 3 кол, funnel/top = 7 кол, forecast/notask = 5 кол).
- **Настройка = порядок + видимость виджетов** через маленькое меню в режиме «Редактировать»
  (переключатели «показать/скрыть» + стрелки «вверх/вниз» по каждому виджету), состояние в
  `localStorage['mg-dash-layout-v2']` как массив `{ i, visible, order }`.
- **«Сбросить раскладку»** — очистка ключа → дефолт.
- **Без пиксельного drag и без resize-хэндла** — это компромисс: юзер меняет порядок и скрывает
  ненужное, но не тянет мышью. Ноль риска по стеку, ноль нового пакета, полностью на токенах.

**Почему (Б):** новый пакет требует аппрува юзера (Library-first, ARCHITECTURE §0.1); а сам
drag/resize — «приятно, но не критично» для первой поставки. Порядок+видимость закрывают 80%
пользы «настраиваемого дашборда» без риска. `mg-dash-layout-v2` остаётся тем же ключом → апгрейд
до (А) позже совместим по схеме (расширить объект полями x/y/w/h).

### Вариант (А) — отложенный апгрейд (нужен аппрув) ⏸
Полноценный drag-move + resize как в мокапе — через **`grid-layout-plus`** (`GridLayout`/`GridItem`,
`vue3-grid-layout`-совместимый API, поддерживает `:layout`, `@layout-updated`, resize-хэндлы).
- **ФЛАГ:** новый npm-пакет → **требует явного аппрува юзера** перед установкой (не ставить молча).
- Плюс: 1-в-1 мокап (перетаскивание за шапку, resize за угол, авто-compact).
- Минус: новая зависимость + повторный тест раскладки; drag на тач-устройствах требует отдельной проверки.
- **Важно при (А):** каждый виджет с ECharts (`WidgetTopBar`) — **перерисовка при resize** через
  `chart.resize()` на событие изменения размера GridItem (vue-echarts `ref` → `.resize()` в
  `ResizeObserver` / `@resized` GridItem). Без этого бар «обрежется» при изменении ширины виджета.

> **Итог:** реализуем **(Б)** в Э11. **(А)** — отдельная задача после аппрува пакета `grid-layout-plus`.
> Оба варианта используют один ключ `mg-dash-layout-v2` и одну кнопку «Редактировать/Готово».

---

## 3. Зоны и компоненты

### 3.1 Тулбар (`DashboardToolbar.vue` — доработка)
| Элемент | Компонент | Props / атрибуты |
|---------|-----------|------------------|
| Период | PrimeVue `SelectButton` / segmented (как сейчас) | Месяц/Квартал/Год → `period` |
| Воронка | PrimeVue `Select` | `pipeline_id`, иконка `pi pi-sitemap` |
| Менеджер | PrimeVue `Select` (только admin/director) | `manager_id` |
| **Режим раскладки** (НОВОЕ) | PrimeVue `Button` toggle | `:label="edit ? t('...done') : t('...edit')"` `:icon="edit ? 'pi pi-check' : 'pi pi-pencil'"` `:severity="edit ? 'primary' : 'secondary'"` `:outlined="!edit"` |
| Экспорт | PrimeVue `Button` | `icon="pi pi-download"` `severity="secondary" outlined` — без изменений |

### 3.2 CurrencyBanner (dismissible — доработка существующего `<Message>`)
| Элемент | Компонент | Атрибуты |
|---------|-----------|----------|
| Баннер | PrimeVue `Message severity="warn"` | `:closable="true"` (было `false`) — крестик скрывает в текущей сессии |
| Ссылка | `router-link` | «Каталог → Курсы валют» → страница FxRate (см. ОВ-3 про целевой роут) |

> Текст закрытия: `Message` с `closable` эмитит `@close` — состояние `bannerDismissed` в
> локальном `ref` вкладки (НЕ localStorage — баннер должен вернуться при новой сессии/reload,
> т.к. проблема курсов не решена; персист скрытия скрыл бы реальную проблему данных).

### 3.3 EditModeBanner (только `edit=true`)
| Элемент | Компонент | Токены |
|---------|-----------|--------|
| Плашка-подсказка | обычный `div` | фон `$primary-50` / бордер `$primary-200`; текст `$primary-color`; иконка `pi pi-arrows-alt` |
| «Сбросить» | PrimeVue `Button text` | `icon="pi pi-refresh"` `severity="secondary" text` → очистка `mg-dash-layout-v2` |

### 3.4 KPI-карты (`WidgetStatusGroups.vue` — рестайл)
| Элемент | Реализация | Токены |
|---------|-----------|--------|
| Карта | `div.kpi-card` (как сейчас), 4 шт | фон `$surface-card`, бордер `$surface-200`, радиус `$radius-lg`, паддинг `$space-4` |
| Hero-акцент (active) | левый бордер 3px + рамка primary | `border-left: 3px solid $primary-color` + `border-color: $primary-color`; в dark — `var(--p-primary-color)` |
| Иконка-плитка | 28×28, `$radius-md` | тон по группе: active `$primary-100/$primary-color`; won `$status-success-bg/$status-success-text`; lost `$status-danger-bg/$status-danger-text`; total `$surface-100/$surface-600` |
| Число | 30px bold | `$surface-900` |
| Сумма | рядом с числом | `$primary-color` (в dark `var(--p-primary-color)`) |
| **Trend-pill** (замена plain-Tag) | inline-flex pill | стрелка `pi-arrow-up-right`/`pi-arrow-down-right` + «N% к прошлому периоду»; good → `$status-success-text` на `$status-success-bg`; bad → `$status-danger-text` на `$status-danger-bg`; `null` → «без сравнения» серым `$surface-500`. Инверсия good/bad для `lost` (рост потерь = плохо) — логика `trendSeverity` уже есть |
| Клик по карте | → deals list с фильтром группы | см. Interactions |

### 3.5 FunnelWidget (`WidgetFunnelTable.vue` — доработка шапки)
| Элемент | Реализация |
|---------|-----------|
| Заголовок | «Конверсия по стадиям · «{pipeline}»» |
| **Сквозная конверсия** (НОВОЕ, справа в шапке `Card #title` / header-slot) | «Сквозная конверсия **N%**», N = round(`won_count / entered_first_stage_count * 100`). **Вычислимо из существующих stage-строк** — см. раздел «Данные / вычисления». Число — `$status-success-text` bold |
| Тело таблицы | DataTable как сейчас (стадия · count · avg_days · transition-bar) — **без изменений в Э11** |

### 3.6 ForecastWidget (`WidgetForecast.vue` — рестайл)
| Элемент | Реализация | Токены |
|---------|-----------|--------|
| Подпись | «Взвешенный прогноз на период» | `$surface-500`, `$font-size-xs` |
| Hero-число | `total_weighted_kopecks` через `formatMoney` | 30px bold `$surface-900` |
| **Stacked composition bar** | горизонтальный бар, 3 сегмента по долям HOT/Warm/Trial | высота 12px, `$radius-pill`; цвета: hot `var(--p-orange-500)`, warm `$status-warning-text` (amber), trial `var(--p-blue-500)` |
| **Legend-строки** (×3) | иконка-плитка + label + % + сумма | иконки: hot `pi-fire`, warm `pi-sun`, trial `pi-clock`; плитка = `color-mix($color 15%, $surface-card)`; строки разделены `$surface-200` |
| Доли (%) | вычисляемы из сумм | pct = round(part_kopecks / (hot+warm+trial) * 100). При нулевой сумме бар пустой, «—» |

> Убрать текущий 2×2 grid из 4 KPI-карт. Взвешенный прогноз теперь hero, hot/warm/trial — legend.
> **CcyPopover (клик по hero → разбивка по валютам) — ВНЕ СКОУПА Э11** (см. §5 Фаза 2).

### 3.7 TopWidget (`WidgetTopBar.vue`)
Без изменений визуала в Э11 — переносится внутрь сетки как виджет `top`. Сегмент Продукты/Менеджеры
и ECharts-бар остаются. **Если реализуется вариант (А)** — добавить `chart.resize()` при resize виджета.

### 3.8 NoTaskWidget (`WidgetDealsWithoutTasks.vue` — переработка в превью-список)
| Элемент | Реализация | Endpoint |
|---------|-----------|----------|
| Заголовок | «Сделки без задач» | — |
| Бейдж «N требуют внимания» (справа в шапке) | pill `$status-warning-bg/$status-warning-text`, иконка `pi-exclamation-triangle` + `pi-arrow-right` | N = `deals_without_tasks.count` (уже в ответе) |
| **Превью-список** (до 4–5 строк) | список `DealDto[]` из deals index с `only_no_task=1` | `GET /api/deals?pipeline_id={id}&only_no_task=1&per_page=5` (см. §Данные) |
| Строка | title + сумма (`formatMoney(amount)`) + стадия (`stage.name`) + «N дн. без задачи» | — |
| «N дн. без задачи» | оранжевый `$status-warning-text`; ≥14 дн → `$status-danger-text` | значение из `days_in_stage` (ГЭП-1, см. ниже) |
| **inline «+ Задача»** | PrimeVue `Button` outlined sm, `icon="pi pi-plus"` | открывает `TaskQuickForm` (mode=create, targetType='deal', targetId=deal.id) — reuse |
| Клик по бейджу/«показать все» | → deals list `only_no_task=1` | `filter_url` из ответа (уже есть) |
| Empty (count=0) | иконка `pi-check-circle` `$status-success-text` + «Все сделки со задачами» | — |
| Loading | `Skeleton` 4 строки | — |

> **Reuse:** строка задачи создаётся через существующий `TaskQuickForm`
> (`front/src/components/tasks/TaskQuickForm.vue`, mode `create`, entity-agnostic `targetType`/`targetId`).
> После успешного создания — убрать строку из превью локально + Toast success + декремент бейджа
> (оптимистично; полный refetch дашборда не обязателен).

---

## 4. Состояния (для каждого виджета)

| Виджет | loading | empty | error |
|--------|---------|-------|-------|
| KPI-карты | `Skeleton` 4×88px | иконка `pi-chart-line` `$font-size-icon-xl` + «Нет сделок за период» | Toast error (глобально из composable) |
| Funnel | `Skeleton` 200px | `pi-funnel` + «Воронка пуста» | Toast error |
| Forecast | `Skeleton` (hero + bar + 3 строки) | `pi-calculator` + «Нет прогноза» | Toast error |
| Top | как сейчас | как сейчас | Toast error |
| NoTask | `Skeleton` 4 строки | `pi-check-circle` success + «Все сделки со задачами» | если превью-запрос упал — показать только счётчик (fallback на старое поведение) + кнопку «Открыть список» |
| Вся вкладка (нет воронки) | — | `Message severity="info"` «Выберите воронку» (`meta.no_pipeline`) — как сейчас | — |

> **Anti-flash:** `loading` из `useDashboardPage` уже гасит empty-state-мигание до первого фетча
> (`dataReady`). Превью NoTask имеет собственный loading — не блокирует остальные виджеты.

---

## 5. Interactions

| Элемент | Действие | Результат | Endpoint |
|---------|----------|-----------|----------|
| Период / Воронка / Менеджер | change | debounce 350ms → refetch дашборда | `GET /api/sales/dashboard` (как есть) |
| Кнопка «Редактировать» | click | `edit = !edit`; показать EditModeBanner + контролы порядка/видимости | — (клиент) |
| Стрелки ↑/↓ виджета (edit, вар. Б) | click | сдвиг `order` в `mg-dash-layout-v2` | — (localStorage) |
| Тумблер «показать/скрыть» (edit, вар. Б) | toggle | `visible` в `mg-dash-layout-v2` | — (localStorage) |
| Drag за шапку / resize за угол (edit, вар. А) | mouse | обновление x/y/w/h; ECharts resize | — (localStorage) |
| «Сбросить раскладку» | click | очистка `mg-dash-layout-v2` → дефолт | — |
| CurrencyBanner ✕ | click | `bannerDismissed=true` (сессия) | — |
| CurrencyBanner ссылка | click | `router.push` к справочнику курсов | — (роут, ОВ-3) |
| KPI-карта «Активные» | click | deals list, статус open | `/deals?pipeline_id={id}` (см. ОВ-2 про точный фильтр статуса) |
| KPI-карта «Выиграно/Проиграно» | click | deals list, статус won/lost | `/deals?pipeline_id={id}&status=won` (ОВ-2) |
| KPI-карта «Итого» | click | все сделки воронки | `/deals?pipeline_id={id}` |
| Funnel-строка (стадия) | click | deals list на этой стадии | `/deals?pipeline_id={id}&stage_id={sid}` (ОВ-2) |
| Forecast legend-строка | click | deals list по сегменту | ОВ-4 (нет готового фильтра hot/warm/trial) — **в Э11 не кликабельно**, hover-подсветка только |
| NoTask строка | click | открыть карточку сделки | `router.push('/deals/'+id)` |
| NoTask «+ Задача» | click | `TaskQuickForm` create → сохранить | `POST /api/deals/{id}/activities` (или текущий task-endpoint TaskQuickForm) |
| NoTask бейдж «N требуют внимания» | click | deals list `only_no_task=1` | `filter_url` из ответа |

---

## 6. Данные — сопоставление виджет → поля ответа

Ответ: `DashboardResponse` (`front/src/entities/salesDashboard.ts`). Composable — `useDashboardPage.ts`. Деньги — копейки (`*_kopecks`), формат `formatMoney(kopecks, locale, base_currency)`.

| Виджет / элемент | Поле в ответе | Готово? |
|------------------|---------------|---------|
| KPI count | `status_groups[].count` | ✅ |
| KPI сумма | `status_groups[].amount_kopecks` | ✅ |
| KPI trend-pill | `status_groups[].trend_pct` (number\|null) | ✅ |
| KPI base currency | `meta.base_currency` | ✅ |
| Funnel строки | `funnel.stages[]`: `stage_name`, `count`, `avg_days_in_stage`, `transition_to_next_pct`, `is_won`, `is_lost` | ✅ |
| Funnel «Сквозная конверсия» | **вычислимо:** `round(funnel.total_won / (первый stage.count по sort_order) * 100)` — оба поля есть (`funnel.total_won` + `stages` сортированы `sort_order`). Fallback если первый count=0 → «—» | ✅ (вычисление на клиенте) |
| Forecast hero | `forecast.total_weighted_kopecks` | ✅ |
| Forecast HOT/Warm/Trial суммы | `forecast.hot_kopecks` / `warm_kopecks` / `trial_kopecks` | ✅ |
| Forecast доли % | вычислимо: `part / (hot+warm+trial) * 100` | ✅ (клиент) |
| Top-10 | `top_products` / `top_managers` (`TopChartData`) | ✅ |
| NoTask счётчик | `deals_without_tasks.count` | ✅ |
| NoTask deep-link | `deals_without_tasks.filter_url` (`/deals?...&only_no_task=1`) | ✅ |
| NoTask **превью-список** | `GET /api/deals?pipeline_id={id}&only_no_task=1&per_page=5` → `DealDto[]`: `title`, `stage.name`, `amount`, `currency`, `days_in_stage`, `stage_changed_at` | ✅ (доступный index-endpoint; `only_no_task` уже поддержан `DealService`) |
| Мультивалюта-баннер | `meta.multi_currency_warning` (bool) | ✅ |
| «Нет воронки» | `meta.no_pipeline` | ✅ |

---

## 7. ГЭПы (нет поля в API — дефолты, НЕ выдумывать endpoint)

| # | ГЭП | Дефолт в Э11 | Что нужно от backend (позже) |
|---|-----|--------------|------------------------------|
| **ГЭП-1** | «N дн. без задачи» в NoTask-строке — точного «дней с момента закрытия последней задачи» в API нет | Показать `days_in_stage` (сколько дней сделка в текущей стадии) как прокси. Порог красного ≥14 — от `days_in_stage`. Подпись «дн. в стадии» вместо «дн. без задачи», чтобы не врать пользователю | Поле `days_without_task` в deals-index payload (расчёт от последней завершённой/созданной задачи) |
| **ГЭП-2** | Trend-pill «к прошлому периоду» — `trend_pct` есть только для `status_groups`, у Funnel/Forecast trend нет | Trend только на KPI-картах (данные есть). Funnel/Forecast — без trend | (опц.) trend по воронке/прогнозу |
| **ГЭП-3** | Forecast — разбивка по исходным валютам (CcyPopover) и «N сделок без курса» | **Вне скоупа Э11** (Фаза 2). Hero-число без popover, dashed-underline не добавлять | Расширение `forecast` полями `by_currency[]` + `deals_missing_rate_count` |
| **ГЭП-4** | Клик по Forecast-сегменту (hot/warm/trial) → фильтр сделок | В Э11 legend-строки НЕ кликабельны (только hover). Нет фильтра сделок по «температуре» | Пресет-фильтр deals `?temperature=hot` или маппинг на probability-диапазон |
| **ГЭП-5** | Клик по KPI/Funnel-строке → точный фильтр deals list по статусу/стадии | Использовать существующие query-параметры deals list (`status`, `stage_id`, `only_no_task`). Точный набор проверить у deals-frontender (ОВ-2) | — (проверка существующих фильтров) |

> **Правило:** ни один ГЭП не блокирует Э11. Все имеют работающий дефолт на существующих данных.

---

## 8. Токены и компоненты

**Компоненты PrimeVue:** `Card`, `Button`, `Select`/`SelectButton`, `Message` (closable), `Tag`,
`Skeleton`, `DataTable`+`Column` (funnel), ECharts через `vue-echarts` (top). Reuse проекта:
`TaskQuickForm`, `formatMoney`/`formatTrendPct` (`@/utils/chartFormatters`), `useDashboardPage`.

**Отступы:** карта `$space-4`; шапка виджета `$space-3 $space-4`; gap сетки `$space-3`/`$space-4`.
**Радиусы:** карты/виджеты `$radius-lg`; иконка-плитка `$radius-md`; pill `$radius-pill`; bar-сегмент `$radius-pill`.
**Поверхности:** фон карты `$surface-card`; hover-строки `var(--mg-surface-hover)`; бордеры `$surface-200`; текст `$surface-900`/`$surface-700`/`$surface-500`.
**Статус-токены:** success `$status-success-text/$status-success-bg`; warning `$status-warning-text/$status-warning-bg`; danger `$status-danger-text/$status-danger-bg`.
**Primary:** `$primary-color`/`$primary-100`/`$primary-50`; **в dark — `var(--p-primary-color)`** (акцент светлеет `#172747 → #4C7DF0`, читать через переменную, не литерал).

**Обе темы — обязательно.** Все токены выше theme-reactive → отдельная dark-ветка не нужна.
Помнить инвертированную navy-шкалу: текст в dark — `$surface-800/900` (светлые), фон-hover — `var(--p-surface-200)`.
**Запрещено:** литеральные hex/px мимо токенов (мокап-литералы `--mg-*`, `#fff` — reference-only,
маппить на репо-токены), Tailwind, градиенты, цветные тени, эмодзи (только PrimeIcons).

---

## 9. i18n-ключи (RU обязательно, EN — задел; namespace `dashboard.*`)

Многие ключи уже существуют (`dashboard.statusGroups.*`, `dashboard.funnel.*`, `dashboard.forecast.*`,
`dashboard.dealsWithoutTasks.*`, `dashboard.multiCurrencyWarning`, `dashboard.empty.*`). НОВЫЕ:

```json
{
  "ru": {
    "dashboard.layout.edit": "Редактировать",
    "dashboard.layout.done": "Готово",
    "dashboard.layout.reset": "Сбросить раскладку",
    "dashboard.layout.editHint": "Режим редактирования: меняйте порядок и видимость виджетов.",
    "dashboard.layout.show": "Показать",
    "dashboard.layout.hide": "Скрыть",
    "dashboard.funnel.overallConversion": "Сквозная конверсия",
    "dashboard.forecast.weightedOnPeriod": "Взвешенный прогноз на период",
    "dashboard.statusGroups.trendVsPrev": "{pct}% к прошлому периоду",
    "dashboard.statusGroups.noCompare": "без сравнения",
    "dashboard.dealsWithoutTasks.needAttention": "{count} требуют внимания",
    "dashboard.dealsWithoutTasks.daysInStage": "{days} дн. в стадии",
    "dashboard.dealsWithoutTasks.addTask": "Задача",
    "dashboard.dealsWithoutTasks.showAll": "Показать все",
    "dashboard.dealsWithoutTasks.taskCreated": "Задача создана",
    "dashboard.currencyBanner.link": "Каталог → Курсы валют"
  },
  "en": {
    "dashboard.layout.edit": "Edit",
    "dashboard.layout.done": "Done",
    "dashboard.layout.reset": "Reset layout",
    "dashboard.layout.editHint": "Edit mode: reorder and toggle widget visibility.",
    "dashboard.funnel.overallConversion": "Overall conversion",
    "dashboard.forecast.weightedOnPeriod": "Weighted forecast for period",
    "dashboard.statusGroups.trendVsPrev": "{pct}% vs previous period",
    "dashboard.statusGroups.noCompare": "no comparison",
    "dashboard.dealsWithoutTasks.needAttention": "{count} need attention",
    "dashboard.dealsWithoutTasks.daysInStage": "{days} d in stage",
    "dashboard.dealsWithoutTasks.addTask": "Task",
    "dashboard.dealsWithoutTasks.showAll": "Show all",
    "dashboard.dealsWithoutTasks.taskCreated": "Task created",
    "dashboard.currencyBanner.link": "Catalog → Exchange rates"
  }
}
```

---

## 10. Референс-экраны
- Мокап: `design-handoff/redesign/dashboard.html` (DS2-прототип, литералы reference-only).
- Текущая реализация: `front/src/pages/DashboardPage/components/tabs/TabOverview.vue` + `components/Widget*.vue`.
- Похожий паттерн inline-задачи: `front/src/components/tasks/TaskQuickForm.vue`, `OpenTasksList.vue`.
- Хаб-табы и фильтр-бар: `front/src/pages/DashboardPage/index.vue`, `components/AnalyticsFilterBar.vue`.

---

## 11. Открытые вопросы

1. **[ОВ-1]** Funnel-тело: в мокапе tapered-bar (полоса по count с цветом стадии) вместо текущей
   DataTable-строки «transition-bar». В Э11 оставляю **текущую DataTable** (визуал бара не в скоупе).
   Нужен ли редизайн тела funnel под мокап-строки в этой фазе? (по умолчанию — нет).
2. **[ОВ-2]** Точные query-параметры deals list для кликов по KPI/Funnel (`status=won|lost|open`,
   `stage_id`). Подтвердить у deals-frontender существующий набор фильтров DealsPage (не выдумывать).
3. **[ОВ-3]** Целевой роут ссылки CurrencyBanner «Курсы валют» — есть ли готовая страница FxRate в
   `/settings` или каталоге? Указать точный `to`.
4. **[ОВ-4]** Клик по Forecast-сегменту (hot/warm/trial) — в Э11 не кликабелен (ГЭП-4). Оставляем hover
   или полностью статичные legend-строки?
5. **[ОВ-5]** Вариант grid: подтвердить **(Б) без пакета** для Э11. Если юзер хочет полный drag/resize
   (А) — **аппрув установки `grid-layout-plus`** (новый npm-пакет).
6. **[ОВ-6]** NoTask-превью — брать сделки только текущей воронки (`pipeline_id` из фильтра) или всех
   доступных? По умолчанию — текущей воронки (консистентно с остальными виджетами).

---

**Скоуп-граница Э11:** ФАЗА 1 (без backend). Валютный CcyPopover прогноза, «N сделок без курса»
и точный «days_without_task» — **Фаза 2, ждёт расширения API** (ГЭП-1, ГЭП-3). В Э11 не реализуются.
```

