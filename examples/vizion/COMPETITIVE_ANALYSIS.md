# Конкурентный разбор — capitaldata.ru (MacroData — Assistant)

> Дата сбора: 2026-05-20. Метод: ручной обход через Playwright под предоставленным аккаунтом (роль `owner` тенанта `macrodata-local-tenant`, план `premium`). Чат не тестировался по запросу (только статическая инспекция CTA). Скриншоты — `capitaldata-01..14*.png` в корне проекта.

Конкурент — внутренний продукт той же группы MACRO. Брендится как **MacroData — Assistant**, tagline в `<meta description>`: «AI assistant for developer CRM data. Ask in natural language». Архитектурно сильнее по дашбордам и UX-обвесу вокруг AI; таблицы и кастомизация отчётов — слабые (нет нашего отчёт-конструктора, нет генерации отчётов из CRUD).

---

## 1. Стек и инфраструктура

| Слой | У них | У нас (Vizion) |
|---|---|---|
| Framework | **Next.js App Router** (13+, RSC-heavy, нет `__NEXT_DATA__` гидрации) | Laravel 12 + Vue 3 SPA |
| Стили | **Tailwind CSS** (чистые утилиты, `antialiased min-h-full flex flex-col font-sans`) | Bootstrap 5 + scoped SCSS |
| UI-kit | **Нет** — ни Radix, ни HeadlessUI, ни shadcn-маркеров; всё на голом Tailwind | PrimeVue 4.5 |
| Чарты | **Recharts** (16+ инстансов на одной странице, классы `recharts-responsive-container`, `recharts-tooltip-wrapper-right`, `recharts-legend-item`) | Chart.js 4 |
| Шрифт | System stack `system-ui, -apple-system, "Segoe UI", Roboto…` — **без кастомного веб-шрифта** | то же (стандартный шрифт у нас тоже системный) |
| Анимация | Нет Framer Motion / motion API | — |
| Иконки | Inline SVG `<svg class="w-4 h-4">…<path/>` (вероятно lucide-react / heroicons — выводы по форме path) | PrimeIcons |
| Auth | **Собственный** `POST /api/auth/login` + cookie-session, `GET /api/auth/session`. Названия как у NextAuth, но это самопис | Sanctum Bearer + iframe_token |
| Бэкенд | Не виден (Next API routes; backend под капотом, возможно Node + БД). Доменная логика — серверные SQL-агрегации | Laravel 12 + Eloquent + read-only MacroData replica |
| Мультитенант | Полноценный SaaS: `saas:true`, `tenant.{id,name,slug,plan,status,timezone,defaultLocale,currencyCode}` в сессии. Импersonation встроенa (`impersonating`, `impersonationEnabled` флаги) | Multi-company через `users.active_company_id` + middleware; impersonation отсутствует |
| Локализация | **4 языка** в UI: `ru`, `en`, `uz` (Oʻzbekcha), `ka` (ქართული). Поле `defaultLocale` в tenant | RU + EN |
| Валюта | 7 валют (USD/RUB/EUR/KZT/UZS/GEL/AED), `currencyCode` в тенанте | — (KZT хардкод предположительно) |
| Таймзоны | 17 пунктов: Москва, Самара, Екб, Новосибирск, Тбилиси, Ташкент, Алматы, Актау, Дубай, Подгорица, Берлин, Париж, Рим, Мадрид, Варшава, Прага, Лондон | UTC по дефолту |
| localStorage | `macrodata-dashboards-v2`, `macrodata-dashboard-period`, `macrodata-custom-widgets`, `macrodata-ui-locale` (версионирование схемы — `v2`!) | — |
| Robots | `<meta name="robots" content="noindex"/>` — закрыто от индексации | — |

**Вывод по стеку:** их фронт легче нашего по объёму зависимостей (нет UI-kit, нет шрифтов, нет анимаций). Это — целенаправленная инженерная экономия, особенно ценная под платный CDN. Recharts вместо Chart.js даёт им декларативный SVG-рендер (наш Chart.js рисует в canvas — труднее экспортить как картинку, труднее печатать).

---

## 2. Архитектура дашбордов

### 2.1 Шапка / навигация

Левый сайдбар, три раздела:

```
DASHBOARDS
  • Overview      — 7 widgets
  • Sales         — 4 widgets
  • Marketing     — 4 widgets
  • + New dashboard

REPORTS
  • Sales funnel
  • Numbers & comparison
  • Manager ranking
  • Due payments
  • Receivables
  • Properties for sale
  • Signed contracts
  • Reconciliation

ACCOUNT
  • Profile
```

В подвале сайдбара — language switcher и Log out.

**Различие dashboards vs reports:** dashboard = composable grid из виджетов, можно создавать пользовательские. Report = заранее свёрстанная страница со своей семантикой (фильтры, таблицы, drill-down). Это очень полезная развилка — мы в Vizion смешиваем оба типа в одной сущности `Report`, и от этого болит конструктор. Стоит подумать о разделении.

### 2.2 Шапка контента дашборда

```
[Overview ▼ ↘ Updated 1 min ago]    [< Apr 2026 >] [Reset] [Refresh] [⚡AI assistant] [+ Widget]
```

- **Period picker** — помесячно, с keyboard shortcut **Alt+1** (Previous month). На некоторых страницах — диапазон через preset (last 7 days / this month).
- **"Updated 1 min ago"** — freshness-label рядом с заголовком (без принудительного автообновления; вручную через Refresh).
- **AI assistant** — кнопка-якорь к чату, передаёт контекст текущего дашборда.
- **Widget** — открывает sidebar-modalку с каталогом (см. 2.4).

### 2.3 KPI strip (Key metrics)

Шесть тайлов в строчку:

| KPI | Drill-down |
|---|---|
| Deals in period | `/dashboard/detail?metric=deals` |
| Revenue, ₸ | `/dashboard/detail?metric=revenue` |
| New leads | `/dashboard/detail?metric=leads` |
| Conversion, % | `/dashboard/detail?metric=conversion` |
| Due, ₸ | `/dashboard/detail?metric=debt` |
| For sale | `/dashboard/detail?metric=properties` |

Каждый тайл — ссылка на детальную страницу с тем же периодом. Очень логичный паттерн — KPI как navigable preview агрегата.

### 2.4 Каталог виджетов

Модалка с двумя табами:

**Standard** (~15 типов виджетов суммарно, видимые свободные слоты):
- Average check by month
- Deals by day / Revenue by day / Average check by day / Leads by day
- Payments by building (по объектам/корпусам)
- Deals by status (распределение по статусам сделки)
- Payments in period (overdue / on time)
- Sales funnel (мини-воронка как виджет)

Уже добавленные на дашбордах:
- Deals by month / Revenue by month / Plan vs actual / Managers / Leads by channel / Conversion by channel

**AI chart** (отдельный таб): одно поле ввода — `Describe the chart (e.g. deals by manager for February)…`. Это NL → chart pipeline, скорее всего ходит в тот же AI-движок что и чат, но без тулинга — единичная генерация виджета по описанию.

### 2.5 Виджет-карточка (UI элемент)

Каждая карточка:
```
[Title]                    [✨ Explain chart in AI assistant] [✕ Remove widget]
[Subtitle/help, e.g. "~12-month window through the selected month"]
[Chart body (Recharts SVG)]
[Legend (если bar/line с несколькими series)]
```

**"Explain chart in AI assistant"** на каждом виджете — потрясающая фича. Кнопка передаёт контекст конкретного виджета (тип, период, данные) в чат-промпт. Это buy-in для AI: пользователь видит график, не понимает аномалию, кликает — и чат отвечает по этим конкретным данным.

### 2.6 Создание нового дашборда

Модалка `New dashboard`:
- Поле имени (placeholder `e.g. Sales Q1 2026`)
- Выбор шаблона: **Empty / Sales (4 widgets) / Leads & marketing (5 widgets) / Finance (4 widgets) / Full overview (10 widgets)**
- Кнопки Create / Cancel

То есть кастомные дашборды строятся не пустыми — пользователю сразу даётся pre-seed по типовому сценарию.

---

## 3. API контракты

### 3.1 Auth

```http
POST /api/auth/login            { username, password, remember? }
GET  /api/auth/session          → 200 / 401
POST /api/tenant/activity       (beacon, после login)
```

Сессия:
```json
{
  "ok": true, "authenticated": true, "saas": true,
  "user": { "id": 2, "email": "Md7xK_p2L9nQw4z", "role": "owner" },
  "tenant": {
    "id": 1, "name": "MacroData Local Tenant", "slug": "macrodata-local-tenant",
    "plan": "premium", "status": "active",
    "timezone": "Asia/Almaty",
    "defaultLocale": "ru", "currencyCode": "KZT"
  },
  "plan": "premium", "effectivePlan": "premium",
  "impersonating": false, "impersonationEnabled": false
}
```

**Полезное для нас:**
- `effectivePlan` отдельно от `plan` — то есть план может быть переопределён (например, trial-extension, manual override staff'ом). Готовая ручка для биллинг-overrides.
- `impersonationEnabled` — флаг приходит в сессии: фронт сам решает показывать ли "log in as user" в админке.
- `currencyCode` на тенанте — фронт форматирует все суммы под валюту компании (₸, $, €). У нас всё хардкод.

### 3.2 Dashboard data — главный endpoint

**Один URL для всех виджетов**, передаём CSV-список widget IDs:

```http
GET /api/dashboard/data?period=2026-04&widgets=summary,deals_by_month,deals_amount_by_month,plan_vs_fact,managers_performance,leads_by_channel,conversion_by_channel
```

Period форматы:
- `YYYY-MM` — один календарный месяц
- `YYYY-MM-DD_YYYY-MM-DD` — произвольный диапазон (используется для 12-month windows)

Ответ — плоский объект, key per widget id:
```json
{
  "summary": [{"total_deals":49,"completed_deals":49,"total_deal_amount":1371228680,"total_leads":5578,"leads_with_deal":30,"total_debt":0,"overdue_debt":0,"active_properties":1163}],
  "deals_by_month": [{"month":"2026-04","cnt":49}],
  "managers_performance": [{"manager_id":78604,"manager":"Гончар Максим","deals_count":12,"deals_amount":"310166700.00","contract_amount":"339000100.00"}, ...],
  "leads_by_channel": [{"channel":"capital-city.kz","cnt":483}, ...],
  "conversion_by_channel": [{"channel":"capital-city.kz","leads":483,"deals":24,"conversion":5.0}, ...]
}
```

**Известные widget IDs** (с этого тенанта):
| ID | Что |
|---|---|
| `summary` | KPI strip |
| `deals_by_month`, `deals_amount_by_month` | Bar / line по месяцам |
| `deals_by_day`, `deals_amount_by_day`, `avg_check_by_day`, `leads_by_day` | Day-level series |
| `avg_check_by_month` | Avg check |
| `plan_vs_fact` | План/факт |
| `managers_performance` | Топ менеджеров (по сделкам + сумме) |
| `leads_by_channel`, `conversion_by_channel` | Каналы |
| `leads_funnel` | Воронка как виджет |
| `deals_by_status` | Распределение по статусам |
| `payments_by_building` | (есть в каталоге, не подгружали) |
| `payments_in_period` | Просрочка |

`?widgets=manager_ranking` → 400 (не существует). То есть **есть строгий whitelist widget IDs на сервере**. URL'ы не угадать.

### 3.3 Funnel — отдельный rich endpoint

```http
GET /api/funnel?start=2026-04-01&end=2026-04-30
```

Возвращает **массивную составную структуру** (10 полей):
```ts
{
  period: { start, end },
  summary: {
    totalLeads, totalDeals, leadsWithDeal,
    completedDeals, reservedDeals, inWorkDeals,
    revenue, conversion, avgDealSum, revenuePerLead
  },
  funnelLeads: [{status_id, cnt}, ...],           // воронка по статусам
  channels: [{channel, leads, deals, revenue, conv_pct}, ...],
  houses:   [{house_id, house, total, new_leads, reserved, completed}, ...],
  managers: [{manager_id, manager, deals_count, completed, revenue}, ...],
  timeSeries:        [{dt, cnt}, ...],   // дневной поток лидов
  dealsTimeSeries:   [{dt, cnt}, ...],   // дневные сделки
  reservedTimeSeries:[{dt, cnt}, ...],
  allDealsTimeSeries:[{dt, cnt}, ...],
}
```

Это монолитный response для одной страницы (воронка как report, не как widget grid). Менее гибко чем наш ReportDataService, но и дешевле для них поддерживать.

### 3.4 Numeric report snapshot — самый интересный endpoint

```http
GET /api/dashboard/weekly-report-snapshot?start=2026-04-01&end=2026-04-30&periodType=month
```

Ответ:
```ts
{
  periodType: "month" | "week",
  primary: { start, end },
  compare: { start, end },            // ← автоматически вычисленный предыдущий период
  compareMode: "prev_month",
  preset: "full",
  compareLengthMismatch: boolean,
  summaryCurrent: { ...8 metrics... },
  summaryCompare: { ...same 8... },
  summaryDeltas: [
    {metric:"total_deals", current:49, compare:58, abs:-9, pct:-15.52}, ...
  ],
  anomalies: [],                       // ← embedded anomaly detection
  sparklineDealsByMonth:    [],        // ← мини-серии под каждый KPI
  sparklineDealAmount:      [],
  sparklineDealsByDay:      [],
  sparklineDealAmountByDay: [],
  reportTimeGrain: "day",              // grain для compare period
  grainNotes: [],                      // human-readable пояснения о grain
  widgetKeys: ["summary","deals_by_day", ...10],
  widgets: { /* все данные пакетом */ },
  capabilities: { exportExcel: true, aiNarrative: true }   // ← per-plan feature gates
}
```

**Что можно спиздить:**
- Авто-comparison с предыдущим периодом, прямо в payload — фронт не считает дельты сам.
- `anomalies[]` — серверная anomaly detection (мы такого не делаем; можно делать дешёвый z-score по последним N точкам).
- `sparkline*` — отдельные массивы под мини-графики в KPI-тайлах. Раздельные ключи → можно лениво подтягивать.
- `capabilities` per response — feature-gate per-plan, фронт не знает что план может, читает прямо из ответа. Нам в Vizion имеет смысл делать так же: `GET /api/reports/{id}/getData` мог бы возвращать `capabilities: {exportXlsx, exportPdf, aiExplain}` в зависимости от роли/плана.

### 3.5 Reconciliation — master-detail с XLS

```
Workflow: select client → select contract → table appears
Period выбор: Contracts | Receivables (две выборки сделок)
Кнопки: Refresh / Download XLS
```

Тонкая, продуманная UX — пока не выбран client+contract, таблица скрыта. У нас нет такого многошагового drilldown'а; это редкий, но очень понятный паттерн для «акта сверки» / «деталей по сделке».

---

## 4. AI-обвес (то, что мы трогать не стали, но видели)

### 4.1 Profile → AI usage (биллинг под AI)

```
AI usage:                ok
Units: 192 / 2500
Cost:  $0.2596 / $100.00
Resets at: 6/1/26, 12:00 AM
AI top-up: +0 units      [можно докупить]
```

**«Units are internal AI usage units; they depend on tokens and request complexity»** — у них **собственная виртуальная валюта AI-запросов**, не токены модели. Один пользовательский «вопрос» = N units = M токенов. Этот слой даёт им:
- предсказуемый биллинг для тенанта (никто не платит «за токены»);
- возможность дёшево считать сложный вопрос как «дорогой» (units != tokens);
- top-up purchase flow;
- ежемесячный сброс лимита.

Сейчас мы (Vizion) AI расходы вообще не лимитируем per tenant. **Это критическая дыра в монетизации** на будущем плане «AI-чат как платная фича».

### 4.2 Saved chat prompts (per tenant)

> «Short label on the button; the full question is applied when you send (you can also star a message in chat). Saved with company data.»
>
> «Repeating the exact same full question sometimes returns a cached answer with fewer tokens — useful for recurring reports, not guaranteed.»

То есть:
- любой ответ в чате можно «звёздочкой» сохранить как шорткат;
- сохранённые промпты привязаны к компании, не к пользователю;
- exact-match по тексту вопроса → cached response → меньше units;
- УЖЕ есть «Add prompt» кнопка в Profile.

Это очень дёшево реализовать у нас (просто таблица `chat_saved_prompts(company_id, label, full_prompt)`), но даёт большой UX-эффект: повторяемые отчёты «расход по статьям за месяц» становятся one-click.

### 4.3 Glossary / Terms for AI

> «Glossary and AI review are optional; everyday questions work in plain language.»
>
> Кнопки: `Add term`, `Insert "Revenue" example`, **`Review glossary with AI`**.

Кастомная терминология тенанта. Пользователь вводит свои термины («продажа», «отгрузка», «бронь = reservation»), и AI использует их как часть system prompt. «Review glossary with AI» — отдельная feature: AI сам предлагает добавить/уточнить термины на основе чатов.

У нас в Vizion `REPORTS_GUIDE.md` ~117 KB зашит в код. Идея вынести **per-tenant glossary** — серьёзная.

### 4.4 AI narrative (numeric report)

> «AI narrative is built from anonymized aggregates only (not the visible table names).»

Кнопка `Build narrative` строит ТЕКСТОВОЕ summary отчёта. И — внимание — **AI получает только агрегаты, без названий полей CRM-таблиц**. Это privacy-by-design: даже если AI-модель кто-то скомпрометирует, сырых имён полей у неё нет.

### 4.5 «Explain chart in AI assistant» per widget

Каждый виджет имеет 🔍-кнопку, которая бросает в чат сообщение типа `Explain the "Plan vs actual" chart for April 2026` с прокидыванием уже посчитанных данных. AI отвечает не «дай SQL», а «вот что вижу: …».

### 4.6 AI chart (single-prompt widget builder)

В «Add widget → AI chart» вкладка с одним input'ом. Это **легковесная альтернатива нашему tool-calling**: НЕ flow с probe_data → create_report, а one-shot text → widget config. Менее мощная, но быстрее работает, дешевле в units, проще объяснить пользователю.

### 4.7 «AI assistant» button на каждой странице отчёта

На любой странице (Funnel, Numeric report, Detail) — справа кнопка `AI assistant`. Очевидно вход в чат с уже подгруженным контекстом отчёта.

---

## 5. UX-паттерны, которые стоит украсть

| Паттерн | Где это у них | Зачем нам |
|---|---|---|
| KPI tile = drill-down link | Overview / dashboard | Сделать наши `column_type=link` правильнее: KPI тоже link |
| Period picker с keyboard shortcut (Alt+1) | везде в header | Удобно для аналитиков |
| «Updated N min ago» | дашборд header | Дешёвый, но видимый сигнал свежести |
| "Reset" период | header | возврат к default |
| "Copy report link" с period в URL | reports | shareable deep-link |
| `?period=YYYY-MM` в URL | везде | состояние в URL, refresh не теряет |
| Single batch widgets endpoint (`?widgets=csv`) | dashboard | один запрос → весь дашборд (сейчас у нас N запросов) |
| Auto-compare prev period в payload (`summaryDeltas`) | weekly-report | дельты не считает фронт |
| `capabilities` в payload | weekly-report | feature-gate per-plan без отдельного endpoint |
| Sparkline под KPI (отдельные массивы) | weekly-report | lazy подгрузка |
| Anomaly detection в payload | weekly-report | server-side z-score / простой алгоритм |
| 4 шаблона при создании дашборда (Empty/Sales/Marketing/Finance/Full) | New dashboard modal | onboarding для новых тенантов |
| "Explain chart in AI assistant" per widget | каждая widget-карточка | главная связка AI + dashboards |
| Saved prompts (starred chat messages) | Profile | сохранённые отчёты one-click |
| Glossary per tenant | Profile → Terms for AI | tenant-specific терминология |
| AI narrative («аноним agg only») | numeric report | один клик → текст отчёта |
| AI usage units (виртуальная валюта) | Profile | монетизация AI + предсказуемые лимиты |
| Top-up AI units | Profile | дополнительная выручка |
| Currency code per tenant (`currencyCode`) | session | мульти-валютный фронт |
| Impersonation flags в session | session | удобный staff-tooling |
| `effectivePlan` ≠ `plan` | session | manual overrides биллинг'а |
| Master-detail UX (Reconciliation) | Reconciliation | паттерн для глубоких drilldown'ов |
| Excel-export per table | Properties report | стандартный xlsx |
| Columns toggle + Column filters | Properties report | UX-паттерн для широких таблиц |
| Expandable row (`Click a building row to expand apartment list`) | Properties report | мы это умеем (group-rows), но у них дешевле |
| 4 локали ru/en/uz/ka | language switcher | сразу выходят на uz/ka рынки CRM-клиентов MACRO |

---

## 6. Конкретные библиотеки на изучение

| Назначение | Их выбор | Что попробовать |
|---|---|---|
| Charts | **Recharts** | если будем мигрировать с Chart.js — Recharts (SVG-декларативный, лучше для печати/SSR) или ECharts (мощнее, но тяжелее) |
| Tailwind | **Tailwind CSS** | у нас Bootstrap 5; миграция = большая, но Tailwind сильно компактнее в build size |
| Next.js | **App Router** | для нас неактуально (Vue + Laravel API), но смотреть как они закрывают server-state |
| Excel export | (видимо) `exceljs` или `xlsx` | в Vizion сейчас экспорта нет — спросить пользователя нужен ли |
| Drag-grid для dashboards | На странице не виден drag-handle. Возможно фиксированная сетка. Если будут добавлять — `react-grid-layout` | для нас (если будем композабельные дашборды) — `vue-grid-layout` |
| Period picker | вероятно их компонент или `react-day-picker` | у нас PrimeVue Calendar, нормально, можно оставить |

---

## 7. Где они слабее нас (наши козыри)

1. **Нет конструктора отчётов.** У них только фиксированный набор виджетов + AI-chart как single-prompt-генератор. Наш `Report` + `ReportSeeder` + AI tool calling (probe → create_report) — это уровень выше. У них пользователь не может создать отчёт «деньги по типам платежей за квартал с группировкой по проекту» — у нас может через чат.
2. **Detail-pages — заглушки.** На `/dashboard/detail?metric=deals` у нас рантайм-ошибка («Failed to fetch»), на `metric=debt` — голый KPI без таблицы, на `metric=properties` — таблица, но без серверной пагинации (вся выборка пришла в один запрос, 34 строки). Если будут добавлять > 1000 строк — задохнутся; у нас Eloquent paginate + qualifyPrimaryColumn + JOIN-sort с самого начала.
3. **Только один tenant в видимости — нет multi-tenant switcher.** В session есть один tenant, нет UI для смены — то есть один логин = одна компания (наш `active_company_id` UX мощнее, мы можем переключать компанию у superadmin'а).
4. **Тяжёлая монолитная воронка (`/api/funnel`).** 10 серий в одном response, не разделимы. Если страница огромная — нет lazy load'а.
5. **Single-prompt AI chart.** Нет нашего tool-calling cascade с probe → validate → save → dry-run retry. Они генерят виджет «вслепую» текстом.
6. **Нет генерации отчётов из CRM-структуры.** У нас в перспективе — отчёты по любому из 64 моделей MacroData; у них фиксированный whitelist widget IDs. Расширять каталог = деплой.
7. **Старые виджеты (Plan vs actual) показывают 0 / N/A** на тестовом аккаунте — план продаж где-то лежит, но не подгружен. Их инфраструктура отчётности зависит от того, заведены ли вспомогательные данные (план продаж, KPI-таргеты). У нас такого нет — мы считаем только из CRM.
8. **Empty channel show as blank row.** `leads_by_channel: [{"channel": "", "cnt": 4188}]` — самый большой канал «без названия». Они не санитайзят. У нас тоже надо аккуратно проверить.

---

## 8. Action items для Vizion (приоритизированные)

### P0 — быстрые победы (1–2 дня каждое)

1. **`/api/reports/{id}/getData` → batch endpoint** `/api/dashboard/data?widgets=csv`. Одним запросом отдавать N виджетов с фронта дашборда. Сейчас фронт делает N запросов параллельно — это работает, но добавим серверную coalescing и keep-alive.
2. **`capabilities` в payload.** Возвращать в каждом `getData` блок `{ canExportXlsx, canExportPdf, canAiExplain, ... }` based on role × plan. Фронт прячет кнопки сам.
3. **«Updated N min ago» лейбл** на верстаке отчётов. Берём из `Cache::tags(['report'])->get(...)->timestamp`.
4. **Auto-compare prev period в payload.** Когда `period=YYYY-MM`, в `meta` отдавать `comparePeriod` + дельты по агрегатам. Дешёвый второй запрос внутри ReportDataService.
5. **Keyboard shortcuts на period picker** (Alt+1 / Alt+2). PrimeVue Calendar умеет, плюс наш wrapper.
6. **«Copy report link» button.** Просто скопировать `location.href` (с current period в URL). Текущий ReportsPage уже хранит period в URL — добавить button.
7. **«Saved prompts» per company.** Таблица `chat_saved_prompts(id, company_id, label, full_prompt, created_by)`. Кнопка ⭐ в `ChatMessage`-bubble. Profile-page: список + добавление.

### P1 — средние (1 неделя каждое)

8. **Dashboards как сущность над Reports.** Сейчас у нас `Report` — и таблица, и чарт. Завести `Dashboard` с layout JSON, который содержит N виджетов (= N existing Reports). Сайдбар: Dashboards (composable) / Reports (single).
9. **Шаблоны нового дашборда.** При `POST /api/dashboards`: 5 шаблонов (Sales / Leads / Finance / Properties / Full overview) → сразу seed widget-list для нового тенанта.
10. **AI usage units + monthly quota.** Таблица `companies.ai_units_limit`, `companies.ai_units_used`, reset cron. В `ChatService::sendMessage` инкремент. Profile-page карточка.
11. **«Explain chart in AI assistant» per widget.** Кнопка ✨ в ChartCard.vue → создаёт новый chat и сразу шлёт `system message: explain the "<title>" chart for <period> with this data: <small JSON>`. Прокидываем prepared data в первый turn (нет нужды AI'ю звать probe_data).
12. **`effectivePlan` ≠ `plan` overrides.** Сейчас планов как таковых нет, но если будут — `companies.plan_override` для staff-overrides.
13. **`currencyCode` per company.** Сейчас всё в ₸/руб. — хардкод. Переводим всё форматирование сумм через `Intl.NumberFormat(locale, {style: 'currency', currency: tenant.currencyCode})`.
14. **Master-detail UX в reports.** Шаблон страницы «выбери X → выбери Y → появится таблица». Для отчётов сверки, отчётов по конкретной сделке, etc.

### P2 — большие (>1 неделя)

15. **Per-tenant glossary для AI.** Таблица `companies.ai_glossary jsonb` с парой `term → meaning`. Инъекция в system prompt в `ChatService`. UI в Profile: список терминов + кнопка «Review glossary with AI» (AI prompt: «вот глоссарий, предложи правки на основании этих чатов»).
16. **AI narrative endpoint.** `GET /api/reports/{id}/ai-narrative?period=...` → запускает чат-турн с **только агрегатами** (без сырых rows), возвращает текстовое summary. Privacy-by-design — даже если AI-провайдер компрометирован, у него нет имён CRM-полей.
17. **Anomaly detection в `getData`.** Простой z-score или MAD для time-series виджетов: на ответе `{ anomalies: [{point, expected, actual, severity}] }`. Фронт показывает 🔺 на точке графика.
18. **Top-up AI units (если будут платные планы).** Stripe / российский эквайринг, charge → +N units.
19. **Impersonation для superadmin.** «Войти от имени user X» — пишет `impersonation_log`, в сессии флаг. Полезно для саппорта.
20. **4 локали (добавить uz, ka)** — если CRM MACRO выходит в Узбекистан / Грузию. Сейчас только ru/en — добавляем `lang/uz/`, `lang/ka/` + `front/src/i18n/uz`, `ka`.
21. **Recharts vs Chart.js** — затратная миграция, но Recharts дружелюбнее для печати/SSR (decl SVG vs canvas). Только если у нас плохо с экспортом графиков как PNG / PDF. Сейчас не приоритет.

---

## 9. Резюме одной фразой

**Они: лёгкий frontend (Next + Tailwind + Recharts), один батч-эндпоинт для дашбордов, мощный SaaS-обвес вокруг AI (units-биллинг, saved prompts, glossary, narrative, "explain this chart"), но фиксированный whitelist виджетов и нет конструктора отчётов.**

**Мы: тяжёлый стек (Vue + PrimeVue + Bootstrap + Chart.js + Laravel + 64 read-only моделей), мощный кастомный конструктор отчётов с AI tool-calling, но без обвеса (нет units-биллинга, нет saved prompts, нет capabilities в payload, нет drill-down KPI, нет dashboard как сущности отдельно от Report).**

Топ-3 что забираем срочно:
1. **AI usage units + quota + top-up** — критически для монетизации.
2. **Dashboards над Reports + composable widget grid + шаблоны** — это ровно та часть UX которой нам сейчас не хватает.
3. **«Explain chart in AI assistant» + Saved prompts + AI narrative** — три фичи, которые превращают «AI как фишка» в «AI как ежедневный инструмент».

---

*Скриншоты собраны под управлением Playwright; чат конкурента не был использован (только статически осмотрены входы). Логин-логаут произведён один раз. Артефакты — `capitaldata-01-dashboard.png … capitaldata-14-new-dashboard.png` в корне проекта.*
