# Epic 22 — Cohort Analytics UI Spec

**Дата:** 2026-06-02  
**Статус:** Ready for implementation  
**Агент-исполнитель:** `frontend-specialist`  
**Бэкенд готов:** да (3 endpoint, сервис, миграция)

---

## Контекст

Когортная аналитика retention клиентов CS-реестра. Дашборд `/analytics/cohorts` показывает:
- Матрицу retention: когорта (месяц первой активации) × число месяцев с начала → % живых
- Линейный график retention по когортам
- KPI-карточки: Total Avg LTV, Projected LTV, MRR, Monthly Churn Rate
- Drill-down: клик на строку матрицы → список участников когорты

**Аудитория:** admin / director. Менеджер видит только свои подписки (фильтр на backend).

---

## API endpoints (бэкенд готов)

| Метод | URL | Описание |
|-------|-----|----------|
| GET | `/api/analytics/cohorts?periods=12&cohort_type=monthly` | Матрица + LTV данные |
| GET | `/api/analytics/cohorts/{cohort_month}/members` | Участники когорты (drill-down) |
| POST | `/api/analytics/cohorts/export?periods=12` | Excel-экспорт матрицы |

### Response shape `/api/analytics/cohorts`
```ts
{
  cohorts: Array<{
    cohort_month: string          // "2025-01"
    initial_count: number         // размер когорты
    retention: Array<{
      month_offset: number        // 0, 1, 2, ...
      active_count: number
      churned_count: number
      retention_pct: number       // 0..100
    }>
    avg_ltv: number               // средний LTV в валюте fee_actual
  }>
  matrix: Record<string, Record<number, number>>  // cohort_month → offset → count
  retention_pct: Record<string, Record<number, number>>  // cohort_month → offset → pct
  avg_ltv_per_cohort: Record<string, number>
  total_avg_ltv: number
  projected_ltv: number
  monthly_churn_rate: number      // 0..1, например 0.05 = 5%
  current_mrr: number
}
```

---

## Страница `/analytics/cohorts`

### Маршрут
Файл: `apps/web/src/app/(app)/analytics/cohorts/page.tsx`

### Заголовок страницы
```
PageHeader title="Когортная аналитика" icon="bi-grid-3x3"
```
Кнопки справа от заголовка:
- `Экспорт .xlsx` — нативная ссылка `<a href="/api/analytics/cohorts/export" download>` (POST через form submit — см. ниже)
- (опционально) Refresh кнопка (revalidate SWR)

**ВАЖНО**: export — POST endpoint, нативный `<a download>` не подходит. Использовать form submit:
```tsx
<form method="POST" action="/api/analytics/cohorts/export?periods=12" target="_blank">
  <button type="submit" className="btn-secondary">
    <i className="bi-download" /> Экспорт .xlsx
  </button>
</form>
```

### Фильтры (header bar)
Горизонтальная панель под заголовком (`flex gap-3 flex-wrap`):

| Поле | Тип | Значения | Default |
|------|-----|----------|---------|
| Глубина (periods) | select | 3 / 6 / 12 / 24 мес | 12 |
| Продукт (product_code) | select | из /api/platforms или вручную | Все |
| Страна (country_code) | select | из /api/analytics/cohorts уникальных или вручную | Все |

Фильтры влияют на SWR-ключ: `useSWR('/api/analytics/cohorts?periods=' + periods + '&product_code=' + product_code + '&country_code=' + country_code, fetcher, { revalidateOnFocus: false, dedupingInterval: 120_000 })`

---

## Блок 1 — KPI-карточки

4 карточки в ряд (`grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6`):

### Карточка 1 — MRR
```
icon: bi-cash-stack  color: text-primary
title: "Текущий MRR"
value: current_mrr formatted as KZT (Intl.NumberFormat)
subtitle: "Monthly Recurring Revenue"
```

### Карточка 2 — Projected LTV
```
icon: bi-graph-up-arrow  color: text-success
title: "Projected LTV"
value: projected_ltv formatted as KZT
subtitle: "MRR / Churn Rate"
note: если monthly_churn_rate == 0 → показывать "—" вместо суммы
```

### Карточка 3 — Total Avg LTV
```
icon: bi-people  color: text-info
title: "Средний LTV на клиента"
value: total_avg_ltv formatted as KZT
subtitle: "По всем когортам"
```

### Карточка 4 — Monthly Churn Rate
```
icon: bi-arrow-down-circle  color: text-danger
title: "Месячный Churn"
value: (monthly_churn_rate * 100).toFixed(1) + "%" 
subtitle: "Средний по всем когортам"
badge: если churn > 0.05 → badge danger "Высокий", если < 0.02 → badge success "Низкий"
```

---

## Блок 2 — CohortMatrix (главный компонент)

Файл: `apps/web/src/components/CohortMatrix.tsx`

### Структура таблицы

```
[ Когорта ] [ Размер ] [ +0 мес ] [ +1 мес ] [ +2 мес ] ... [ +N мес ]
[ 2025-01  ] [  20   ] [ 100%   ] [  90%   ] [  75%   ] ... [  40%   ]
[ 2025-02  ] [  15   ] [ 100%   ] [  87%   ] [  70%   ] ... [   —    ]
```

- Строка = cohort_month (из `data.cohorts`)
- Столбец = month_offset (0, 1, ..., periods)
- Если данных для offset нет (будущий период) — показывать "—"
- Первая строка — `<thead>` с `<th>`, данные — `<tbody>` с `<tr>`

### Heatmap окраска ячеек

Цвет заливки фона ячейки на основе `retention_pct`:
- 100% → `#d1fae5` (зелёный)
- 80-99% → `#bbf7d0`
- 60-79% → `#fef9c3` (жёлтый)
- 40-59% → `#fed7aa` (оранжевый)
- 20-39% → `#fecaca` (красный светлый)
- 0-19% → `#f87171` (красный)
- "—" → прозрачный фон

Цвет текста: если фон тёмный (< 30%) → `text-white`, иначе `text-gray-900`

Реализация:
```tsx
function getCellStyle(pct: number | undefined): React.CSSProperties {
  if (pct === undefined) return {}
  if (pct >= 100) return { backgroundColor: '#d1fae5' }
  if (pct >= 80) return { backgroundColor: '#bbf7d0' }
  if (pct >= 60) return { backgroundColor: '#fef9c3' }
  if (pct >= 40) return { backgroundColor: '#fed7aa' }
  if (pct >= 20) return { backgroundColor: '#fecaca' }
  return { backgroundColor: '#f87171', color: 'white' }
}
```

### Hover tooltip

При наведении на ячейку — `title` атрибут:
```
"5 из 20 клиентов (offset +3 мес)"
```
Данные берутся из `data.matrix[cohort_month][offset]` (absolute count) + `data.cohorts[i].initial_count`.

### Клик на строку (drill-down)

При клике на строку таблицы (`onClick` на `<tr>`) — открывать Modal с участниками когорты.
Modal загружает `/api/analytics/cohorts/{cohort_month}/members`.

### Props интерфейс

```ts
interface CohortMatrixProps {
  cohorts: CohortData[]
  retentionPct: Record<string, Record<number, number>>
  matrix: Record<string, Record<number, number>>
  maxOffset: number  // = periods из фильтра
  onCohortClick?: (cohortMonth: string) => void
}
```

---

## Блок 3 — RetentionLineChart

Файл: `apps/web/src/components/RetentionLineChart.tsx`

**Реализация: чистый SVG без сторонних библиотек** (как другие charts в проекте).

### Данные

X-ось: month_offset (0, 1, 2, ..., maxOffset)
Y-ось: retention_pct (0..100)
Линии: одна на каждую когорту (максимум 12 когорт, если больше — показывать только последние 12 по дате)

### SVG структура

```
viewBox="0 0 600 300"
Padding: left=50, bottom=30, top=20, right=20

X-метки: 0, 3, 6, 9, 12 (или все если periods <= 6)
Y-метки: 0%, 25%, 50%, 75%, 100%
Grid lines: горизонтальные пунктирные (stroke-dasharray="4,4" stroke="#e5e7eb")

Линии когорт: stroke-width=2, без маркеров точек (clean lines)
Цвета: 12 цветов из палитры (bootstrap primary, success, danger, warning, info + оттенки)
Легенда: снизу или справа, когорта → цвет, max 12 строк
```

### Цветовая палитра для линий

```ts
const COHORT_COLORS = [
  '#172747', '#2B4987', '#3b82f6', '#10b981', '#f59e0b',
  '#ef4444', '#8b5cf6', '#06b6d4', '#84cc16', '#f97316',
  '#ec4899', '#64748b',
]
```

### Props

```ts
interface RetentionLineChartProps {
  retentionPct: Record<string, Record<number, number>>
  maxOffset: number
  width?: number   // default 600
  height?: number  // default 300
}
```

---

## Блок 4 — LTV таблица по когортам

Простая таблица под графиком:

| Когорта | Размер | Avg LTV | Projected (TODO) |
|---------|--------|---------|------------------|
| 2025-01 | 20     | 150,000 | —                |

Сортировка по cohort_month (desc по умолчанию).
LTV форматируется как `Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'KZT', maximumFractionDigits: 0 }).format(value)`.

---

## Блок 5 — Drill-down Modal (участники когорты)

Открывается при клике на строку матрицы.

### Заголовок Modal
`Когорта {cohort_month} — {count} участников`

### Таблица участников

| Контрагент | Дата старта | Текущий этап | Дата ухода | LTV |
|-----------|-------------|--------------|-----------|-----|
| ООО Ромашка | 2025-01-15 | A2 | — | 150,000 |
| ИП Иванов | 2025-01-20 | C0 | 2025-06-01 | 75,000 |

- `churn_date` — если null → "—"
- `is_churned: true` → строка красного оттенка (`bg-red-50`)
- `is_active: false` → badge danger "Неактивна"
- LTV в этом Modal = fee_actual × (churn_date - cohort_start_date) в месяцах (вычислить на фронте)

### Загрузка

```ts
const { data: members } = useSWR(
  selectedCohort ? `/api/analytics/cohorts/${selectedCohort}/members` : null,
  fetcher,
  { revalidateOnFocus: false }
)
```

---

## Навигация — Sidebar

В группе «Аналитика» добавить новый пункт:

```tsx
// В apps/web/src/components/Sidebar.tsx или аналогичном месте
{
  href: '/analytics/cohorts',
  label: 'Когортная аналитика',
  icon: 'bi-grid-3x3',
}
```

Расположить после существующих пунктов аналитики (dashboard, funnel, forecast).

---

## Состояния загрузки

- Skeleton loader для таблицы (анимированные серые строки): `animate-pulse bg-gray-200 rounded`
- KPI-карточки: `—` вместо данных пока loading
- Ошибка загрузки: `<div className="alert-danger">Не удалось загрузить данные когортной аналитики</div>`

---

## TypeScript типы

Создать `apps/web/src/types/cohort.ts`:

```ts
export interface CohortRow {
  month_offset: number
  active_count: number
  churned_count: number
  retention_pct: number
}

export interface CohortData {
  cohort_month: string
  initial_count: number
  retention: CohortRow[]
  avg_ltv: number
}

export interface CohortAnalyticsResponse {
  cohorts: CohortData[]
  matrix: Record<string, Record<number, number>>
  retention_pct: Record<string, Record<number, number>>
  avg_ltv_per_cohort: Record<string, number>
  total_avg_ltv: number
  projected_ltv: number
  monthly_churn_rate: number
  current_mrr: number
}

export interface CohortMember {
  subscription_id: number
  counterparty_name: string
  cohort_start_date: string | null
  current_stage_code: string | null
  current_stage_name: string | null
  churn_date: string | null
  is_churned: boolean
  fee_actual: number | null
  is_active: boolean
}

export interface CohortMembersResponse {
  cohort_month: string
  count: number
  members: CohortMember[]
}
```

---

## Порядок реализации для frontend-specialist

1. Создать `apps/web/src/types/cohort.ts` (типы)
2. Создать `apps/web/src/components/CohortMatrix.tsx` (главный компонент)
3. Создать `apps/web/src/components/RetentionLineChart.tsx` (SVG-график)
4. Создать `apps/web/src/app/(app)/analytics/cohorts/page.tsx` (страница)
5. Добавить пункт «Когортная аналитика» в Sidebar
6. `npx tsc --noEmit` — 0 ошибок

---

## Что НЕ делает frontend

- НЕ использует recharts/d3/chart.js — только SVG
- НЕ использует `Authorization` header — cookie auth автоматически
- НЕ делает нативный `<a download>` для POST /cohorts/export — использует form submit
- НЕ хардкодит цвета heatmap в Tailwind-классах (они динамические) — использует inline `style`
- НЕ показывает Projected LTV если monthly_churn_rate == 0 (делить на ноль нельзя, бэк вернёт 0.0)

---

## Acceptance Criteria для frontend

- [ ] `/analytics/cohorts` рендерится без console errors
- [ ] CohortMatrix: heatmap цвета меняются корректно (100% зелёный, <20% красный)
- [ ] Hover на ячейке показывает tooltip с абсолютным числом
- [ ] Клик на строку открывает Modal с участниками
- [ ] RetentionLineChart: минимум одна линия при наличии данных
- [ ] KPI-карточки: все 4 показывают данные (или "—" если 0)
- [ ] Фильтры меняют данные (revalidate SWR)
- [ ] Кнопка Excel export скачивает файл
- [ ] `npx tsc --noEmit` = 0 ошибок
- [ ] Sidebar содержит «Когортная аналитика» с иконкой `bi-grid-3x3`
