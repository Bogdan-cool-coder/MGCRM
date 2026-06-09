# Vizion — План доработок на основе конкурентного анализа capitaldata.ru

> Фактурная база — `COMPETITIVE_ANALYSIS.md` (35 KB, собрано 2026-05-20).
> Скриншоты конкурента — `capitaldata-01-dashboard.png … capitaldata-14-new-dashboard.png` в корне проекта.
> Этот документ — план реализации, не дублирует сырые данные реконнанса.

---

## Принятые решения (2026-05-20)

| # | Вопрос | Решение |
|---|---|---|
| 1 | Чарт-библиотека | **Остаёмся на Chart.js 4** (без миграции). PrimeVue `<Chart>` остаётся как есть. |
| 2 | Layout виджетов дашборда | **Drag-and-drop через `vue-grid-layout`** (актуальный форк для Vue 3 — `grid-layout-plus`). State: массив `{i, x, y, w, h}` per widget, persist per user × per report. |
| 3 | Данные виджетов | **Полный датасет с применёнными фильтрами** (без пагинации). Backend отдаёт виджеты отдельным агрегат-запросом либо через `ReportDataService::getDashboardData()` без pagination. |
| 8 | Мини-чат | **Продолжать последний активный чат per user**. Если page-context изменился (другой отчёт) — создавать новый чат. На не-отчётных страницах — продолжать последний context-free. |

---

## Преамбула

**Цель:** добавить в Vizion выбранные UX-паттерны конкурента, не разрушив ни одной существующей фичи.

**Что НЕ делаем:**
- Монетизацию, AI usage units, top-up — не нужны (корпоративный внутренний продукт).
- Tailwind вместо Bootstrap — запрещено стеком.
- Recharts (React-библиотека) — не совместима с Vue 3 напрямую, не используем.
- Локали uz/ka — бэклог.
- Глобальные рефакторинги существующего кода вне скоупа пункта.

**Совместимость — железное правило:** все 6 существующих системных отчётов из `src/database/seeders/ReportSeeder.php` должны работать без изменений после каждой итерации. Новые поля в `report.config` — аддитивные (nullable или с дефолтами).

**Формат работы:** каждый пункт = отдельная сессия / отдельная фича-ветка. При старте сессии агент читает только свой пункт + указанные в нём файлы.

---

## Roadmap — чеклист

| # | Название | Статус | Сложность |
|---|---|---|---|
| 1 | Дашборды как вид отчёта (Chart.js 4 + grid-layout-plus) | ✅ Готово (2026-05-20) | Высокая |
| 2 | Группировка колонок таблицы и виджетов дашборда | ✅ Готово (2026-05-21) | Средняя |
| 3 | Мультивалютность + таймзоны на уровне компании | ✅ Готово (2026-05-20) | Средняя |
| 4 | `<meta name="robots" content="noindex">` | ✅ Готово (2026-05-20) | Тривиальная |
| 5 | Tooltip-подсказки к заголовкам колонок | ✅ Готово (2026-05-21) | Средняя |
| 6 | Мини-чат-виджет в тулбаре | ✅ Готово (2026-05-21) | Средняя |

---

## Зависимости между пунктами

```
п.3 (валюта/таймзона в companies) → п.1 (форматирование сумм в виджетах)
п.1 (dashboard-вид) → п.2 (группировка виджетов в дашборде)
п.5 (описания колонок в конфиге) → не блокирует, аддитивно
п.6 (мини-чат) → независим, но использует router-state handoff из CLAUDE.md
п.4 → независим полностью
```

---

## Пункт 1. Дашборды как вид отчёта (виджеты поверх Chart.js 4)

**One-liner:** Добавить на страницу отчёта переключатель «таблица / дашборд»; в режиме дашборда — виджеты как срез полного датасета отчёта (без пагинации); drag-and-drop layout через `grid-layout-plus`; состояние хранится per user × per report; конфиг виджетов поддаётся AI-генерации.

---

### 1.1 Как у них (capitaldata.ru)

Источник: `COMPETITIVE_ANALYSIS.md` §1, §2, §6.

- Recharts — React-библиотека (SVG, декларативная), `recharts-responsive-container`, `recharts-tooltip-wrapper-right`, `recharts-legend-item`. 16+ инстансов на одной странице дашборда. (capitaldata-01-dashboard.png, capitaldata-02-dashboard.png)
- Навигация: слева — «Dashboards» (composable grid виджетов) и «Reports» (страницы с таблицами) — две отдельные сущности. (capitaldata-01-dashboard.png)
- Дашборд-шапка: `[title ▼] [Updated 1 min ago] [< period >] [Reset] [Refresh] [AI assistant] [+ Widget]`. (capitaldata-02-dashboard.png)
- Виджет-карточка: заголовок + «Explain chart in AI assistant» + «Remove» + chart body (Recharts SVG). (capitaldata-06-widget-card.png)
- `localStorage`: ключи `macrodata-dashboards-v2`, `macrodata-dashboard-period`, `macrodata-custom-widgets` — версионированная схема. (COMPETITIVE_ANALYSIS.md §1)
- Каталог виджетов (модалка Add widget): «Standard» ~15 типов + «AI chart» (single-prompt). (capitaldata-05-add-widget.png)

**Ключевое отличие от нас:** у них Dashboard и Report — разные сущности. Нас просит пользователь: виджеты — это СРЕЗ ТОГО ЖЕ ЗАПРОСА, не отдельные запросы. Связь жёсткая: `report.config` определяет датасет, виджеты — способы его визуализации.

---

### 1.2 Как у нас сейчас

**Backend:**
- `src/app/Models/Report.php` — поля `config` (jsonb), `is_system`, `user_id`, `company_id`. Config содержит `primary_model`, `columns[]`, `where[]`, `group_by`, `chart` (Chart.js-формат: `{type, labels, datasets}`).
- `src/app/Services/MacroData/ReportDataService.php` — `getData()` возвращает `{columns, rows, meta, chart, filters_available, filters_applied}`. `chart`-ключ собирается там же (агрегация по `chart.groupBy` из конфига).
- `src/database/seeders/ReportSeeder.php` — 6 системных отчётов, у некоторых задан `chart` в конфиге.

**Frontend:**
- `front/src/pages/ReportPage/index.vue` — страница отчёта. Содержит `<Tabs>` с двумя вкладками: «Таблица» (value="0") и «График» (value="1"). Chart рендерится через `<Chart>` из `primevue/chart` (Chart.js wrapper). Конфиг чарта: `chartConfig` (из `useReportPresentation`), `chartOptions`.
- `front/src/pages/ReportPage/composables/useReportPresentation.ts` — вычисляет `chartConfig` и `chartOptions` из `report.chart` (ответ API).
- `front/src/components/cards/GenerateReportTile/GenerateReportTile.vue` — тайл «Создать отчёт через AI» на странице `/reports`.
- `front/src/pages/ReportsPage/index.vue` — список отчётов (карточки).
- `front/src/entities/report/types.ts` — TypeScript-типы отчёта.
- Текущая chart-зависимость: `"chart.js": "^4.5.1"` в `front/package.json`. PrimeVue использует Chart.js через свой `<Chart>` компонент (не прямой import).

**Состояние вкладок сейчас:** не сохраняется — при переходе на другую страницу и обратно вкладка сбрасывается в «Таблица».

---

### 1.3 Что делаем — Acceptance Criteria

**A. Чарт-библиотека — Chart.js 4 (без изменений).**

Решение принято: остаёмся на Chart.js 4 и PrimeVue `<Chart>`. Никакой миграции на vue-echarts, ApexCharts или Recharts. Recharts (React-библиотека) — полностью исключён. Идея конкурента переиспользована на уровне UX (composable dashboards), не на уровне библиотеки. Существующие инстансы `<Chart>` в `ReportPage` не трогаем.

**B. Переключатель «таблица / дашборд» per user × per report.**

1. Добавить в интерфейс кнопку/toggle `[Таблица] [Дашборд]` в шапке отчёта (рядом с текущим `<TabList>`).
2. Режим «Таблица» — текущее поведение (неизменно).
3. Режим «Дашборд» — отображаются виджеты на основе данных из текущего ответа `GET /api/reports/{id}`.
4. Состояние переключателя сохраняется в `localStorage` под ключом `vizion-report-view-{report_id}-{user_id}` (или в Pinia-сторе `reportViewStore`, если уже есть).
5. Фильтры общие: при смене фильтра в любом режиме — данные обновляются, оба режима используют тот же `report` из `useReportPageData`.

**C. Конфиг виджетов дашборда в `report.config`.**

Новое поле в `report.config.dashboard_widgets[]` (аддитивное, nullable):

```jsonc
{
  "dashboard_widgets": [
    {
      "id": "deals_by_complex",           // уникальный ID виджета
      "title": {"ru": "Сделки по ЖК", "en": "Deals by Complex"},
      "chart_type": "bar",                // bar | line | pie | doughnut
      "value_field": "deal_sum",          // поле для значения (из columns)
      "label_field": "geo_complex_name",  // поле для подписи (из columns)
      "aggregation": "sum"                // sum | count | avg | max | min
    }
  ]
}
```

Правило простоты: конфиг не сложнее существующего `chart`-поля в конфиге. AI-инструменты (`ReportTool`) должны уметь генерировать `dashboard_widgets[]` из тех же данных что и `chart`.

**D. Рендер виджетов из данных дашборд-датасета на фронте.**

- Фронт делает отдельный fetch на `GET /api/reports/{id}/dashboard-data` (без пагинации, hard cap 5000 строк). Backend возвращает плоский массив строк; `group_by` в конфиге игнорируется — результат всегда flat. Агрегация виджетов (группировка по `label_field`, применение `aggregation` к `value_field`) выполняется целиком на фронте из этого датасета. Таблица и дашборд-вид используют разные источники данных: таблица — пагинированный `getData()`, дашборд — полный `getDashboardData()`. Backend включает в ответ `meta.dashboard_limited: bool` и `meta.dashboard_total_estimate: int` (если реальный датасет был усечён до 5000).
- Каждый виджет — карточка с заголовком и чартом.
- Drag-grid (перестановка виджетов) — **`grid-layout-plus`** (актуальный Vue 3 форк `vue-grid-layout`). State хранится как массив `{i, x, y, w, h}` per widget. Persist per user × per report в `localStorage` под ключом `vizion-dashboard-layout-{report_id}-{user_id}`.
- Скрыть/показать виджет — multiselect в шапке дашборд-режима (все по дефолту).
- Позиции и видимость виджетов сохраняются в `localStorage` под ключом `vizion-dashboard-layout-{report_id}-{user_id}`.

**E. Backend — поддержка `dashboard_widgets` в конфиге.**

- `src/app/Services/MacroData/ReportDataService.php` — добавить метод `getDashboardData($report, $filters)`: возвращает полный датасет с применёнными фильтрами без пагинации (per_page игнорируется). Фронт строит агрегации виджетов из этих данных. Acceptance: «график "сделки за месяц" показывает все 49 сделок периода, не только видимые 50 строк». Для дашборд-режима backend вызывает `getDashboardData()`, для таблицы — `getData()` с пагинацией (без изменений).
- `src/app/Services/AI/ReportTool.php` — добавить `dashboard_widgets` в список допустимых ключей конфига при `create_report` / `update_report` (чтобы AI мог их генерировать).
- `src/REPORTS_GUIDE.md` — добавить описание `dashboard_widgets` в секцию «Report Config Format».
- Миграция не нужна: `config` уже jsonb, аддитивно.

**F. Совместимость существующих отчётов.**

- Все 6 отчётов в `ReportSeeder.php` — `dashboard_widgets` у них `null`/отсутствует. Это нормально: режим дашборда для таких отчётов просто не показывает виджеты (или показывает placeholder «добавьте виджеты через AI-чат»).
- Существующий `chart`-ключ в конфиге НЕ удаляется и НЕ переименовывается (используется для вкладки «График» в текущем поведении).

---

### 1.4 Затрагиваемые слои

| Слой | Файлы |
|---|---|
| Frontend | `front/src/pages/ReportPage/index.vue`, `front/src/pages/ReportPage/composables/useReportPresentation.ts`, новый composable `useReportDashboard.ts`, новый компонент `ReportDashboardView.vue`, `front/src/entities/report/types.ts` |
| Frontend libs | `grid-layout-plus` — добавить в `front/package.json` |
| Frontend state | `front/src/stores/` — возможно новый `reportViewStore.ts` |
| Backend (dashboard data) | `src/app/Services/MacroData/ReportDataService.php` — новый метод `getDashboardData()` (без пагинации) |
| Backend (AI) | `src/app/Services/AI/ReportTool.php` — разрешить `dashboard_widgets` в конфиге |
| Docs (AI prompt) | `src/REPORTS_GUIDE.md` — секция «dashboard_widgets» |
| Seeders | `src/database/seeders/ReportSeeder.php` — опционально добавить `dashboard_widgets` в системные отчёты |
| DB migration | Не требуется |

---

### 1.5 Агенты и порядок

1. **`frontend-specialist`** — переключатель вид/дашборд, рендер виджетов, localStorage-состояние. Основная работа.
2. **`chat-ai-engineer`** — добавить `dashboard_widgets` в допустимые ключи `ReportTool` + обновить `REPORTS_GUIDE.md`.
3. **`report-author`** (опционально) — добавить `dashboard_widgets` в несколько системных отчётов для демонстрации.
4. **`qa-tester`** — визуальная проверка переключателя, виджетов, сохранения состояния.
5. **`product-manager`** — review + sync docs.

---

### 1.6 Риски и нюансы

- Recharts — React-lib, не совместима с Vue 3 без React runtime wrapper. Не использовать. Решение принято: Chart.js 4 без изменений.
- Drag-grid — используем `grid-layout-plus` (актуальный Vue 3 форк `vue-grid-layout`). Добавить в `package.json`. Проверить совместимость с PrimeVue DataTable scroll и Bootstrap grid на одной странице.
- Данные виджетов — **полный датасет через `getDashboardData()`** (решение принято). Для больших датасетов (>10K строк) может быть медленно — рассмотреть лимит на уровне `getDashboardData()` (например max 5000 строк) или кэш. Не блокирует реализацию п.1, но нужно учесть при проектировании метода.
- `getDashboardData()` — отдельный endpoint `GET /api/reports/{id}/dashboard-data` или флаг в существующем `GET /api/reports/{id}?mode=dashboard`. Рекомендация: отдельный endpoint для явного разделения пагинированного и полного датасета.

---

### 1.7 Открытые вопросы

Все блокирующие вопросы по п.1 закрыты решениями 2026-05-20 (см. раздел «Принятые решения» в начале документа).

---

## Пункт 2. Группировка колонок таблицы и виджетов дашборда

**One-liner:** Добавить collapsible column groups в таблицу и collapsible widget groups в дашборд-вид; кнопка управления группировкой — рядом с фильтрами в шапке отчёта.

---

### 2.1 Как у них (capitaldata.ru)

Источник: `COMPETITIVE_ANALYSIS.md` §2.2 (шапка дашборда), §2.4 (каталог виджетов).

- На дашборде — виджеты сгруппированы тематически. Нет явного UI collapse колонок таблицы — у них таблицы как отдельные «Reports», дашборды — отдельно.
- Кнопки «Reset», «Refresh» рядом с периодом и заголовком дашборда. Каталог виджетов (sidebar-модалка) содержит группы виджетов.
- Скриншоты: capitaldata-01-dashboard.png (layout групп виджетов), capitaldata-03-add-widget.png (каталог).

---

### 2.2 Как у нас сейчас

**Таблица (flat-режим):**
- `front/src/pages/ReportPage/index.vue` — рендерит `<DataTable>` с `<Column v-for="col in tableColumns">`. Нет группировки колонок. PrimeVue DataTable поддерживает `columnGroup` через `<ColumnGroup>` / `<Row>` / `<Column>`.
- `front/src/entities/report/types.ts` — тип `ReportColumn` (поля: `field`, `header`, `type`, `sortable`, `badge`, `truncate`, `visible`, `link_template`, `label_field`).

**Grouped-режим (master/detail):**
- Существующая группировка данных (`group_by` в конфиге) — это группировка строк по значению, не группировка колонок. Другое понятие.
- `front/src/pages/ReportPage/components/ReportGroupHeader.vue` — заголовок группы строк.

**Дашборд-вид:** отсутствует (реализуется в п. 1).

---

### 2.3 Что делаем — Acceptance Criteria

**A. Группировка колонок таблицы.**

1. В `report.config.columns[]` — добавить опциональное поле `column_group` (string, nullable): название группы колонок. Пример: `"column_group": "Финансы"`.
2. Если в конфиге есть хотя бы одна колонка с `column_group` — PrimeVue DataTable рендерит `<ColumnGroup>` с двухуровневым заголовком (верхний ряд — группы с colspan, нижний — имена колонок).
3. Группы collapsible: клик на заголовок группы → колонки группы скрываются (через `v-if` или PrimeVue column visibility API). Состояние collapse per user × per report в `localStorage`.
4. Если `column_group` у всех колонок null — поведение таблицы без изменений (обратная совместимость).
5. Кнопка управления группировкой (toggle all / collapse by group) — в `filter-toggle-wrap` рядом с кнопкой фильтра. Иконка `pi pi-table` или аналог.

**B. Группировка виджетов в дашборд-режиме.**

1. В `report.config.dashboard_widgets[].widget_group` (string, nullable) — группа виджета.
2. Виджеты одной группы отображаются под общим collapsible заголовком.
3. Кнопка «Группировки» в шапке дашборд-режима — показывает/скрывает группы.
4. Состояние — `localStorage`.

**C. Совместимость.**

- Все существующие отчёты без `column_group` → нет двухуровневого заголовка, нет кнопки группировки. Без изменений.

---

### 2.4 Затрагиваемые слои

| Слой | Файлы |
|---|---|
| Frontend | `front/src/pages/ReportPage/index.vue`, `front/src/entities/report/types.ts`, новый composable `useColumnGroups.ts` |
| Backend (AI prompt) | `src/REPORTS_GUIDE.md` — добавить `column_group` в описание колонки |
| Seeders | Не трогать (нет column_group в существующих) |
| DB migration | Не требуется |

---

### 2.5 Агенты и порядок

1. **`frontend-specialist`** — реализация ColumnGroup в DataTable, composable, localStorage-состояние, кнопка в шапке.
2. **`chat-ai-engineer`** — добавить `column_group` и `widget_group` в `REPORTS_GUIDE.md` и допустимые ключи `ReportTool`.
3. **`qa-tester`** — проверить collapsible группы, совместимость с существующими отчётами.

---

### 2.6 Риски и нюансы

- PrimeVue DataTable `<ColumnGroup>` — немного нестандартный API (отдельные теги `<ColumnGroup kind="header">`, `<Row>`, `<Column>`). Совместимость с expandedRows (master/detail grouped-режим) нужно проверить отдельно: не комбинировать column groups с group_by на одном отчёте (или проверить что PrimeVue не ломается).
- Зависит от п. 1 в части группировки виджетов: нельзя реализовать п. 2.B без дашборд-вида из п. 1.

---

### 2.7 Открытые вопросы

✅ **#4 (закрыто 2026-05-21):** Группировка колонок — только в flat-таблицах. Решение: только flat-таблицы. Backend `prevalidateColumnGroups` отклоняет `column_group` + непустой `group_by.fields`. Фронт fail-safes — игнорирует `column_group` при активном `group_by`.

---

## Пункт 3. Мультивалютность + таймзоны на уровне компании

**One-liner:** Сохранять `currency_code` и `timezone` в таблице `companies`; все форматирования сумм и дат в интерфейсе подтягивают их из активной компании.

---

### 3.1 Как у них (capitaldata.ru)

Источник: `COMPETITIVE_ANALYSIS.md` §1 (стек), §3.1 (API Auth/session).

- `tenant.currencyCode` в ответе `GET /api/auth/session` — фронт форматирует все суммы через `Intl.NumberFormat(locale, {style: 'currency', currency: currencyCode})`. (COMPETITIVE_ANALYSIS.md §3.1)
- 7 валют: USD, RUB, EUR, KZT, UZS, GEL, AED.
- `tenant.timezone` — одна таймзона на компанию. 17 вариантов (Москва, Алматы, Дубай, Лондон и т.д.). (COMPETITIVE_ANALYSIS.md §1)
- Фронт форматирует все даты в таймзоне тенанта. (COMPETITIVE_ANALYSIS.md §1)

---

### 3.2 Как у нас сейчас

**Backend:**
- `src/app/Models/Company.php` — поля: `id`, `name`, `is_system`, `macrodata_host/port/database/username/password`, `crm_url`. Нет `currency_code`, нет `timezone`.
- `src/database/migrations/` — последняя миграция companies добавляла `crm_url`.
- `src/app/Http/Controllers/CompanyController.php` — CRUD компаний.
- `src/database/seeders/SystemSeeder.php` — создаёт Vizion company + superadmins. Нет currency/timezone полей.
- `src/database/seeders/CapitalInvestSeeder.php`, `BOMISeeder.php`, `SabaGroupSeeder.php` — клиентские компании, нет currency/timezone.

**Frontend:**
- `front/src/entities/company/types.ts` — тип `Company`. Нет `currency_code`, `timezone`.
- `front/src/composables/useFormatter.ts` — форматирование дат/чисел. Скорее всего хардкод локали без currency.
- `front/src/entities/company/mappers.ts` — маппинг API → entity.

---

### 3.3 Что делаем — Acceptance Criteria

**A. Backend — миграция и модель.**

1. Новая миграция: `ALTER TABLE companies ADD COLUMN currency_code VARCHAR(3) NULL DEFAULT 'RUB'`, `ADD COLUMN timezone VARCHAR(64) NULL DEFAULT 'Europe/Moscow'`.
2. `src/app/Models/Company.php` — добавить `currency_code` и `timezone` в `$fillable`.
3. `src/app/Http/Controllers/CompanyController.php` — добавить `currency_code` и `timezone` в validation rules и fillable для `store()`/`update()`.
4. `GET /api/user` и `GET /api/companies/{id}` — ответ теперь включает `currency_code` и `timezone`.

**B. Seeders — обновить.**

1. `src/database/seeders/SystemSeeder.php` — Vizion company: `currency_code=null`, `timezone=null` (системная компания без данных).
2. `src/database/seeders/CapitalInvestSeeder.php` — добавить `currency_code='KZT'`, `timezone='Asia/Almaty'` (ориентировочно).
3. Аналогично для `BOMISeeder.php` и `SabaGroupSeeder.php`.
4. Все сидеры идемпотентны (`updateOrCreate`) — повторный запуск безопасен.

**C. FRONTEND.md — обновить схему ответа.**

Добавить `currency_code` и `timezone` в описание `Company`-объекта (в `GET /api/user` → `active_company` и в `GET /api/companies/{id}`).

**D. Frontend — форматирование сумм.**

1. `front/src/entities/company/types.ts` — добавить `currency_code: string | null`, `timezone: string | null`.
2. `front/src/composables/useFormatter.ts` — функция `formatCurrency(value, currencyCode?)`: использует `Intl.NumberFormat(locale, {style: 'currency', currency: currencyCode ?? 'RUB', maximumFractionDigits: 0})`. Берёт `currencyCode` из `companiesStore.currentCompany.currency_code`.
3. Все места в компонентах где рендерятся `currency`-колонки — заменить хардкод на `formatCurrency(value)`. Основное место: `front/src/pages/ReportPage/index.vue` (рендер ячеек таблицы по типу `currency`).
4. Обратная совместимость: если `currency_code` null — fallback на текущее поведение (₽ или без символа).

**E. Frontend — форматирование дат.**

1. `front/src/composables/useFormatter.ts` — функция `formatDate(value, timezone?)`: использует `Intl.DateTimeFormat(locale, {timeZone: timezone ?? 'UTC', ...})`. Берёт `timezone` из `companiesStore.currentCompany.timezone`.
2. Все колонки типа `date` и `datetime` — использовать `formatDate(value)`.
3. Fallback: если `timezone` null — UTC.

---

### 3.4 Затрагиваемые слои

| Слой | Файлы |
|---|---|
| DB migration | Новая миграция в `src/database/migrations/` |
| Backend model | `src/app/Models/Company.php` |
| Backend controller | `src/app/Http/Controllers/CompanyController.php` |
| Seeders | `src/database/seeders/SystemSeeder.php`, `CapitalInvestSeeder.php`, `BOMISeeder.php`, `SabaGroupSeeder.php` |
| Frontend entity | `front/src/entities/company/types.ts`, `front/src/entities/company/mappers.ts` |
| Frontend composable | `front/src/composables/useFormatter.ts` |
| Frontend page | `front/src/pages/ReportPage/index.vue` (рендер currency-ячеек) |
| Docs | `FRONTEND.md` — схема Company |

---

### 3.5 Агенты и порядок

1. **`backend-specialist`** — миграция + модель + контроллер + сидеры.
2. **`frontend-specialist`** — types, mappers, useFormatter, обновить рендер ячеек.
3. **`qa-tester`** — проверить форматирование сумм, переключение компании меняет валюту.
4. **`product-manager`** — sync FRONTEND.md.

---

### 3.6 Риски и нюансы

- `Intl.NumberFormat` с `style: 'currency'` добавляет символ валюты (₸, $, €). Проверить что это не ломает ширину колонок в таблице. Возможно нужен `currencyDisplay: 'narrowSymbol'` для компактности.
- Таймзонная конвертация дат: MacroData возвращает даты как `YYYY-MM-DDTHH:MM:SS.000000Z` (UTC). `Intl.DateTimeFormat` с `timeZone` корректно конвертирует из UTC в локальное время компании. Проверить что существующие отчёты (Финансы с `date_to`, Реестр договоров с `deal_date`) не меняют смысл (сдвиг дат на 3-5 часов при московском времени vs UTC).
- `currency_code` и `timezone` на уровне компании, не пользователя — один пользователь видит данные всегда в валюте/таймзоне своей активной компании. При переключении компании → пересчитать форматирование (следить за реактивностью через `companiesStore.currentCompany`).

---

### 3.7 Открытые вопросы

✅ **#5 (закрыто):** CapitalInvest + SabaGroup → KZT / Asia/Almaty; BOMI → UZS / Asia/Tashkent; **Buildera → AED / Asia/Dubai** (по crm_url=macrosales.ae). Указаны явно в сидерах.

---

## Пункт 4. `<meta name="robots" content="noindex">`

**One-liner:** Закрыть Vizion от поисковой индексации; одна строка в `front/index.html`.

---

### 4.1 Как у них (capitaldata.ru)

Источник: `COMPETITIVE_ANALYSIS.md` §1.

```html
<meta name="robots" content="noindex"/>
```

Конкурент его имеет. Мы — закрытый корпоративный инструмент, индексация нежелательна.

---

### 4.2 Как у нас сейчас

`front/index.html` — нет `<meta name="robots">`. Есть `<meta charset>`, `<meta viewport>`, `<meta http-equiv="Content-Security-Policy">`, `<title>Vizion</title>`.

---

### 4.3 Что делаем — Acceptance Criteria

1. Добавить в `front/index.html` после `<meta name="viewport">`:
   ```html
   <meta name="robots" content="noindex, nofollow" />
   ```
   (`nofollow` — дополнительно блокируем переход по ссылкам со страниц, если кто-то вдруг попадёт на них).
2. Проверить что сборка `npm run build` проходит без предупреждений.
3. Проверить браузером (F12 → Elements): meta-тег присутствует в DOM.

---

### 4.4 Затрагиваемые слои

| Слой | Файлы |
|---|---|
| Frontend | `front/index.html` |

---

### 4.5 Агенты и порядок

1. **`frontend-specialist`** — добавить тег. Буквально 1 строка.
2. **`qa-tester`** — не нужен (нет UI-изменений).
3. **`product-manager`** — review (trivial, но через workflow).

---

### 4.6 Риски и нюансы

- Нет рисков. Мета-тег не влияет на поведение приложения.
- Если в будущем появятся публичные landing-страницы в этом же SPA — потребуется per-route meta через `vue-meta` или `useHead`. Сейчас не актуально.

---

### 4.7 Открытые вопросы

Нет.

**Статус:** ✅ Готово 2026-05-20 — `front/index.html` обновлён, `frontend-changes-08-05.md` логирован.

---

## Пункт 5. Tooltip-подсказки «?» к заголовкам столбцов

**One-liner:** Добавить опциональное поле `description` в конфиг каждой колонки; иконка «?» у заголовка при наведении показывает пояснение на человеческом языке; AI-конструктор генерирует `description` вместе с колонкой.

---

### 5.1 Как у них (capitaldata.ru)

Конкурент не демонстрирует эту фичу явно, но паттерн «iконка ❓ у заголовка» — стандартный UX для аналитических инструментов. В реконне зафиксированы виджет-карточки с `subtitle/help` под заголовком (`~12-month window through the selected month` в capitaldata-06-widget-card.png) — это аналог column description на уровне виджета.

---

### 5.2 Как у нас сейчас

**Backend:**
- `report.config.columns[]` — текущие поля: `field`, `header` (jsonb), `type`, `sortable`, `visible`, `badge`, `truncate`, `link_template`, `label_field`. Нет `description`.
- `src/app/Services/MacroData/ReportDataService.php` — `buildColumns()` формирует массив колонок из конфига. `description` не нужно обрабатывать — это pure UI-поле.
- `src/REPORTS_GUIDE.md` — описывает формат `columns[]`. Нет упоминания description.

**Frontend:**
- `front/src/entities/report/types.ts` — тип `ReportColumn`. Нет `description`.
- `front/src/pages/ReportPage/index.vue` — рендер `<Column :header="col.header">` — текстовый заголовок. Нет места для иконки.
- `front/src/pages/ReportPage/index.vue` строки ~164 — рендер дочерних колонок grouped-режима аналогично.

---

### 5.3 Что делаем — Acceptance Criteria

**A. Backend — расширение схемы конфига (no migration needed).**

- `report.config.columns[].description` — jsonb `{ru: "...", en: "..."}` или просто строка. Nullable. Backend не валидирует содержимое (jsonb проглотит). `ReportDataService` прокидывает `description` в ответ API (в массив `columns`).
- Проверить что `buildColumns()` в `ReportDataService.php` включает `description` в возвращаемый объект колонки (или убедиться что он уже возвращает все ключи конфига аддитивно).

**B. Frontend — тип и рендер.**

1. `front/src/entities/report/types.ts` — добавить в `ReportColumn`: `description?: LocalizedString | string | null`.
2. `front/src/pages/ReportPage/index.vue` — в slot `#header` для каждой `<Column>` рендерить:
   ```
   [Заголовок колонки] [? иконка]   ← иконка только если description != null
   ```
   При наведении на иконку — PrimeVue `<Tooltip>` с текстом из `description` (локализованный через `getLocalizedValue()`).
3. Иконка — `pi pi-question-circle` (PrimeIcons, уже есть).
4. В grouped-режиме (дочерние колонки) — аналогично.

**C. AI-конструктор — генерация description.**

1. `src/app/Services/AI/ReportTool.php` — `description` уже допускается в конфиге (jsonb проходит через). Явно документировать в `REPORTS_GUIDE.md`.
2. `src/REPORTS_GUIDE.md` — в секцию «Columns Format» добавить:
   ```
   - description (optional, jsonb {"ru": "...", "en": "..."} or string):
     Human-readable explanation of how the column is calculated.
     Example: {"ru": "Сумма всех проведённых платежей по договору", "en": "Sum of all processed payments for the deal"}
     Always generate this field for financial and calculated columns.
   ```
   Ключевое: «Always generate» — AI по умолчанию добавляет description к расчётным колонкам.

**D. Существующие системные отчёты.**

- Добавить `description` в `ReportSeeder.php` для ключевых колонок (опционально, по решению пользователя). Не обязательно для п. 5 — feature работает и без этого (просто у старых отчётов иконки не будет).

---

### 5.4 Затрагиваемые слои

| Слой | Файлы |
|---|---|
| Backend | `src/app/Services/MacroData/ReportDataService.php` — убедиться что `description` пробрасывается в `columns` ответа |
| AI prompt | `src/REPORTS_GUIDE.md` — добавить `description` в формат колонки |
| AI tool | `src/app/Services/AI/ReportTool.php` — допустить `description` в схеме |
| Frontend entity | `front/src/entities/report/types.ts` |
| Frontend page | `front/src/pages/ReportPage/index.vue` (header slot для Column) |
| Seeders (опц.) | `src/database/seeders/ReportSeeder.php` — обогатить колонки описаниями |
| DB migration | Не требуется |

---

### 5.5 Агенты и порядок

1. **`macrodata-engineer`** — проверить что `ReportDataService::buildColumns()` не отбрасывает неизвестные ключи конфига (или добавить `description` в whitelist).
2. **`chat-ai-engineer`** — обновить `REPORTS_GUIDE.md` (секция Columns + пример) + `ReportTool.php` (допустить ключ).
3. **`frontend-specialist`** — типы + рендер иконки + Tooltip в заголовке.
4. **`report-author`** (опционально) — добавить `description` в несколько колонок существующих отчётов.
5. **`qa-tester`** — проверить Tooltip, i18n (RU/EN переключение), совместимость без description.

---

### 5.6 Риски и нюансы

- `ReportDataService::buildColumns()` скорее всего формирует колонки из конфига как-есть (читает нужные ключи). Если там whitelist — нужно добавить `description`. Если passthrough (все ключи конфига идут в ответ) — ничего делать не надо на backend.
- Tooltip на заголовке таблицы может конфликтовать с сортировкой (клик по заголовку → сортировка vs hover → tooltip). Это нормально — tooltip triggered by hover, sort triggered by click. Но нужно проверить что иконка `pi-question-circle` при клике не триггерит sort (надо `@click.stop`).
- `description` — jsonb `{ru, en}` или просто строка? Рекомендация: jsonb (соответствует `header` и другим i18n-полям конфига). Если строка — она отобразится как есть без i18n.
- Для AI-генерации: jsonb с двумя ключами усложняет промпт. Можно разрешить и строку (AI генерирует на языке системного промпта = RU), и объект (при явном запросе i18n).

---

### 5.7 Открытые вопросы

✅ **#6 (закрыто 2026-05-21):** `description` добавлен к 23 колонкам 6 системных отчётов в `ReportSeeder.php` в рамках этой задачи (report-author). Покрыты финансовые суммы, расчётные expressions, статусы, cumulative-агрегаты, payment_schedule и неочевидные даты.

---

## Пункт 6. Мини-чат-виджет в тулбаре

**One-liner:** Иконка чата в Toolbox открывает overlay-виджет с чатом; на странице отчёта — автоинжект полного конфига отчёта в первое сообщение; expand → полноэкранный чат в новой вкладке.

---

### 6.1 Как у них (capitaldata.ru)

Источник: `COMPETITIVE_ANALYSIS.md` §4.5, §4.7, §2.2.

- «AI assistant» кнопка в шапке каждого дашборда. Передаёт контекст текущего дашборда. (capitaldata-02-dashboard.png, строка шапки)
- «Explain chart in AI assistant» на каждом виджете — кнопка бросает в чат сообщение с данными конкретного виджета. (capitaldata-06-widget-card.png)
- На страницах Reports — кнопка `AI assistant` в правом углу. (COMPETITIVE_ANALYSIS.md §4.7)

**Их подход:** раздроблен — кнопка в шапке + кнопка на каждом виджете. Наш — менее раздроблен: одна точка входа в Toolbox + автоконтекст по странице.

---

### 6.2 Как у нас сейчас

**Toolbox:**
- `front/src/components/Toolbox/Toolbox.vue` — Toolbox-компонент, содержит: `ToolboxToggle`, `ToolboxPanel`. В `ToolboxPanel` — nav-items (навигация) + slot `#actions` (CompanySwitcher + ProfileMenu).
- `front/src/components/Toolbox/types.ts` — тип `ToolboxNavItem`.
- `front/src/components/Toolbox/composables/useToolboxOverlays.ts` — управление overlay-панелями (компания, профиль).
- Toolbox живёт в `DefaultLayout` (`front/src/layouts/DefaultLayout/index.vue`).

**Чат:**
- `front/src/pages/AiReportsPage/index.vue` — страница AI-конструктора (полноэкранный чат).
- `front/src/pages/AiChatPage/index.vue` — страница quick_qa чата.
- `front/src/pages/shared/useChatPage.ts` — общий composable для чат-страниц. Содержит `initScope`, router-state handoff (`activateChatId`), `pendingFirstMessage`.
- `front/src/components/chat/ChatPageShell.vue` — оболочка чат-страницы (sidebar список + основная часть с сообщениями).
- `front/src/components/chat/composables/useChat.ts` — основная логика чата.
- CLAUDE.md §«Cross-page chat activation (router-state handoff)»: `router.push({ name: 'AiReports', state: { activateChatId: chatId } })` — уже реализован паттерн.

**Текущие страницы отчёта:**
- `front/src/pages/ReportPage/index.vue` — есть `report` объект (включая `report.config`). Нет интеграции с чатом.

---

### 6.3 Что делаем — Acceptance Criteria

**A. Иконка чата в Toolbox.**

1. Добавить nav-item «Чат» в Toolbox (иконка `pi pi-comments` или `pi pi-chat`) или добавить в `#actions` slot рядом с ProfileMenu.
2. Клик → открывается overlay/popover над Toolbox (аналогично CompanySwitcher и ProfileMenu) с мини-чат-виджетом.
3. Мини-виджет — компонент `MiniChatWidget.vue` в `front/src/components/chat/`.
4. Видимость по ролям: `viewer` не видит (`shared/auth/capabilities.ts`).

**B. Мини-чат-виджет (MiniChatWidget.vue).**

1. Структура: `[Header: "AI Чат" + кнопка расширить ↗ + кнопка закрыть ✕]` / `[ChatMessageList (последние N сообщений)]` / `[ChatInput]`.
2. Использует существующие компоненты `ChatMessageBubble.vue`, `ChatInput.vue` в mini-режиме.
3. Размер: ~400×500px, overflow-y: auto для сообщений.
4. При открытии виджета — логика определения чата:
   - Если текущая страница — страница отчёта (`/reports/{id}`) и page-context (`report_id`) совпадает с `report_id` последнего активного чата пользователя → продолжаем этот чат.
   - Если page-context изменился (пользователь перешёл на другой отчёт) → создать новый чат с новым контекстом (`report_generation` type).
   - Если текущая страница — не страница отчёта → продолжаем последний активный чат пользователя (context-free, `quick_qa` type).
   - «Последний активный» = первый из `GET /api/chats` (сортировка по `updated_at` desc).
5. **Expand:** кнопка `↗` в заголовке виджета открывает `window.open('/ai-reports?chat={id}', '_blank')` или аналогичный URL. Передать chat ID через query-param или через `history.state` не работает для `_blank` (разные контексты). Вариант: URL вида `/ai-reports/{chat_id}` (если роутер поддерживает) или `localStorage`-флаг с активным chat ID.

**C. Автоинжект report-контекста (на странице отчёта).**

1. При открытии мини-чата с `/reports/{id}` — в первое сообщение чата инжектируется system-prefix:
   ```
   [Контекст отчёта: {report.title[locale]}]
   Конфиг: {JSON.stringify(report.config, null, 0)}
   Применённые фильтры: {JSON.stringify(report.filters_applied)}
   ---
   {сообщение пользователя}
   ```
2. Реализация: `MiniChatWidget.vue` принимает prop `reportContext?: { reportId, title, config, filters_applied }`. При первом сообщении — если `reportContext` задан, конкатенировать к содержимому сообщения перед отправкой.
3. `front/src/pages/ReportPage/index.vue` — передавать `reportContext` в Toolbox/MiniChatWidget через provide/inject или через пропс layout-слоя. **❓ Открытый вопрос #7:** лучше provide/inject (ReportPage → DefaultLayout → Toolbox → MiniChatWidget) или Pinia store `reportContextStore`?
4. С остальных страниц — `reportContext` null → чистый чат без инжекта.
5. **НЕ трогать** `ChatService.php`, `Prism`, `ProcessChatMessageJob` — только UI + структура первого сообщения.

**D. Expand в полноэкранный чат.**

1. Кнопка `↗` (иконка `pi pi-external-link`) — открывает `/ai-reports` в новой вкладке.
2. Передать `activateChatId` через `sessionStorage` (не `localStorage` — чтобы не засорять другие вкладки) под ключом `vizion-mini-chat-activate-{timestamp}`. На AiReportsPage — прочитать и применить (one-shot, удалить после).
3. Альтернатива: включить chat ID в URL `/ai-reports?activate={chatId}` — проще и без sessionStorage. AiReportsPage читает query-param при монтировании. **Рекомендация: URL query-param — чище.**

---

### 6.4 Затрагиваемые слои

| Слой | Файлы |
|---|---|
| Frontend — Toolbox | `front/src/components/Toolbox/Toolbox.vue`, `front/src/components/Toolbox/ToolboxPanel.vue`, `front/src/components/Toolbox/composables/useToolboxOverlays.ts`, `front/src/components/Toolbox/types.ts` |
| Frontend — новый компонент | `front/src/components/chat/MiniChatWidget.vue` |
| Frontend — ReportPage | `front/src/pages/ReportPage/index.vue` — provide reportContext |
| Frontend — AiReportsPage | `front/src/pages/AiReportsPage/index.vue` — читать query-param `?activate=` |
| Frontend — RBAC | `front/src/shared/auth/capabilities.ts` — добавить capability для мини-чата |
| Frontend i18n | Новые ключи в локалях Toolbox и MiniChatWidget |
| Backend | Не затрагивается (только UI) |
| DB migration | Не требуется |

---

### 6.5 Агенты и порядок

1. **`frontend-specialist`** — MiniChatWidget + интеграция в Toolbox + report-context provide/inject + expand via URL query-param.
2. **`qa-tester`** — проверить открытие виджета, отправку сообщения, инжект контекста (на странице отчёта и без), expand.
3. **`product-manager`** — review, sync `chats_frontend.md` если изменился интерфейс.

---

### 6.6 Риски и нюансы

- Открытый Toolbox overlay + другой overlay (компания/профиль) — нужно закрывать при открытии мини-чата и наоборот. `useToolboxOverlays.ts` уже умеет это (один открытый overlay).
- Report config в первом сообщении может быть большим (несколько KB). Проверить что GLM-5-turbo / Anthropic принимают длинные user-messages без truncation. Если config > ~2KB — передавать только ключевые поля: `primary_model`, `columns[].field`, `filters_applied`. Не весь конфиг.
- `viewer` не имеет доступа к чату. Иконку в Toolbox показывать только `analyst`, `admin`, `superadmin` — через `capabilities.ts`.
- При мини-чате: SSE-стрим (`ChatStreamController`) работает через `EventSource`. MiniChatWidget должен использовать тот же `useChatStream.ts` composable что и полноэкранный чат. Не дублировать логику.
- Паттерн router-state handoff через `history.state` (описан в CLAUDE.md) не работает при `window.open(_blank)` — разные контексты истории. Использовать URL query-param `?activate={chatId}` как рекомендовано.

---

### 6.7 Открытые вопросы

✅ **#7 (закрыто 2026-05-21):** Pinia store `useReportContextStore` (`front/src/stores/reportContext.ts`). ReportPage пишет snapshot через `set()` при изменении report/фильтров/locale (watchEffect с deep:true, immediate:true). MiniChatWidget читает реактивно без prop-drilling через DefaultLayout.

---

### 6.8 Заметки по реализации (постфактум 2026-05-21)

**vue-router history.state clobber.** Паттерн router-state handoff (`history.state.activateChatId`) несовместим с `window.open(_blank)` — у новой вкладки свой history stack. Expand-кнопка в MiniChatWidget открывает `/ai-reports?activate={chatId}` через URL query-param. AiReportsPage читает `?activate=` при монтировании (one-shot, параметр убирается через `router.replace` без перехода). Этот вариант конкурирует с описанным в CLAUDE.md `history.state` handoff — оба паттерна существуют, применяются в разных сценариях: `history.state` — для навигации в той же вкладке, `?activate=` — для cross-tab.

**Expand flow (финальный).** MiniChatWidget: кнопка `↗` вызывает `window.open('/ai-reports?activate=' + chatId, '_blank')`. AiReportsPage в `onMounted` → `initScope()` читает `route.query.activate`, выполняет `loadChat(id)`, затем `router.replace({ query: {} })` чтобы убрать параметр из адресной строки. Если активного чата нет (404 или пустой список) — создаёт новый чат type=report_generation.

---

## Бэклог — Backend sync user_report_preferences (вне исходного scope)

**Статус: ✅ Готово (2026-05-21)**

Таблица `user_report_preferences` (`user_id`, `report_id`, `view_mode`, `dashboard_layout`, `hidden_column_groups`, `hidden_widget_groups`) + `GET /api/reports/{id}/preferences` и `PUT /api/reports/{id}/preferences` endpoints (partial upsert: явный `null` сбрасывает поле, отсутствующий ключ не трогает). Фронт: singleton `useReportPreferences` + 4 thin wrappers (`useReportViewMode`, `useDashboardLayout`, `useColumnGroups`, `useWidgetGroups`); debounce 600ms; localStorage как кэш для первого paint (перезаписывается после GET-ответа сервера, optimistic update при PUT). Заменяет 4 изолированных localStorage-ключа — настройки переезжают с устройства на устройство.

---

## Бэклог (не делаем сейчас)

| Пункт | Источник | Причина откладывания |
|---|---|---|
| 4 локали (uz, ka) | COMPETITIVE_ANALYSIS.md §1 | Рынок uz/ka не приоритет сейчас |
| AI usage units / квоты | COMPETITIVE_ANALYSIS.md §4.1 | Монетизация не нужна (корпоративный продукт) |
| AI narrative (текстовый summary отчёта) | COMPETITIVE_ANALYSIS.md §4.4 | Требует отдельного AI-турна, сложность оправдана позже |
| Anomaly detection в payload | COMPETITIVE_ANALYSIS.md §3.4 | Требует z-score алгоритм в ReportDataService |
| Glossary per tenant | COMPETITIVE_ANALYSIS.md §4.3 | Сложно, но полезно позже (таблица + инжект в system prompt) |
| Шаблоны при создании дашборда | COMPETITIVE_ANALYSIS.md §2.6 | Зависит от п.1 (Dashboard как сущность) |
| «Updated N min ago» freshness label | COMPETITIVE_ANALYSIS.md §2.2 | Тривиально: `Cache::tags(['report'])->get()->timestamp`. В бэклог т.к. кеш отчётов не реализован. |
| Keyboard shortcuts Alt+1/Alt+2 | COMPETITIVE_ANALYSIS.md §2.2 | Низкий приоритет, PrimeVue Calendar поддерживает |
| Master-detail UX (Reconciliation pattern) | COMPETITIVE_ANALYSIS.md §3.5 | «Выбери X → выбери Y → таблица» — отдельный report template |
| Excel-export per table | COMPETITIVE_ANALYSIS.md §5 | Нет сейчас, в бэклог. Если нужен — `maatwebsite/excel` или `phpspreadsheet` на backend |
| Saved prompts (starred chat messages) | COMPETITIVE_ANALYSIS.md §4.2 | `chat_saved_prompts(company_id, label, full_prompt)` — дешёво реализовать, но не горит |
| Auto-compare prev period в payload | COMPETITIVE_ANALYSIS.md §3.4 | Дополнительный запрос в `ReportDataService`, полезно для KPI-дашбордов |
| `capabilities` в payload getData | COMPETITIVE_ANALYSIS.md §3.4 | `{canExportXlsx, canAiExplain}` per role — дешёво, но нужна планировка фич |
| Impersonation для superadmin | COMPETITIVE_ANALYSIS.md §3.1 | «Войти от имени user X» — полезно для саппорта, но сложность среднее |
| «Copy report link» button | COMPETITIVE_ANALYSIS.md §5 | `navigator.clipboard.writeText(location.href)` — тривиально, но нет срочности |

---

## Открытые вопросы (сводный список)

Вопросы #1, #2, #3, #5, #8 закрыты решениями 2026-05-20 (см. раздел «Принятые решения»).

| # | Пункт | Вопрос | Блокирует |
|---|---|---|---|
| ~~4~~ | ~~п.2~~ | ~~Группировка колонок нужна и в grouped-отчётах (master/detail) или только в flat?~~ | ~~Закрыто 2026-05-21: только flat-таблицы. `prevalidateColumnGroups` отклоняет column_group + непустой group_by.fields; фронт игнорирует column_group при активном group_by~~ |
| ~~5~~ | ~~п.3~~ | ~~Дефолтные `currency_code` и `timezone` для CapitalInvest, BOMI, SabaGroup?~~ | ~~Закрыто 2026-05-20: CapitalInvest+SabaGroup→KZT/Asia/Almaty; BOMI→UZS/Asia/Tashkent; Buildera→AED/Asia/Dubai~~ |
| ~~6~~ | ~~п.5~~ | ~~Добавить `description` к колонкам существующих отчётов в ReportSeeder сразу или отдельной итерацией?~~ | ~~Закрыто 2026-05-21: добавлено сразу — 23 колонки в 6 системных отчётах через report-author~~ |
| ~~7~~ | ~~п.6~~ | ~~reportContext: provide/inject или Pinia store?~~ | ~~Закрыто 2026-05-21: Pinia store `useReportContextStore` (`front/src/stores/reportContext.ts`). ReportPage пишет snapshot через `set()`, MiniChatWidget читает реактивно.~~ |

---

*Документ создан 2026-05-20. Актуализировать статус roadmap-чеклиста после завершения каждого пункта.*
